<?php
declare(strict_types=1);

use App\Model\Shared;
use Pika\LocalExtensions\Manager\PathGuard;
use Pika\LocalExtensions\Manager\StateStore as ManagerState;
use Pika\LocalExtensions\PikaSupplySync\Service\CategoryIcons;
use Pika\LocalExtensions\PikaSupplySync\Service\ImageCache;
use Pika\LocalExtensions\PikaSupplySync\Service\PlannedCategoryMapper;
use Pika\LocalExtensions\PikaSupplySync\Service\RunBudget;
use Pika\LocalExtensions\PikaSupplySync\Service\SafeHttpClient;
use Pika\LocalExtensions\PikaSupplySync\Service\SharedGateway;
use Pika\LocalExtensions\PikaSupplySync\Service\SourceLock;
use Pika\LocalExtensions\PikaSupplySync\Service\SourcePolicy;

// This trusted CLI never prints service responses, paths or exception text.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
const CATEGORY_ICONS_MAX_FILE_BYTES = 131072;
$receiptSha = null;
$receiptSaved = false;

$writeResult = static function (array $result, int $exitCode): never {
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL);
    exit($exitCode);
};

$safeCounts = static function (array $plan): array {
    $requested = count($plan['targets']);
    $manual = $plan['skipped']['manual'] ?? null;
    $default = $plan['skipped']['upstream_default'] ?? null;
    if ($requested < 1 || $requested > 16 || !is_int($manual) || !is_int($default)
        || $manual < 0 || $default < 0 || $manual + $default > $requested) {
        throw new RuntimeException('invalid result counts');
    }
    return [
        'requested' => $requested,
        'eligible' => $requested - $manual - $default,
        'skipped_manual' => $manual,
        'skipped_upstream_default' => $default,
    ];
};

$parseArguments = static function (array $tokens): array {
    $arguments = [];
    foreach ($tokens as $token) {
        if ($token === '--help' && count($tokens) === 1) {
            return ['help' => true];
        }
        if (!is_string($token) || !str_starts_with($token, '--') || !str_contains($token, '=')) {
            throw new RuntimeException('invalid argument');
        }
        [$name, $value] = explode('=', substr($token, 2), 2);
        if (!in_array($name, ['mode', 'root', 'source', 'ids', 'plan-file', 'plan-sha', 'receipt-file', 'receipt-sha'], true)
            || $value === '' || array_key_exists($name, $arguments)) {
            throw new RuntimeException('invalid argument');
        }
        $arguments[$name] = $value;
    }
    $required = match ($arguments['mode'] ?? '') {
        'preview' => ['mode', 'root', 'source', 'ids', 'plan-file'],
        'apply' => ['mode', 'root', 'source', 'plan-file', 'plan-sha', 'receipt-file'],
        'rollback' => ['mode', 'root', 'source', 'receipt-file', 'receipt-sha'],
        default => throw new RuntimeException('invalid mode'),
    };
    $keys = array_keys($arguments);
    sort($keys, SORT_STRING);
    sort($required, SORT_STRING);
    if ($keys !== $required
        || preg_match('/^[1-9][0-9]{0,9}$/D', $arguments['source']) !== 1
        || (int)$arguments['source'] > 2147483647) {
        throw new RuntimeException('invalid arguments');
    }
    foreach (['plan-sha', 'receipt-sha'] as $key) {
        if (isset($arguments[$key]) && preg_match('/^[a-f0-9]{64}$/D', $arguments[$key]) !== 1) {
            throw new RuntimeException('invalid hash');
        }
    }
    if (isset($arguments['ids'])) {
        $ids = explode(',', $arguments['ids']);
        $previous = 0;
        if (count($ids) > 16) {
            throw new RuntimeException('too many categories');
        }
        foreach ($ids as $id) {
            if (preg_match('/^[1-9][0-9]{0,9}$/D', $id) !== 1
                || (int)$id > 2147483647 || (int)$id <= $previous) {
                throw new RuntimeException('category ids must be canonical and increasing');
            }
            $previous = (int)$id;
        }
        $arguments['ids'] = array_map('intval', $ids);
    }
    return $arguments;
};

$assertPrivateParent = static function (string $path, string $siteRoot, int $owner): void {
    if (strlen($path) > 4096 || !str_starts_with($path, '/')
        || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
        || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', basename($path)) !== 1) {
        throw new RuntimeException('private file path invalid');
    }
    $parent = dirname($path);
    clearstatcache(true, $parent);
    $metadata = @lstat($parent);
    if ($parent === '/' || realpath($parent) !== $parent || is_link($parent)
        || $parent === $siteRoot || str_starts_with($parent, $siteRoot . '/')
        || !is_array($metadata) || ($metadata['mode'] & 0170000) !== 0040000
        || ($metadata['mode'] & 07777) !== 0700 || (int)$metadata['uid'] !== $owner) {
        throw new RuntimeException('private file parent unsafe');
    }
};

$assertFileIdentity = static function ($handle, string $path, int $owner): array {
    clearstatcache(true, $path);
    $metadata = fstat($handle);
    $pathMetadata = @lstat($path);
    if (!is_array($metadata) || !is_array($pathMetadata) || is_link($path)
        || realpath($path) !== $path
        || ($metadata['mode'] & 0170000) !== 0100000
        || ($pathMetadata['mode'] & 0170000) !== 0100000
        || ($metadata['mode'] & 07777) !== 0600 || (int)$metadata['uid'] !== $owner
        || (int)$metadata['nlink'] !== 1 || (int)$pathMetadata['nlink'] !== 1
        || $metadata['dev'] !== $pathMetadata['dev'] || $metadata['ino'] !== $pathMetadata['ino']) {
        throw new RuntimeException('private file identity unsafe');
    }
    return $metadata;
};

$assertNewFile = static function (string $path, string $siteRoot, int $owner) use ($assertPrivateParent): void {
    $assertPrivateParent($path, $siteRoot, $owner);
    clearstatcache(true, $path);
    if (@lstat($path) !== false) {
        throw new RuntimeException('private output already exists');
    }
};

$readFrozen = static function (string $path, string $sha, string $siteRoot, int $owner) use (
    $assertPrivateParent,
    $assertFileIdentity,
): array {
    $assertPrivateParent($path, $siteRoot, $owner);
    clearstatcache(true, $path);
    $metadata = @lstat($path);
    if (!is_array($metadata) || is_link($path) || ($metadata['mode'] & 0170000) !== 0100000
        || ($metadata['mode'] & 07777) !== 0600 || (int)$metadata['uid'] !== $owner
        || (int)$metadata['nlink'] !== 1 || $metadata['size'] < 2
        || $metadata['size'] > CATEGORY_ICONS_MAX_FILE_BYTES) {
        throw new RuntimeException('private input unsafe');
    }
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('private input unavailable');
    }
    try {
        $before = $assertFileIdentity($handle, $path, $owner);
        $bytes = stream_get_contents($handle, CATEGORY_ICONS_MAX_FILE_BYTES + 1);
        $after = $assertFileIdentity($handle, $path, $owner);
        $assertPrivateParent($path, $siteRoot, $owner);
        if (!is_string($bytes) || strlen($bytes) !== $before['size']
            || $before['size'] !== $after['size'] || strlen($bytes) > CATEGORY_ICONS_MAX_FILE_BYTES
            || !hash_equals($sha, hash('sha256', $bytes))) {
            throw new RuntimeException('private input changed or hash mismatch');
        }
        $decoded = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('private input must be an object');
        }
        return $decoded;
    } finally {
        fclose($handle);
    }
};

$writeFrozen = static function (string $path, array $value, string $siteRoot, int $owner) use (
    $assertNewFile,
    $assertPrivateParent,
    $assertFileIdentity,
): string {
    $bytes = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
    if (strlen($bytes) > CATEGORY_ICONS_MAX_FILE_BYTES) {
        throw new RuntimeException('private output too large');
    }
    $assertNewFile($path, $siteRoot, $owner);
    $previousUmask = umask(0177);
    try {
        $handle = @fopen($path, 'x+b');
    } finally {
        umask($previousUmask);
    }
    if ($handle === false) {
        throw new RuntimeException('private output unavailable');
    }
    try {
        $assertFileIdentity($handle, $path, $owner);
        $written = 0;
        while ($written < strlen($bytes)) {
            $count = fwrite($handle, substr($bytes, $written));
            if (!is_int($count) || $count < 1) {
                throw new RuntimeException('private output incomplete');
            }
            $written += $count;
        }
        if (!fflush($handle) || !fsync($handle)) {
            throw new RuntimeException('private output not durable');
        }
        $metadata = $assertFileIdentity($handle, $path, $owner);
        $assertPrivateParent($path, $siteRoot, $owner);
        if ($metadata['size'] !== strlen($bytes)) {
            throw new RuntimeException('private output length mismatch');
        }
        // Persist the exclusive directory entry as well as the file contents.
        // Linux supports fsync() on a read-only directory handle.
        $parent = dirname($path);
        $directory = @fopen($parent, 'rb');
        if ($directory === false) {
            throw new RuntimeException('private output parent unavailable');
        }
        try {
            $directoryMetadata = fstat($directory);
            $parentMetadata = @lstat($parent);
            if (!is_array($directoryMetadata) || !is_array($parentMetadata)
                || ($directoryMetadata['mode'] & 0170000) !== 0040000
                || $directoryMetadata['dev'] !== $parentMetadata['dev']
                || $directoryMetadata['ino'] !== $parentMetadata['ino']
                || !fsync($directory)) {
                throw new RuntimeException('private output parent not durable');
            }
            $assertPrivateParent($path, $siteRoot, $owner);
            $assertFileIdentity($handle, $path, $owner);
        } finally {
            fclose($directory);
        }
        return hash('sha256', $bytes);
    } finally {
        // Retain even an incomplete file for reconciliation; never retry or delete it.
        fclose($handle);
    }
};

try {
    $arguments = $parseArguments(array_slice($_SERVER['argv'] ?? [], 1));
    if (isset($arguments['help'])) {
        fwrite(STDOUT, "Usage: php bin/category-icons.php --mode=preview --root=/canonical/site --source=ID --ids=ASCENDING_CSV --plan-file=/private/plan.json\n"
            . "       php bin/category-icons.php --mode=apply --root=/canonical/site --source=ID --plan-file=/private/plan.json --plan-sha=SHA256 --receipt-file=/private/receipt.json\n"
            . "       php bin/category-icons.php --mode=rollback --root=/canonical/site --source=ID --receipt-file=/private/receipt.json --receipt-sha=SHA256\n"
            . "At most 16 IDs; non-root runtime owner; private parent 0700 outside site; files 0600. No automatic retry or cache deletion.\n");
        exit(0);
    }
    if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() === 0) {
        throw new RuntimeException('CLI requires a non-root runtime owner');
    }
    $siteRoot = realpath($arguments['root']);
    if ($siteRoot === false || $siteRoot === '/' || $siteRoot !== $arguments['root'] || !is_dir($siteRoot)
        || is_link($siteRoot) || !is_file($siteRoot . '/kernel/Console.php')
        || is_link($siteRoot . '/kernel/Console.php') || !is_file($siteRoot . '/config/app.php')
        || is_link($siteRoot . '/config/app.php')) {
        throw new RuntimeException('site root invalid');
    }
    $extensionRoot = $siteRoot . '/local-extensions/extensions/PikaCatalogHub';
    $managerBootstrap = $siteRoot . '/local-extensions/bootstrap.php';
    $supplyBootstrap = $siteRoot . '/local-extensions/extensions/PikaSupplySync/bootstrap.php';
    if (realpath(dirname(__DIR__)) !== $extensionRoot
        || !is_file($managerBootstrap) || is_link($managerBootstrap)
        || !is_file($supplyBootstrap) || is_link($supplyBootstrap)
        || !is_file($extensionRoot . '/bootstrap.php') || is_link($extensionRoot . '/bootstrap.php')) {
        throw new RuntimeException('installed extension bootstrap invalid');
    }
    require $siteRoot . '/kernel/Console.php';
    require $managerBootstrap;
    require $supplyBootstrap;
    require $extensionRoot . '/bootstrap.php';
    ini_set('display_errors', '0');
    ini_set('log_errors', '0');
    $owner = PathGuard::runtimeOwner();
    if (posix_geteuid() !== $owner || !ManagerState::isEnabled('PikaCatalogHub')) {
        throw new RuntimeException('runtime owner or catalog state invalid');
    }

    $mode = $arguments['mode'];
    $sourceId = (int)$arguments['source'];
    $frozen = null;
    if ($mode === 'preview') {
        $assertNewFile($arguments['plan-file'], $siteRoot, $owner);
    } elseif ($mode === 'apply') {
        $frozen = $readFrozen($arguments['plan-file'], $arguments['plan-sha'], $siteRoot, $owner);
        $assertNewFile($arguments['receipt-file'], $siteRoot, $owner);
    } else {
        $frozen = $readFrozen($arguments['receipt-file'], $arguments['receipt-sha'], $siteRoot, $owner);
        $receiptSha = $arguments['receipt-sha'];
        $receiptSaved = true;
    }

    $lock = new SourceLock();
    if (!$lock->acquire($sourceId)) {
        $writeResult(['status' => 'busy', 'error_code' => 'CATEGORY_ICONS_SOURCE_BUSY'], 1);
    }
    $budget = new RunBudget();
    $sourceStarted = false;
    try {
        $budget->beginSource($sourceId);
        $sourceStarted = true;
        $source = Shared::query()->find($sourceId);
        if (!$source instanceof Shared || (int)$source->id !== $sourceId) {
            throw new RuntimeException('source unavailable');
        }
        $policy = new SourcePolicy();
        $http = new SafeHttpClient($policy, null, $budget);
        $service = new CategoryIcons(
            new SharedGateway($http, $policy),
            new ImageCache($http, $budget),
            new PlannedCategoryMapper(),
        );
        if ($mode === 'preview') {
            $plan = $service->preview($source, $arguments['ids']);
            $counts = $safeCounts($plan);
            $sha = $writeFrozen($arguments['plan-file'], $plan, $siteRoot, $owner);
            $result = ['status' => 'preview', 'counts' => $counts, 'plan_sha256' => $sha];
        } else {
            if ($mode === 'apply') {
                $frozen = $service->prepare($source, $frozen);
                // A durable, exclusively created rollback receipt precedes every DB write.
                $receiptSha = $writeFrozen($arguments['receipt-file'], $frozen, $siteRoot, $owner);
                $receiptSaved = true;
            }
            $applied = $service->apply($source, $frozen, $mode === 'rollback');
            $counts = $safeCounts($frozen['plan']);
            if (!in_array($applied['status'] ?? null, [
                'applied', 'already_applied', 'rolled_back', 'already_rolled_back', 'no_changes',
            ], true) || !is_int($applied['changed'] ?? null) || !is_int($applied['total'] ?? null)
                || $applied['changed'] < 0 || $applied['changed'] > $applied['total']
                || $applied['total'] !== $counts['eligible']) {
                throw new RuntimeException('invalid apply result');
            }
            $counts['changed'] = $applied['changed'];
            $counts['total'] = $applied['total'];
            $result = ['status' => $applied['status'], 'counts' => $counts,
                'receipt_sha256' => $receiptSha, 'receipt_saved' => true];
        }
    } finally {
        if ($sourceStarted) {
            $budget->endSource();
        }
        $lock->release();
    }
    $writeResult($result, 0);
} catch (Throwable) {
    fwrite(STDERR, "CATEGORY_ICONS_FAILED\n");
    $writeResult([
        'status' => 'failed',
        'error_code' => 'CATEGORY_ICONS_FAILED',
        'receipt_saved' => $receiptSaved,
        'receipt_sha256' => $receiptSha,
    ], 1);
}
