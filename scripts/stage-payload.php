<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

function decodeJson(string $path): array
{
    if (!is_file($path) || is_link($path)) {
        fail("missing manifest: {$path}");
    }
    $bytes = file_get_contents($path);
    if (!is_string($bytes) || strlen($bytes) > 262144) {
        fail("manifest is unreadable or too large: {$path}");
    }
    try {
        $value = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        fail("invalid manifest: {$path}");
    }
    if (!is_array($value)) {
        fail("manifest root must be an object: {$path}");
    }
    return $value;
}

function assertRelative(string $path): void
{
    if ($path === '' || $path[0] === '/' || str_contains($path, "\0") || str_contains($path, '\\')) {
        fail("unsafe relative path: {$path}");
    }
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            fail("unsafe relative path: {$path}");
        }
    }
}

function copyFileStrict(string $source, string $target): void
{
    if (!is_file($source) || is_link($source)) {
        fail("source is not a regular file: {$source}");
    }
    $size = filesize($source);
    if (!is_int($size) || $size > 32 * 1024 * 1024) {
        fail("source file is too large: {$source}");
    }
    $dir = dirname($target);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        fail("unable to create stage directory: {$dir}");
    }
    if (!copy($source, $target) || !chmod($target, 0644)) {
        fail("unable to stage file: {$source}");
    }
}

function copyTreeStrict(string $source, string $target, array $exclude = []): int
{
    $root = realpath($source);
    if ($root === false || !is_dir($root) || is_link($source)) {
        fail("invalid source tree: {$source}");
    }
    $count = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $entry) {
        if ($entry->isLink() || !$entry->isFile()) {
            fail("payload contains a non-regular file: {$entry->getPathname()}");
        }
        $relative = substr($entry->getPathname(), strlen($root) + 1);
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
        assertRelative($relative);
        if (in_array($relative, $exclude, true)) {
            continue;
        }
        copyFileStrict($entry->getPathname(), rtrim($target, '/') . '/' . $relative);
        $count++;
        if ($count > 5000) {
            fail("payload file limit exceeded: {$source}");
        }
    }
    return $count;
}

$options = getopt('', ['repo-root:', 'stage-root:', 'mode:']);
$repoArg = $options['repo-root'] ?? null;
$stageArg = $options['stage-root'] ?? null;
$mode = $options['mode'] ?? null;
if (!is_string($repoArg) || !is_string($stageArg) || !is_string($mode)
    || !in_array($mode, ['install', 'update'], true)) {
    fail('usage: stage-payload.php --repo-root PATH --stage-root PATH --mode install|update');
}
$repo = realpath($repoArg);
$stage = realpath($stageArg);
if ($repo === false || $stage === false || !is_dir($repo) || !is_dir($stage)) {
    fail('invalid repository or stage root');
}

$release = decodeJson($repo . '/release.json');
if (($release['schema'] ?? null) !== 1) {
    fail('unsupported release manifest schema');
}

$total = copyTreeStrict($repo . '/manager/site', $stage);

foreach (($release['extensions'] ?? []) as $id) {
    if (!is_string($id) || preg_match('/^[A-Z][A-Za-z0-9]{1,63}$/D', $id) !== 1) {
        fail('invalid extension id');
    }
    $source = $repo . "/extensions/{$id}";
    $manifest = decodeJson($source . '/local-extension.json');
    if (($manifest['id'] ?? null) !== $id || ($manifest['type'] ?? null) !== 'plugin') {
        fail("extension manifest identity mismatch: {$id}");
    }
    $total += copyTreeStrict($source, $stage . "/local-extensions/extensions/{$id}", [
        'runtime.log',
    ]);

    foreach (($manifest['assets'] ?? []) as $asset) {
        if (!is_array($asset) || !is_string($asset['source'] ?? null) || !is_string($asset['target'] ?? null)) {
            fail("invalid asset declaration: {$id}");
        }
        $assetSource = $asset['source'];
        $assetTarget = $asset['target'];
        assertRelative($assetSource);
        assertRelative($assetTarget);
        $allowedTarget = "assets/local-extensions/{$id}/";
        if (!str_starts_with(rtrim($assetTarget, '/') . '/', $allowedTarget)) {
            fail("asset target escaped extension namespace: {$id}");
        }
        $total += copyTreeStrict($source . '/' . rtrim($assetSource, '/'), $stage . '/' . rtrim($assetTarget, '/'));
    }
}

foreach (($release['themes'] ?? []) as $id) {
    if (!is_string($id) || preg_match('/^[A-Z][A-Za-z0-9]{1,63}$/D', $id) !== 1) {
        fail('invalid theme id');
    }
    $source = $repo . "/themes/{$id}";
    $manifest = decodeJson($source . '/theme.json');
    if (($manifest['id'] ?? null) !== $id || ($manifest['type'] ?? null) !== 'theme') {
        fail("theme manifest identity mismatch: {$id}");
    }
    $preserve = $manifest['preserve'] ?? [];
    $exclude = $manifest['exclude'] ?? [];
    if (!is_array($preserve) || !is_array($exclude)) {
        fail("invalid theme file policy: {$id}");
    }
    $preservePolicy = [];
    $excludePolicy = [];
    foreach ($preserve as $relative) {
        if (!is_string($relative)) {
            fail("invalid theme file policy entry: {$id}");
        }
        assertRelative($relative);
        $preservePolicy[] = $relative;
    }
    foreach ($exclude as $relative) {
        if (!is_string($relative)) {
            fail("invalid theme file policy entry: {$id}");
        }
        assertRelative($relative);
        $excludePolicy[] = $relative;
    }
    if (array_intersect($preservePolicy, $excludePolicy) !== []) {
        fail("theme preserve and exclude policies overlap: {$id}");
    }

    // Preserve files are defaults: install them once, but never stage them for
    // an update. Excluded files are never part of a public payload.
    $skip = $excludePolicy;
    if ($mode === 'update') {
        $skip = array_merge($skip, $preservePolicy);
    }
    $total += copyTreeStrict($source, $stage . "/app/View/User/Theme/{$id}", $skip);
}

foreach (($release['payment_adapters'] ?? []) as $id) {
    if (!is_string($id) || preg_match('/^[A-Z][A-Za-z0-9]{1,63}$/D', $id) !== 1) {
        fail('invalid payment adapter id');
    }
    $source = $repo . "/payment-adapters/{$id}";
    $manifest = decodeJson($source . '/payment-adapter.json');
    if (($manifest['schema'] ?? null) !== 1
        || ($manifest['id'] ?? null) !== $id
        || ($manifest['type'] ?? null) !== 'payment-adapter') {
        fail("payment adapter manifest identity mismatch: {$id}");
    }
    $total += copyTreeStrict($source, $stage . "/app/Pay/{$id}", [
        'payment-adapter.json',
        'README.md',
        'runtime.log',
    ]);
}

fwrite(STDOUT, "STAGE_PASS files={$total}\n");
