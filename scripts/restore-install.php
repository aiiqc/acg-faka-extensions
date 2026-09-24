<?php
declare(strict_types=1);

umask(0077);

/** @var null|array{site: string, uid: int, gid: int, roots: array<string, array<string, int>>, active: bool} */
$officialBarrierState = null;
$officialBarrierReleaseRunning = false;

function fail(string $message): never
{
    global $officialBarrierState, $officialBarrierReleaseRunning;
    $exit = 1;
    if (is_array($officialBarrierState)
        && ($officialBarrierState['active'] ?? false) === true
        && !$officialBarrierReleaseRunning) {
        $officialBarrierReleaseRunning = true;
        if (!releaseOfficialRuntimeBarriers($officialBarrierState)) {
            fwrite(STDERR, "ERROR: ROLLBACK_INCOMPLETE official runtime barrier release failed\n");
            $exit = 70;
        }
        $officialBarrierState['active'] = false;
        $officialBarrierReleaseRunning = false;
    }
    fwrite(STDERR, "ERROR: {$message}\n");
    exit($exit);
}

/** @return array<string, int> */
function officialRuntimeRoots(): array
{
    return [
        'assets/cache' => 0o755,
        'app/Pay' => 0o755,
        'app/Plugin' => 0o755,
        'app/View/User/Theme' => 0o755,
        'config' => 0o750,
        'kernel/Install' => 0o750,
        'runtime' => 0o750,
    ];
}

/** @return array{0: int, 1: int} */
function officialRuntimeFinalOwner(string $relative, int $webUid, int $webGid): array
{
    return in_array($relative, ['config', 'kernel/Install'], true)
        ? [0, $webGid]
        : [$webUid, $webGid];
}

function noExtendedAcl(string $path): bool
{
    if (!function_exists('proc_open') || !is_executable('/usr/bin/ls')) {
        return false;
    }
    $pipes = [];
    $process = proc_open(
        ['/usr/bin/ls', '-ld', '--', $path],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        ['LC_ALL' => 'C']
    );
    if (!is_resource($process)) {
        return false;
    }
    $stdout = stream_get_contents($pipes[1], 4096);
    $stderr = stream_get_contents($pipes[2], 4096);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0 || !is_string($stdout) || $stdout === '' || $stderr === false) {
        return false;
    }
    $token = strtok($stdout, " \t\r\n");
    return is_string($token) && !str_ends_with($token, '+');
}

function uidIsQuiescent(int $uid): bool
{
    if ($uid < 1 || !is_dir('/proc') || !is_readable('/proc/self/status')) {
        return false;
    }
    $entries = scandir('/proc');
    if (!is_array($entries)) {
        return false;
    }
    foreach ($entries as $entry) {
        if (!ctype_digit($entry)) {
            continue;
        }
        $status = '/proc/' . $entry . '/status';
        $bytes = @file_get_contents($status, false, null, 0, 16384);
        if (!is_string($bytes)) {
            continue;
        }
        if (preg_match('/^Uid:\s+([0-9]+)\s+([0-9]+)\s+([0-9]+)\s+([0-9]+)$/m', $bytes, $match) !== 1) {
            continue;
        }
        foreach (array_slice($match, 1, 4) as $slot) {
            if ((int)$slot === $uid) {
                return false;
            }
        }
    }
    return true;
}

function freshLstat(string $path): array|false
{
    clearstatcache(true, $path);
    return lstat($path);
}

/** @return array<string, int> */
function boundDirectory(string $path, string $label, ?array $expected = null): array
{
    $stat = freshLstat($path);
    $real = realpath($path);
    if (!is_array($stat) || $real !== $path || !is_dir($path) || is_link($path)
        || !noExtendedAcl($path)) {
        fail("{$label} is not a canonical ACL-free directory: {$path}");
    }
    $identity = [
        'dev' => (int)($stat['dev'] ?? -1),
        'ino' => (int)($stat['ino'] ?? -1),
        'nlink' => (int)($stat['nlink'] ?? -1),
        'uid' => (int)($stat['uid'] ?? -1),
        'gid' => (int)($stat['gid'] ?? -1),
        'mode' => (int)($stat['mode'] ?? 0) & 0o7777,
    ];
    // Overlay filesystems are permitted to expose canonical directories with
    // nlink=1, and a directory's count can change as child directories are
    // created or removed. Stable identity is bound by path/dev/inode/type/ACL.
    if ($identity['dev'] < 0 || $identity['ino'] < 1 || $identity['nlink'] < 1) {
        fail("{$label} identity is invalid: {$path}");
    }
    if (is_array($expected)
        && ($identity['dev'] !== ($expected['dev'] ?? null)
            || $identity['ino'] !== ($expected['ino'] ?? null))) {
        fail("{$label} device/inode identity drifted: {$path}");
    }
    return $identity;
}

function assertProtectedOfficialParent(string $path, string $label): void
{
    $stat = lstat($path);
    if (!is_array($stat) || realpath($path) !== $path || !is_dir($path) || is_link($path)
        || (int)($stat['uid'] ?? -1) !== 0 || (int)($stat['gid'] ?? -1) !== 0
        || (((int)($stat['mode'] ?? 0) & 0o7777) & 0o022) !== 0
        || !noExtendedAcl($path)) {
        fail("{$label} must be a protected root-owned directory: {$path}");
    }
}

/** @return array{site: string, uid: int, gid: int, roots: array<string, array<string, int>>, active: bool} */
function takeOfficialRuntimeBarriers(
    string $siteRoot,
    int $webUid,
    int $webGid
): array {
    if (!uidIsQuiescent($webUid)) {
        fail('dedicated Web identity must have zero processes before restore barriers');
    }
    $state = [
        'site' => $siteRoot,
        'uid' => $webUid,
        'gid' => $webGid,
        'roots' => [],
        'active' => true,
    ];
    global $officialBarrierState;
    $officialBarrierState = $state;
    foreach (officialRuntimeRoots() as $relative => $mode) {
        $path = $siteRoot . '/' . $relative;
        assertProtectedOfficialParent(dirname($path), 'official runtime protected parent');
        $identity = boundDirectory($path, 'official runtime root');
        [$expectedUid, $expectedGid] = officialRuntimeFinalOwner($relative, $webUid, $webGid);
        if ($identity['uid'] !== $expectedUid || $identity['gid'] !== $expectedGid
            || $identity['mode'] !== $mode) {
            fail("official runtime root does not match the installed contract: {$relative}");
        }
        $officialBarrierState['roots'][$relative] = $identity;
        if (!chown($path, 0) || !chgrp($path, 0) || !chmod($path, 0o700)) {
            fail("unable to establish official runtime restore barrier: {$relative}");
        }
        $barrier = boundDirectory($path, 'official runtime restore barrier', $identity);
        if ($barrier['uid'] !== 0 || $barrier['gid'] !== 0 || $barrier['mode'] !== 0o700) {
            fail("official runtime restore barrier verification failed: {$relative}");
        }
    }
    if (!uidIsQuiescent($webUid)) {
        fail('dedicated Web identity became active after restore barriers');
    }
    if (!officialRuntimeBarriersMatch($officialBarrierState, true)) {
        fail('official runtime restore barriers drifted before mutation');
    }
    return $officialBarrierState;
}

/**
 * @param array{site: string, uid: int, gid: int, roots: array<string, array<string, int>>, active: bool} $state
 */
function officialRuntimeBarriersMatch(array $state, bool $requireComplete): bool
{
    if (($state['active'] ?? false) !== true
        || !is_string($state['site'] ?? null)
        || !is_int($state['uid'] ?? null)
        || !is_array($state['roots'] ?? null)
        || !uidIsQuiescent($state['uid'])) {
        return false;
    }
    $modes = officialRuntimeRoots();
    if ($requireComplete && array_keys($state['roots']) !== array_keys($modes)) {
        return false;
    }
    foreach ($state['roots'] as $relative => $identity) {
        $path = $state['site'] . '/' . $relative;
        $stat = freshLstat($path);
        if (!array_key_exists($relative, $modes) || !is_array($identity)
            || !is_array($stat) || realpath($path) !== $path
            || !is_dir($path) || is_link($path) || !noExtendedAcl($path)
            || (int)($stat['dev'] ?? -1) !== ($identity['dev'] ?? null)
            || (int)($stat['ino'] ?? -1) !== ($identity['ino'] ?? null)
            || (int)($stat['uid'] ?? -1) !== 0 || (int)($stat['gid'] ?? -1) !== 0
            || (((int)($stat['mode'] ?? 0) & 0o7777) !== 0o700)) {
            return false;
        }
    }
    return true;
}

/** @param array{site: string, uid: int, gid: int, roots: array<string, array<string, int>>, active: bool} $state */
function releaseOfficialRuntimeBarriers(array &$state): bool
{
    if (($state['active'] ?? false) !== true) {
        return true;
    }
    $siteRoot = $state['site'];
    $webUid = $state['uid'];
    $webGid = $state['gid'];
    if (!officialRuntimeBarriersMatch($state, false)) {
        fwrite(STDERR, "ERROR: official runtime barriers drifted before release\n");
        return false;
    }
    $rows = array_reverse($state['roots'], true);
    $modes = officialRuntimeRoots();
    // Preflight every bound root before authorizing any of them. This avoids a
    // later identity failure leaving an earlier root exposed to the Web UID.
    foreach ($rows as $relative => $identity) {
        $mode = $modes[$relative] ?? null;
        $path = $siteRoot . '/' . $relative;
        $stat = freshLstat($path);
        if (!is_int($mode) || !is_array($identity) || !is_array($stat) || realpath($path) !== $path
            || !is_dir($path) || is_link($path) || !noExtendedAcl($path)
            || (int)($stat['dev'] ?? -1) !== ($identity['dev'] ?? null)
            || (int)($stat['ino'] ?? -1) !== ($identity['ino'] ?? null)
            || (int)($stat['uid'] ?? -1) !== 0 || (int)($stat['gid'] ?? -1) !== 0
            || (((int)($stat['mode'] ?? 0) & 0o7777) !== 0o700)) {
            fwrite(STDERR, "ERROR: official runtime barrier identity drifted: {$relative}\n");
            return false;
        }
    }
    foreach ($rows as $relative => $identity) {
        $mode = $modes[$relative];
        $path = $siteRoot . '/' . $relative;
        [$expectedUid, $expectedGid] = officialRuntimeFinalOwner($relative, $webUid, $webGid);
        // Ownership is transferred last. The config and installer parents
        // deliberately remain root-owned and only expose their exact mutable
        // files plus the installer OS subtree.
        if (!chmod($path, $mode) || !chgrp($path, $expectedGid) || !chown($path, $expectedUid)) {
            fwrite(STDERR, "ERROR: unable to authorize official runtime root: {$relative}\n");
            return false;
        }
        $stat = freshLstat($path);
        if (!is_array($stat) || realpath($path) !== $path || is_link($path)
            || (int)($stat['dev'] ?? -1) !== ($identity['dev'] ?? null)
            || (int)($stat['ino'] ?? -1) !== ($identity['ino'] ?? null)
            || (int)($stat['uid'] ?? -1) !== $expectedUid
            || (int)($stat['gid'] ?? -1) !== $expectedGid
            || (((int)($stat['mode'] ?? 0) & 0o7777) !== $mode)) {
            fwrite(STDERR, "ERROR: official runtime root authorization drifted: {$relative}\n");
            return false;
        }
    }
    $state['active'] = false;
    return true;
}

function canonicalDirectory(string $path, string $label): string
{
    if ($path === '' || $path[0] !== '/' || is_link($path)) {
        fail("{$label} must be an exact absolute directory path");
    }
    $real = realpath($path);
    if ($real === false || $real !== $path || !is_dir($real) || $real === DIRECTORY_SEPARATOR) {
        fail("{$label} must be an exact absolute directory path");
    }
    return $real;
}

function isWithin(string $path, string $root): bool
{
    return $path === $root || str_starts_with($path . '/', rtrim($root, '/') . '/');
}

function assertRootOwnedDirectory(string $path, int $mode, string $label): string
{
    $real = realpath($path);
    $permissions = fileperms($path);
    if ($real === false || $real !== $path || !is_dir($path) || is_link($path)
        || fileowner($path) !== 0 || filegroup($path) !== 0
        || $permissions === false || ($permissions & 0777) !== $mode) {
        fail("{$label} ownership or permissions are unsafe: {$path}");
    }
    return $real;
}

function assertRootOwnedFile(string $path, array $modes, string $label): string
{
    $real = realpath($path);
    $permissions = fileperms($path);
    if ($real === false || $real !== $path || !is_file($path) || is_link($path)
        || fileowner($path) !== 0 || filegroup($path) !== 0
        || $permissions === false || !in_array($permissions & 0777, $modes, true)) {
        fail("{$label} ownership or permissions are unsafe: {$path}");
    }
    return $real;
}

function assertProtectedRootChain(string $path, string $label): void
{
    if ($path === '' || $path[0] !== '/') {
        fail("{$label} path is not absolute");
    }
    $cursor = DIRECTORY_SEPARATOR;
    foreach (explode(DIRECTORY_SEPARATOR, trim($path, DIRECTORY_SEPARATOR)) as $segment) {
        if ($segment === '') {
            continue;
        }
        $cursor = rtrim($cursor, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $segment;
        $permissions = fileperms($cursor);
        if (!is_dir($cursor) || is_link($cursor)
            || fileowner($cursor) !== 0 || filegroup($cursor) !== 0
            || $permissions === false || ($permissions & 0o022) !== 0) {
            fail("{$label} ancestry is writable or not root-owned: {$cursor}");
        }
        if (!noExtendedAcl($cursor)) {
            fail("{$label} ancestry must not have an extended POSIX ACL: {$cursor}");
        }
    }
}

/** @return array{0: array<string, mixed>, 1: string} */
function readSafeJson(string $path, bool $requireRootOwner): array
{
    if (!is_file($path) || is_link($path)) {
        fail("unsafe receipt path: {$path}");
    }
    $mode = fileperms($path);
    $owner = fileowner($path);
    if ($mode === false || ($mode & 0o022) !== 0 || ($requireRootOwner && $owner !== 0)) {
        fail("receipt ownership or permissions are unsafe: {$path}");
    }
    $bytes = file_get_contents($path);
    if (!is_string($bytes) || strlen($bytes) > 1048576) {
        fail("receipt is unreadable or too large: {$path}");
    }
    try {
        $value = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        fail("receipt JSON is invalid: {$path}");
    }
    if (!is_array($value) || array_is_list($value)) {
        fail("receipt root is invalid: {$path}");
    }
    return [$value, $bytes];
}

function safeRelative(mixed $path): string
{
    if (!is_string($path) || $path === '' || $path[0] === '/' || str_contains($path, "\0")
        || str_contains($path, '\\')) {
        fail('receipt contains an unsafe relative path');
    }
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..'
            || preg_match('/^[A-Za-z0-9._-]+$/D', $segment) !== 1) {
            fail('receipt contains an unsafe relative path');
        }
    }
    return $path;
}

function isCanonicalSingleLinkAclFreeFile(string $path): bool
{
    $stat = freshLstat($path);
    return is_array($stat) && is_file($path) && !is_link($path)
        && realpath($path) === $path
        && (int)($stat['nlink'] ?? 0) === 1
        && (int)($stat['uid'] ?? -1) === 0
        && (int)($stat['gid'] ?? -1) === 0
        && (((int)($stat['mode'] ?? 0)) & 0o7777) === 0o644
        && noExtendedAcl($path);
}

function assertSafeRegularFile(
    string $root,
    string $relative,
    string $label,
    bool $immutable = false
): string
{
    $relative = safeRelative($relative);
    $cursor = $root;
    if ($immutable) {
        $rootStat = freshLstat($cursor);
        if (!is_array($rootStat) || !is_dir($cursor) || is_link($cursor)
            || realpath($cursor) !== $cursor
            || (int)($rootStat['uid'] ?? -1) !== 0
            || (int)($rootStat['gid'] ?? -1) !== 0
            || (((int)($rootStat['mode'] ?? 0) & 0o7777) & 0o022) !== 0
            || !noExtendedAcl($cursor)) {
            fail("{$label} root is not immutable: {$relative}");
        }
    }
    $segments = explode('/', $relative);
    foreach ($segments as $index => $segment) {
        $cursor .= '/' . $segment;
        if (is_link($cursor)) {
            fail("{$label} contains a symbolic link: {$relative}");
        }
        if ($index < count($segments) - 1 && !is_dir($cursor)) {
            fail("{$label} parent is missing or unsafe: {$relative}");
        }
        if ($immutable && $index < count($segments) - 1) {
            $parentStat = freshLstat($cursor);
            if (!is_array($parentStat) || realpath($cursor) !== $cursor
                || (int)($parentStat['uid'] ?? -1) !== 0
                || (int)($parentStat['gid'] ?? -1) !== 0
                || (((int)($parentStat['mode'] ?? 0) & 0o7777) & 0o022) !== 0
                || !noExtendedAcl($cursor)) {
                fail("{$label} parent is not immutable: {$relative}");
            }
        }
    }
    if (!is_file($cursor)) {
        fail("{$label} is missing or unsafe: {$relative}");
    }
    if ($immutable && !isCanonicalSingleLinkAclFreeFile($cursor)) {
        fail("{$label} must be a canonical ACL-free regular file with exactly one hard link: {$relative}");
    }
    return $cursor;
}

function assertDedicatedWebMutableFile(
    string $path,
    int $webUid,
    int $webGid,
    array $allowedModes,
    string $failureMessage
): void {
    $stat = lstat($path);
    $mode = is_array($stat) ? ((int)($stat['mode'] ?? 0) & 0o7777) : -1;
    if (!is_array($stat) || !is_file($path) || is_link($path)
        || realpath($path) !== $path || (int)($stat['nlink'] ?? 0) !== 1
        || (int)($stat['uid'] ?? -1) !== $webUid
        || (int)($stat['gid'] ?? -1) !== $webGid
        || !in_array($mode, $allowedModes, true) || !noExtendedAcl($path)) {
        fail($failureMessage);
    }
}

function assertSafeDirectory(string $root, string $relative, string $label): string
{
    $relative = safeRelative($relative);
    $cursor = $root;
    foreach (explode('/', $relative) as $segment) {
        $cursor .= '/' . $segment;
        if (is_link($cursor) || !is_dir($cursor)) {
            fail("{$label} is missing or unsafe: {$relative}");
        }
    }
    return $cursor;
}

function isAllowedInstalledPath(string $relative): bool
{
    foreach ([
        'local-extensions/',
        'app/View/Admin/LocalExtensions/',
        'app/View/User/Theme/Pika/',
        'app/Pay/PikaBEpusdtAdapter/',
        'assets/admin/controller/local-extensions/',
        'assets/local-extensions/',
    ] as $prefix) {
        if (str_starts_with($relative, $prefix)) {
            return true;
        }
    }
    return in_array($relative, [
        'app/Controller/Admin/LocalExtensions.php',
        'app/Controller/Admin/Api/LocalExtensions.php',
        'app/Controller/User/Api/PikaBEpusdt.php',
    ], true);
}

final class RestoreMutationFailure extends RuntimeException
{
}

function mutationFailure(string $message): never
{
    throw new RestoreMutationFailure($message);
}

function stageSiblingCopy(string $source, string $target, string $prefix, string $expectedHash): string
{
    $temporary = tempnam(dirname($target), $prefix);
    if ($temporary === false) {
        mutationFailure("unable to reserve same-filesystem restore stage for {$target}");
    }
    $metadata = stat($source);
    if ($metadata === false || !copy($source, $temporary)
        || !chmod($temporary, $metadata['mode'] & 07777)
        || !chown($temporary, $metadata['uid'])
        || !chgrp($temporary, $metadata['gid'])
        || !hash_equals($expectedHash, hash_file('sha256', $temporary))) {
        @unlink($temporary);
        mutationFailure("unable to stage verified same-filesystem copy for {$target}");
    }
    return $temporary;
}

function injectRestoreFailure(string $point): void
{
    if (getenv('PIKA_RESTORE_TESTING') === '1' && $point === 'after-payload-stage'
        && getenv('PIKA_RESTORE_TEST_FAIL_AT') === 'reinclude-payment-rule') {
        global $paymentRotationGuard;
        if (!is_array($paymentRotationGuard) || $paymentRotationGuard['backup'] === null
            || !paymentRotationGuardMatches($paymentRotationGuard)
            || !rename($paymentRotationGuard['backup'], $paymentRotationGuard['rule'])) {
            mutationFailure('unable to inject payment rule isolation loss');
        }
    }
    if (getenv('PIKA_RESTORE_TESTING') === '1'
        && getenv('PIKA_RESTORE_TEST_FAIL_AT') === $point) {
        mutationFailure("injected restore failure at {$point}");
    }
}

/**
 * @param array<string, array{before_sha256: string, after_sha256: string}> $bridgeFiles
 * @param array<string, string> $stagedBridgeOld
 * @param array<string, string> $stagedBridgeNew
 * @param list<string> $swappedBridge
 * @param array<string, string> $stagedPayload
 * @param array<string, string> $payloadHashes
 * @param array<string, string> $payloadPaths
 */
function rollbackRestore(
    string $siteRoot,
    array $bridgeFiles,
    array $stagedBridgeOld,
    array $stagedBridgeNew,
    array $swappedBridge,
    array $stagedPayload,
    array $payloadHashes,
    array $payloadPaths,
    string $stateSiteRoot,
    ?string $stateArchivePath,
    bool $stateArchived,
    string $siteReceiptHash,
    int $webUid,
    int $webGid,
    array $paymentLogs
): bool {
    global $officialBarrierState;
    $ok = true;

    if (!is_array($officialBarrierState)
        || !officialRuntimeBarriersMatch($officialBarrierState, true)) {
        return false;
    }

    if ($stateArchived) {
        if ($stateArchivePath === null
            || file_exists($stateSiteRoot) || is_link($stateSiteRoot)
            || !is_dir($stateArchivePath) || is_link($stateArchivePath)
            || !rename($stateArchivePath, $stateSiteRoot)) {
            $ok = false;
        }
    }

    $restoredReceipt = $stateSiteRoot . '/install-receipt.json';
    $restoredRuntime = $stateSiteRoot . '/runtime';
    $restoredSecrets = $stateSiteRoot . '/secrets';
    $restoredRuntimeMode = fileperms($restoredRuntime);
    $restoredSecretsMode = fileperms($restoredSecrets);
    if (!is_file($restoredReceipt) || is_link($restoredReceipt)
        || !hash_equals($siteReceiptHash, hash_file('sha256', $restoredReceipt))
        || !is_dir($restoredRuntime) || is_link($restoredRuntime)
        || fileowner($restoredRuntime) !== $webUid
        || filegroup($restoredRuntime) !== $webGid
        || $restoredRuntimeMode === false || ($restoredRuntimeMode & 0777) !== 0750
        || !is_dir($restoredSecrets) || is_link($restoredSecrets)
        || fileowner($restoredSecrets) !== 0
        || filegroup($restoredSecrets) !== $webGid
        || $restoredSecretsMode === false || ($restoredSecretsMode & 0777) !== 0750) {
        $ok = false;
    }

    foreach (array_reverse($swappedBridge) as $relative) {
        $target = $siteRoot . '/' . $relative;
        $rollback = $stagedBridgeOld[$relative] ?? '';
        if ($rollback === '' || !isCanonicalSingleLinkAclFreeFile($rollback)
            || !hash_equals($bridgeFiles[$relative]['after_sha256'], hash_file('sha256', $rollback))
            || !rename($rollback, $target)) {
            $ok = false;
        }
    }

    foreach (array_reverse(array_keys($stagedPayload)) as $relative) {
        $target = $payloadPaths[$relative];
        $temporary = $stagedPayload[$relative];
        if (file_exists($target) || is_link($target)
            || !is_file($temporary) || is_link($temporary)
            || !hash_equals($payloadHashes[$relative], hash_file('sha256', $temporary))
            || (isset($paymentLogs[$relative])
                && !samePaymentLog(paymentLogMetadata($temporary, $webUid, $webGid), $paymentLogs[$relative]))
            || !rename($temporary, $target)) {
            $ok = false;
        }
    }

    foreach ($bridgeFiles as $relative => $hashes) {
        $target = $siteRoot . '/' . $relative;
        if (!isCanonicalSingleLinkAclFreeFile($target)
            || !hash_equals($hashes['after_sha256'], hash_file('sha256', $target))) {
            $ok = false;
        }
    }
    foreach ($payloadHashes as $relative => $expectedHash) {
        $target = $payloadPaths[$relative];
        if (!is_file($target) || is_link($target)
            || !hash_equals($expectedHash, hash_file('sha256', $target))
            || (isset($paymentLogs[$relative])
                && !samePaymentLog(paymentLogMetadata($target, $webUid, $webGid), $paymentLogs[$relative]))) {
            $ok = false;
        }
    }

    // Preserve every remaining recovery artifact when any rollback step or
    // post-rollback verification failed. Deleting it would make manual
    // recovery less reliable precisely when it is still needed.
    if ($ok) {
        foreach ([$stagedBridgeOld, $stagedBridgeNew, $stagedPayload] as $stagedSet) {
            foreach ($stagedSet as $temporary) {
                if ((file_exists($temporary) || is_link($temporary)) && !unlink($temporary)) {
                    $ok = false;
                }
            }
        }
    }
    return $ok;
}

function addCleanupDirectories(array &$directories, string $relative): void
{
    $allowedRoots = [
        'local-extensions',
        'app/View/Admin/LocalExtensions',
        'app/View/User/Theme/Pika',
        'app/Pay/PikaBEpusdtAdapter',
        'assets/admin/controller/local-extensions',
        'assets/local-extensions',
        'runtime/local-extensions',
    ];
    $directory = dirname($relative);
    while ($directory !== '.' && $directory !== '') {
        $allowed = false;
        foreach ($allowedRoots as $root) {
            if ($directory === $root || str_starts_with($directory . '/', $root . '/')) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            break;
        }
        $directories[$directory] = true;
        $directory = dirname($directory);
    }
}

function ensureProtectedSubdirectory(string $root, string $relative): string
{
    $relative = safeRelative($relative);
    $cursor = $root;
    foreach (explode('/', $relative) as $segment) {
        $cursor .= '/' . $segment;
        if (!file_exists($cursor) && !is_link($cursor)) {
            if (!mkdir($cursor, 0700) || !chown($cursor, 0)
                || !chgrp($cursor, 0) || !chmod($cursor, 0700)) {
                fail("unable to create protected mutable backup directory: {$cursor}");
            }
        }
        assertRootOwnedDirectory($cursor, 0700, 'mutable backup directory');
    }
    return $cursor;
}

function preserveMutableSetting(
    string $backupRoot,
    string $settingPath,
    string $settingRelative,
    int $webUid,
    int $webGid
): string {
    $settingHash = hash_file('sha256', $settingPath);
    if (!is_string($settingHash)) {
        fail('unable to hash Pika mutable setting for preservation');
    }
    $archiveRoot = ensureProtectedSubdirectory($backupRoot, 'mutable-site-files');
    $targetDirectory = ensureProtectedSubdirectory(
        $archiveRoot,
        'app/View/User/Theme/Pika'
    );
    $target = $targetDirectory . '/Setting.php';
    $manifestPath = $archiveRoot . '/manifest.json';

    $targetExists = file_exists($target) || is_link($target);
    $manifestExists = file_exists($manifestPath) || is_link($manifestPath);
    if ($targetExists !== $manifestExists) {
        fail('existing Pika mutable setting backup is partial');
    }
    if ($targetExists) {
        $targetMode = fileperms($target);
        [$manifest] = readSafeJson($manifestPath, true);
        if (!is_file($target) || is_link($target)
            || fileowner($target) !== 0 || filegroup($target) !== 0
            || $targetMode === false || ($targetMode & 0777) !== 0600
            || !hash_equals($settingHash, hash_file('sha256', $target))
            || ($manifest['schema'] ?? null) !== 1
            || ($manifest['path'] ?? null) !== $settingRelative
            || ($manifest['sha256'] ?? null) !== $settingHash
            || ($manifest['original_uid'] ?? null) !== $webUid
            || ($manifest['original_gid'] ?? null) !== $webGid
            || ($manifest['original_mode'] ?? null) !== 0640) {
            fail('existing Pika mutable setting backup is incomplete or mismatched');
        }
        return $target;
    }

    $targetStage = tempnam($targetDirectory, '.Setting.php.');
    $manifestStage = tempnam($archiveRoot, '.manifest.json.');
    if ($targetStage === false || $manifestStage === false) {
        if (is_string($targetStage)) {
            @unlink($targetStage);
        }
        if (is_string($manifestStage)) {
            @unlink($manifestStage);
        }
        fail('unable to reserve mutable setting backup stages');
    }
    $manifest = [
        'schema' => 1,
        'path' => $settingRelative,
        'sha256' => $settingHash,
        'original_uid' => $webUid,
        'original_gid' => $webGid,
        'original_mode' => 0640,
    ];
    $manifestBytes = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($manifestBytes)) {
        @unlink($targetStage);
        @unlink($manifestStage);
        fail('unable to encode mutable setting backup manifest');
    }
    $manifestBytes .= "\n";

    $ok = copy($settingPath, $targetStage)
        && chown($targetStage, 0) && chgrp($targetStage, 0) && chmod($targetStage, 0600)
        && hash_equals($settingHash, hash_file('sha256', $targetStage))
        && file_put_contents($manifestStage, $manifestBytes, LOCK_EX) === strlen($manifestBytes)
        && chown($manifestStage, 0) && chgrp($manifestStage, 0) && chmod($manifestStage, 0600)
        && rename($targetStage, $target)
        && rename($manifestStage, $manifestPath);
    if (!$ok) {
        @unlink($targetStage);
        @unlink($manifestStage);
        @unlink($target);
        @unlink($manifestPath);
        fail('unable to publish protected Pika mutable setting backup');
    }
    return $target;
}

/** @return list<string> */
function paymentLogNames(): array
{
    // Only stable output from the documented numeric, same-directory gzip rule.
    // Interrupted rotations and site-specific names require manual preservation.
    return ['runtime.log', 'runtime.log.1', 'runtime.log.2.gz', 'runtime.log.3.gz',
        'runtime.log.4.gz', 'runtime.log.5.gz', 'runtime.log.6.gz', 'runtime.log.7.gz'];
}

/** @return null|array<string, int|string> */
function paymentLogMetadata(string $path, int $webUid, int $webGid): ?array
{
    $before = freshLstat($path);
    if (!is_array($before) || !is_file($path) || is_link($path) || realpath($path) !== $path
        || (int)$before['nlink'] !== 1 || (int)$before['uid'] !== $webUid
        || (int)$before['gid'] !== $webGid || ((int)$before['mode'] & 0o7777) !== 0o640
        || !noExtendedAcl($path)) {
        return null;
    }
    $hash = hash_file('sha256', $path);
    $after = freshLstat($path);
    foreach (['dev', 'ino', 'nlink', 'uid', 'gid', 'mode', 'size', 'mtime', 'ctime'] as $key) {
        if (!is_array($after) || $before[$key] !== $after[$key]) {
            return null;
        }
    }
    if (!is_string($hash)) {
        return null;
    }
    return ['sha256' => $hash, 'size' => (int)$before['size'],
        'original_uid' => (int)$before['uid'], 'original_gid' => (int)$before['gid'],
        'original_mode' => (int)$before['mode'] & 0o7777, 'original_mtime' => (int)$before['mtime'],
        'original_dev' => (int)$before['dev'], 'original_ino' => (int)$before['ino'],
        'original_nlink' => (int)$before['nlink'], 'original_ctime' => (int)$before['ctime']];
}

function samePaymentLog(?array $actual, array $expected): bool
{
    // Rename-based rollback retains identity and mtime, but necessarily changes ctime.
    if ($actual === null) {
        return false;
    }
    unset($actual['original_ctime'], $expected['original_ctime']);
    return $actual === $expected;
}

/**
 * Bind the exact adapter tree, including receipt files and the finite log set.
 * Unknown entries are not cleanup candidates, even when they look like logs.
 * @return array<string, array<string, int>>
 */
function paymentDirectorySnapshot(string $siteRoot, array $installedFiles, array $logPaths): array
{
    $root = 'app/Pay/PikaBEpusdtAdapter';
    $children = [$root => []];
    foreach (array_merge(array_keys($installedFiles), array_keys($logPaths)) as $relative) {
        if (!str_starts_with($relative, $root . '/')) {
            continue;
        }
        $cursor = $relative;
        do {
            $parent = dirname($cursor);
            $children[$parent][basename($cursor)] = true;
            $cursor = $parent;
        } while ($cursor !== $root);
    }
    $identities = [];
    foreach ($children as $relative => $expected) {
        $path = $siteRoot . '/' . $relative;
        $identity = boundDirectory($path, 'payment adapter directory');
        if ($identity['uid'] !== 0 || $identity['gid'] !== 0 || $identity['mode'] !== 0o755) {
            fail('payment adapter directory ownership or permissions are unsafe');
        }
        $entries = scandir($path);
        $actual = is_array($entries) ? array_values(array_diff($entries, ['.', '..'])) : null;
        $names = array_keys($expected);
        sort($names, SORT_STRING);
        if ($actual !== $names) {
            fail('payment adapter contains unknown or unsupported entries; preserve them before restore');
        }
        $identities[$relative] = $identity;
    }
    foreach ($installedFiles as $relative => $hash) {
        if (str_starts_with($relative, $root . '/')
            && !isCanonicalSingleLinkAclFreeFile($siteRoot . '/' . $relative)) {
            fail('payment adapter receipt file ownership, type, links or ACL are unsafe');
        }
    }
    return $identities;
}

function paymentStagedTreeMatches(string $siteRoot, array $directories, array $stagedPayload): bool
{
    foreach ($directories as $relative => $identity) {
        $path = $siteRoot . '/' . $relative;
        $stat = freshLstat($path);
        if (!is_array($stat) || realpath($path) !== $path || is_link($path) || !is_dir($path)
            || !noExtendedAcl($path)) {
            return false;
        }
        foreach (['dev', 'ino', 'uid', 'gid'] as $key) {
            if ((int)$stat[$key] !== $identity[$key]) {
                return false;
            }
        }
        if (((int)$stat['mode'] & 0o7777) !== $identity['mode']) {
            return false;
        }
        $names = [];
        foreach ($directories as $child => $_) {
            if (dirname($child) === $relative) {
                $names[] = basename($child);
            }
        }
        foreach ($stagedPayload as $stage) {
            if (dirname($stage) === $path) {
                $names[] = basename($stage);
            }
        }
        sort($names, SORT_STRING);
        $entries = scandir($path);
        if (!is_array($entries) || array_values(array_diff($entries, ['.', '..'])) !== $names) {
            return false;
        }
    }
    return true;
}

function assertPaymentLogBackup(string $archiveRoot, array $logs): void
{
    assertProtectedRootChain($archiveRoot, 'payment log backup');
    $directory = boundDirectory($archiveRoot, 'payment log backup');
    if ($directory['uid'] !== 0 || $directory['gid'] !== 0 || $directory['mode'] !== 0o700) {
        fail('payment log backup directory ownership or permissions are unsafe');
    }
    $expectedNames = ['manifest.json'];
    foreach ($logs as $relative => $metadata) {
        $expectedNames[] = basename($relative);
    }
    sort($expectedNames, SORT_STRING);
    $entries = scandir($archiveRoot);
    if (!is_array($entries) || array_values(array_diff($entries, ['.', '..'])) !== $expectedNames) {
        fail('payment log backup is partial or contains unknown entries');
    }
    foreach ($expectedNames as $name) {
        $path = $archiveRoot . '/' . $name;
        $stat = freshLstat($path);
        if (!is_array($stat) || !is_file($path) || is_link($path) || realpath($path) !== $path
            || (int)$stat['uid'] !== 0 || (int)$stat['gid'] !== 0 || (int)$stat['nlink'] !== 1
            || ((int)$stat['mode'] & 0o7777) !== 0o600 || !noExtendedAcl($path)) {
            fail('payment log backup ownership, type, links or ACL are unsafe');
        }
    }
    [$manifest] = readSafeJson($archiveRoot . '/manifest.json', true);
    if (array_keys($manifest) !== ['schema', 'files'] || ($manifest['schema'] ?? null) !== 1
        || !is_array($manifest['files'] ?? null) || array_keys($manifest['files']) !== array_keys($logs)) {
        fail('payment log backup manifest does not match the exact log set');
    }
    foreach ($logs as $relative => $metadata) {
        $saved = $manifest['files'][$relative];
        $path = $archiveRoot . '/' . basename($relative);
        if (!is_array($saved) || !is_int($saved['original_ctime'] ?? null)
            || !samePaymentLog($saved, $metadata)
            || filesize($path) !== $metadata['size']
            || !hash_equals($metadata['sha256'], hash_file('sha256', $path))) {
            fail('payment log backup content or original metadata is mismatched');
        }
    }
}

function preservePaymentLogs(string $backupRoot, array $logPaths, array $logs): string
{
    $target = $backupRoot . '/payment-runtime-logs';
    if (file_exists($target) || is_link($target)) {
        assertPaymentLogBackup($target, $logs);
        return $target;
    }
    $stage = ensureProtectedSubdirectory($backupRoot, '.payment-runtime-logs-' . bin2hex(random_bytes(8)));
    foreach ($logPaths as $relative => $source) {
        $copy = $stage . '/' . basename($relative);
        if (!copy($source, $copy) || !chown($copy, 0) || !chgrp($copy, 0) || !chmod($copy, 0o600)
            || !touch($copy, $logs[$relative]['original_mtime'])) {
            fail("unable to preserve payment logs; recovery stage retained at {$stage}");
        }
    }
    $bytes = json_encode(['schema' => 1, 'files' => $logs], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($bytes)
        || file_put_contents($stage . '/manifest.json', $bytes . "\n", LOCK_EX) !== strlen($bytes) + 1
        || !chmod($stage . '/manifest.json', 0o600)) {
        fail("unable to preserve payment log manifest; recovery stage retained at {$stage}");
    }
    assertPaymentLogBackup($stage, $logs);
    if (file_exists($target) || is_link($target) || !rename($stage, $target)) {
        fail("unable to publish payment log backup; recovery stage retained at {$stage}");
    }
    assertPaymentLogBackup($target, $logs);
    return $target;
}

function paymentRotationGuardMatches(array $guard): bool
{
    clearstatcache();
    $parent = lstat('/etc/logrotate.d');
    if (file_exists($guard['rule']) || is_link($guard['rule'])
        || !is_array($parent) || is_link('/etc/logrotate.d') || !noExtendedAcl('/etc/logrotate.d')) {
        return false;
    }
    foreach (['dev', 'ino', 'uid', 'gid', 'mode'] as $key) {
        if ($parent[$key] !== $guard['parent'][$key]) {
            return false;
        }
    }
    foreach ($guard['parents'] as $path => $expected) {
        $current = lstat($path);
        if (!is_array($current) || realpath($path) !== $path || !noExtendedAcl($path)) {
            return false;
        }
        foreach (['dev', 'ino', 'uid', 'gid', 'mode'] as $key) {
            if ($current[$key] !== $expected[$key]) {
                return false;
            }
        }
    }
    if ($guard['backup'] === null) {
        return true;
    }
    $stat = lstat($guard['backup']);
    if (!is_array($stat) || realpath($guard['backup']) !== $guard['backup']
        || is_link($guard['backup']) || !noExtendedAcl($guard['backup'])) {
        return false;
    }
    foreach (['dev', 'ino', 'uid', 'gid', 'mode'] as $key) {
        if ($stat[$key] !== $guard['stat'][$key]) {
            return false;
        }
    }
    return (int)$stat['nlink'] === 1 && $stat['size'] === $guard['stat']['size']
        && $stat['mtime'] === $guard['stat']['mtime']
        && hash_equals($guard['sha256'], hash_file('sha256', $guard['backup']));
}

function requirePaymentRotationIsolation(string $siteRoot): array
{
    $rule = getenv('PIKA_PAYMENT_LOGROTATE_RULE');
    $backup = getenv('PIKA_PAYMENT_LOGROTATE_RULE_BACKUP');
    if (!is_string($rule) || preg_match('~\A/etc/logrotate\.d/[A-Za-z0-9_-][A-Za-z0-9._-]*\z~D', $rule) !== 1
        || !is_string($backup) || $backup === '') {
        fail('PIKA_PAYMENT_LOGROTATE_RULE and PIKA_PAYMENT_LOGROTATE_RULE_BACKUP must identify the isolated site rule');
    }
    assertProtectedRootChain('/etc/logrotate.d', 'payment logrotate include directory');
    $guard = ['rule' => $rule, 'backup' => null, 'parent' => lstat('/etc/logrotate.d')];
    // Explicit operator declaration for an installation whose full effective
    // configuration has never included this site's log. Never infer this from
    // absent archives alone; the payload preflight also requires no rotations.
    if ($backup !== 'not-configured') {
        if (realpath($backup) !== $backup || isWithin($backup, '/etc/logrotate.d')
            || isWithin($backup, $siteRoot) || isWithin($backup, '/var/lib/pika-local-extensions/sites')) {
            fail('isolated payment logrotate rule must be outside the site and include directory');
        }
        assertProtectedRootChain(dirname($backup), 'isolated payment logrotate rule');
        $stat = freshLstat($backup);
        if (!is_array($stat) || !is_file($backup) || is_link($backup)
            || (int)$stat['uid'] !== 0 || (int)$stat['gid'] !== 0 || (int)$stat['nlink'] !== 1
            || !in_array((int)$stat['mode'] & 0o7777, [0o600, 0o640, 0o644], true)
            || $stat['size'] > 16384 || !noExtendedAcl($backup)) {
            fail('isolated payment logrotate rule ownership, type, links or ACL are unsafe');
        }
        $bytes = file_get_contents($backup);
        $withoutComments = is_string($bytes) ? preg_replace('/^\s*#.*$/m', '', $bytes) : null;
        if (!is_string($withoutComments)
            || preg_match('~\A\s*' . preg_quote($siteRoot . '/app/Pay/PikaBEpusdtAdapter/runtime.log', '~')
                . '\s*\{[^{}]*\}\s*\z~sD', $withoutComments) !== 1) {
            fail('isolated logrotate rule must contain only the exact site payment log stanza');
        }
        $guard = ['rule' => $rule, 'backup' => $backup, 'stat' => $stat,
            'parent' => lstat('/etc/logrotate.d'), 'sha256' => hash('sha256', $bytes)];
    }
    $guard['parents'] = [];
    foreach (['/etc/logrotate.d', $guard['backup'] === null ? '/etc/logrotate.d' : dirname($backup)] as $path) {
        while ($path !== '/') {
            $guard['parents'][$path] = lstat($path);
            $path = dirname($path);
        }
    }
    if (!paymentRotationGuardMatches($guard)) {
        fail('payment logrotate rule must remain isolated outside its include directory');
    }
    // Quarantine prevents future normal jobs from selecting this site. Drain
    // jobs that may already have loaded the old rule, without locking the
    // machine-wide state or making other sites skip a scheduled rotation.
    $processes = scandir('/proc');
    if (!is_array($processes)) {
        fail('unable to inspect logrotate processes after isolating the site rule');
    }
    foreach ($processes as $pid) {
        if (ctype_digit($pid)) {
            $comm = @file_get_contents('/proc/' . $pid . '/comm', false, null, 0, 64);
            if (is_string($comm) && trim($comm) === 'logrotate') {
                fail('logrotate processes must finish after isolating the site rule and before restore');
            }
            if (!is_string($comm) && is_dir('/proc/' . $pid)) {
                fail('unable to inspect a live process while draining payment log rotations');
            }
        }
    }
    if (!paymentRotationGuardMatches($guard)) {
        fail('payment logrotate isolation drifted while draining prior rotations');
    }
    return $guard;
}

$options = getopt('', ['site-root:', 'receipt:']);
$siteArg = $options['site-root'] ?? null;
$receiptArg = $options['receipt'] ?? null;
if (!is_string($siteArg) || !is_string($receiptArg)) {
    fail('usage: restore-install.php --site-root /absolute/path --receipt /absolute/backup/install-receipt.json');
}
if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    fail('restore helper must run as root');
}
$maintenanceLock = fopen('/run/pika-local-extensions-maintenance.lock', 'c');
if (!is_resource($maintenanceLock) || !flock($maintenanceLock, LOCK_EX | LOCK_NB)) {
    fail('another local-extension maintenance operation is already running');
}
if (!chmod('/run/pika-local-extensions-maintenance.lock', 0o600)) {
    fail('unable to protect the maintenance lock');
}

$siteRoot = canonicalDirectory($siteArg, 'site root');
if ($receiptArg === '' || $receiptArg[0] !== '/' || is_link($receiptArg)) {
    fail('receipt must be an exact absolute regular-file path');
}
$receiptPath = realpath($receiptArg);
if ($receiptPath === false || $receiptPath !== $receiptArg || !is_file($receiptPath)) {
    fail('receipt must be an exact absolute regular-file path');
}
$backupRoot = canonicalDirectory(dirname($receiptPath), 'backup root');
if ($receiptPath !== $backupRoot . '/install-receipt.json') {
    fail('receipt must name the exact protected backup receipt');
}
if (isWithin($backupRoot, $siteRoot) || isWithin($siteRoot, $backupRoot)) {
    fail('site root and backup root must not overlap');
}
assertProtectedRootChain($backupRoot, 'backup root');
$backupMode = fileperms($backupRoot);
if ($backupMode === false || ($backupMode & 0777) !== 0700
    || fileowner($backupRoot) !== 0 || filegroup($backupRoot) !== 0) {
    fail('backup root ownership or permissions are unsafe');
}
$paymentRotationGuard = requirePaymentRotationIsolation($siteRoot);

[$receipt, $receiptBytes] = readSafeJson($receiptPath, true);
$stateBase = assertRootOwnedDirectory('/var/lib/pika-local-extensions', 0755, 'external state root');
assertProtectedRootChain($stateBase, 'external state root');
$stateSites = assertRootOwnedDirectory($stateBase . '/sites', 0755, 'external state sites root');
$stateSiteRoot = assertRootOwnedDirectory(
    $stateSites . '/' . hash('sha256', $siteRoot),
    0755,
    'site state root'
);
if (isWithin($backupRoot, $stateSiteRoot) || isWithin($stateSiteRoot, $backupRoot)) {
    fail('backup root and external site state must not overlap');
}
$stateRuntime = $stateSiteRoot . '/runtime';
$runtimePermissions = fileperms($stateRuntime);
if (realpath($stateRuntime) !== $stateRuntime || !is_dir($stateRuntime) || is_link($stateRuntime)
    || $runtimePermissions === false || ($runtimePermissions & 0007) !== 0) {
    fail('external site runtime is missing or unsafe');
}
$siteReceiptPath = assertRootOwnedFile(
    $stateSiteRoot . '/install-receipt.json',
    [0400, 0440],
    'protected site receipt'
);
[$siteReceipt, $siteReceiptBytes] = readSafeJson($siteReceiptPath, true);
$siteReceiptHash = hash('sha256', $siteReceiptBytes);
if (!hash_equals(hash('sha256', $receiptBytes), hash('sha256', $siteReceiptBytes))
    || $receipt !== $siteReceipt) {
    fail('explicit receipt does not match the verified site receipt');
}
if (($receipt['schema'] ?? null) !== 1 || ($receipt['backup_dir'] ?? null) !== $backupRoot) {
    fail('install receipt schema or exact backup path is invalid');
}
$webUid = $receipt['web_uid'] ?? null;
$webGid = $receipt['web_gid'] ?? null;
if (!is_int($webUid) || $webUid < 1 || $webUid > 2147483647
    || !is_int($webGid) || $webGid < 1 || $webGid > 2147483647) {
    fail('install receipt web identity is invalid');
}
if (fileowner($stateRuntime) !== $webUid
    || filegroup($stateRuntime) !== $webGid
    || ($runtimePermissions & 0777) !== 0750) {
    fail('external runtime does not match the receipt web identity');
}

// From this point until the final release, every privileged site mutation is
// performed behind seven exact device/inode-bound root:root 0700 barriers.
// This also makes direct invocation of the PHP helper enforce the real
// maintenance condition rather than trusting restore.sh's flag alone.
$officialBarrierState = takeOfficialRuntimeBarriers(
    $siteRoot,
    $webUid,
    $webGid
);
foreach ([
    'config/store.php',
    'config/mcp.php',
    'config/terms',
    'kernel/Install/Lock',
] as $relative) {
    $path = assertSafeRegularFile($siteRoot, $relative, 'official exact mutable file');
    assertDedicatedWebMutableFile(
        $path,
        $webUid,
        $webGid,
        [0o600, 0o640],
        "official exact mutable file does not match the installed contract: {$relative}"
    );
}

$expectedBridge = [
    'kernel/Kernel.php',
    'kernel/Helper.php',
    'app/Controller/Admin/Api/Config.php',
    'app/View/Admin/Footer.html',
    'assets/common/js/editor/markdown/editorv2.js',
];
$bridge = $receipt['bridge_files'] ?? null;
if (!is_array($bridge)) {
    fail('bridge receipt is invalid');
}
$actualBridge = array_keys($bridge);
sort($actualBridge, SORT_STRING);
$sortedExpectedBridge = $expectedBridge;
sort($sortedExpectedBridge, SORT_STRING);
if ($actualBridge !== $sortedExpectedBridge) {
    fail('bridge receipt does not contain the exact approved file set');
}

$backupSiteRoot = assertSafeDirectory($backupRoot, 'site', 'backup site directory');
$bridgeFiles = [];
$bridgeCurrentPaths = [];
$bridgeBackupPaths = [];
foreach ($expectedBridge as $relative) {
    $hashes = $bridge[$relative] ?? null;
    if (!is_array($hashes)) {
        fail("bridge hash receipt is invalid: {$relative}");
    }
    $beforeHash = $hashes['before_sha256'] ?? null;
    $afterHash = $hashes['after_sha256'] ?? null;
    if (!is_string($beforeHash) || !is_string($afterHash)
        || preg_match('/^[a-f0-9]{64}$/D', $beforeHash) !== 1
        || preg_match('/^[a-f0-9]{64}$/D', $afterHash) !== 1) {
        fail("bridge hash receipt is invalid: {$relative}");
    }
    $current = assertSafeRegularFile($siteRoot, $relative, 'current bridge file', true);
    $before = assertSafeRegularFile($backupSiteRoot, $relative, 'backup bridge file', true);
    if (!hash_equals($afterHash, hash_file('sha256', $current))
        || !hash_equals($beforeHash, hash_file('sha256', $before))) {
        fail("bridge file verification failed: {$relative}");
    }
    $bridgeFiles[$relative] = [
        'before_sha256' => $beforeHash,
        'after_sha256' => $afterHash,
    ];
    $bridgeCurrentPaths[$relative] = $current;
    $bridgeBackupPaths[$relative] = $before;
}

$settingRelative = 'app/View/User/Theme/Pika/Setting.php';
$registryRelative = 'local-extensions/registry.json';
$paymentRuntimeRelative = 'app/Pay/PikaBEpusdtAdapter/runtime.log';
$installed = $receipt['installed_files'] ?? null;
if (!is_array($installed) || $installed === [] || count($installed) > 10000) {
    fail('installed file receipt is invalid');
}
$installedFiles = [];
foreach ($installed as $entry) {
    if (!is_array($entry)) {
        fail('installed file receipt entry is invalid');
    }
    $relative = safeRelative($entry['path'] ?? null);
    $expectedHash = $entry['sha256'] ?? null;
    if (!isAllowedInstalledPath($relative)
        || in_array($relative, $expectedBridge, true)
        || in_array($relative, [$settingRelative, $registryRelative, $paymentRuntimeRelative], true)
        || (dirname($relative) === 'app/Pay/PikaBEpusdtAdapter'
            && in_array(basename($relative), paymentLogNames(), true))
        || isset($installedFiles[$relative])
        || !is_string($expectedHash)
        || preg_match('/^[a-f0-9]{64}$/D', $expectedHash) !== 1) {
        fail("installed file receipt entry is unsafe: {$relative}");
    }
    $current = assertSafeRegularFile($siteRoot, $relative, 'installed file');
    if (!hash_equals($expectedHash, hash_file('sha256', $current))) {
        fail("installed file verification failed: {$relative}");
    }
    $installedFiles[$relative] = $expectedHash;
}

$settingPath = assertSafeRegularFile($siteRoot, $settingRelative, 'Pika mutable setting');
$registryPath = assertSafeRegularFile($siteRoot, $registryRelative, 'generated registry');
$paymentRuntimePath = assertSafeRegularFile(
    $siteRoot,
    $paymentRuntimeRelative,
    'payment adapter runtime log'
);
assertDedicatedWebMutableFile(
    $settingPath,
    $webUid,
    $webGid,
    [0o640],
    'Pika mutable setting does not match the receipt web identity'
);
assertDedicatedWebMutableFile(
    $paymentRuntimePath,
    $webUid,
    $webGid,
    [0o640],
    'payment adapter runtime log does not match the receipt web identity'
);
$paymentLogPaths = [];
$paymentLogs = [];
foreach (paymentLogNames() as $name) {
    $relative = 'app/Pay/PikaBEpusdtAdapter/' . $name;
    $path = $siteRoot . '/' . $relative;
    if (!file_exists($path) && !is_link($path)) {
        continue;
    }
    $metadata = paymentLogMetadata($path, $webUid, $webGid);
    if ($metadata === null) {
        fail('payment log ownership, type, links, ACL or stable identity is unsafe');
    }
    $paymentLogPaths[$relative] = $path;
    $paymentLogs[$relative] = $metadata;
}
$paymentDirectories = paymentDirectorySnapshot($siteRoot, $installedFiles, $paymentLogPaths);
if ($paymentRotationGuard['backup'] === null && array_keys($paymentLogPaths) !== [$paymentRuntimeRelative]) {
    fail('payment rotation was declared not-configured but rotated logs exist; preserve and isolate the actual rule');
}

$secretsPath = $stateSiteRoot . '/secrets';
$secretsPermissions = fileperms($secretsPath);
if (realpath($secretsPath) !== $secretsPath
    || !is_dir($secretsPath) || is_link($secretsPath)
    || fileowner($secretsPath) !== 0
    || filegroup($secretsPath) !== $webGid
    || $secretsPermissions === false
    || ($secretsPermissions & 0777) !== 0750) {
    fail('payment secret directory does not match the receipt web identity');
}

// Preserve the latest user-edited Pika setting in the protected install backup
// before any live payload is staged for removal. A failed restore may leave
// this additional recovery copy in place, but it never changes live behavior.
$mutableSettingBackup = preserveMutableSetting(
    $backupRoot,
    $settingPath,
    $settingRelative,
    $webUid,
    $webGid
);
$paymentLogBackup = preservePaymentLogs($backupRoot, $paymentLogPaths, $paymentLogs);
foreach ($paymentLogPaths as $relative => $path) {
    if (!samePaymentLog(paymentLogMetadata($path, $webUid, $webGid), $paymentLogs[$relative])) {
        fail('payment log changed during preservation; live files and backups retained');
    }
}
if (!paymentRotationGuardMatches($paymentRotationGuard)
    || paymentDirectorySnapshot($siteRoot, $installedFiles, $paymentLogPaths) !== $paymentDirectories) {
    fail('payment log isolation or directory identity changed before restore mutation');
}

// Mutation starts only after every receipt, path and content precondition above passes.
$cleanupDirectories = [];
$payloadHashes = [];
$payloadPaths = [];
foreach ($installedFiles as $relative => $expectedHash) {
    $current = assertSafeRegularFile($siteRoot, $relative, 'installed file');
    $payloadHashes[$relative] = $expectedHash;
    $payloadPaths[$relative] = $current;
    addCleanupDirectories($cleanupDirectories, $relative);
}

foreach (array_merge([
    $settingRelative => $settingPath,
    $registryRelative => $registryPath,
], $paymentLogPaths) as $relative => $absolute) {
    if (!is_file($absolute) || is_link($absolute)) {
        fail("known installer-created file became unsafe: {$relative}");
    }
    $hash = hash_file('sha256', $absolute);
    if (!is_string($hash)) {
        fail("unable to hash known installer-created file: {$relative}");
    }
    $payloadHashes[$relative] = $hash;
    $payloadPaths[$relative] = $absolute;
    addCleanupDirectories($cleanupDirectories, $relative);
}

$stagedBridgeOld = [];
$stagedBridgeNew = [];
$stagedPayload = [];
$swappedBridge = [];
$mutationStarted = false;
$transactionCommitted = false;
$archivesRoot = $stateBase . '/archives';
$archivesRootCreated = false;
$stateArchivePath = null;
$stateArchived = false;

if (!officialRuntimeBarriersMatch($officialBarrierState, true)) {
    fail('official runtime barriers drifted before restore mutation');
}

try {
    $mutationStarted = true;
    if (file_exists($archivesRoot) || is_link($archivesRoot)) {
        assertRootOwnedDirectory($archivesRoot, 0700, 'external state archive root');
    } else {
        if (!mkdir($archivesRoot, 0700)
            || !chown($archivesRoot, 0)
            || !chgrp($archivesRoot, 0)
            || !chmod($archivesRoot, 0700)) {
            @rmdir($archivesRoot);
            mutationFailure('unable to create protected external state archive root');
        }
        $archivesRootCreated = true;
        assertRootOwnedDirectory($archivesRoot, 0700, 'external state archive root');
    }
    $siteDevice = stat($stateSiteRoot)['dev'] ?? null;
    $archiveDevice = stat($archivesRoot)['dev'] ?? null;
    if (!is_int($siteDevice) || !is_int($archiveDevice) || $siteDevice !== $archiveDevice) {
        mutationFailure('external state archive root must share the site state filesystem');
    }
    $stateArchivePath = $archivesRoot . '/'
        . hash('sha256', $siteRoot) . '-'
        . gmdate('Ymd\\THis\\Z') . '-'
        . bin2hex(random_bytes(8));
    if (file_exists($stateArchivePath) || is_link($stateArchivePath)) {
        mutationFailure('external state archive destination already exists');
    }

    foreach ($expectedBridge as $relative) {
        $current = $bridgeCurrentPaths[$relative];
        $before = $bridgeBackupPaths[$relative];
        if (!isCanonicalSingleLinkAclFreeFile($current)
            || !hash_equals($bridgeFiles[$relative]['after_sha256'], hash_file('sha256', $current))
            || !isCanonicalSingleLinkAclFreeFile($before)
            || !hash_equals($bridgeFiles[$relative]['before_sha256'], hash_file('sha256', $before))) {
            mutationFailure("bridge changed before same-filesystem staging: {$relative}");
        }
        $stagedBridgeOld[$relative] = stageSiblingCopy(
            $current,
            $current,
            '.pika-restore-old.',
            $bridgeFiles[$relative]['after_sha256']
        );
        $stagedBridgeNew[$relative] = stageSiblingCopy(
            $before,
            $current,
            '.pika-restore-new.',
            $bridgeFiles[$relative]['before_sha256']
        );
    }

    // Phase one: move every removable payload file to a same-directory name.
    // Until cleanup, every rename is reversible without crossing filesystems.
    foreach ($payloadHashes as $relative => $expectedHash) {
        $current = $payloadPaths[$relative];
        if (!is_file($current) || is_link($current)
            || !hash_equals($expectedHash, hash_file('sha256', $current))
            || (isset($paymentLogs[$relative])
                && !samePaymentLog(paymentLogMetadata($current, $webUid, $webGid), $paymentLogs[$relative]))) {
            mutationFailure("payload changed before reversible staging: {$relative}");
        }
        $temporary = tempnam(dirname($current), '.pika-restore-payload.');
        if ($temporary === false || !rename($current, $temporary)) {
            if (is_string($temporary)) {
                @unlink($temporary);
            }
            mutationFailure("unable to reversibly stage payload: {$relative}");
        }
        $stagedPayload[$relative] = $temporary;
        if (!hash_equals($expectedHash, hash_file('sha256', $temporary))
            || (isset($paymentLogs[$relative])
                && !samePaymentLog(paymentLogMetadata($temporary, $webUid, $webGid), $paymentLogs[$relative]))) {
            mutationFailure("staged payload hash mismatch: {$relative}");
        }
    }
    injectRestoreFailure('after-payload-stage');
    if (!paymentRotationGuardMatches($paymentRotationGuard)
        || !paymentStagedTreeMatches($siteRoot, $paymentDirectories, $stagedPayload)) {
        mutationFailure('payment log isolation or staged directory changed during restore');
    }

    // Phase two: each bridge replacement is one same-directory atomic rename.
    // Verified copies of all five installed bridge files remain available for
    // rollback until the complete post-state has passed validation.
    if (!officialRuntimeBarriersMatch($officialBarrierState, true)) {
        mutationFailure('official runtime barriers drifted before bridge replacement');
    }
    foreach ($expectedBridge as $index => $relative) {
        $current = $bridgeCurrentPaths[$relative];
        if (!isCanonicalSingleLinkAclFreeFile($current)
            || !hash_equals($bridgeFiles[$relative]['after_sha256'], hash_file('sha256', $current))
            || !isCanonicalSingleLinkAclFreeFile($stagedBridgeNew[$relative])
            || !rename($stagedBridgeNew[$relative], $current)) {
            mutationFailure("bridge restore failed: {$relative}");
        }
        $swappedBridge[] = $relative;
        if (!isCanonicalSingleLinkAclFreeFile($current)
            || !hash_equals($bridgeFiles[$relative]['before_sha256'], hash_file('sha256', $current))) {
            mutationFailure("restored bridge hash mismatch: {$relative}");
        }
        if ($index === 0) {
            injectRestoreFailure('after-first-bridge-swap');
        }
    }

    foreach ($bridgeFiles as $relative => $hashes) {
        $current = $bridgeCurrentPaths[$relative];
        if (!isCanonicalSingleLinkAclFreeFile($current)
            || !hash_equals($hashes['before_sha256'], hash_file('sha256', $current))) {
            mutationFailure("restored bridge hash mismatch: {$relative}");
        }
    }
    foreach (array_keys($payloadHashes) as $relative) {
        $livePath = $payloadPaths[$relative];
        if (file_exists($livePath) || is_link($livePath)) {
            mutationFailure("payload remains at its live path after restore: {$relative}");
        }
    }
    if (!rename($stateSiteRoot, $stateArchivePath)) {
        mutationFailure('unable to atomically archive external site state');
    }
    $stateArchived = true;
    if (file_exists($stateSiteRoot) || is_link($stateSiteRoot)
        || realpath($stateArchivePath) !== $stateArchivePath
        || !is_dir($stateArchivePath) || is_link($stateArchivePath)
        || !is_dir($stateArchivePath . '/runtime') || is_link($stateArchivePath . '/runtime')
        || !is_dir($stateArchivePath . '/secrets') || is_link($stateArchivePath . '/secrets')
        || !is_file($stateArchivePath . '/install-receipt.json')
        || is_link($stateArchivePath . '/install-receipt.json')
        || !hash_equals(hash('sha256', $siteReceiptBytes), hash_file('sha256', $stateArchivePath . '/install-receipt.json'))) {
        mutationFailure('archived external site state failed post-rename verification');
    }
    injectRestoreFailure('after-state-archive');
    if (!paymentRotationGuardMatches($paymentRotationGuard)
        || !paymentStagedTreeMatches($siteRoot, $paymentDirectories, $stagedPayload)) {
        mutationFailure('payment log isolation or staged directory changed before restore commit');
    }
    $transactionCommitted = true;
} catch (Throwable $error) {
    if (!$mutationStarted) {
        fail($error->getMessage());
    }
    if (!paymentRotationGuardMatches($paymentRotationGuard)) {
        // Do not expose live log names to a root rotator after isolation was
        // lost. Keep original inodes staged and barriers closed for recovery.
        fwrite(STDERR, "RESTORE_ROLLBACK_FAIL payment_rule_isolation_lost recovery_files_retained\n");
        exit(70);
    }
    $rollbackPassed = rollbackRestore(
        $siteRoot,
        $bridgeFiles,
        $stagedBridgeOld,
        $stagedBridgeNew,
        $swappedBridge,
        $stagedPayload,
        $payloadHashes,
        $payloadPaths,
        $stateSiteRoot,
        $stateArchivePath,
        $stateArchived,
        $siteReceiptHash,
        $webUid,
        $webGid,
        $paymentLogs
    );
    if ($archivesRootCreated && is_dir($archivesRoot) && !is_link($archivesRoot)) {
        $archiveEntries = scandir($archivesRoot);
        if ($archiveEntries === false || count($archiveEntries) !== 2 || !rmdir($archivesRoot)) {
            $rollbackPassed = false;
        }
    }
    if (!releaseOfficialRuntimeBarriers($officialBarrierState)) {
        $rollbackPassed = false;
    }
    if ($rollbackPassed) {
        fwrite(STDERR, "RESTORE_ROLLBACK_PASS cause={$error->getMessage()}\n");
        exit(1);
    }
    fwrite(STDERR, "RESTORE_ROLLBACK_FAIL cause={$error->getMessage()}\n");
    exit(70);
}

if (!$transactionCommitted) {
    fail('restore transaction did not reach a committed state');
}

// Cleanup occurs only after the runtime post-state is complete. A cleanup
// failure leaves the restored bridge and absent live payload intact, and is
// reported for bounded manual removal instead of recreating a partial install.
if (!officialRuntimeBarriersMatch($officialBarrierState, true)) {
    fail('official runtime barriers drifted before restore cleanup');
}
if (!paymentRotationGuardMatches($paymentRotationGuard)) {
    fwrite(STDERR, "RESTORE_ROLLBACK_FAIL payment_rule_isolation_lost recovery_files_retained\n");
    exit(70);
}
if (!paymentStagedTreeMatches($siteRoot, $paymentDirectories, $stagedPayload)) {
    fail('payment staged directory changed before cleanup; recovery files retained');
}
assertPaymentLogBackup($paymentLogBackup, $paymentLogs);
foreach ($paymentLogs as $relative => $metadata) {
    if (!samePaymentLog(paymentLogMetadata($stagedPayload[$relative], $webUid, $webGid), $metadata)) {
        fail('staged payment log changed before cleanup; recovery files retained');
    }
}
$cleanupFailures = [];
foreach ([$stagedPayload, $stagedBridgeOld, $stagedBridgeNew] as $stagedSet) {
    foreach ($stagedSet as $temporary) {
        if ((file_exists($temporary) || is_link($temporary)) && !unlink($temporary)) {
            $cleanupFailures[] = $temporary;
        }
    }
}

$directories = array_keys($cleanupDirectories);
usort($directories, static function (string $left, string $right): int {
    $depth = substr_count($right, '/') <=> substr_count($left, '/');
    return $depth !== 0 ? $depth : strcmp($right, $left);
});
foreach ($directories as $relative) {
    $absolute = $siteRoot . '/' . $relative;
    if (!file_exists($absolute) && !is_link($absolute)) {
        continue;
    }
    if (is_link($absolute) || !is_dir($absolute)) {
        fail("cleanup directory became unsafe: {$relative}");
    }
    $entries = scandir($absolute);
    if ($entries === false) {
        $cleanupFailures[] = $absolute;
        continue;
    }
    if (count($entries) === 2 && !rmdir($absolute)) {
        $cleanupFailures[] = $absolute;
    }
}

// No privileged write below an official mutable root is permitted after this
// point.  The remaining checks are read-only post-state validation.
if (!releaseOfficialRuntimeBarriers($officialBarrierState)) {
    fwrite(STDERR, "RESTORE_BARRIER_RELEASE_FAIL\n");
    exit(70);
}

foreach ($bridgeFiles as $relative => $hashes) {
    $current = assertSafeRegularFile($siteRoot, $relative, 'restored bridge file', true);
    if (!hash_equals($hashes['before_sha256'], hash_file('sha256', $current))) {
        fail("restored bridge hash mismatch: {$relative}");
    }
}
foreach (array_keys($installedFiles) as $relative) {
    if (file_exists($siteRoot . '/' . $relative) || is_link($siteRoot . '/' . $relative)) {
        fail("installed file remains after restore: {$relative}");
    }
}
if (file_exists($siteRoot . '/app/Pay/PikaBEpusdtAdapter')
    || is_link($siteRoot . '/app/Pay/PikaBEpusdtAdapter')) {
    fail('payment adapter directory remains after restore; reinstall is blocked');
}
if (file_exists($stateSiteRoot) || is_link($stateSiteRoot)) {
    fail('live external site state remains after restore');
}
if ($stateArchivePath === null
    || !is_dir($stateArchivePath) || is_link($stateArchivePath)
    || !is_dir($stateArchivePath . '/runtime') || is_link($stateArchivePath . '/runtime')
    || !is_dir($stateArchivePath . '/secrets') || is_link($stateArchivePath . '/secrets')
    || !is_file($stateArchivePath . '/install-receipt.json')
    || is_link($stateArchivePath . '/install-receipt.json')) {
    fail('archived external site state became unavailable after restore');
}

if ($cleanupFailures !== []) {
    fwrite(
        STDERR,
        'RESTORE_CLEANUP_FAIL runtime_state=archived remnants=' . implode(',', $cleanupFailures) . "\n"
    );
    exit(2);
}

fwrite(
    STDOUT,
    'RESTORE_PASS files=' . count($installedFiles)
        . " backup={$backupRoot} state_runtime=archived"
        . " payment_logs={$paymentLogBackup}"
        . " mutable_setting={$mutableSettingBackup} archive={$stateArchivePath}\n"
);
