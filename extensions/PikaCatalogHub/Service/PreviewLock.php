<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaCatalogHub\Service;

use Pika\LocalExtensions\Manager\PathGuard;
use RuntimeException;

final class PreviewLock
{
    private const COOLDOWN_SECONDS = 30;
    private const RELATIVE_DIRECTORY = 'extensions/PikaCatalogHub';

    /** @var resource|null */
    private $handle = null;
    private \Closure $clock;

    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock === null
            ? static fn(): int => time()
            : \Closure::fromCallable($clock);
    }

    public function acquire(): void
    {
        if (is_resource($this->handle)) {
            throw new RuntimeException('分类预览锁已被当前请求持有。');
        }
        $path = PathGuard::stateDirectory(self::RELATIVE_DIRECTORY, 0o700) . '/preview.lock';
        if (is_link($path)) {
            throw new RuntimeException('分类预览锁路径不安全。');
        }
        $existed = file_exists($path);
        $handle = fopen($path, $existed ? 'r+b' : 'x+b');
        if ($handle === false) {
            throw new RuntimeException('无法创建分类预览锁。');
        }
        if (!$existed && !chmod($path, 0o600)) {
            fclose($handle);
            throw new RuntimeException('无法保护分类预览锁。');
        }
        clearstatcache(true, $path);
        try {
            $metadata = $this->assertSafeHandle($handle, $path);
            if ($metadata['size'] > 32) {
                throw new RuntimeException('分类预览锁内容不正确。');
            }
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('已有分类预览正在运行，请稍后重试。');
            }
            rewind($handle);
            $contents = stream_get_contents($handle);
            if (!is_string($contents)) {
                throw new RuntimeException('无法读取分类预览冷却状态。');
            }
            $contents = trim($contents);
            $lastFinished = $contents === '' ? 0 : filter_var($contents, FILTER_VALIDATE_INT);
            $now = $this->now();
            if ($lastFinished === false || $lastFinished < 0 || $lastFinished > $now) {
                throw new RuntimeException('分类预览冷却状态不正确。');
            }
            if ($lastFinished > 0 && $now - $lastFinished < self::COOLDOWN_SECONDS) {
                throw new RuntimeException('分类预览处于 30 秒冷却期，请稍后重试。');
            }
            $this->handle = $handle;
        } catch (\Throwable $exception) {
            @flock($handle, LOCK_UN);
            fclose($handle);
            throw $exception;
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
        try {
            $value = $this->now() . "\n";
            if (!rewind($handle) || !ftruncate($handle, 0)) {
                throw new RuntimeException('无法更新分类预览冷却状态。');
            }
            $written = fwrite($handle, $value);
            if ($written !== strlen($value) || !fflush($handle)
                || (function_exists('fsync') && !fsync($handle))) {
                throw new RuntimeException('无法持久化分类预览冷却状态。');
            }
        } catch (\Throwable $exception) {
            $failure = $exception;
        } finally {
            if (!flock($handle, LOCK_UN) && $failure === null) {
                $failure = new RuntimeException('无法释放分类预览锁。');
            }
            fclose($handle);
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    /** @param resource $handle @return array{dev:int,ino:int,mode:int,uid:int,nlink:int,size:int} */
    private function assertSafeHandle($handle, string $path): array
    {
        $metadata = fstat($handle);
        $pathMetadata = lstat($path);
        if (!is_array($metadata) || !is_array($pathMetadata)
            || ($metadata['mode'] & 0o170000) !== 0o100000
            || ($metadata['mode'] & 0o777) !== 0o600
            || $metadata['uid'] !== PathGuard::runtimeOwner()
            || $metadata['nlink'] !== 1
            || is_link($path)
            || $metadata['dev'] !== $pathMetadata['dev']
            || $metadata['ino'] !== $pathMetadata['ino']) {
            throw new RuntimeException('分类预览锁的类型、权限或文件身份不安全。');
        }
        return $metadata;
    }

    private function now(): int
    {
        $now = ($this->clock)();
        if (!is_int($now) || $now < 0) {
            throw new RuntimeException('分类预览时钟不正确。');
        }
        return $now;
    }
}
