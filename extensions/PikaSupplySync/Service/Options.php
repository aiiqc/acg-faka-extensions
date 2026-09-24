<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaSupplySync\Service;

use InvalidArgumentException;

final class Options
{
    public const MODE_BASIC = 'basic';
    public const MODE_FULL = 'full';
    public const SYNC_FIELDS = ['name', 'cover', 'description', 'price', 'inventory', 'options'];

    /** @param int[] $sourceIds */
    private function __construct(
        public string $mode,
        public array $sourceIds,
        public float $premiumPercent,
        public int $batchLimit,
        public float $zeroFusePercent,
        public int $zeroFuseMin,
        public bool $dryRun,
        public ?array $syncFields,
        private array $upstreamConfigSourceIds,
    ) {
    }

    public static function fromArray(array $config, array $overrides = []): self
    {
        $values = array_merge($config, array_filter(
            $overrides,
            static fn(mixed $value): bool => $value !== null
        ));

        $modeValue = $values['mode'] ?? self::MODE_BASIC;
        if (!is_scalar($modeValue) || strlen((string)$modeValue) > 16) {
            throw new InvalidArgumentException('mode 格式不正确');
        }
        $mode = strtolower(trim((string)$modeValue));
        if (!in_array($mode, [self::MODE_BASIC, self::MODE_FULL], true)) {
            throw new InvalidArgumentException('mode 必须是 basic 或 full');
        }

        $sourceIds = self::sourceIds($values['source_ids'] ?? '');
        $premiumPercent = self::decimal($values['premium_percent'] ?? 0, 0, 1000, 'premium_percent');
        $batchLimit = self::integer($values['batch_limit'] ?? 100, 1, 500, 'batch_limit');
        $zeroFusePercent = self::decimal($values['zero_fuse_percent'] ?? 10, 1, 100, 'zero_fuse_percent');
        $zeroFuseMin = self::integer($values['zero_fuse_min'] ?? 5, 1, 500, 'zero_fuse_min');

        $dryRun = filter_var($values['dry_run'] ?? false, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($dryRun === null) {
            throw new InvalidArgumentException('dry_run 必须是布尔值');
        }

        $syncFields = [];
        foreach (self::SYNC_FIELDS as $field) {
            $key = 'sync_' . $field;
            if (!array_key_exists($key, $values)) continue;
            if (!is_bool($values[$key])) throw new InvalidArgumentException('同步字段必须是复选框布尔值');
            $syncFields[$field] = $values[$key];
        }
        if ($syncFields !== [] && count($syncFields) !== count(self::SYNC_FIELDS)) {
            throw new InvalidArgumentException('请完整保存六项同步选择');
        }

        // Execution overrides (including CLI --source) cannot expand saved ownership.
        $followConfig = array_key_exists('follow_upstream_config', $config) ? $config['follow_upstream_config'] : false;
        if (!is_bool($followConfig)) {
            throw new InvalidArgumentException('完整商品配置跟随上游必须是复选框布尔值');
        }
        $upstreamConfigSourceIds = [];
        if ($followConfig) {
            foreach (self::SYNC_FIELDS as $field) {
                if (!is_bool($config['sync_' . $field] ?? null)) {
                    throw new InvalidArgumentException('完整商品配置跟随上游需要明确保存六项同步选择');
                }
            }
            $upstreamConfigSourceIds = self::sourceIds($config['follow_upstream_config_source_ids'] ?? '',
                'follow_upstream_config_source_ids');
            if ($upstreamConfigSourceIds === []) {
                throw new InvalidArgumentException('完整商品配置跟随上游需要独立指定跟随货源，不能留空或从参与同步的货源推定');
            }
        }

        return new self(
            $mode,
            $sourceIds,
            $premiumPercent,
            $batchLimit,
            $zeroFusePercent,
            $zeroFuseMin,
            $dryRun,
            $syncFields === [] ? null : $syncFields,
            $upstreamConfigSourceIds,
        );
    }

    public function followsUpstreamConfig(int $sourceId): bool
    {
        return $this->syncFields !== null
            && ($this->sourceIds === [] || in_array($sourceId, $this->sourceIds, true))
            && in_array($sourceId, $this->upstreamConfigSourceIds, true);
    }

    public function syncs(string $field): bool
    {
        return $this->syncFields === null || ($this->syncFields[$field] ?? false);
    }

    public function premiumFactor(): float
    {
        return $this->premiumPercent / 100;
    }

    /** @return int[] */
    private static function sourceIds(mixed $value, string $name = 'source_ids'): array
    {
        if (is_string($value)) {
            if (strlen($value) > 1024) {
                throw new InvalidArgumentException("{$name} 配置过长");
            }
            $value = trim($value) === '' ? [] : explode(',', $value);
        }
        if (!is_array($value)) {
            $value = [$value];
        }
        if (count($value) > 100) {
            throw new InvalidArgumentException("{$name} 最多允许 100 个共享店铺");
        }

        $ids = [];
        foreach ($value as $candidate) {
            $candidate = is_scalar($candidate) ? trim((string)$candidate) : '';
            if ($candidate === '' || !ctype_digit($candidate) || (int)$candidate < 1) {
                throw new InvalidArgumentException("{$name} 只能包含正整数");
            }
            $ids[(int)$candidate] = (int)$candidate;
        }
        ksort($ids, SORT_NUMERIC);
        return array_values($ids);
    }

    private static function integer(mixed $value, int $min, int $max, string $name): int
    {
        $value = is_scalar($value) ? trim((string)$value) : '';
        if (!preg_match('/^\d+$/D', $value)) {
            throw new InvalidArgumentException("{$name} 必须是整数");
        }
        $integer = (int)$value;
        if ($integer < $min || $integer > $max) {
            throw new InvalidArgumentException("{$name} 超出 {$min}-{$max} 范围");
        }
        return $integer;
    }

    private static function decimal(mixed $value, float $min, float $max, string $name): float
    {
        if (!is_scalar($value) || !is_numeric((string)$value)) {
            throw new InvalidArgumentException("{$name} 必须是数字");
        }
        $number = (float)$value;
        if (!is_finite($number) || $number < $min || $number > $max) {
            throw new InvalidArgumentException("{$name} 超出 {$min}-{$max} 范围");
        }
        return $number;
    }
}
