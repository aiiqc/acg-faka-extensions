<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaSupplySync\Service;

use RuntimeException;

final class BudgetExceeded extends RuntimeException
{
    public readonly ?array $safeDiagnostics;

    public function __construct(
        public readonly string $scope,
        string $message,
        ?array $safeDiagnostics = null,
        public readonly string $resource = 'generic',
    ) {
        if (!in_array($resource, ['generic', 'image_count', 'image_bytes'], true)) {
            throw new RuntimeException('同步预算资源类型不正确');
        }
        $this->safeDiagnostics = $safeDiagnostics === null ? null : UpstreamFailure::sanitize(
            ['category' => 'budget'] + $safeDiagnostics,
        );
        parent::__construct($message);
    }

    public function isSource(): bool
    {
        return $this->scope === 'source';
    }

    public function isImageQuota(): bool
    {
        return in_array($this->resource, ['image_count', 'image_bytes'], true);
    }
}

final class RunBudget
{
    private const MAX_WALL_SECONDS = 300;
    private const MAX_IMAGE_DOWNLOADS = 100;
    private const MAX_IMAGE_BYTES = 52428800;
    private const MAX_TEXT_BYTES = 10485760;
    private const MAX_SOURCE_WALL_SECONDS = 120;
    private const MAX_SOURCE_IMAGE_DOWNLOADS = 25;
    private const MAX_SOURCE_IMAGE_BYTES = 26214400;
    private const MAX_SOURCE_TEXT_BYTES = 5242880;

    private float $startedAt;
    private ?float $lastObservedAt = null;
    private int $imageDownloads = 0;
    private int $imageBytes = 0;
    private int $textBytes = 0;
    private ?int $sourceId = null;
    private float $sourceStartedAt = 0.0;
    private int $sourceImageDownloads = 0;
    private int $sourceImageBytes = 0;
    private int $sourceTextBytes = 0;
    /** @var \Closure(): float */
    private \Closure $clock;

    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock === null
            ? static fn(): float => hrtime(true) / 1000000000
            : \Closure::fromCallable($clock);
        $this->startedAt = $this->now();
    }

    public function beginSource(int $sourceId): void
    {
        if ($sourceId < 1 || $sourceId > 4294967295) {
            throw new RuntimeException('共享店铺 ID 不正确');
        }
        if ($this->sourceId !== null) {
            throw new RuntimeException('上一货源预算尚未结束');
        }
        $this->checkpointRound();
        $this->sourceId = $sourceId;
        $this->sourceStartedAt = $this->now();
        $this->sourceImageDownloads = 0;
        $this->sourceImageBytes = 0;
        $this->sourceTextBytes = 0;
    }

    public function endSource(): void
    {
        $this->sourceId = null;
        $this->sourceStartedAt = 0.0;
        $this->sourceImageDownloads = 0;
        $this->sourceImageBytes = 0;
        $this->sourceTextBytes = 0;
    }

    public function checkpoint(): void
    {
        $this->assertDeadline($this->now());
    }

    private function checkpointRound(): void
    {
        $this->assertRoundDeadline($this->now());
    }

    public function remainingMilliseconds(int $minimum = 1): int
    {
        if ($minimum < 1) {
            throw new RuntimeException('请求所需剩余预算不正确');
        }
        $now = $this->now();
        $this->assertDeadline($now);

        $scope = 'round';
        $remaining = ($this->startedAt + self::MAX_WALL_SECONDS) - $now;
        if ($this->sourceId !== null) {
            $sourceRemaining = ($this->sourceStartedAt + self::MAX_SOURCE_WALL_SECONDS) - $now;
            if ($sourceRemaining < $remaining) {
                $scope = 'source';
                $remaining = $sourceRemaining;
            }
        }

        $milliseconds = (int)floor($remaining * 1000);
        if ($milliseconds < $minimum) {
            throw new BudgetExceeded(
                $scope,
                $scope === 'source'
                    ? ($minimum === 1 ? '单一货源同步剩余时间不足 1 毫秒' : '单一货源同步剩余时间不足')
                    : ($minimum === 1 ? '单轮同步剩余时间不足 1 毫秒' : '单轮同步剩余时间不足'),
            );
        }
        return $milliseconds;
    }

    private function assertDeadline(float $now): void
    {
        $this->assertRoundDeadline($now);
        if (
            $this->sourceId !== null
            && $now >= $this->sourceStartedAt + self::MAX_SOURCE_WALL_SECONDS
        ) {
            throw new BudgetExceeded('source', '单一货源同步超过 120 秒安全上限');
        }
    }

    private function assertRoundDeadline(float $now): void
    {
        if ($now >= $this->startedAt + self::MAX_WALL_SECONDS) {
            throw new BudgetExceeded('round', '单轮同步超过 300 秒安全上限');
        }
    }

    private function now(): float
    {
        $value = ($this->clock)();
        if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value)) {
            throw new RuntimeException('同步计时器返回值不正确');
        }
        $this->lastObservedAt = (float)$value;
        return (float)$value;
    }

    /** Last existing clock sample only: no new checkpoint, clock call or deadline decision. */
    public function diagnosticRemaining(): array
    {
        if ($this->lastObservedAt === null) return [];
        $remaining = ['round_ms' => (int)floor(max(0, min(self::MAX_WALL_SECONDS,
            $this->startedAt + self::MAX_WALL_SECONDS - $this->lastObservedAt)) * 1000)];
        if ($this->sourceId !== null) {
            $remaining['source_ms'] = (int)floor(max(0, min(self::MAX_SOURCE_WALL_SECONDS,
                $this->sourceStartedAt + self::MAX_SOURCE_WALL_SECONDS - $this->lastObservedAt)) * 1000);
        }
        return $remaining;
    }

    public function reserveImage(): void
    {
        $this->checkpoint();
        if ($this->sourceId !== null) {
            $this->sourceImageDownloads++;
            if ($this->sourceImageDownloads > self::MAX_SOURCE_IMAGE_DOWNLOADS) {
                throw new BudgetExceeded('source', '单一货源封面下载超过 25 张安全上限', null, 'image_count');
            }
        }
        $this->imageDownloads++;
        if ($this->imageDownloads > self::MAX_IMAGE_DOWNLOADS) {
            throw new BudgetExceeded('round', '单轮封面下载超过 100 张安全上限', null, 'image_count');
        }
    }

    public function consumeImage(int $bytes): void
    {
        $this->checkpoint();
        if ($bytes < 1) {
            throw new RuntimeException('远端封面字节数不正确');
        }
        $this->imageBytes += $bytes;
        if ($this->sourceId !== null) {
            $this->sourceImageBytes += $bytes;
        }
        if ($this->imageBytes > self::MAX_IMAGE_BYTES) {
            throw new BudgetExceeded('round', '单轮封面下载超过 50MB 安全上限', null, 'image_bytes');
        }
        if ($this->sourceId !== null && $this->sourceImageBytes > self::MAX_SOURCE_IMAGE_BYTES) {
            throw new BudgetExceeded('source', '单一货源封面下载超过 25MB 安全上限', null, 'image_bytes');
        }
    }

    public function consumeText(int $bytes): void
    {
        $this->checkpoint();
        if ($bytes < 0) {
            throw new RuntimeException('远端文本字节数不正确');
        }
        $this->textBytes += $bytes;
        if ($this->sourceId !== null) {
            $this->sourceTextBytes += $bytes;
        }
        if ($this->textBytes > self::MAX_TEXT_BYTES) {
            throw new BudgetExceeded('round', '单轮商品文本超过 10MB 安全上限');
        }
        if ($this->sourceId !== null && $this->sourceTextBytes > self::MAX_SOURCE_TEXT_BYTES) {
            throw new BudgetExceeded('source', '单一货源商品文本超过 5MB 安全上限');
        }
    }
}
