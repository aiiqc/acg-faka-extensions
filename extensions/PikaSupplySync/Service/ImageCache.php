<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaSupplySync\Service;

use App\Model\Shared;
use RuntimeException;

final class ImageCache
{
    private const MAX_WIDTH = 8192;
    private const MAX_HEIGHT = 8192;
    private const MAX_PIXELS = 40000000;
    private const MAX_CACHE_BYTES = 268435456;
    private const MAX_CACHE_FILES = 10000;
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];

    private RunBudget $budget;
    /** @var array<string,string|\Throwable> Periodic refresh outcomes in this run. */
    private array $downloads = [];

    public function __construct(private SafeHttpClient $http, ?RunBudget $budget = null)
    {
        $this->budget = $budget ?? new RunBudget();
    }

    public function beginRun(): void
    {
        $this->downloads = [];
    }

    public function localize(Shared $source, string $cover, bool $refresh = false): string
    {
        $url = $this->absoluteUrl((string)$source->domain, $cover);
        $hash = hash('sha256', $url);
        if ($refresh && array_key_exists($hash, $this->downloads)) {
            $result = $this->downloads[$hash];
            if ($result instanceof \Throwable) throw $result;
            return $result;
        }
        $directory = $this->directory();
        $existing = $this->existing($directory, $hash);
        if (!$refresh && $existing !== null) {
            return $this->publicPath($existing);
        }

        try {
            $result = $this->download($url, $directory, $hash, $refresh);
            if ($refresh) $this->downloads[$hash] = $result;
            return $result;
        } catch (BudgetExceeded $exception) {
            // A source-local budget failure must not poison another source's
            // fresh budget. No completed refresh result exists to reuse.
            throw $exception;
        } catch (\Throwable $exception) {
            if ($refresh) $this->downloads[$hash] = $exception;
            throw $exception;
        }
    }

    private function download(string $url, string $directory, string $hash, bool $refresh): string
    {
        $this->budget->reserveImage();
        try {
            $response = $this->http->getImage($url);
        } catch (BudgetExceeded $exception) {
            throw $exception;
        } catch (UpstreamFailure $exception) {
            // Periodic refresh may hold an unavailable image, but must not
            // disguise credentials, schema/size or unknown safety failures.
            if ($refresh && (!in_array($exception->diagnostics['category'],
                ['transport', 'http_retryable', 'http_rejected'], true)
                || in_array($exception->diagnostics['http_status'], [401, 403], true))) {
                throw $exception;
            }
            throw new RemoteCoverUnavailable('远端封面下载失败', 0, $exception);
        } catch (\Throwable $exception) {
            if ($refresh) throw $exception;
            throw new RemoteCoverUnavailable('远端封面下载失败', 0, $exception);
        }
        $bytes = $response['body'];
        $this->budget->consumeImage(strlen($bytes));
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!is_string($mime) || !isset(self::EXTENSIONS[$mime])) {
            throw new RemoteCoverUnavailable('远端封面不是允许的位图格式');
        }
        $size = @getimagesizefromstring($bytes);
        if (!is_array($size)) {
            throw new RemoteCoverUnavailable('远端封面图像数据损坏');
        }
        $width = (int)($size[0] ?? 0);
        $height = (int)($size[1] ?? 0);
        if (
            $width < 1
            || $height < 1
            || $width > self::MAX_WIDTH
            || $height > self::MAX_HEIGHT
            || $width * $height > self::MAX_PIXELS
        ) {
            throw new RemoteCoverUnavailable('远端封面尺寸超过安全上限');
        }

        // Never replace bytes that may still be referenced by an unselected
        // product. The content suffix also changes the browser's resource URL.
        $contentHash = hash('sha256', $bytes);
        $cacheKey = $refresh ? $hash . '-' . $contentHash : $hash;
        $lock = $this->cacheLock();
        $temporary = false;
        try {
            $existing = $this->existing($directory, $cacheKey);
            if ($existing !== null) {
                if ($refresh && (filesize($existing) !== strlen($bytes)
                    || !hash_equals($contentHash, (string)hash_file('sha256', $existing)))) {
                    throw new RuntimeException('封面内容版本缓存校验失败');
                }
                return $this->publicPath($existing);
            }
            $this->assertCacheCapacity($directory, strlen($bytes));
            $target = $directory . '/' . $cacheKey . '.' . self::EXTENSIONS[$mime];
            $temporary = tempnam($directory, '.image-');
            if ($temporary === false || is_link($temporary)) {
                throw new RuntimeException('无法创建封面临时文件');
            }
            $handle = fopen($temporary, 'wb');
            if ($handle === false) {
                throw new RuntimeException('无法写入封面临时文件');
            }
            try {
                $written = 0;
                while ($written < strlen($bytes)) {
                    $count = fwrite($handle, substr($bytes, $written));
                    if ($count === false || $count === 0) {
                        throw new RuntimeException('封面缓存写入失败');
                    }
                    $written += $count;
                }
                if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                    throw new RuntimeException('封面缓存刷盘失败');
                }
            } finally {
                fclose($handle);
            }
            chmod($temporary, 0644);
            if (!rename($temporary, $target)) {
                throw new RuntimeException('封面缓存原子替换失败');
            }
            chmod($target, 0644);
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $this->publicPath($target);
    }

    private function existing(string $directory, string $hash): ?string
    {
        foreach (self::EXTENSIONS as $extension) {
            $existing = $directory . '/' . $hash . '.' . $extension;
            if (is_link($existing)) {
                throw new RuntimeException('封面缓存不能是符号链接');
            }
            if (is_file($existing) && filesize($existing) > 0) {
                return $existing;
            }
        }
        return null;
    }

    /** @return resource */
    private function cacheLock()
    {
        $directory = LocalPath::directory(
            'runtime/local-extensions/extensions/PikaSupplySync',
            0700
        );
        $path = $directory . '/image-cache.lock';
        if (is_link($path)) {
            throw new RuntimeException('封面缓存锁不能是符号链接');
        }
        $handle = fopen($path, 'c');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            is_resource($handle) && fclose($handle);
            throw new RuntimeException('无法锁定封面缓存');
        }
        chmod($path, 0600);
        return $handle;
    }

    private function assertCacheCapacity(string $directory, int $incomingBytes): void
    {
        $files = 0;
        $bytes = 0;
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry->isLink() || !$entry->isFile()) {
                throw new RuntimeException('封面缓存目录包含不安全文件');
            }
            $files++;
            $bytes += $entry->getSize();
            if ($files >= self::MAX_CACHE_FILES || $bytes + $incomingBytes > self::MAX_CACHE_BYTES) {
                throw new RuntimeException('封面缓存达到 10000 文件或 256MB 安全上限');
            }
        }
    }

    private function absoluteUrl(string $base, string $cover): string
    {
        $cover = trim($cover);
        if (
            $cover === ''
            || strlen($cover) > 2048
            || preg_match('/[\x00-\x20\x7F\\\\]/', $cover)
            || str_starts_with($cover, '//')
        ) {
            throw new RemoteCoverUnavailable('远端封面地址不正确');
        }
        if (preg_match('#^https://#i', $cover) === 1) {
            return $cover;
        }
        if (parse_url($cover, PHP_URL_SCHEME) !== null) {
            throw new RemoteCoverUnavailable('远端封面只允许 HTTPS');
        }
        return rtrim($base, '/') . '/' . ltrim($cover, '/');
    }

    private function directory(): string
    {
        return LocalPath::directory('assets/cache/pika-supply-sync', 0755);
    }

    private function publicPath(string $path): string
    {
        return '/assets/cache/pika-supply-sync/' . basename($path);
    }
}
