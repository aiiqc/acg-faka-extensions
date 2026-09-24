<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

function safeJson(string $path): array
{
    if (!is_file($path) || is_link($path)) {
        fail("unsafe receipt path: {$path}");
    }
    $mode = fileperms($path);
    if ($mode === false || ($mode & 0o022) !== 0) {
        fail("receipt permissions are unsafe: {$path}");
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

function numericMode(string $path): int
{
    $mode = fileperms($path);
    if ($mode === false) {
        fail("unable to inspect path permissions: {$path}");
    }
    return $mode & 0o7777;
}

function assertNoExtendedAcl(string $path, string $label): void
{
    if (!function_exists('proc_open')) {
        fail(
            "PHP CLI proc_open is required to inspect {$label} ACLs; "
            . 'enable proc_open for the trusted maintenance CLI and rerun installed doctor'
        );
    }
    if (!is_executable('/usr/bin/ls')) {
        fail("trusted /usr/bin/ls is required to inspect {$label} ACLs: {$path}");
    }
    $command = ['/usr/bin/ls', '-ld', '--', $path];
    $pipes = [];
    $process = proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        ['LC_ALL' => 'C']
    );
    if (!is_resource($process)) {
        fail("unable to inspect {$label} ACL marker: {$path}");
    }
    $stdout = stream_get_contents($pipes[1], 4096);
    $stderr = stream_get_contents($pipes[2], 4096);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0 || !is_string($stdout) || $stdout === '' || $stderr === false) {
        fail("unable to inspect {$label} ACL marker: {$path}");
    }
    $token = strtok($stdout, " \t\r\n");
    if (!is_string($token) || str_ends_with($token, '+')) {
        fail("{$label} must not have an extended POSIX ACL: {$path}");
    }
}

function assertOfficialRuntimeDirectory(
    string $siteRoot,
    string $relative,
    int $webUid,
    int $webGid,
    int $expectedMode
): string {
    $path = $siteRoot . DIRECTORY_SEPARATOR . $relative;
    if (!is_dir($path) || is_link($path) || realpath($path) !== $path
        || !str_starts_with($path . DIRECTORY_SEPARATOR, $siteRoot . DIRECTORY_SEPARATOR)
        || fileowner($path) !== $webUid
        || filegroup($path) !== $webGid
        || numericMode($path) !== $expectedMode) {
        fail(
            "official runtime directory {$relative} must be a canonical, dedicated-Web-owned "
            . sprintf('%04o', $expectedMode)
            . ' directory without group/world write; do not rerun install on an installed site; '
            . 'follow the documented maintenance recovery flow'
        );
    }
    assertNoExtendedAcl($path, "official runtime directory {$relative}");
    return $path;
}

function assertProtectedOfficialDirectory(
    string $siteRoot,
    string $relative,
    int $webGid,
    int $expectedMode
): string {
    $path = $siteRoot . DIRECTORY_SEPARATOR . $relative;
    if (!is_dir($path) || is_link($path) || realpath($path) !== $path
        || !str_starts_with($path . DIRECTORY_SEPARATOR, $siteRoot . DIRECTORY_SEPARATOR)
        || fileowner($path) !== 0 || filegroup($path) !== $webGid
        || numericMode($path) !== $expectedMode) {
        fail(
            "protected official directory {$relative} must be canonical, root-owned, "
            . "group-traversable by the dedicated Web identity, and "
            . sprintf('%04o', $expectedMode)
        );
    }
    assertNoExtendedAcl($path, "protected official directory {$relative}");
    return $path;
}

function assertDedicatedWebNode(
    string $path,
    int $webUid,
    int $webGid,
    string $label,
    ?string $smartyViewRoot = null
): void {
    $stat = lstat($path);
    if (!is_array($stat) || is_link($path) || (!is_dir($path) && !is_file($path))
        || fileowner($path) !== $webUid || filegroup($path) !== $webGid) {
        fail("{$label} must be owned by the dedicated Web identity: {$path}");
    }
    $mode = numericMode($path);
    // Official cache cleanup lets bundled Smarty recreate only these directories.
    $isSmartyViewDirectory = $smartyViewRoot !== null
        && is_dir($path)
        && $mode === 0o771
        && realpath($path) === $path
        && ($path === $smartyViewRoot
            || str_starts_with($path, $smartyViewRoot . DIRECTORY_SEPARATOR));
    if (($mode & 0o022) !== 0 && !$isSmartyViewDirectory) {
        fail("{$label} must not be group/world writable: {$path}");
    }
    if (is_dir($path)) {
        if (($mode & 0o300) !== 0o300) {
            fail("{$label} directory must remain owner-writable and searchable: {$path}");
        }
    } elseif (($mode & 0o200) === 0 || (int)($stat['nlink'] ?? 0) !== 1) {
        fail("{$label} file must remain owner-writable with exactly one hard link: {$path}");
    }
    assertNoExtendedAcl($path, $label);
}

function assertDedicatedWebTree(
    string $root,
    int $webUid,
    int $webGid,
    string $label,
    array $excludedDirectChildren = [],
    ?string $smartyViewRoot = null
): void {
    if (!is_dir($root) || is_link($root) || realpath($root) !== $root) {
        fail("{$label} root is unsafe: {$root}");
    }
    $stack = [$root];
    $seen = 0;
    while ($stack !== []) {
        $directory = array_pop($stack);
        if (!is_string($directory)) {
            fail("{$label} traversal state is invalid");
        }
        $entries = scandir($directory);
        if (!is_array($entries)) {
            fail("unable to inspect {$label}: {$directory}");
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if ($directory === $root && in_array($entry, $excludedDirectChildren, true)) {
                continue;
            }
            $seen++;
            if ($seen > 200000) {
                fail("{$label} exceeds the 200000-node verification limit");
            }
            assertDedicatedWebNode($path, $webUid, $webGid, $label, $smartyViewRoot);
            if (is_dir($path)) {
                $stack[] = $path;
            }
        }
    }
}

function assertOfficialThrottleTree(
    string $runtimeRoot,
    int $webUid,
    int $webGid
): void {
    $root = $runtimeRoot . DIRECTORY_SEPARATOR . 'throttle';
    if (!file_exists($root) && !is_link($root)) {
        return;
    }
    if (!is_dir($root) || is_link($root) || realpath($root) !== $root
        || fileowner($root) !== $webUid || filegroup($root) !== $webGid
        || numericMode($root) !== 0o755) {
        fail('official throttle directory is unsafe: ' . $root);
    }
    assertNoExtendedAcl($root, 'official throttle directory');

    $entries = scandir($root);
    if (!is_array($entries)) {
        fail('unable to inspect official throttle tree: ' . $root);
    }
    $seen = 0;
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $seen++;
        if ($seen > 200000) {
            fail('official throttle tree exceeds the 200000-node verification limit');
        }
        if (preg_match('/\A[a-f0-9]{32}\z/D', $entry) !== 1) {
            fail('official throttle cache name is invalid: ' . $entry);
        }
        $path = $root . DIRECTORY_SEPARATOR . $entry;
        $stat = lstat($path);
        $mode = numericMode($path);
        if (!is_array($stat) || !is_file($path) || is_link($path)
            || realpath($path) !== $path
            || fileowner($path) !== $webUid || filegroup($path) !== $webGid
            || (int)($stat['nlink'] ?? 0) !== 1
            || !in_array($mode, [0o755, 0o777], true)) {
            fail('official throttle cache file is unsafe: ' . $path);
        }
        assertNoExtendedAcl($path, 'official throttle cache file');
    }
}

function assertDedicatedWebFile(
    string $path,
    int $webUid,
    int $webGid,
    string $label,
    array $allowedModes = [0o600, 0o640]
): void {
    if (!is_file($path) || is_link($path) || realpath($path) !== $path) {
        fail("{$label} must be a regular file: {$path}");
    }
    assertDedicatedWebNode($path, $webUid, $webGid, $label);
    if (!in_array(numericMode($path), $allowedModes, true)) {
        fail("{$label} mode must be 0600 or 0640 without special bits: {$path}");
    }
}

function assertCanonicalSingleLinkAclFreeFile(string $path, string $label): void
{
    $stat = lstat($path);
    if (!is_array($stat) || !is_file($path) || is_link($path)
        || realpath($path) !== $path || (int)($stat['nlink'] ?? 0) !== 1) {
        fail("{$label} must be a canonical regular file with exactly one hard link: {$path}");
    }
    assertNoExtendedAcl($path, $label);
}

function assertRootDirectory(string $path, array $allowedModes, string $label): void
{
    if (!is_dir($path) || is_link($path) || realpath($path) !== $path) {
        fail("{$label} is missing, non-canonical, or unsafe: {$path}");
    }
    if (fileowner($path) !== 0 || filegroup($path) !== 0
        || !in_array(numericMode($path), $allowedModes, true)) {
        fail("{$label} owner or mode is unsafe: {$path}");
    }
    assertNoExtendedAcl($path, $label);
}

function assertRootFile(string $path, array $allowedModes, string $label): void
{
    $stat = lstat($path);
    if (!is_file($path) || is_link($path) || realpath($path) !== $path) {
        fail("{$label} is missing, non-canonical, or unsafe: {$path}");
    }
    if (!is_array($stat) || (int)($stat['nlink'] ?? 0) !== 1
        || fileowner($path) !== 0 || filegroup($path) !== 0
        || !in_array(numericMode($path), $allowedModes, true)) {
        fail("{$label} owner or mode is unsafe: {$path}");
    }
    assertNoExtendedAcl($path, $label);
}

function assertRootReadableDatabaseFile(
    string $path,
    int $webGid,
    string $label
): void {
    $stat = lstat($path);
    $mode = is_array($stat) ? numericMode($path) : -1;
    $gid = is_array($stat) ? (int)($stat['gid'] ?? -1) : -1;
    if (!is_array($stat) || !is_file($path) || is_link($path) || realpath($path) !== $path
        || (int)($stat['nlink'] ?? 0) !== 1 || (int)($stat['uid'] ?? -1) !== 0
        || !(($gid === 0 && $mode === 0o644) || ($gid === $webGid && $mode === 0o640))) {
        fail("{$label} owner or mode is unsafe: {$path}");
    }
    assertNoExtendedAcl($path, $label);
}

function assertProtectedRootChain(string $path, string $label): void
{
    if (!str_starts_with($path, DIRECTORY_SEPARATOR)) {
        fail("{$label} path is not absolute");
    }
    $cursor = DIRECTORY_SEPARATOR;
    foreach (explode(DIRECTORY_SEPARATOR, trim($path, DIRECTORY_SEPARATOR)) as $segment) {
        if ($segment === '') {
            continue;
        }
        $cursor = rtrim($cursor, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $segment;
        if (!is_dir($cursor) || is_link($cursor)
            || fileowner($cursor) !== 0 || filegroup($cursor) !== 0
            || (numericMode($cursor) & 0o022) !== 0) {
            fail("{$label} ancestry is writable or not root-owned: {$cursor}");
        }
        assertNoExtendedAcl($cursor, "{$label} ancestry");
    }
}

function safeRelative(mixed $path): string
{
    if (!is_string($path)
        || preg_match('#^(?!/)[A-Za-z0-9._/-]+$#D', $path) !== 1) {
        fail('receipt contains an unsafe relative path');
    }
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            fail('receipt contains a non-canonical relative path');
        }
    }
    return $path;
}

function installedPathAllowed(string $path): bool
{
    if (in_array($path, [
        'app/Controller/Admin/LocalExtensions.php',
        'app/Controller/Admin/Api/LocalExtensions.php',
        'app/Controller/User/Api/PikaBEpusdt.php',
    ], true)) {
        return true;
    }
    foreach ([
        'local-extensions/',
        'app/View/Admin/LocalExtensions/',
        'app/View/User/Theme/Pika/',
        'app/Pay/PikaBEpusdtAdapter/',
        'assets/admin/controller/local-extensions/',
        'assets/local-extensions/',
    ] as $prefix) {
        if (str_starts_with($path, $prefix)) {
            return !in_array($path, [
                'app/View/User/Theme/Pika/Setting.php',
                'local-extensions/registry.json',
                'app/Pay/PikaBEpusdtAdapter/runtime.log',
            ], true);
        }
    }
    return false;
}

function verifiedRegularFile(
    string $root,
    string $relative,
    string $label,
    bool $immutable = false,
    array $approvedMutableAncestors = []
): string
{
    $cursor = $root;
    if (!is_dir($cursor) || is_link($cursor)) {
        fail("{$label} root is missing or unsafe: {$relative}");
    }
    if ($immutable && (fileowner($cursor) !== 0 || filegroup($cursor) !== 0
        || (numericMode($cursor) & 0o022) !== 0)) {
        fail("{$label} root is replaceable by a non-root identity: {$relative}");
    }
    if ($immutable) {
        assertNoExtendedAcl($cursor, "{$label} root");
    }
    $segments = explode('/', $relative);
    foreach (array_slice($segments, 0, -1) as $segment) {
        $cursor .= DIRECTORY_SEPARATOR . $segment;
        if (!is_dir($cursor) || is_link($cursor)) {
            fail("{$label} parent is missing or unsafe: {$relative}");
        }
        if ($immutable) {
            $approved = $approvedMutableAncestors[$cursor] ?? null;
            if (is_array($approved)) {
                if (fileowner($cursor) !== ($approved['uid'] ?? null)
                    || filegroup($cursor) !== ($approved['gid'] ?? null)
                    || numericMode($cursor) !== ($approved['mode'] ?? null)) {
                    fail("{$label} approved compatibility-first parent drifted: {$relative}");
                }
            } elseif (fileowner($cursor) !== 0 || filegroup($cursor) !== 0
                || (numericMode($cursor) & 0o022) !== 0) {
                fail("{$label} parent is replaceable by a non-root identity: {$relative}");
            }
            assertNoExtendedAcl($cursor, "{$label} parent");
        }
    }
    $path = $root . DIRECTORY_SEPARATOR . $relative;
    if (!is_file($path) || is_link($path)) {
        fail("{$label} is missing or unsafe: {$relative}");
    }
    if ($immutable) {
        assertCanonicalSingleLinkAclFreeFile($path, $label);
    }
    if ($immutable && (fileowner($path) !== 0 || filegroup($path) !== 0
        || numericMode($path) !== 0o644)) {
        fail("{$label} owner or mode is unsafe: {$relative}");
    }
    return $path;
}

$options = getopt('', ['site-root:']);
$siteArg = $options['site-root'] ?? null;
if (!is_string($siteArg)) {
    fail('usage: verify-install.php --site-root PATH');
}
$siteRoot = realpath($siteArg);
if ($siteRoot === false || $siteRoot === DIRECTORY_SEPARATOR || !is_dir($siteRoot)) {
    fail('invalid site root');
}
$sitePrefix = rtrim($siteRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

$stateBase = '/var/lib/pika-local-extensions';
$stateSites = $stateBase . '/sites';
$stateSiteRoot = $stateSites . '/' . hash('sha256', $siteRoot);
assertRootDirectory($stateBase, [0o755], 'external state root');
assertRootDirectory($stateSites, [0o755], 'external state sites root');
assertRootDirectory($stateSiteRoot, [0o755], 'site state root');
$siteReceiptPath = $stateSiteRoot . '/install-receipt.json';
assertRootFile($siteReceiptPath, [0o400, 0o440], 'protected site receipt');
[$receipt, $siteReceiptBytes] = safeJson($siteReceiptPath);
if (($receipt['schema'] ?? null) !== 1 || !is_string($receipt['backup_dir'] ?? null)) {
    fail('install receipt schema is invalid');
}
$webUid = $receipt['web_uid'] ?? null;
$webGid = $receipt['web_gid'] ?? null;
if (!is_int($webUid) || $webUid < 1 || $webUid > 2147483647
    || !is_int($webGid) || $webGid < 1 || $webGid > 2147483647) {
    fail('install receipt web identity is invalid');
}
$officialRuntimeModes = [
    'assets/cache' => 0o755,
    'assets/cache/general' => 0o755,
    'assets/cache/general/image' => 0o755,
    'assets/cache/pika-supply-sync' => 0o755,
    'app/Pay' => 0o755,
    'app/Plugin' => 0o755,
    'app/View/User/Theme' => 0o755,
    'kernel/Install/OS' => 0o750,
    'runtime' => 0o750,
];
$officialRuntimePaths = [];
foreach ($officialRuntimeModes as $relative => $expectedMode) {
    $officialRuntimePaths[$relative] = assertOfficialRuntimeDirectory(
        $siteRoot,
        $relative,
        $webUid,
        $webGid,
        $expectedMode
    );
}
$officialProtectedModes = [
    'config' => 0o750,
    'kernel/Install' => 0o750,
];
foreach ($officialProtectedModes as $relative => $expectedMode) {
    $officialRuntimePaths[$relative] = assertProtectedOfficialDirectory(
        $siteRoot,
        $relative,
        $webGid,
        $expectedMode
    );
}
foreach (['Base.php', 'Pay.php', 'Signature.php'] as $paymentCore) {
    assertRootFile(
        $officialRuntimePaths['app/Pay'] . DIRECTORY_SEPARATOR . $paymentCore,
        [0o644],
        'official payment core file'
    );
}
assertRootFile(
    $officialRuntimePaths['config'] . '/app.php',
    [0o644],
    'official application version file'
);
assertRootReadableDatabaseFile(
    $officialRuntimePaths['config'] . '/database.php',
    $webGid,
    'official database configuration file'
);
assertRootFile(
    $officialRuntimePaths['kernel/Install'] . '/Install.sql',
    [0o644],
    'official installer schema file'
);
$approvedMutableAncestors = [];
foreach (['app/Pay', 'app/View/User/Theme'] as $relative) {
    $approvedMutableAncestors[$officialRuntimePaths[$relative]] = [
        'uid' => $webUid,
        'gid' => $webGid,
        'mode' => $officialRuntimeModes[$relative],
    ];
}
assertDedicatedWebTree(
    $officialRuntimePaths['assets/cache'],
    $webUid,
    $webGid,
    'official cache tree'
);
assertDedicatedWebTree(
    $officialRuntimePaths['app/Plugin'],
    $webUid,
    $webGid,
    'official plugin tree'
);
assertDedicatedWebTree(
    $officialRuntimePaths['runtime'],
    $webUid,
    $webGid,
    'official runtime tree',
    ['throttle'],
    $officialRuntimePaths['runtime'] . DIRECTORY_SEPARATOR . 'view'
);
assertOfficialThrottleTree($officialRuntimePaths['runtime'], $webUid, $webGid);
assertDedicatedWebTree(
    $officialRuntimePaths['kernel/Install/OS'],
    $webUid,
    $webGid,
    'official installer OS tree'
);
assertDedicatedWebTree(
    $officialRuntimePaths['app/Pay'],
    $webUid,
    $webGid,
    'official payment adapter tree',
    ['Base.php', 'Pay.php', 'PikaBEpusdtAdapter', 'Signature.php']
);
assertDedicatedWebTree(
    $officialRuntimePaths['app/View/User/Theme'],
    $webUid,
    $webGid,
    'official theme tree',
    ['Pika']
);
foreach (['store.php', 'mcp.php', 'terms'] as $mutableConfig) {
    assertDedicatedWebFile(
        $officialRuntimePaths['config'] . DIRECTORY_SEPARATOR . $mutableConfig,
        $webUid,
        $webGid,
        'official mutable config file'
    );
}
assertDedicatedWebFile(
    $officialRuntimePaths['kernel/Install'] . '/Lock',
    $webUid,
    $webGid,
    'official install lock'
);
$updateRoot = $officialRuntimePaths['kernel/Install'] . '/Update';
if (file_exists($updateRoot) || is_link($updateRoot)) {
    fail('official online core update staging is unsupported on a fixed-artifact installation');
}
$runtimePath = $stateSiteRoot . '/runtime';
$runtimeMode = is_dir($runtimePath) && !is_link($runtimePath) ? numericMode($runtimePath) : -1;
if (realpath($runtimePath) !== $runtimePath
    || fileowner($runtimePath) !== $webUid
    || filegroup($runtimePath) !== $webGid
    || $runtimeMode !== 0o750) {
    fail('external runtime does not match the receipt web identity');
}
$backupRoot = realpath($receipt['backup_dir']);
if ($backupRoot === false || $receipt['backup_dir'] !== $backupRoot
    || !is_dir($backupRoot) || is_link($backupRoot)
    || str_starts_with($backupRoot . '/', $sitePrefix)
    || str_starts_with($backupRoot . '/', $stateSiteRoot . '/')) {
    fail('install backup path is invalid');
}
assertProtectedRootChain($backupRoot, 'install backup root');
assertRootDirectory($backupRoot, [0o700], 'install backup root');
$externalReceiptPath = $backupRoot . '/install-receipt.json';
assertRootFile($externalReceiptPath, [0o400, 0o440], 'protected backup receipt');
[$externalReceipt, $externalReceiptBytes] = safeJson($externalReceiptPath);
if (!hash_equals(hash('sha256', $externalReceiptBytes), hash('sha256', $siteReceiptBytes))
    || $externalReceipt !== $receipt) {
    fail('site receipt does not match the protected backup receipt');
}

$bridgeFiles = $receipt['bridge_files'] ?? null;
$expectedBridgeFiles = [
    'app/Controller/Admin/Api/Config.php',
    'app/View/Admin/Footer.html',
    'assets/common/js/editor/markdown/editorv2.js',
    'kernel/Helper.php',
    'kernel/Kernel.php',
];
$actualBridgeFiles = is_array($bridgeFiles) ? array_keys($bridgeFiles) : [];
sort($actualBridgeFiles, SORT_STRING);
if (!is_array($bridgeFiles) || array_is_list($bridgeFiles)
    || $actualBridgeFiles !== $expectedBridgeFiles) {
    fail('bridge receipt is invalid');
}
foreach ($bridgeFiles as $relative => $hashes) {
    $relative = safeRelative($relative);
    if (!is_array($hashes)
        || preg_match('/^[a-f0-9]{64}$/D', (string)($hashes['before_sha256'] ?? '')) !== 1
        || preg_match('/^[a-f0-9]{64}$/D', (string)($hashes['after_sha256'] ?? '')) !== 1) {
        fail("bridge hash receipt is invalid: {$relative}");
    }
    $current = verifiedRegularFile($siteRoot, $relative, 'bridge file', true);
    $before = verifiedRegularFile($backupRoot . '/site', $relative, 'bridge backup file', true);
    if (!hash_equals($hashes['after_sha256'], hash_file('sha256', $current))
        || !hash_equals($hashes['before_sha256'], hash_file('sha256', $before))) {
        fail("bridge file verification failed: {$relative}");
    }
}

$installed = $receipt['installed_files'] ?? null;
if (!is_array($installed) || !array_is_list($installed)
    || $installed === [] || count($installed) > 10000) {
    fail('installed file receipt is invalid');
}
$seenInstalled = [];
foreach ($installed as $entry) {
    if (!is_array($entry)) {
        fail('installed file receipt entry is invalid');
    }
    $relative = safeRelative($entry['path'] ?? null);
    if (!installedPathAllowed($relative)) {
        fail("installed file is outside the release allowlist: {$relative}");
    }
    if (isset($seenInstalled[$relative])) {
        fail("installed file receipt contains a duplicate path: {$relative}");
    }
    $seenInstalled[$relative] = true;
    $expected = $entry['sha256'] ?? null;
    $current = verifiedRegularFile(
        $siteRoot,
        $relative,
        'installed file',
        true,
        $approvedMutableAncestors
    );
    if (!is_string($expected) || preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1
        || !hash_equals($expected, hash_file('sha256', $current))) {
        fail("installed file verification failed: {$relative}");
    }
}

$settingPath = verifiedRegularFile(
    $siteRoot,
    'app/View/User/Theme/Pika/Setting.php',
    'Pika mutable setting'
);
assertCanonicalSingleLinkAclFreeFile($settingPath, 'Pika mutable setting');
if (fileowner($settingPath) !== $webUid
    || filegroup($settingPath) !== $webGid
    || numericMode($settingPath) !== 0o640) {
    fail('Pika mutable setting does not match the receipt web identity');
}

$paymentRuntimePath = verifiedRegularFile(
    $siteRoot,
    'app/Pay/PikaBEpusdtAdapter/runtime.log',
    'payment adapter runtime log'
);
assertCanonicalSingleLinkAclFreeFile($paymentRuntimePath, 'payment adapter runtime log');
if (fileowner($paymentRuntimePath) !== $webUid
    || filegroup($paymentRuntimePath) !== $webGid
    || numericMode($paymentRuntimePath) !== 0o640) {
    fail('payment adapter runtime log does not match the receipt web identity');
}

$secretsPath = $stateSiteRoot . '/secrets';
if (realpath($secretsPath) !== $secretsPath
    || !is_dir($secretsPath) || is_link($secretsPath)
    || fileowner($secretsPath) !== 0
    || filegroup($secretsPath) !== $webGid
    || numericMode($secretsPath) !== 0o750) {
    fail('payment secret directory does not match the receipt web identity');
}
foreach (['bepusdt-token', 'bepusdt-namespace'] as $secretName) {
    $secretPath = $secretsPath . '/' . $secretName;
    if (!file_exists($secretPath) && !is_link($secretPath)) {
        continue;
    }
    $secretStat = lstat($secretPath);
    if (!is_file($secretPath) || is_link($secretPath)
        || !is_array($secretStat)
        || (int)($secretStat['nlink'] ?? 0) !== 1
        || fileowner($secretPath) !== 0
        || filegroup($secretPath) !== $webGid
        || numericMode($secretPath) !== 0o640) {
        fail("payment secret file is unsafe: {$secretName}");
    }
}

fwrite(STDOUT, "INSTALL_VERIFY_PASS files=" . count($installed) . "\n");
