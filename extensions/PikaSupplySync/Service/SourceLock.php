<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaSupplySync\Service;

use Pika\LocalExtensions\Manager\PathGuard;
use RuntimeException;

final class SourceLock
{
    /** @var resource|null */
    private $handle = null;

    public function acquire(int $sourceId): bool
    {
        if ($sourceId < 1 || $this->handle !== null) {
            return false;
        }
        $directory = LocalPath::directory(
            'runtime/local-extensions/extensions/PikaSupplySync',
            0700
        );
        $path = $directory . '/source-' . $sourceId . '.run.lock';
        if (is_link($path)) {
            throw new RuntimeException('货源同步锁不能是符号链接');
        }

        $exists = file_exists($path);
        if ($exists) {
            $this->assertSafeExistingPath($path);
        }
        $handle = $this->openHandle($path, $exists);
        if ($handle === false) {
            throw new RuntimeException('无法创建货源同步锁');
        }
        try {
            clearstatcache(true, $path);
            $this->assertSafeHandle($handle, $path);
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                fclose($handle);
                return false;
            }
            clearstatcache(true, $path);
            $this->assertSafeHandle($handle, $path);
        } catch (\Throwable $exception) {
            @flock($handle, LOCK_UN);
            fclose($handle);
            throw $exception;
        }
        $this->handle = $handle;
        return true;
    }

    public function release(): void
    {
        if (!is_resource($this->handle)) {
            $this->handle = null;
            return;
        }
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }

    /** @return resource|false */
    private function openHandle(string $path, bool $exists)
    {
        if ($exists) {
            return @fopen($path, 'r+b');
        }

        $previousUmask = umask(0o177);
        try {
            return @fopen($path, 'x+b');
        } finally {
            umask($previousUmask);
        }
    }

    private function assertSafeExistingPath(string $path): void
    {
        clearstatcache(true, $path);
        $metadata = lstat($path);
        if (!is_array($metadata)
            || is_link($path)
            || ((int)$metadata['mode'] & 0o170000) !== 0o100000
            || ((int)$metadata['mode'] & 0o777) !== 0o600
            || (int)$metadata['uid'] !== PathGuard::runtimeOwner()
            || (int)$metadata['nlink'] !== 1) {
            throw new RuntimeException('货源同步锁的类型、权限或文件身份不安全');
        }
    }

    /** @param resource $handle */
    private function assertSafeHandle($handle, string $path): void
    {
        $metadata = fstat($handle);
        $pathMetadata = lstat($path);
        if (!is_array($metadata)
            || !is_array($pathMetadata)
            || is_link($path)
            || ((int)$metadata['mode'] & 0o170000) !== 0o100000
            || ((int)$pathMetadata['mode'] & 0o170000) !== 0o100000
            || ((int)$metadata['mode'] & 0o777) !== 0o600
            || (int)$metadata['uid'] !== PathGuard::runtimeOwner()
            || (int)$metadata['nlink'] !== 1
            || (int)$pathMetadata['nlink'] !== 1
            || (int)$metadata['dev'] !== (int)$pathMetadata['dev']
            || (int)$metadata['ino'] !== (int)$pathMetadata['ino']) {
            throw new RuntimeException('货源同步锁的类型、权限或文件身份不安全');
        }
    }
}
