<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaSupplySync\Service;

use RuntimeException;

/** The bounded, ID-based initial-import plan. It never infers a parent from a name. */
final class UpstreamCategoryTree
{
    public const MAX_CATEGORIES = 200;
    public const MAX_TREE_NODES = 2048;
    public const MAX_ITEMS = 10000;
    public const MAX_DEPTH = 100;

    /** Canonical bounded identities for the separate, read-only icon request. */
    public static function iconIds(array $ids): array
    {
        if (!array_is_list($ids) || $ids === [] || count($ids) > 100) {
            throw new RuntimeException('PIKA_TREE_INVALID');
        }
        $seen = [];
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1 || $id > 0x7fffffff || isset($seen[$id])) {
                throw new RuntimeException('PIKA_TREE_INVALID');
            }
            $seen[$id] = true;
        }
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** Validate selected category metadata without changing any frozen import plan. */
    public static function iconNodes(mixed $snapshot, array $ids): array
    {
        $ids = self::iconIds($ids);
        if (!is_array($snapshot) || ($snapshot['schema'] ?? null) !== 3
            || ($snapshot['capability'] ?? null) !== 'pika_category_icons') {
            throw new RuntimeException('PIKA_TREE_UNSUPPORTED');
        }
        if (!self::keys($snapshot, ['schema', 'capability', 'categories'])
            || !is_array($snapshot['categories']) || !array_is_list($snapshot['categories'])
            || count($snapshot['categories']) !== count($ids)) {
            throw new RuntimeException('PIKA_TREE_INVALID');
        }
        $nodes = [];
        foreach ($snapshot['categories'] as $value) {
            if (!is_array($value) || !self::keys($value, ['id', 'pid', 'name', 'sort', 'icon'])) {
                throw new RuntimeException('PIKA_TREE_INVALID');
            }
            $icon = $value['icon'];
            if (!is_string($icon) || strlen($icon) > 2048 || !mb_check_encoding($icon, 'UTF-8')
                || preg_match('/[\x00-\x20\x7F\p{Cc}\p{Cf}\p{Zl}\p{Zp}\\\\]/u', $icon)
                || preg_match('/\A\s|\s\z/u', $icon) || str_starts_with($icon, '//')) {
                throw new RuntimeException('PIKA_TREE_INVALID');
            }
            $scheme = parse_url($icon, PHP_URL_SCHEME);
            if ($scheme === false || ($scheme !== null && strcasecmp($scheme, 'https') !== 0)) {
                throw new RuntimeException('PIKA_TREE_INVALID');
            }
            unset($value['icon']);
            $node = self::node($value);
            if (isset($nodes[$node['id']])) {
                throw new RuntimeException('PIKA_TREE_ID_CONFLICT');
            }
            $nodes[$node['id']] = $node + ['icon' => $icon];
        }
        ksort($nodes, SORT_NUMERIC);
        if (array_keys($nodes) !== $ids) {
            throw new RuntimeException('PIKA_TREE_INVALID');
        }
        return $nodes;
    }

    public static function normalizeTarget(mixed $target): array
    {
        if (!is_array($target) || !self::keys($target, array_key_exists('category_icons', $target)
                ? ['mode', 'path', 'category_icons'] : ['mode', 'path'])
            || (array_key_exists('category_icons', $target) && $target['category_icons'] !== true)
            || $target['mode'] !== 'mirror' || !is_array($target['path'])
            || !array_is_list($target['path']) || $target['path'] === []
            || count($target['path']) > self::MAX_DEPTH) {
            throw new RuntimeException('PIKA_TREE_INVALID');
        }
        $path = [];
        $seen = [];
        $parent = 0;
        foreach ($target['path'] as $node) {
            $node = self::node($node);
            if (isset($seen[$node['id']]) || $node['pid'] !== $parent) {
                throw new RuntimeException('PIKA_TREE_INVALID');
            }
            $seen[$node['id']] = true;
            $path[] = $node;
            $parent = $node['id'];
        }
        return ['mode' => 'mirror', 'path' => $path] + (array_key_exists('category_icons', $target) ? ['category_icons' => true] : []);
    }

    public static function categoryKey(string $name, array $target): string
    {
        if (($target['mode'] ?? null) !== 'mirror') {
            return 'category:' . $name;
        }
        $path = self::normalizeTarget($target)['path'];
        $leaf = $path[count($path) - 1];
        if ($leaf['name'] !== $name) {
            throw new RuntimeException('PIKA_TREE_INVALID');
        }
        return 'upstream:' . $leaf['id'];
    }

    /** Project one opt-in response; reject extra branches and every incomplete ancestry. */
    public function flatten(mixed $snapshot, bool $categoryIcons = false): array
    {
        if (!is_array($snapshot) || !in_array($snapshot['schema'] ?? null, [1, 2], true)
            || ($snapshot['capability'] ?? null) !== 'pika_category_tree') {
            throw new RuntimeException('PIKA_TREE_UNSUPPORTED');
        }
        $schema = $snapshot['schema'];
        if (!self::keys($snapshot, ['schema', 'capability', 'categories', 'items'])
            || !is_array($snapshot['categories']) || !array_is_list($snapshot['categories'])
            || !is_array($snapshot['items']) || !array_is_list($snapshot['items'])
            || $snapshot['categories'] === [] || $snapshot['items'] === []
            || count($snapshot['categories']) > self::MAX_TREE_NODES
            || count($snapshot['items']) > self::MAX_ITEMS) {
            throw new RuntimeException('PIKA_TREE_LIMIT_EXCEEDED');
        }
        $nodes = [];
        foreach ($snapshot['categories'] as $node) {
            $node = self::node($node);
            if (isset($nodes[$node['id']])) {
                throw new RuntimeException('PIKA_TREE_ID_CONFLICT');
            }
            $nodes[$node['id']] = $node;
        }
        $catalog = [];
        $used = [];
        $itemCategories = [];
        foreach ($snapshot['items'] as $item) {
            if (!is_array($item) || !self::keys($item, $schema === 1
                    ? ['code', 'category_id', 'name', 'stock'] : ['code', 'category_id', 'stock'])
                || !is_string($item['code']) || $item['code'] === '' || strlen($item['code']) > 64
                || preg_match('/[\x00-\x20\x7F]/', $item['code'])
                || !is_int($item['category_id']) || $item['category_id'] < 1
                || !is_int($item['stock']) || $item['stock'] < 0 || $item['stock'] > 0x7fffffff
                || ($schema === 1 && (!is_string($item['name']) || !mb_check_encoding($item['name'], 'UTF-8')
                    || mb_strlen($item['name'], 'UTF-8') > 255
                    || preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $item['name'])))) {
                throw new RuntimeException('PIKA_TREE_INVALID');
            }
            if (isset($catalog[$item['code']])) {
                throw new RuntimeException('PIKA_TREE_ID_CONFLICT');
            }
            $id = $item['category_id'];
            $itemCategories[$id] = true;
            if (count($itemCategories) > self::MAX_CATEGORIES) {
                throw new RuntimeException('PIKA_TREE_LIMIT_EXCEEDED');
            }
            $path = [];
            $seen = [];
            while ($id !== 0) {
                if (isset($seen[$id])) {
                    throw new RuntimeException('PIKA_TREE_CYCLE');
                }
                if (!isset($nodes[$id])) {
                    throw new RuntimeException('PIKA_TREE_ANCESTOR_UNAVAILABLE');
                }
                if (count($path) >= self::MAX_DEPTH) {
                    throw new RuntimeException('PIKA_TREE_LIMIT_EXCEEDED');
                }
                $seen[$id] = $used[$id] = true;
                array_unshift($path, $nodes[$id]);
                $id = $nodes[$id]['pid'];
            }
            $leaf = $path[count($path) - 1];
            $catalog[$item['code']] = ['code' => $item['code']]
                + ($schema === 1 ? ['name' => $item['name']] : []) + [
                'category' => $leaf['name'],
                'stock' => $item['stock'], 'item' => [], 'target' => ['mode' => 'mirror', 'path' => $path]
                    + ($categoryIcons ? ['category_icons' => true] : []),
            ];
        }
        if (count($used) !== count($nodes)) {
            throw new RuntimeException('PIKA_TREE_UNEXPECTED_BRANCH');
        }
        ksort($catalog, SORT_STRING);
        return $catalog;
    }

    /** Same suggestion shape as smart mode, with identity and product bindings frozen into the hash. */
    public function suggest(array $catalog): array
    {
        if ($catalog === [] || count($catalog) > self::MAX_ITEMS) {
            throw new RuntimeException('PIKA_TREE_LIMIT_EXCEEDED');
        }
        $categories = [];
        $bindings = [];
        $nodes = [];
        foreach ($catalog as $row) {
            if (!is_array($row) || !is_string($row['category'] ?? null)
                || !is_string($row['code'] ?? null) || !is_int($row['stock'] ?? null)) {
                throw new RuntimeException('PIKA_TREE_INVALID');
            }
            $target = self::normalizeTarget($row['target'] ?? null);
            $key = self::categoryKey($row['category'], $target);
            foreach ($target['path'] as $node) {
                if (isset($nodes[$node['id']]) && $nodes[$node['id']] !== $node) {
                    throw new RuntimeException('PIKA_TREE_ID_CONFLICT');
                }
                $nodes[$node['id']] = $node;
            }
            $categories[$key] ??= ['name' => $row['category'], 'count' => 0, 'target' => $target, 'confidence' => 'high'];
            if ($categories[$key]['target'] !== $target || isset($bindings[$row['code']])) {
                throw new RuntimeException('PIKA_TREE_ID_CONFLICT');
            }
            $categories[$key]['count']++;
            $bindings[$row['code']] = ['code' => $row['code'], 'category_key' => $key, 'stock' => $row['stock']];
        }
        if (count($categories) > self::MAX_CATEGORIES || count($nodes) > self::MAX_TREE_NODES) {
            throw new RuntimeException('PIKA_TREE_LIMIT_EXCEEDED');
        }
        ksort($categories, SORT_STRING);
        ksort($bindings, SORT_STRING);
        $payload = ['schema' => 1, 'category_mode' => 'mirror', 'categories' => array_values($categories),
            'counts' => ['items' => count($catalog), 'categories' => count($categories), 'high' => count($categories), 'low' => 0]];
        return $payload + ['plan_hash' => hash('sha256', json_encode(
            [$payload, array_values($bindings)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ))];
    }

    public static function node(mixed $node): array
    {
        if (!is_array($node) || !self::keys($node, ['id', 'pid', 'name', 'sort'])
            || !is_int($node['id']) || $node['id'] < 1 || $node['id'] > 0x7fffffff
            || !is_int($node['pid']) || $node['pid'] < 0 || $node['pid'] > 0x7fffffff
            || !is_int($node['sort']) || $node['sort'] < 0 || $node['sort'] > 0x7fffffff
            || !is_string($node['name']) || !mb_check_encoding($node['name'], 'UTF-8')
            || $node['name'] === '' || mb_strlen($node['name'], 'UTF-8') > 128
            || preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $node['name'])
            || preg_match('/\A[\s\p{Z}]|[\s\p{Z}]\z/u', $node['name'])) {
            throw new RuntimeException('PIKA_TREE_INVALID');
        }
        return ['id' => $node['id'], 'pid' => $node['pid'], 'name' => $node['name'], 'sort' => $node['sort']];
    }

    private static function keys(array $value, array $keys): bool
    {
        return count($value) === count($keys) && array_diff(array_keys($value), $keys) === [];
    }
}
