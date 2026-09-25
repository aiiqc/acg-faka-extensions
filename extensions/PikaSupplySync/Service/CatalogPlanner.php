<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaSupplySync\Service;

use RuntimeException;

final class CatalogPlanner
{
    private const MAX_GROUPS = 200;
    private const MAX_ITEMS = 10000;

    /**
     * @return array<string, array{code:string,name:string,category:string,stock:?int,item:array}>
     */
    public function flatten(mixed $tree, bool $allowManualNullStock = false): array
    {
        if (!is_array($tree) || count($tree) > self::MAX_GROUPS) {
            throw new RuntimeException('远端商品分类结构不正确或数量过多');
        }

        $catalog = [];
        foreach ($tree as $group) {
            if (!is_array($group) || !is_array($group['children'] ?? null)) {
                throw new RuntimeException('远端商品分类结构不正确');
            }
            $category = $this->plainText($group['name'] ?? null, 128, '分类名称');
            foreach ($group['children'] as $item) {
                if (!is_array($item)) {
                    throw new RuntimeException('远端商品记录格式不正确');
                }
                $code = $this->code($item['code'] ?? $item['id'] ?? null);
                if (isset($catalog[$code])) {
                    throw new RuntimeException('远端商品编号重复：' . substr(hash('sha256', $code), 0, 12));
                }
                $stock = $allowManualNullStock && self::isManualNullStock($item)
                    ? null
                    : $this->stock($item['stock'] ?? null);
                $catalog[$code] = [
                    'code' => $code,
                    'name' => $this->plainText($item['name'] ?? null, 255, '商品名称', true),
                    'category' => $category,
                    'stock' => $stock,
                    'item' => $item,
                ];
                if (count($catalog) > self::MAX_ITEMS) {
                    throw new RuntimeException('远端商品数量超过 10000 条安全上限');
                }
            }
        }
        ksort($catalog, SORT_STRING);
        return $catalog;
    }

    /**
     * @param array<string, array{code:string,name:string,category:string,stock:?int,item:array}> $catalog
     * @param array<string, array{id:int,status:int,stock:int,managed?:bool,inventory_sync?:int}> $local
     * @return array{actions:array<int,array{type:string,code:string,lane:string}>,next_cursor:string,next_priority_cursor:string,counts:array<string,int>,fuse:bool,fuse_ratio:float}
     */
    public function plan(
        array $catalog,
        array $local,
        string $cursor,
        Options $options,
        string $priorityCursor = '',
        array $targetCodes = [],
    ): array
    {
        if ($catalog === []) {
            throw new RuntimeException('远端商品目录为空，已拒绝执行任何商品写入');
        }

        $zeroCandidates = [];
        $explicitZeroCount = 0;
        $activeCount = 0;
        foreach ($local as $code => $row) {
            // Unknown stock must not dilute or trigger either zero-stock fuse.
            if (isset($catalog[$code]) && $catalog[$code]['stock'] === null) {
                continue;
            }
            if (
                ($row['managed'] ?? true)
                && (int)($row['inventory_sync'] ?? 1) === 1
                && (int)$row['stock'] > 0
            ) {
                $activeCount++;
                if (!isset($catalog[$code]) || $catalog[$code]['stock'] <= 0) {
                    $zeroCandidates[$code] = true;
                    if (isset($catalog[$code]) && $catalog[$code]['stock'] === 0) {
                        $explicitZeroCount++;
                    }
                }
            }
        }
        $ratio = $activeCount > 0 ? (count($zeroCandidates) / $activeCount) * 100 : 0.0;
        $fuse = count($zeroCandidates) >= $options->zeroFuseMin && $ratio > $options->zeroFusePercent;
        // Missing entries retain the aggregate gate; explicit zeroes use the same
        // thresholds independently so missing entries cannot freeze valid zeroes.
        $explicitZeroRatio = $activeCount > 0 ? ($explicitZeroCount / $activeCount) * 100 : 0.0;
        $explicitZeroFuse = $explicitZeroCount >= $options->zeroFuseMin
            && $explicitZeroRatio > $options->zeroFusePercent;

        $work = $options->mode === Options::MODE_FULL
            ? array_unique(array_merge(array_keys($catalog), array_keys($local)))
            : array_keys($local);
        if ($targetCodes !== []) $work = $targetCodes;
        // PHP converts decimal-string array keys (for example "1001") to
        // integers. Keep product codes as strings before cursor comparison and
        // before they are emitted into the synchronization plan.
        $work = array_map(static fn(mixed $code): string => (string)$code, $work);
        sort($work, SORT_STRING);

        $priority = [];
        foreach ($work as $code) {
            $remote = $catalog[$code] ?? null;
            $row = $local[$code] ?? null;
            if ($row === null || !($row['managed'] ?? true)) {
                continue;
            }
            if ($remote !== null && $remote['stock'] === null) {
                continue;
            }
            $isHeldZero = isset($zeroCandidates[$code]) && ($remote === null ? $fuse : $explicitZeroFuse);
            $remoteStock = $remote === null ? 0 : (int)$remote['stock'];
            $inventorySync = (int)($row['inventory_sync'] ?? 1) === 1;
            if (
                !$isHeldZero
                && $inventorySync
                && $remoteStock !== (int)$row['stock']
            ) {
                $priority[] = $code;
            }
        }
        sort($priority, SORT_STRING);
        $priorityLimit = $targetCodes === [] && $options->batchLimit > 3
            ? max(1, (int)floor($options->batchLimit * 0.75))
            : 0;
        $prioritySelected = $priorityLimit > 0
            ? $this->batch($priority, $priorityCursor, $priorityLimit)
            : [];
        $selected = $prioritySelected;
        $remaining = $options->batchLimit - count($selected);
        $normalSelected = [];
        if ($remaining > 0) {
            $priorityMap = $priorityLimit > 0 ? array_fill_keys($priority, true) : [];
            $normalWork = array_values(array_filter($work, static function (string $code) use ($priorityMap, $local): bool {
                if (isset($priorityMap[$code])) {
                    return false;
                }
                return !isset($local[$code]) || ($local[$code]['managed'] ?? true);
            }));
            $normalSelected = $targetCodes === [] ? $this->batch($normalWork, $cursor, $remaining) : $normalWork;
            $selected = array_merge($selected, $normalSelected);
        }
        $actions = [];
        $counts = ['sync' => 0, 'import' => 0, 'zero' => 0, 'hold_zero' => 0, 'held_unknown' => 0];
        $prioritySelection = array_fill_keys($prioritySelected, true);

        foreach ($selected as $code) {
            $remote = $catalog[$code] ?? null;
            $row = $local[$code] ?? null;
            if ($row !== null && !($row['managed'] ?? true)) {
                continue;
            }
            if ($remote !== null && $remote['stock'] === null) {
                $type = 'held_unknown';
            } elseif ($row === null) {
                if ($options->mode === Options::MODE_FULL && $remote !== null) {
                    $type = (int)$remote['stock'] > 0 ? 'import' : 'hold_zero';
                } else {
                    continue;
                }
            } elseif ($remote === null || $remote['stock'] <= 0) {
                if ((int)($row['inventory_sync'] ?? 1) !== 1) {
                    if ($remote === null) {
                        continue;
                    }
                    $type = 'sync';
                } elseif (($remote === null ? $fuse : $explicitZeroFuse) && (int)$row['stock'] > 0) {
                    $type = 'hold_zero';
                } else {
                    $type = 'zero';
                }
            } else {
                $type = 'sync';
            }
            $counts[$type]++;
            $actions[] = [
                'type' => $type,
                'code' => $code,
                'lane' => isset($prioritySelection[$code]) ? 'priority' : 'normal',
            ];
        }

        return [
            'actions' => $actions,
            // Priority work must not jump the ordinary rotation cursor. When a
            // whole batch is consumed by stock/status mismatches, preserve the
            // cursor and resume price/config rotation after those mismatches clear.
            'next_cursor' => $targetCodes !== [] || $normalSelected === [] ? $cursor : (string)end($normalSelected),
            'next_priority_cursor' => $prioritySelected === []
                ? $priorityCursor
                : (string)end($prioritySelected),
            'counts' => $counts,
            'fuse' => $fuse,
            'fuse_ratio' => round($ratio, 2),
        ];
    }

    public static function isManualNullStock(array $item): bool
    {
        return array_key_exists('stock', $item)
            && $item['stock'] === null
            && ($item['delivery_way'] ?? null) === 1;
    }

    /** @param string[] $work @return string[] */
    private function batch(array $work, string $cursor, int $limit): array
    {
        if ($work === []) {
            return [];
        }
        $start = 0;
        if ($cursor !== '') {
            foreach ($work as $index => $code) {
                if (strcmp($code, $cursor) > 0) {
                    $start = $index;
                    break;
                }
                $start = count($work);
            }
        }
        if ($start >= count($work)) {
            $start = 0;
        }
        return array_slice($work, $start, $limit);
    }

    private function code(mixed $value): string
    {
        if (!is_scalar($value)) {
            throw new RuntimeException('远端商品编号格式不正确');
        }
        $code = trim((string)$value);
        if ($code === '' || strlen($code) > 64 || preg_match('/[\x00-\x20\x7F]/', $code)) {
            throw new RuntimeException('远端商品编号必须是 1-64 位且不能包含空白或控制字符');
        }
        return $code;
    }

    private function plainText(
        mixed $value,
        int $max,
        string $label,
        bool $normalizeNameWhitespace = false
    ): string
    {
        if (!is_scalar($value)) {
            throw new RuntimeException("远端{$label}格式不正确");
        }

        $raw = (string)$value;
        if ($normalizeNameWhitespace) {
            if (
                !mb_check_encoding($raw, 'UTF-8')
                || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $raw)
            ) {
                throw new RuntimeException("远端{$label}内容不正确");
            }
        }
        $text = strip_tags($raw);
        if ($normalizeNameWhitespace) {
            $normalized = preg_replace('/[\t\r\n]+/', ' ', $text);
            if (!is_string($normalized) || preg_match('/[\x00-\x1F\x7F]/u', $normalized)) {
                throw new RuntimeException("远端{$label}内容不正确");
            }
            $text = $normalized;
        }
        $text = trim($text);
        if ($text === '' || !mb_check_encoding($text, 'UTF-8') || mb_strlen($text, 'UTF-8') > $max || preg_match('/[\x00-\x1F\x7F]/u', $text)) {
            throw new RuntimeException("远端{$label}内容不正确");
        }
        return $text;
    }

    private function stock(mixed $value): int
    {
        if (is_int($value)) {
            $stock = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/D', trim($value))) {
            $stock = (int)trim($value);
        } elseif (is_float($value) && floor($value) === $value) {
            $stock = (int)$value;
        } else {
            throw new RuntimeException('远端商品库存必须是非负整数');
        }
        if ($stock < 0 || $stock > 2147483647) {
            throw new RuntimeException('远端商品库存超出有效范围');
        }
        return $stock;
    }
}
