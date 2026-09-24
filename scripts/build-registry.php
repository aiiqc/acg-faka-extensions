<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

function decodeJsonFile(string $path): array
{
    if (!is_file($path) || is_link($path)) {
        fail("invalid JSON file: {$path}");
    }
    $bytes = file_get_contents($path);
    if (!is_string($bytes) || strlen($bytes) > 262144) {
        fail("JSON file is unreadable or too large: {$path}");
    }
    try {
        $value = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        fail("invalid JSON syntax: {$path}");
    }
    if (!is_array($value)) {
        fail("JSON root must be an object: {$path}");
    }
    return $value;
}

function assertId(mixed $id, string $expected): void
{
    if (!is_string($id) || $id !== $expected || preg_match('/^[A-Z][A-Za-z0-9]{1,63}$/D', $id) !== 1) {
        fail("invalid component id: {$expected}");
    }
}

function relativeManifest(string $siteRoot, string $absolute): string
{
    $sitePrefix = rtrim($siteRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $real = realpath($absolute);
    if ($real === false || !str_starts_with($real, $sitePrefix)) {
        fail("manifest escaped site root: {$absolute}");
    }
    return substr($real, strlen($sitePrefix));
}

$options = getopt('', ['site-root:', 'release:', 'output:']);
$siteArg = $options['site-root'] ?? null;
$releasePath = $options['release'] ?? null;
$outputArg = $options['output'] ?? null;
if (!is_string($siteArg) || !is_string($releasePath) || !is_string($outputArg)) {
    fail('usage: build-registry.php --site-root PATH --release release.json --output registry.json');
}

$siteRoot = realpath($siteArg);
if ($siteRoot === false || $siteRoot === DIRECTORY_SEPARATOR || !is_dir($siteRoot)) {
    fail('invalid site root');
}
$release = decodeJsonFile($releasePath);
if (($release['schema'] ?? null) !== 1) {
    fail('unsupported release schema');
}

$registry = ['schema' => 1, 'extensions' => [], 'themes' => []];

foreach (($release['extensions'] ?? []) as $id) {
    if (!is_string($id)) {
        fail('extension id must be a string');
    }
    assertId($id, $id);
    $manifestPath = $siteRoot . "/local-extensions/extensions/{$id}/local-extension.json";
    $manifest = decodeJsonFile($manifestPath);
    assertId($manifest['id'] ?? null, $id);
    if (($manifest['schema'] ?? null) !== 1 || ($manifest['type'] ?? null) !== 'plugin') {
        fail("invalid extension manifest: {$id}");
    }
    $registry['extensions'][] = [
        'id' => $id,
        'manifest' => relativeManifest($siteRoot . '/local-extensions', $manifestPath),
        'manifest_sha256' => hash_file('sha256', $manifestPath),
    ];
}

foreach (($release['themes'] ?? []) as $id) {
    if (!is_string($id)) {
        fail('theme id must be a string');
    }
    assertId($id, $id);
    $manifestPath = $siteRoot . "/app/View/User/Theme/{$id}/theme.json";
    $manifest = decodeJsonFile($manifestPath);
    assertId($manifest['id'] ?? null, $id);
    if (($manifest['schema'] ?? null) !== 1 || ($manifest['type'] ?? null) !== 'theme') {
        fail("invalid theme manifest: {$id}");
    }
    $registry['themes'][] = [
        'id' => $id,
        'manifest' => relativeManifest($siteRoot, $manifestPath),
        'manifest_sha256' => hash_file('sha256', $manifestPath),
    ];
}

$outputDir = dirname($outputArg);
if (!is_dir($outputDir) || is_link($outputDir)) {
    fail('registry output directory is invalid');
}
$tmp = tempnam($outputDir, '.registry.');
if ($tmp === false) {
    fail('unable to create registry temporary file');
}
$json = json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
if (file_put_contents($tmp, $json, LOCK_EX) !== strlen($json) || !chmod($tmp, 0644) || !rename($tmp, $outputArg)) {
    @unlink($tmp);
    fail('unable to publish registry');
}

fwrite(STDOUT, "REGISTRY_PASS extensions=" . count($registry['extensions']) . " themes=" . count($registry['themes']) . "\n");
