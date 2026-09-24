<?php
declare(strict_types=1);

use Pika\LocalExtensions\Manager\PathGuard;
use Pika\LocalExtensions\Manager\StateStore as ManagerState;
use Pika\LocalExtensions\PikaCatalogHub\Service\JobService;
use Pika\LocalExtensions\PikaCatalogHub\Service\JobWorker;
use Pika\LocalExtensions\PikaSupplySync\Service\LocalPath;

const CATALOG_EXTENSION_ID = 'PikaCatalogHub';
const SUPPLY_EXTENSION_ID = 'PikaSupplySync';

$writeResult = static function (array $result, int $exitCode): never {
    fwrite(STDOUT, json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ) . PHP_EOL);
    exit($exitCode);
};

$parseArguments = static function (array $tokens): array {
    $parsed = [];
    foreach ($tokens as $token) {
        if (!is_string($token) || !str_starts_with($token, '--') || $token === '--') {
            throw new RuntimeException('unsupported argument');
        }
        if ($token === '--help') {
            if (isset($parsed['help'])) {
                throw new RuntimeException('duplicate argument');
            }
            $parsed['help'] = true;
            continue;
        }
        $separator = strpos($token, '=');
        if ($separator === false) {
            throw new RuntimeException('missing argument value');
        }
        $name = substr($token, 2, $separator - 2);
        $value = substr($token, $separator + 1);
        if (!in_array($name, ['root', 'batch'], true) || $value === '' || isset($parsed[$name])) {
            throw new RuntimeException('invalid argument');
        }
        $parsed[$name] = $value;
    }
    return $parsed;
};

$assertWorkerLockPath = static function (string $path, array $metadata): void {
    if (is_link($path)
        || ($metadata['mode'] & 0170000) !== 0100000
        || ($metadata['mode'] & 0777) !== 0600
        || (int)$metadata['uid'] !== PathGuard::runtimeOwner()
        || (int)$metadata['nlink'] !== 1) {
        throw new RuntimeException('worker lock unsafe');
    }
};

$assertWorkerLockHandle = static function ($handle, string $path, string $message): void {
    clearstatcache(true, $path);
    $metadata = fstat($handle);
    $pathMetadata = lstat($path);
    if (!is_array($metadata)
        || !is_array($pathMetadata)
        || ($metadata['mode'] & 0170000) !== 0100000
        || ($metadata['mode'] & 0777) !== 0600
        || (int)$metadata['uid'] !== PathGuard::runtimeOwner()
        || (int)$metadata['nlink'] !== 1
        || $metadata['dev'] !== $pathMetadata['dev']
        || $metadata['ino'] !== $pathMetadata['ino']
        || is_link($path)) {
        throw new RuntimeException($message);
    }
};

$openWorkerLock = static function (string $path) use ($assertWorkerLockPath) {
    clearstatcache(true, $path);
    $pathMetadata = @lstat($path);
    if (is_array($pathMetadata)) {
        $assertWorkerLockPath($path, $pathMetadata);
        $handle = @fopen($path, 'r+b');
        if ($handle === false) {
            throw new RuntimeException('worker lock unavailable');
        }
        return $handle;
    }

    $previousUmask = umask(0177);
    try {
        $handle = @fopen($path, 'x+b');
    } finally {
        umask($previousUmask);
    }
    if ($handle !== false) {
        return $handle;
    }

    // A concurrent worker may have created the lock after lstat(). Reuse it
    // only after applying the complete existing-file contract.
    clearstatcache(true, $path);
    $pathMetadata = @lstat($path);
    if (!is_array($pathMetadata)) {
        throw new RuntimeException('worker lock unavailable');
    }
    $assertWorkerLockPath($path, $pathMetadata);
    $handle = @fopen($path, 'r+b');
    if ($handle === false) {
        throw new RuntimeException('worker lock unavailable');
    }
    return $handle;
};

try {
    $arguments = $parseArguments(array_slice($_SERVER['argv'] ?? [], 1));
    if (isset($arguments['help'])) {
        fwrite(STDOUT, "Usage: php bin/worker.php [--root=/path/to/acg-faka] [--batch=20]\n");
        exit(0);
    }
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        throw new RuntimeException('worker must not run as root');
    }

    $extensionRoot = dirname(__DIR__);
    $candidate = $arguments['root'] ?? getenv('ACG_FAKA_ROOT') ?: dirname($extensionRoot, 3);
    if (!is_string($candidate) || $candidate === '') {
        throw new RuntimeException('site root missing');
    }
    $siteRoot = realpath($candidate);
    if ($siteRoot === false
        || $siteRoot !== rtrim($candidate, '/')
        || !is_dir($siteRoot)
        || is_link($siteRoot)
        || !is_file($siteRoot . '/kernel/Console.php')
        || is_link($siteRoot . '/kernel/Console.php')
        || !is_file($siteRoot . '/config/app.php')
        || is_link($siteRoot . '/config/app.php')) {
        throw new RuntimeException('site root invalid');
    }
    $managerBootstrap = $siteRoot . '/local-extensions/bootstrap.php';
    $supplyBootstrap = $siteRoot . '/local-extensions/extensions/PikaSupplySync/bootstrap.php';
    if (!is_file($managerBootstrap) || is_link($managerBootstrap)
        || !is_file($supplyBootstrap) || is_link($supplyBootstrap)
        || !is_file($extensionRoot . '/bootstrap.php') || is_link($extensionRoot . '/bootstrap.php')) {
        throw new RuntimeException('extension bootstrap invalid');
    }

    require $siteRoot . '/kernel/Console.php';
    require $managerBootstrap;
    require $supplyBootstrap;
    require $extensionRoot . '/bootstrap.php';

    if (!ManagerState::isEnabled(CATALOG_EXTENSION_ID) || !ManagerState::isEnabled(SUPPLY_EXTENSION_ID)) {
        $writeResult([
            'status' => 'disabled',
            'task_hash' => null,
            'counts' => [
                'items' => 0,
                'categories' => 0,
                'processed' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'skipped' => 0,
            ],
            'error_code' => null,
        ], 0);
    }

    $batch = $arguments['batch'] ?? '20';
    if (!is_string($batch) || preg_match('/^(?:[1-9]|1\d|20)$/D', $batch) !== 1) {
        throw new RuntimeException('batch invalid');
    }

    $directory = LocalPath::directory(
        'runtime/local-extensions/extensions/PikaCatalogHub',
        0o700,
    );
    $lockPath = $directory . '/worker.run.lock';
    $lock = $openWorkerLock($lockPath);
    try {
        $assertWorkerLockHandle($lock, $lockPath, 'worker lock unsafe');
    } catch (Throwable $exception) {
        fclose($lock);
        throw $exception;
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        $writeResult([
            'status' => 'busy',
            'task_hash' => null,
            'counts' => [
                'items' => 0,
                'categories' => 0,
                'processed' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'skipped' => 0,
            ],
            'error_code' => null,
        ], 0);
    }
    try {
        $assertWorkerLockHandle($lock, $lockPath, 'worker lock changed while acquiring it');
    } catch (Throwable $exception) {
        flock($lock, LOCK_UN);
        fclose($lock);
        throw $exception;
    }

    try {
        $jobs = new JobService();
        $jobs->drainSnapshotGc();
        $jobs->recoverInterrupted();
        $result = (new JobWorker($jobs))->runOne((int)$batch);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    // A fully scanned import with isolated items is a reported business result,
    // not a broken worker. All fatal and source-limit failures still exit 1.
    $finishedWithIssues = $result['status'] === 'failed'
        && $result['error_code'] === 'IMPORT_FINISHED_WITH_ISSUES'
        && $result['counts']['processed'] === $result['counts']['items']
        && $result['counts']['failed'] > 0
        && $result['counts']['succeeded'] + $result['counts']['failed'] + $result['counts']['skipped']
            === $result['counts']['processed'];
    $writeResult($result, $result['status'] === 'failed' && !$finishedWithIssues ? 1 : 0);
} catch (Throwable) {
    $writeResult([
        'status' => 'failed',
        'task_hash' => null,
        'counts' => [
            'items' => 0,
            'categories' => 0,
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'skipped' => 0,
        ],
        'error_code' => 'WORKER_START_FAILED',
    ], 1);
}
