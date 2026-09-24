<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaCatalogHub\Service;

use App\Model\Category;
use App\Model\UserGroup;
use Pika\LocalExtensions\PikaSupplySync\Service\UpstreamCategoryTree;
use RuntimeException;

/** Read only the ancestor closure of this request's native, authorized items. */
final class SharedCategoryTree
{
    private \Closure $load;

    public function __construct(?UserGroup $group = null, ?callable $load = null)
    {
        $this->load = $load === null ? static function (int $id) use ($group): ?array {
            $row = Category::query()->find($id, ['id', 'pid', 'name', 'sort', 'owner', 'status', 'hide', 'user_level_config', 'icon']);
            if ($row === null) {
                return null;
            }
            $values = $row->toArray();
            // The native shared catalog does not enforce this category gate.
            // Mirror mode deliberately applies the native shop group rule too.
            $config = (int)($values['hide'] ?? -1) === 1 ? $row->getLevelConfig($group) : null;
            $values['group_visible'] = is_array($config) && in_array($config['show'] ?? null, [1, '1'], true);
            return $values;
        } : \Closure::fromCallable($load);
    }

    /** Only requested categories inside the native merchant's authorized closure. */
    public function exportIcons(mixed $native, array $ids): array
    {
        $ids = UpstreamCategoryTree::iconIds($ids);
        $authorized = array_column($this->export($native, 2)['categories'], null, 'id');
        $categories = [];
        foreach ($ids as $id) {
            if (!isset($authorized[$id])) {
                throw new RuntimeException('PIKA_TREE_ANCESTOR_UNAVAILABLE');
            }
            $row = ($this->load)($id);
            if (!is_array($row) || !array_key_exists('icon', $row) || $this->projection($row) !== $authorized[$id]
                || $this->integer($row['status'] ?? null) !== 1
                || !in_array($row['hide'] ?? null, [0, '0', 1, '1'], true)
                || ((int)$row['hide'] === 1 && ($row['group_visible'] ?? null) !== true)) {
                throw new RuntimeException('PIKA_TREE_CHANGED');
            }
            $categories[] = $authorized[$id] + ['icon' => $row['icon'] === null ? '' : $row['icon']];
        }
        $snapshot = ['schema' => 3, 'capability' => 'pika_category_icons', 'categories' => $categories];
        $snapshot['categories'] = array_values(UpstreamCategoryTree::iconNodes($snapshot, $ids));
        return $snapshot;
    }

    public function export(mixed $native, int $schema = 1): array
    {
        if (!in_array($schema, [1, 2], true) || !is_array($native) || !array_is_list($native)
            || count($native) > UpstreamCategoryTree::MAX_CATEGORIES) {
            throw new RuntimeException('PIKA_TREE_UNAVAILABLE');
        }
        $items = [];
        $leaves = [];
        foreach ($native as $group) {
            if (!is_array($group) || !is_array($group['children'] ?? null) || !array_is_list($group['children'])) {
                throw new RuntimeException('PIKA_TREE_UNAVAILABLE');
            }
            // 3.7.0 may retain an empty group after its hidden-product filter.
            if ($group['children'] === []) {
                continue;
            }
            $leaf = $this->projection($group);
            if (isset($leaves[$leaf['id']]) && $leaves[$leaf['id']] !== $leaf) {
                throw new RuntimeException('PIKA_TREE_ID_CONFLICT');
            }
            $leaves[$leaf['id']] = $leaf;
            foreach ($group['children'] as $item) {
                if (!is_array($item) || $this->integer($item['category_id'] ?? null, 1) !== $leaf['id']) {
                    throw new RuntimeException('PIKA_TREE_UNAVAILABLE');
                }
                $projection = ['code' => $item['code'] ?? null, 'category_id' => $leaf['id']];
                if ($schema === 1) {
                    $projection['name'] = $item['name'] ?? null;
                }
                $projection['stock'] = $this->integer($item['stock'] ?? null);
                $items[] = $projection;
                if (count($items) > UpstreamCategoryTree::MAX_ITEMS) {
                    throw new RuntimeException('PIKA_TREE_LIMIT_EXCEEDED');
                }
            }
        }
        $nodes = [];
        $owners = [];
        foreach ($leaves as $leaf) {
            $id = $leaf['id'];
            $seen = [];
            $childOwner = null;
            while ($id !== 0) {
                if (isset($seen[$id])) {
                    throw new RuntimeException('PIKA_TREE_CYCLE');
                }
                if (count($seen) >= UpstreamCategoryTree::MAX_DEPTH) {
                    throw new RuntimeException('PIKA_TREE_LIMIT_EXCEEDED');
                }
                $seen[$id] = true;
                if (!isset($nodes[$id])) {
                    if (count($nodes) >= UpstreamCategoryTree::MAX_TREE_NODES) {
                        throw new RuntimeException('PIKA_TREE_LIMIT_EXCEEDED');
                    }
                    $row = ($this->load)($id);
                    if (!is_array($row) || $this->integer($row['id'] ?? null, 1) !== $id
                        || $this->integer($row['status'] ?? null) !== 1
                        || !in_array($row['hide'] ?? null, [0, '0', 1, '1'], true)
                        || ((int)$row['hide'] === 1 && ($row['group_visible'] ?? null) !== true)) {
                        throw new RuntimeException('PIKA_TREE_ANCESTOR_UNAVAILABLE');
                    }
                    $owners[$id] = $this->integer($row['owner'] ?? null);
                    $nodes[$id] = $this->projection($row);
                }
                if ($childOwner !== null && $owners[$id] !== $childOwner) {
                    throw new RuntimeException('PIKA_TREE_ANCESTOR_UNAVAILABLE');
                }
                if ($id === $leaf['id'] && $nodes[$id] !== $leaf) {
                    throw new RuntimeException('PIKA_TREE_CHANGED');
                }
                $childOwner = $owners[$id];
                $id = $nodes[$id]['pid'];
            }
        }
        ksort($nodes, SORT_NUMERIC);
        $result = ['schema' => $schema, 'capability' => 'pika_category_tree', 'categories' => array_values($nodes), 'items' => $items];
        (new UpstreamCategoryTree())->flatten($result);
        return $result;
    }

    private function projection(array $node): array
    {
        if (!array_key_exists('pid', $node)) {
            throw new RuntimeException('PIKA_TREE_ANCESTOR_UNAVAILABLE');
        }
        return UpstreamCategoryTree::node(['id' => $this->integer($node['id'] ?? null, 1),
            'pid' => $node['pid'] === null ? 0 : $this->integer($node['pid'] ?? null),
            'name' => $node['name'] ?? null, 'sort' => $this->integer($node['sort'] ?? null)]);
    }

    private function integer(mixed $value, int $minimum = 0): int
    {
        if ((!is_int($value) && (!is_string($value) || preg_match('/^(0|[1-9][0-9]{0,9})$/D', $value) !== 1))
            || (int)$value < $minimum || (int)$value > 0x7fffffff) {
            throw new RuntimeException('PIKA_TREE_ANCESTOR_UNAVAILABLE');
        }
        return (int)$value;
    }
}
