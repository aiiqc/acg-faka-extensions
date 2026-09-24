<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaCatalogHub\Service;

use RuntimeException;

final class PreviewPlanner
{
    private const PREVIEW_SCHEMA = 3;
    private const MAX_ITEMS = 10000;
    private const MAX_ISSUE_ROWS = 100;
    private const MAX_TARGETS_PER_ITEM = 16;
    private const FALLBACK_GROUP = '其他';

    public function __construct(private ?LiteralMatcher $matcher = null)
    {
        $this->matcher ??= new LiteralMatcher();
    }

    /**
     * @param array<string,mixed> $catalog
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public function preview(int $sourceId, array $catalog, array $config): array
    {
        if ($sourceId < 1 || $sourceId > 0x7fffffff) {
            throw new RuntimeException('预览货源 ID 不正确。');
        }
        $config = ConfigSchema::normalize($config);
        $sourceAlias = $this->sourceAlias($sourceId, $config['aliases']);
        $records = $this->records($catalog);
        if ($records === []) {
            throw new RuntimeException('远端商品目录为空，无法生成可信分类预览。');
        }

        $treeCounts = [];
        $conflicts = [];
        $unclassified = [];
        $conflictCount = 0;
        $unclassifiedCount = 0;
        $classifiedCount = 0;
        $itemHash = hash_init('sha256');

        foreach ($records as $record) {
            $targets = $this->targets($record, $config['rules']);
            if (count($targets) > self::MAX_TARGETS_PER_ITEM) {
                throw new RuntimeException('单一商品命中的冲突分类超过 16 项安全上限。');
            }
            $fingerprint = hash('sha256', $sourceId . "\0" . $record['code']);
            $base = [
                'fingerprint' => $fingerprint,
                'category' => $record['category'],
                'name' => $record['name'],
                'targets' => $targets,
            ];
            if ($targets === []) {
                $unclassifiedCount++;
                if (count($unclassified) < self::MAX_ISSUE_ROWS) {
                    $unclassified[] = $base;
                }
                $treeCounts[self::FALLBACK_GROUP][''][$record['category']] =
                    ($treeCounts[self::FALLBACK_GROUP][''][$record['category']] ?? 0) + 1;
                $status = 'fallback';
            } elseif (count($targets) > 1) {
                $conflictCount++;
                if (count($conflicts) < self::MAX_ISSUE_ROWS) {
                    $conflicts[] = $base;
                }
                $status = 'conflict';
            } else {
                $target = $targets[0];
                $treeCounts[$target['group']][$target['family']][$record['category']] =
                    ($treeCounts[$target['group']][$target['family']][$record['category']] ?? 0) + 1;
                $classifiedCount++;
                $status = 'classified';
            }
            $encodedItem = CanonicalJson::encode($base + ['status' => $status]);
            hash_update($itemHash, pack('N', strlen($encodedItem)) . $encodedItem);
        }

        $tree = $this->tree($treeCounts, $sourceAlias, $sourceId);
        $counts = [
            'total' => count($records),
            'matched' => $classifiedCount,
            'conflicts' => $conflictCount,
            'unclassified' => $unclassifiedCount,
        ];
        $hashPayload = [
            'schema' => self::PREVIEW_SCHEMA,
            'source_id' => $sourceId,
            'source_alias' => $sourceAlias,
            'tree' => $tree,
            'items_sha256' => hash_final($itemHash),
        ];

        return [
            'schema' => self::PREVIEW_SCHEMA,
            'source_id' => $sourceId,
            'source_alias' => $sourceAlias,
            'tree' => $tree,
            'conflicts' => $conflicts,
            'unclassified' => $unclassified,
            'counts' => $counts,
            'truncated' => [
                'conflicts' => $conflictCount > self::MAX_ISSUE_ROWS,
                'unclassified' => $unclassifiedCount > self::MAX_ISSUE_ROWS,
            ],
            'ready' => $counts['conflicts'] === 0,
            'plan_hash' => hash('sha256', CanonicalJson::encode($hashPayload)),
        ];
    }

    /** @param list<array{source_id:int,alias:string}> $aliases */
    private function sourceAlias(int $sourceId, array $aliases): string
    {
        foreach ($aliases as $alias) {
            if ($alias['source_id'] === $sourceId) {
                return $alias['alias'];
            }
        }
        throw new RuntimeException('请先为该货源设置唯一别名，再生成分类预览。');
    }

    /** @return list<array{code:string,name:string,category:string}> */
    private function records(array $catalog): array
    {
        if (count($catalog) > self::MAX_ITEMS) {
            throw new RuntimeException('分类预览商品超过 10000 项安全上限。');
        }
        $records = [];
        $seen = [];
        foreach ($catalog as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('分类预览商品记录格式不正确。');
            }
            $code = $this->code($row['code'] ?? null);
            if (isset($seen[$code])) {
                throw new RuntimeException('分类预览商品编号重复。');
            }
            $seen[$code] = true;
            $records[] = [
                'code' => $code,
                'name' => $this->text($row['name'] ?? null, 255, '商品名称'),
                'category' => $this->sourceCategory($row['category'] ?? null),
            ];
        }
        usort($records, static fn(array $left, array $right): int => strcmp($left['code'], $right['code']));
        return $records;
    }

    /** @param array{name:string,category:string} $record @param list<array> $rules @return list<array{group:string,family:string}> */
    private function targets(array $record, array $rules): array
    {
        $highest = null;
        $targets = [];
        foreach ($rules as $rule) {
            if (!$this->matcher->matches($rule, $record['category'], $record['name'])) {
                continue;
            }
            if ($highest === null || $rule['priority'] > $highest) {
                $highest = $rule['priority'];
                $targets = [];
            }
            if ($rule['priority'] !== $highest) {
                continue;
            }
            $target = $rule['target'];
            $targets[$target['group'] . "\0" . $target['family']] = $target;
        }
        $targets = array_values($targets);
        usort($targets, static fn(array $left, array $right): int =>
            strcmp(CanonicalJson::encode($left), CanonicalJson::encode($right))
        );
        return $targets;
    }

    /**
     * @param array<string,array<string,array<string,int>>> $counts
     * @return list<array{name:string,children:list<
     *     array{name:string,source_id:int,count:int,children:list<array{name:string,count:int}>}
     *     |array{name:string,children:list<array{name:string,source_id:int,count:int,children:list<array{name:string,count:int}>}>}
     * >}>
     */
    private function tree(array $counts, string $alias, int $sourceId): array
    {
        $tree = [];
        $groups = array_map(static fn(string|int $key): string => (string)$key, array_keys($counts));
        usort($groups, self::compareText(...));
        foreach ($groups as $group) {
            $children = [];
            $families = array_map(
                static fn(string|int $key): string => (string)$key,
                array_keys($counts[$group]),
            );
            usort($families, self::compareText(...));
            foreach ($families as $family) {
                $sourceCategories = [];
                $categoryNames = array_map(
                    static fn(string|int $key): string => (string)$key,
                    array_keys($counts[$group][$family]),
                );
                usort($categoryNames, self::compareText(...));
                foreach ($categoryNames as $categoryName) {
                    $sourceCategories[] = [
                        'name' => $categoryName,
                        'count' => $counts[$group][$family][$categoryName],
                    ];
                }
                $sourceNode = [
                    'name' => $alias,
                    'source_id' => $sourceId,
                    'count' => array_sum($counts[$group][$family]),
                    'children' => $sourceCategories,
                ];
                if ($family === '') {
                    $children[] = $sourceNode;
                    continue;
                }
                $children[] = [
                    'name' => $family,
                    'children' => [$sourceNode],
                ];
            }
            $tree[] = ['name' => $group, 'children' => $children];
        }
        return $tree;
    }

    private function code(mixed $value): string
    {
        if (!is_scalar($value)) {
            throw new RuntimeException('分类预览商品编号格式不正确。');
        }
        $value = trim((string)$value);
        if ($value === '' || strlen($value) > 64 || preg_match('/[\x00-\x20\x7F]/', $value) === 1) {
            throw new RuntimeException('分类预览商品编号格式不正确。');
        }
        return $value;
    }

    private function text(mixed $value, int $max, string $label): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            throw new RuntimeException("分类预览{$label}格式不正确。");
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > $max || preg_match('/\p{Cc}/u', $value) === 1) {
            throw new RuntimeException("分类预览{$label}格式不正确。");
        }
        return $value;
    }

    private function sourceCategory(mixed $value): string
    {
        $value = $this->text($value, 128, '分类名称');
        if (preg_match('/[\p{Cf}\p{Zl}\p{Zp}]/u', $value) === 1) {
            throw new RuntimeException('分类预览分类名称格式不正确。');
        }
        return $value;
    }

    private static function compareText(string $left, string $right): int
    {
        $folded = strcmp(mb_strtolower($left, 'UTF-8'), mb_strtolower($right, 'UTF-8'));
        return $folded !== 0 ? $folded : strcmp($left, $right);
    }
}
