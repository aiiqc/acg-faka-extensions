<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

function assertNoExtendedAcl(string $path, string $label): void
{
    if (!function_exists('proc_open') || !is_executable('/usr/bin/ls')) {
        fail("trusted /usr/bin/ls and proc_open are required to inspect {$label} ACLs");
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
        fail("unable to inspect {$label} ACL marker: {$path}");
    }
    $stdout = stream_get_contents($pipes[1], 4096);
    $stderr = stream_get_contents($pipes[2], 4096);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    $token = is_string($stdout) ? strtok($stdout, " \t\r\n") : false;
    if ($status !== 0 || !is_string($token) || $token === '' || $stderr === false) {
        fail("unable to inspect {$label} ACL marker: {$path}");
    }
    if (str_ends_with($token, '+')) {
        fail("{$label} must not have an extended POSIX ACL: {$path}");
    }
}

function assertImmutableBridgeFile(string $root, string $relative, string $label): string
{
    $cursor = $root;
    $rootMode = fileperms($cursor);
    if (!is_dir($cursor) || is_link($cursor) || realpath($cursor) !== $cursor
        || fileowner($cursor) !== 0 || filegroup($cursor) !== 0
        || $rootMode === false || ($rootMode & 0o022) !== 0) {
        fail("{$label} root is not immutable: {$root}");
    }
    assertNoExtendedAcl($cursor, "{$label} root");
    $segments = explode('/', $relative);
    foreach (array_slice($segments, 0, -1) as $segment) {
        $cursor .= '/' . $segment;
        $mode = fileperms($cursor);
        if (!is_dir($cursor) || is_link($cursor) || realpath($cursor) !== $cursor
            || fileowner($cursor) !== 0 || filegroup($cursor) !== 0
            || $mode === false || ($mode & 0o022) !== 0) {
            fail("{$label} parent is not immutable: {$relative}");
        }
        assertNoExtendedAcl($cursor, "{$label} parent");
    }
    $path = $root . '/' . $relative;
    $stat = lstat($path);
    if (!is_array($stat) || !is_file($path) || is_link($path)
        || realpath($path) !== $path || (int)($stat['nlink'] ?? 0) !== 1) {
        fail("{$label} must be a canonical regular file with exactly one hard link: {$relative}");
    }
    assertNoExtendedAcl($path, $label);
    if ((int)($stat['uid'] ?? -1) !== 0 || (int)($stat['gid'] ?? -1) !== 0
        || (((int)($stat['mode'] ?? 0)) & 0o7777) !== 0o644) {
        fail("{$label} must be root:root mode 0644 without special bits: {$relative}");
    }
    return $path;
}

$options = getopt('', [
    'output:', 'site-root:', 'backup-dir:', 'bridge-version:', 'upstream-commit:', 'file-list:',
    'web-uid:', 'web-gid:'
]);
foreach ([
    'output', 'site-root', 'backup-dir', 'bridge-version', 'upstream-commit', 'file-list',
    'web-uid', 'web-gid',
] as $key) {
    if (!isset($options[$key]) || !is_string($options[$key]) || $options[$key] === '') {
        fail("missing --{$key}");
    }
}

if (preg_match('/^[1-9][0-9]{0,9}$/D', $options['web-uid']) !== 1
    || preg_match('/^[1-9][0-9]{0,9}$/D', $options['web-gid']) !== 1
    || (int)$options['web-uid'] > 2147483647
    || (int)$options['web-gid'] > 2147483647) {
    fail('invalid web UID/GID receipt identity');
}

$siteRoot = realpath($options['site-root']);
$backupDir = realpath($options['backup-dir']);
$fileList = realpath($options['file-list']);
if ($siteRoot === false || $backupDir === false || $fileList === false) {
    fail('receipt input path does not exist');
}

$installed = file($fileList, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (!is_array($installed) || count($installed) > 10000) {
    fail('invalid installed file list');
}
foreach ($installed as $relative) {
    if (!is_string($relative) || preg_match('#^(?!/)(?!.*(?:^|/)\.\.(?:/|$))[A-Za-z0-9._/-]+$#D', $relative) !== 1) {
        fail('unsafe installed file path');
    }
}

$bridge = [];
foreach ([
    'kernel/Kernel.php',
    'kernel/Helper.php',
    'app/Controller/Admin/Api/Config.php',
    'app/View/Admin/Footer.html',
    'assets/common/js/editor/markdown/editorv2.js',
] as $relative) {
    $current = assertImmutableBridgeFile($siteRoot, $relative, 'current bridge receipt input');
    $before = assertImmutableBridgeFile($backupDir . '/site', $relative, 'backup bridge receipt input');
    $bridge[$relative] = [
        'before_sha256' => hash_file('sha256', $before),
        'after_sha256' => hash_file('sha256', $current),
    ];
}

$installedFiles = [];
foreach ($installed as $relative) {
    $absolute = $siteRoot . '/' . $relative;
    if (!is_file($absolute) || is_link($absolute)) {
        fail("installed file is missing or unsafe: {$relative}");
    }
    $installedFiles[] = [
        'path' => $relative,
        'sha256' => hash_file('sha256', $absolute),
    ];
}

$receipt = [
    'schema' => 1,
    'installed_at' => gmdate('c'),
    'bridge_version' => $options['bridge-version'],
    'upstream_commit' => $options['upstream-commit'],
    'backup_dir' => $backupDir,
    'web_uid' => (int)$options['web-uid'],
    'web_gid' => (int)$options['web-gid'],
    'bridge_files' => $bridge,
    'installed_files' => $installedFiles,
];

$output = $options['output'];
$outputDir = dirname($output);
if (!is_dir($outputDir) || is_link($outputDir)) {
    fail('invalid receipt output directory');
}
$tmp = tempnam($outputDir, '.receipt.');
if ($tmp === false) {
    fail('unable to create receipt temporary file');
}
$json = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
if (file_put_contents($tmp, $json, LOCK_EX) !== strlen($json) || !chmod($tmp, 0640) || !rename($tmp, $output)) {
    @unlink($tmp);
    fail('unable to publish install receipt');
}
fwrite(STDOUT, "RECEIPT_PASS files=" . count($installedFiles) . "\n");
