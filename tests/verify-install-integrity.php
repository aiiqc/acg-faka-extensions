<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function mkdirExact(string $path, int $mode): void
{
    if (!is_dir($path) && !mkdir($path, $mode, true) && !is_dir($path)) {
        fail("unable to create directory: {$path}");
    }
    if (!chmod($path, $mode)) {
        fail("unable to set directory mode: {$path}");
    }
}

function writeExact(string $path, string $bytes, int $mode = 0o644): void
{
    $parent = dirname($path);
    if (!is_dir($parent) && !mkdir($parent, 0o755, true) && !is_dir($parent)) {
        fail("unable to create fixture file parent: {$parent}");
    }
    if (file_put_contents($path, $bytes) !== strlen($bytes) || !chmod($path, $mode)) {
        fail("unable to write fixture file: {$path}");
    }
}

function removeTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($path);
}

function runVerifier(string $verifier, string $site): array
{
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $verifier, '--site-root', $site],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        fail('unable to execute verifier');
    }
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $output];
}

function expectFailure(string $verifier, string $site, string $message): void
{
    [$status, $output] = runVerifier($verifier, $site);
    if ($status === 0 || !str_contains($output, $message)) {
        fail("expected verifier failure containing '{$message}', got status={$status}: {$output}");
    }
}

if (posix_geteuid() !== 0) {
    fail('integrity fixture must run as root in an isolated container');
}
$verifier = realpath($argv[1] ?? '');
if ($verifier === false || !is_file($verifier)) {
    fail('usage: verify-install-integrity.php VERIFIER');
}

$identity = 'pika-verify-' . getmypid();
$site = "/opt/{$identity}/site";
$backup = "/var/backups/{$identity}";
$bridgeFiles = [
    'kernel/Kernel.php',
    'kernel/Helper.php',
    'app/Controller/Admin/Api/Config.php',
    'app/View/Admin/Footer.html',
    'assets/common/js/editor/markdown/editorv2.js',
];
$installedPath = 'local-extensions/bootstrap.php';
$paymentInstalledPath = 'app/Pay/PikaBEpusdtAdapter/Config/Info.php';

mkdirExact($site, 0o755);
foreach ($bridgeFiles as $relative) {
    writeExact("{$site}/{$relative}", "current:{$relative}\n");
    writeExact("{$backup}/site/{$relative}", "before:{$relative}\n");
}
writeExact("{$site}/{$installedPath}", "installed\n");
writeExact("{$site}/{$paymentInstalledPath}", "payment-installed\n");
foreach (['Base.php', 'Pay.php', 'Signature.php'] as $paymentCore) {
    writeExact("{$site}/app/Pay/{$paymentCore}", "<?php // {$paymentCore}\n");
}
writeExact("{$site}/config/app.php", "<?php return ['version' => '3.6.4'];\n");
writeExact("{$site}/config/database.php", "<?php return [];\n");
writeExact("{$site}/kernel/Install/Install.sql", "-- fixture\n");
chmod($backup, 0o700);

$stateBase = '/var/lib/pika-local-extensions';
$stateSites = "{$stateBase}/sites";
$stateSite = $stateSites . '/' . hash('sha256', realpath($site));
mkdirExact($stateBase, 0o755);
mkdirExact($stateSites, 0o755);
mkdirExact($stateSite, 0o755);
$webUid = 1001;
$webGid = 1001;
$runtime = "{$stateSite}/runtime";
mkdirExact($runtime, 0o750);
chown($runtime, $webUid);
chgrp($runtime, $webGid);
$secrets = "{$stateSite}/secrets";
mkdirExact($secrets, 0o750);
chown($secrets, 0);
chgrp($secrets, $webGid);
writeExact("{$site}/app/View/User/Theme/Pika/Setting.php", "<?php return [];\n", 0o640);
chown("{$site}/app/View/User/Theme/Pika/Setting.php", $webUid);
chgrp("{$site}/app/View/User/Theme/Pika/Setting.php", $webGid);
writeExact("{$site}/app/Pay/PikaBEpusdtAdapter/runtime.log", '', 0o640);
chown("{$site}/app/Pay/PikaBEpusdtAdapter/runtime.log", $webUid);
chgrp("{$site}/app/Pay/PikaBEpusdtAdapter/runtime.log", $webGid);
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
foreach ($officialRuntimeModes as $relative => $mode) {
    $path = "{$site}/{$relative}";
    mkdirExact($path, $mode);
    chown($path, $webUid);
    chgrp($path, $webGid);
}
$officialProtectedModes = [
    'config' => 0o750,
    'kernel/Install' => 0o750,
];
foreach ($officialProtectedModes as $relative => $mode) {
    $path = "{$site}/{$relative}";
    mkdirExact($path, $mode);
    chown($path, 0);
    chgrp($path, $webGid);
}
$dynamicFiles = [
    'app/Plugin/Fixture/Config.php',
    'app/Pay/FixturePay/Config/Info.php',
    'app/View/User/Theme/FixtureTheme/Setting.php',
    'runtime/trusted_proxies',
];
foreach ($dynamicFiles as $relative) {
    writeExact("{$site}/{$relative}", "dynamic\n", 0o644);
    chown("{$site}/{$relative}", $webUid);
    chgrp("{$site}/{$relative}", $webGid);
    $cursor = dirname("{$site}/{$relative}");
    while ($cursor !== $site && fileowner($cursor) === 0) {
        chown($cursor, $webUid);
        chgrp($cursor, $webGid);
        chmod($cursor, 0o755);
        $cursor = dirname($cursor);
    }
}
foreach (['config/store.php', 'config/mcp.php', 'config/terms', 'kernel/Install/Lock'] as $relative) {
    writeExact("{$site}/{$relative}", "dynamic\n", 0o640);
    chown("{$site}/{$relative}", $webUid);
    chgrp("{$site}/{$relative}", $webGid);
}
$siteReceipt = "{$stateSite}/install-receipt.json";
$backupReceipt = "{$backup}/install-receipt.json";

$receipt = [
    'schema' => 1,
    'backup_dir' => realpath($backup),
    'web_uid' => $webUid,
    'web_gid' => $webGid,
    'bridge_files' => [],
    'installed_files' => [
        [
            'path' => $installedPath,
            'sha256' => hash_file('sha256', "{$site}/{$installedPath}"),
        ],
        [
            'path' => $paymentInstalledPath,
            'sha256' => hash_file('sha256', "{$site}/{$paymentInstalledPath}"),
        ],
    ],
];
foreach ($bridgeFiles as $relative) {
    $receipt['bridge_files'][$relative] = [
        'before_sha256' => hash_file('sha256', "{$backup}/site/{$relative}"),
        'after_sha256' => hash_file('sha256', "{$site}/{$relative}"),
    ];
}

$publish = static function (array $value) use ($siteReceipt, $backupReceipt): void {
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    writeExact($siteReceipt, $json, 0o400);
    writeExact($backupReceipt, $json, 0o400);
};

$publish($receipt);
[$baselineStatus, $baselineOutput] = runVerifier($verifier, $site);
if ($baselineStatus !== 0 || !str_contains($baselineOutput, 'INSTALL_VERIFY_PASS files=2')) {
    fail("baseline verification failed: {$baselineOutput}");
}

$duplicate = $receipt;
$duplicate['installed_files'][] = $duplicate['installed_files'][0];
$publish($duplicate);
expectFailure($verifier, $site, 'installed file receipt contains a duplicate path');

$outsideAllowlist = $receipt;
$outsideAllowlist['installed_files'][] = [
    'path' => 'config/app.php',
    'sha256' => str_repeat('a', 64),
];
$publish($outsideAllowlist);
expectFailure($verifier, $site, 'installed file is outside the release allowlist');

$otherPaymentAdapter = $receipt;
$otherPaymentAdapter['installed_files'][] = [
    'path' => 'app/Pay/Epusdt/Submit.php',
    'sha256' => str_repeat('c', 64),
];
$publish($otherPaymentAdapter);
expectFailure($verifier, $site, 'installed file is outside the release allowlist');

$generatedMutable = $receipt;
$generatedMutable['installed_files'][] = [
    'path' => 'local-extensions/registry.json',
    'sha256' => str_repeat('b', 64),
];
$publish($generatedMutable);
expectFailure($verifier, $site, 'installed file is outside the release allowlist');

$paymentRuntimeImmutable = $receipt;
$paymentRuntimeImmutable['installed_files'][] = [
    'path' => 'app/Pay/PikaBEpusdtAdapter/runtime.log',
    'sha256' => hash_file('sha256', "{$site}/app/Pay/PikaBEpusdtAdapter/runtime.log"),
];
$publish($paymentRuntimeImmutable);
expectFailure($verifier, $site, 'installed file is outside the release allowlist');

$extraBridge = $receipt;
$extraBridge['bridge_files']['kernel/Plugin.php'] = $extraBridge['bridge_files']['kernel/Kernel.php'];
$publish($extraBridge);
expectFailure($verifier, $site, 'bridge receipt is invalid');

$publish($receipt);
$bridgeHardlink = "{$site}/kernel/Kernel.hardlink";
link("{$site}/kernel/Kernel.php", $bridgeHardlink);
expectFailure($verifier, $site, 'bridge file must be a canonical regular file with exactly one hard link');
unlink($bridgeHardlink);

$backupBridgeHardlink = "{$backup}/site/kernel/Kernel.hardlink";
link("{$backup}/site/kernel/Kernel.php", $backupBridgeHardlink);
expectFailure($verifier, $site, 'bridge backup file must be a canonical regular file with exactly one hard link');
unlink($backupBridgeHardlink);

$bridgeMetadataPath = "{$site}/kernel/Kernel.php";
chown($bridgeMetadataPath, 65534);
chgrp($bridgeMetadataPath, 65534);
expectFailure($verifier, $site, 'bridge file owner or mode is unsafe');
chown($bridgeMetadataPath, 0);
chgrp($bridgeMetadataPath, 0);
foreach ([0o664, 0o600, 0o4644] as $unsafeBridgeMode) {
    chmod($bridgeMetadataPath, $unsafeBridgeMode);
    expectFailure($verifier, $site, 'bridge file owner or mode is unsafe');
    chmod($bridgeMetadataPath, 0o644);
}

$backupBridgeMetadataPath = "{$backup}/site/kernel/Kernel.php";
chmod($backupBridgeMetadataPath, 0o600);
expectFailure($verifier, $site, 'bridge backup file owner or mode is unsafe');
chmod($backupBridgeMetadataPath, 0o644);

chmod("{$site}/{$installedPath}", 0o664);
expectFailure($verifier, $site, 'installed file owner or mode is unsafe');
chmod("{$site}/{$installedPath}", 0o644);

$imageRuntime = "{$site}/assets/cache/general/image";
chmod($imageRuntime, 0o775);
expectFailure($verifier, $site, 'official runtime directory assets/cache/general/image must be a canonical');
chmod($imageRuntime, 0o755);

chmod("{$site}/app/Plugin/Fixture/Config.php", 0o444);
expectFailure($verifier, $site, 'official plugin tree file must remain owner-writable');
chmod("{$site}/app/Plugin/Fixture/Config.php", 0o644);

chmod("{$site}/app/Pay/Base.php", 0o664);
expectFailure($verifier, $site, 'official payment core file owner or mode is unsafe');
chmod("{$site}/app/Pay/Base.php", 0o644);

chown("{$site}/config/database.php", $webUid);
expectFailure($verifier, $site, 'official database configuration file owner or mode is unsafe');
chown("{$site}/config/database.php", 0);

chown($imageRuntime, 0);
chgrp($imageRuntime, 0);
expectFailure($verifier, $site, 'official runtime directory assets/cache/general/image must be a canonical');
chown($imageRuntime, $webUid);
chgrp($imageRuntime, $webGid);

$imageRuntimeReal = "{$imageRuntime}.real";
rename($imageRuntime, $imageRuntimeReal);
symlink($imageRuntimeReal, $imageRuntime);
expectFailure($verifier, $site, 'official runtime directory assets/cache/general/image must be a canonical');
unlink($imageRuntime);
rename($imageRuntimeReal, $imageRuntime);

chmod("{$site}/app/Pay/PikaBEpusdtAdapter/runtime.log", 0o644);
expectFailure($verifier, $site, 'payment adapter runtime log does not match the receipt web identity');
chmod("{$site}/app/Pay/PikaBEpusdtAdapter/runtime.log", 0o640);

chmod("{$site}/config/store.php", 0o644);
expectFailure($verifier, $site, 'official mutable config file mode must be 0600 or 0640');
chmod("{$site}/config/store.php", 0o600);
[$mutableModeStatus, $mutableModeOutput] = runVerifier($verifier, $site);
if ($mutableModeStatus !== 0) {
    fail("valid 0600 exact mutable config failed verification: {$mutableModeOutput}");
}
chmod("{$site}/config/store.php", 0o4640);
expectFailure($verifier, $site, 'official mutable config file mode must be 0600 or 0640');
chmod("{$site}/config/store.php", 0o640);

$settingHardlink = "{$site}/app/View/User/Theme/Pika/Setting.hardlink";
link("{$site}/app/View/User/Theme/Pika/Setting.php", $settingHardlink);
expectFailure($verifier, $site, 'Pika mutable setting must be a canonical regular file with exactly one hard link');
unlink($settingHardlink);
chmod("{$site}/app/View/User/Theme/Pika/Setting.php", 0o4640);
expectFailure($verifier, $site, 'Pika mutable setting does not match the receipt web identity');
chmod("{$site}/app/View/User/Theme/Pika/Setting.php", 0o640);

$runtimeLogHardlink = "{$site}/app/Pay/PikaBEpusdtAdapter/runtime.hardlink";
link("{$site}/app/Pay/PikaBEpusdtAdapter/runtime.log", $runtimeLogHardlink);
expectFailure($verifier, $site, 'payment adapter runtime log must be a canonical regular file with exactly one hard link');
unlink($runtimeLogHardlink);
chmod("{$site}/app/Pay/PikaBEpusdtAdapter/runtime.log", 0o4640);
expectFailure($verifier, $site, 'payment adapter runtime log does not match the receipt web identity');
chmod("{$site}/app/Pay/PikaBEpusdtAdapter/runtime.log", 0o640);

chmod($secrets, 0o755);
expectFailure($verifier, $site, 'payment secret directory does not match the receipt web identity');
chmod($secrets, 0o750);

writeExact("{$secrets}/bepusdt-token", 'fixture-token-value', 0o640);
chown("{$secrets}/bepusdt-token", 0);
chgrp("{$secrets}/bepusdt-token", $webGid);
$publish($receipt);
[$secretStatus, $secretOutput] = runVerifier($verifier, $site);
if ($secretStatus !== 0) {
    fail("valid optional payment secret failed verification: {$secretOutput}");
}
chmod("{$secrets}/bepusdt-token", 0o644);
expectFailure($verifier, $site, 'payment secret file is unsafe: bepusdt-token');
chmod("{$secrets}/bepusdt-token", 0o640);
$hardlink = "{$secrets}/bepusdt-token-hardlink";
link("{$secrets}/bepusdt-token", $hardlink);
expectFailure($verifier, $site, 'payment secret file is unsafe: bepusdt-token');
unlink($hardlink);
unlink("{$secrets}/bepusdt-token");

chmod("{$site}/local-extensions", 0o775);
expectFailure($verifier, $site, 'installed file parent is replaceable by a non-root identity');
chmod("{$site}/local-extensions", 0o755);

chmod($siteReceipt, 0o644);
expectFailure($verifier, $site, 'protected site receipt owner or mode is unsafe');
chmod($siteReceipt, 0o400);

chmod($backup, 0o755);
expectFailure($verifier, $site, 'install backup root owner or mode is unsafe');
chmod($backup, 0o700);

register_shutdown_function(static function () use ($site, $backup, $stateSite, $identity): void {
    removeTree("/opt/{$identity}");
    removeTree($backup);
    removeTree($stateSite);
});

fwrite(STDOUT, "PASS install receipt integrity, allowlist, ownership and mode gates\n");
