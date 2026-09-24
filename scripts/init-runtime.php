<?php
declare(strict_types=1);

const PIKA_STATE_BASE = '/var/lib/pika-local-extensions/sites';

/** @var list<array{path: string, dev: int, ino: int, nlink: int, type: string}> */
$createdStateNodes = [];
/** @var list<array{path: string, dev: int, ino: int, nlink: int, type: string}> */
$createdControlDirectories = [];
$controlDirectoryCreationUnbound = false;
$runtimePublished = false;
$cleanupRunning = false;

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
        $bytes = @file_get_contents('/proc/' . $entry . '/status', false, null, 0, 16384);
        if (!is_string($bytes)
            || preg_match('/^Uid:\s+([0-9]+)\s+([0-9]+)\s+([0-9]+)\s+([0-9]+)$/m', $bytes, $match) !== 1) {
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

/** @return array{dev: int, ino: int, nlink: int, uid: int, gid: int, mode: int, type: string}|null */
function nodeIdentity(string $path): ?array
{
    // PHP 8.1/8.2 may retain lstat/owner/mode values across the root-owned
    // authorization sequence. Always re-read the filesystem before binding.
    clearstatcache(true, $path);
    $stat = lstat($path);
    if (!is_array($stat) || is_link($path) || (!is_dir($path) && !is_file($path))
        || realpath($path) !== $path || !noExtendedAcl($path)) {
        return null;
    }
    $type = is_dir($path) ? 'directory' : 'regular file';
    $identity = [
        'dev' => (int)($stat['dev'] ?? -1),
        'ino' => (int)($stat['ino'] ?? -1),
        'nlink' => (int)($stat['nlink'] ?? -1),
        'uid' => (int)($stat['uid'] ?? -1),
        'gid' => (int)($stat['gid'] ?? -1),
        'mode' => (int)($stat['mode'] ?? 0) & 0o7777,
        'type' => $type,
    ];
    if ($identity['dev'] < 0 || $identity['ino'] < 1
        || ($type === 'directory' ? $identity['nlink'] < 1 : $identity['nlink'] !== 1)) {
        return null;
    }
    return $identity;
}

/** @param array{path: string, dev: int, ino: int, nlink: int, type: string} $expected */
function matchesCreatedIdentity(array $expected): bool
{
    $actual = nodeIdentity($expected['path']);
    return is_array($actual)
        && $actual['dev'] === $expected['dev']
        && $actual['ino'] === $expected['ino']
        && $actual['type'] === $expected['type']
        && ($expected['type'] === 'directory'
            ? $actual['nlink'] >= 1
            : $actual['nlink'] === 1 && $expected['nlink'] === 1);
}

function recordCreated(string $path, string $type): void
{
    global $createdStateNodes;
    $identity = nodeIdentity($path);
    if (!is_array($identity) || $identity['type'] !== $type) {
        fail("created state node is unsafe: {$path}");
    }
    $createdStateNodes[] = [
        'path' => $path,
        'dev' => $identity['dev'],
        'ino' => $identity['ino'],
        'nlink' => $identity['nlink'],
        'type' => $type,
    ];
}

function cleanupCreatedState(int $webUid): bool
{
    global $createdStateNodes, $runtimePublished;
    if ($createdStateNodes === []) {
        return true;
    }
    $expectedPaths = [];
    foreach ($createdStateNodes as $node) {
        if (!matchesCreatedIdentity($node)) {
            return false;
        }
        $expectedPaths[$node['path']] = true;
    }
    foreach ($createdStateNodes as $node) {
        if ($node['type'] !== 'directory') {
            continue;
        }
        $entries = scandir($node['path']);
        if (!is_array($entries)) {
            return false;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (!isset($expectedPaths[$node['path'] . '/' . $entry])) {
                return false;
            }
        }
    }
    if ($runtimePublished) {
        if (!uidIsQuiescent($webUid)) {
            return false;
        }
        $runtime = $createdStateNodes[2] ?? null;
        if (!is_array($runtime) || $runtime['type'] !== 'directory'
            || !matchesCreatedIdentity($runtime)
            || !chown($runtime['path'], 0) || !chgrp($runtime['path'], 0)
            || !chmod($runtime['path'], 0o700) || !matchesCreatedIdentity($runtime)) {
            return false;
        }
        $runtimePublished = false;
    }
    for ($index = count($createdStateNodes) - 1; $index >= 0; $index--) {
        $node = $createdStateNodes[$index];
        if (!matchesCreatedIdentity($node)) {
            return false;
        }
        $removed = $node['type'] === 'directory'
            ? rmdir($node['path'])
            : unlink($node['path']);
        if (!$removed) {
            return false;
        }
    }
    $createdStateNodes = [];
    return true;
}

function cleanupCreatedControlDirectories(): bool
{
    global $createdControlDirectories, $controlDirectoryCreationUnbound;
    if ($controlDirectoryCreationUnbound) {
        return false;
    }
    for ($index = count($createdControlDirectories) - 1; $index >= 0; $index--) {
        $node = $createdControlDirectories[$index];
        if (!matchesCreatedIdentity($node)) {
            return false;
        }
        $actual = nodeIdentity($node['path']);
        $entries = scandir($node['path']);
        if (!is_array($actual) || $actual['uid'] !== 0 || $actual['gid'] !== 0
            || !in_array($actual['mode'], [0o700, 0o755], true) || !is_array($entries)
            || array_values(array_diff($entries, ['.', '..'])) !== []) {
            return false;
        }
        if (!rmdir($node['path'])) {
            return false;
        }
    }
    $createdControlDirectories = [];
    return true;
}

function fail(string $message): never
{
    global $createdStateNodes, $createdControlDirectories;
    global $controlDirectoryCreationUnbound, $cleanupRunning;
    $exit = 1;
    if (!$cleanupRunning && ($createdStateNodes !== []
        || $createdControlDirectories !== [] || $controlDirectoryCreationUnbound)) {
        $cleanupRunning = true;
        $webUid = $GLOBALS['runtimeWebUid'] ?? 0;
        $complete = is_int($webUid) && $webUid > 0 && cleanupCreatedState($webUid);
        if ($complete) {
            $complete = cleanupCreatedControlDirectories();
        }
        if (!$complete) {
            fwrite(STDERR, "ERROR: RUNTIME_INIT_ROLLBACK_INCOMPLETE state residue retained\n");
            $exit = 70;
        }
    }
    fwrite(STDERR, "ERROR: {$message}\n");
    exit($exit);
}

function numericId(mixed $value, string $label): int
{
    if (!is_string($value) || preg_match('/^[1-9][0-9]{0,9}$/D', $value) !== 1) {
        fail("{$label} is invalid");
    }
    $id = (int)$value;
    if ($id > 2147483647) {
        fail("{$label} is invalid");
    }
    return $id;
}

function ensureControlDirectory(string $path, int $mode): void
{
    global $createdControlDirectories, $controlDirectoryCreationUnbound;
    if (is_link($path)) {
        fail("state control directory is unsafe: {$path}");
    }
    if (!file_exists($path)) {
        if (!mkdir($path, 0o700)) {
            fail('unable to create state control directory');
        }
        $controlDirectoryCreationUnbound = true;
        $identity = nodeIdentity($path);
        if (!is_array($identity) || $identity['type'] !== 'directory'
            || $identity['uid'] !== 0 || $identity['gid'] !== 0) {
            fail("new state control directory is unsafe: {$path}");
        }
        $createdControlDirectories[] = [
            'path' => $path,
            'dev' => $identity['dev'],
            'ino' => $identity['ino'],
            'nlink' => $identity['nlink'],
            'type' => 'directory',
        ];
        $controlDirectoryCreationUnbound = false;
        if (!chown($path, 0) || !chgrp($path, 0) || !chmod($path, $mode)) {
            fail("unable to protect state control directory: {$path}");
        }
    }
    $identity = nodeIdentity($path);
    if (!is_array($identity) || $identity['type'] !== 'directory'
        || $identity['uid'] !== 0 || $identity['gid'] !== 0 || $identity['mode'] !== $mode) {
        fail("state control directory is unsafe: {$path}");
    }
}

function createStateDirectory(string $path): void
{
    if (file_exists($path) || is_link($path) || !mkdir($path, 0o700)) {
        fail("state directory already exists or cannot be created: {$path}");
    }
    if (!chown($path, 0) || !chgrp($path, 0) || !chmod($path, 0o700)) {
        @rmdir($path);
        fail("unable to protect state directory: {$path}");
    }
    recordCreated($path, 'directory');
}

/** @param array{path: string, dev: int, ino: int, nlink: int, type: string} $expected */
function authorizeCreatedNode(array $expected, int $uid, int $gid, int $mode): void
{
    if (!matchesCreatedIdentity($expected)
        || !chmod($expected['path'], $mode)
        || !chgrp($expected['path'], $gid)
        || !chown($expected['path'], $uid)) {
        fail("unable to authorize state node: {$expected['path']}");
    }
    $actual = nodeIdentity($expected['path']);
    if (!is_array($actual) || $actual['dev'] !== $expected['dev']
        || $actual['ino'] !== $expected['ino'] || $actual['uid'] !== $uid
        || $actual['gid'] !== $gid || $actual['mode'] !== $mode) {
        fail("state node authorization drifted: {$expected['path']}");
    }
}

try {
    $options = getopt('', ['site-root:', 'web-uid:', 'web-gid:']);
    $siteArg = $options['site-root'] ?? null;
    if (!is_string($siteArg) || $siteArg === '' || $siteArg[0] !== '/') {
        fail('usage: init-runtime.php --site-root /absolute/path --web-uid UID --web-gid GID');
    }
    $siteRoot = realpath($siteArg);
    if ($siteRoot === false || !is_dir($siteRoot) || is_link($siteArg)) {
        fail('site root is invalid');
    }
    $siteRoot = rtrim($siteRoot, DIRECTORY_SEPARATOR);
    $webUid = numericId($options['web-uid'] ?? null, 'web UID');
    $webGid = numericId($options['web-gid'] ?? null, 'web GID');
    $runtimeWebUid = $webUid;
    if (!uidIsQuiescent($webUid)) {
        fail('dedicated Web identity must have zero processes before runtime initialization');
    }

    foreach (['/var', '/var/lib'] as $trustedParent) {
        $identity = nodeIdentity($trustedParent);
        if (!is_array($identity) || $identity['type'] !== 'directory'
            || $identity['uid'] !== 0 || $identity['gid'] !== 0
            || ($identity['mode'] & 0o022) !== 0) {
            fail('state control parent is unavailable or unsafe');
        }
    }

    $controlRoot = dirname(PIKA_STATE_BASE);
    ensureControlDirectory($controlRoot, 0o755);
    ensureControlDirectory(PIKA_STATE_BASE, 0o755);

    $siteDirectory = PIKA_STATE_BASE . DIRECTORY_SEPARATOR . hash('sha256', $siteRoot);
    $secrets = $siteDirectory . '/secrets';
    $runtime = $siteDirectory . '/runtime';
    $config = $runtime . '/config';
    createStateDirectory($siteDirectory);
    createStateDirectory($secrets);
    createStateDirectory($runtime);
    createStateDirectory($config);

    $keyPath = $runtime . '/csrf.key';
    $handle = fopen($keyPath, 'xb');
    if ($handle === false) {
        fail('unable to create CSRF key');
    }
    recordCreated($keyPath, 'regular file');
    $key = bin2hex(random_bytes(32)) . "\n";
    $written = fwrite($handle, $key);
    $flushed = fflush($handle);
    if (function_exists('fsync')) {
        $flushed = fsync($handle) && $flushed;
    }
    fclose($handle);
    if ($written !== strlen($key) || !$flushed || !chown($keyPath, 0)
        || !chgrp($keyPath, 0) || !chmod($keyPath, 0o600)) {
        fail('unable to create protected CSRF key');
    }

    if (!uidIsQuiescent($webUid)) {
        fail('dedicated Web identity became active before runtime authorization');
    }
    authorizeCreatedNode($createdStateNodes[0], 0, 0, 0o755);
    authorizeCreatedNode($createdStateNodes[1], 0, $webGid, 0o750);
    authorizeCreatedNode($createdStateNodes[4], $webUid, $webGid, 0o600);
    authorizeCreatedNode($createdStateNodes[3], $webUid, $webGid, 0o750);
    $runtimePublished = true;
    authorizeCreatedNode($createdStateNodes[2], $webUid, $webGid, 0o750);
    if (!uidIsQuiescent($webUid)) {
        fail('dedicated Web identity became active after runtime authorization');
    }

    // After runtime is handed to the Web identity, this process performs only
    // identity/ACL reads. The root installer must never write through that path.
    foreach ($createdStateNodes as $node) {
        if (!matchesCreatedIdentity($node)) {
            fail("published state identity drifted: {$node['path']}");
        }
    }

    $createdStateNodes = [];
    $createdControlDirectories = [];
    fwrite(STDOUT, "RUNTIME_INIT_PASS\n");
} catch (Throwable) {
    fail('runtime initialization raised an exception');
}
