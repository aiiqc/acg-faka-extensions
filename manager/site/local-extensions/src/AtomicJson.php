<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\Manager;

final class AtomicJson
{
    /** @return array<string,mixed> */
    public static function read(string $path, array $default): array
    {
        self::assertRuntimePath($path);
        if (is_link($path)) {
            throw new \RuntimeException('Local extension state path is unsafe.');
        }
        if (!file_exists($path)) {
            return $default;
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open local extension state.');
        }
        try {
            $metadata = self::assertSensitiveHandle($handle, $path, 'state');
            if ($metadata['size'] > 1048576) {
                throw new \RuntimeException('Local extension state is too large.');
            }
            $contents = stream_get_contents($handle);
            if (!is_string($contents)) {
                throw new \RuntimeException('Unable to read local extension state.');
            }
        } finally {
            fclose($handle);
        }
        try {
            $decoded = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Local extension state is invalid.', 0, $exception);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \RuntimeException('Local extension state root is invalid.');
        }
        return $decoded;
    }

    /** @param callable(array<string,mixed>):array<string,mixed> $mutator */
    public static function update(string $path, array $default, callable $mutator): array
    {
        self::ensureRuntimeDirectory(dirname($path));
        $lockPath = $path . '.lock';
        $lock = self::openLock($lockPath);

        try {
            self::assertSensitiveHandle($lock, $lockPath, 'lock');
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Unable to lock local extension state.');
            }
            self::assertSensitiveHandle($lock, $lockPath, 'lock');
            $next = $mutator(self::read($path, $default));
            self::writeLocked($path, $next);
            if (!flock($lock, LOCK_UN)) {
                throw new \RuntimeException('Unable to unlock local extension state.');
            }
            return $next;
        } finally {
            fclose($lock);
        }
    }

    /** @return resource */
    private static function openLock(string $path)
    {
        clearstatcache(true, $path);
        $pathMetadata = @lstat($path);
        if (is_array($pathMetadata)) {
            self::assertSafeExistingLockPath($path, $pathMetadata);
            $handle = @fopen($path, 'r+b');
            if ($handle === false) {
                throw new \RuntimeException('Unable to open local extension state lock.');
            }
            return $handle;
        }

        $previousUmask = umask(0o177);
        try {
            $handle = @fopen($path, 'x+b');
        } finally {
            umask($previousUmask);
        }
        if ($handle !== false) {
            return $handle;
        }

        // Another request may have created the lock after the first lstat().
        // Re-open only after applying the complete existing-file contract.
        clearstatcache(true, $path);
        $pathMetadata = @lstat($path);
        if (!is_array($pathMetadata)) {
            throw new \RuntimeException('Unable to open local extension state lock.');
        }
        self::assertSafeExistingLockPath($path, $pathMetadata);
        $handle = @fopen($path, 'r+b');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open local extension state lock.');
        }
        return $handle;
    }

    /** @param array<string,int> $metadata */
    private static function assertSafeExistingLockPath(string $path, array $metadata): void
    {
        if (
            is_link($path)
            || ($metadata['mode'] & 0o170000) !== 0o100000
            || $metadata['uid'] !== PathGuard::runtimeOwner()
            || ($metadata['mode'] & 0o777) !== 0o600
            || $metadata['nlink'] !== 1
        ) {
            throw new \RuntimeException('Local extension lock path is unsafe.');
        }
    }

    private static function writeLocked(string $path, array $value): void
    {
        try {
            $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Unable to encode local extension state.', 0, $exception);
        }
        $temp = dirname($path) . '/.' . basename($path) . '.tmp-' . bin2hex(random_bytes(12));
        $handle = fopen($temp, 'xb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to create local extension state temporary file.');
        }

        $published = false;
        try {
            if (!chmod($temp, 0600)) {
                throw new \RuntimeException('Unable to protect local extension state temporary file.');
            }
            self::assertSensitiveHandle($handle, $temp, 'temporary file');
            $offset = 0;
            $length = strlen($json);
            while ($offset < $length) {
                $written = fwrite($handle, substr($json, $offset));
                if ($written === false || $written === 0) {
                    throw new \RuntimeException('Unable to write local extension state.');
                }
                $offset += $written;
            }
            if (!fflush($handle)) {
                throw new \RuntimeException('Unable to flush local extension state.');
            }
            if (function_exists('fsync') && !fsync($handle)) {
                throw new \RuntimeException('Unable to flush local extension state.');
            }
        } catch (\Throwable $exception) {
            @unlink($temp);
            throw $exception;
        } finally {
            fclose($handle);
        }

        try {
            if (!rename($temp, $path)) {
                throw new \RuntimeException('Unable to publish local extension state.');
            }
            $published = true;
            clearstatcache(true, $path);
            $publishedHandle = fopen($path, 'rb');
            if ($publishedHandle === false) {
                throw new \RuntimeException('Unable to verify published local extension state.');
            }
            try {
                self::assertSensitiveHandle($publishedHandle, $path, 'published state');
            } finally {
                fclose($publishedHandle);
            }
        } finally {
            if (!$published && file_exists($temp)) {
                @unlink($temp);
            }
        }
    }

    private static function ensureRuntimeDirectory(string $directory): void
    {
        $runtimeRoot = PathGuard::stateRoot();
        if ($directory === $runtimeRoot) {
            return;
        }
        if (!str_starts_with($directory, $runtimeRoot . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Local extension state path escaped runtime root.');
        }
        PathGuard::stateDirectory(substr($directory, strlen($runtimeRoot) + 1));
    }

    private static function assertRuntimePath(string $path): void
    {
        $runtimeRoot = PathGuard::stateRoot();
        $directory = dirname($path);
        $realDirectory = realpath($directory);
        if ($realDirectory === false
            || is_link($directory)
            || ($realDirectory !== $runtimeRoot
                && !str_starts_with($realDirectory . DIRECTORY_SEPARATOR, $runtimeRoot . DIRECTORY_SEPARATOR))) {
            throw new \RuntimeException('Local extension state path escaped runtime root.');
        }
    }

    /** @param resource $handle @return array{dev:int,ino:int,mode:int,uid:int,nlink:int,size:int} */
    private static function assertSensitiveHandle($handle, string $path, string $label): array
    {
        clearstatcache(true, $path);
        $metadata = fstat($handle);
        $pathMetadata = lstat($path);
        if (!is_array($metadata)
            || !is_array($pathMetadata)
            || ($metadata['mode'] & 0o170000) !== 0o100000
            || $metadata['uid'] !== PathGuard::runtimeOwner()
            || ($metadata['mode'] & 0o777) !== 0o600
            || $metadata['nlink'] !== 1
            || is_link($path)
            || $metadata['dev'] !== $pathMetadata['dev']
            || $metadata['ino'] !== $pathMetadata['ino']) {
            throw new \RuntimeException("Local extension {$label} ownership or permissions are unsafe.");
        }
        return $metadata;
    }
}
