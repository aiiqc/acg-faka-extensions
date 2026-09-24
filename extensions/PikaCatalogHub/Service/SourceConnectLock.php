<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaCatalogHub\Service;

use Pika\LocalExtensions\Manager\PathGuard;
use RuntimeException;

final class SourceConnectLock
{
    /** @var resource|null */
    private $handle = null;

    public function acquire(): void
    {
        if (is_resource($this->handle)) {
            throw new RuntimeException('货源接入锁已被当前请求持有。');
        }

        $path = PathGuard::stateDirectory('extensions/PikaCatalogHub/locks', 0o700)
            . '/source-connect.lock';
        $handle = $this->openLock($path);
        try {
            $this->assertSafeHandle($handle, $path);
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('已有货源接入操作正在保存，请稍后重试。');
            }
            $this->assertSafeHandle($handle, $path);
            $this->handle = $handle;
        } catch (\Throwable $exception) {
            @flock($handle, LOCK_UN);
            fclose($handle);
            throw $exception;
        }
    }

    /** @return resource */
    private function openLock(string $path)
    {
        clearstatcache(true, $path);
        $pathMetadata = @lstat($path);
        if (is_array($pathMetadata)) {
            $this->assertSafeExistingPath($path, $pathMetadata);
            $handle = @fopen($path, 'r+b');
            if ($handle === false) {
                throw new RuntimeException('无法打开货源接入锁。');
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

        // A concurrent request may have created the lock after lstat(). Reuse
        // it only after applying the complete existing-file contract.
        clearstatcache(true, $path);
        $pathMetadata = @lstat($path);
        if (!is_array($pathMetadata)) {
            throw new RuntimeException('无法创建货源接入锁。');
        }
        $this->assertSafeExistingPath($path, $pathMetadata);
        $handle = @fopen($path, 'r+b');
        if ($handle === false) {
            throw new RuntimeException('无法打开货源接入锁。');
        }
        return $handle;
    }

    /** @param array<string,int> $metadata */
    private function assertSafeExistingPath(string $path, array $metadata): void
    {
        if (is_link($path)
            || ($metadata['mode'] & 0o170000) !== 0o100000
            || ($metadata['mode'] & 0o777) !== 0o600
            || (int)$metadata['uid'] !== PathGuard::runtimeOwner()
            || (int)$metadata['nlink'] !== 1) {
            throw new RuntimeException('货源接入锁路径不安全。');
        }
    }

    public function release(): void
    {
        if (!is_resource($this->handle)) {
            $this->handle = null;
            return;
        }
        $handle = $this->handle;
        $this->handle = null;
        $failure = null;
        if (!flock($handle, LOCK_UN)) {
            $failure = new RuntimeException('无法释放货源接入锁。');
        }
        fclose($handle);
        if ($failure !== null) {
            throw $failure;
        }
    }

    /** @param resource $handle */
    private function assertSafeHandle($handle, string $path): void
    {
        clearstatcache(true, $path);
        $metadata = fstat($handle);
        $pathMetadata = lstat($path);
        if (!is_array($metadata)
            || !is_array($pathMetadata)
            || ($metadata['mode'] & 0o170000) !== 0o100000
            || ($metadata['mode'] & 0o777) !== 0o600
            || $metadata['uid'] !== PathGuard::runtimeOwner()
            || $metadata['nlink'] !== 1
            || is_link($path)
            || $metadata['dev'] !== $pathMetadata['dev']
            || $metadata['ino'] !== $pathMetadata['ino']) {
            throw new RuntimeException('货源接入锁的类型、权限或文件身份不安全。');
        }
    }
}
