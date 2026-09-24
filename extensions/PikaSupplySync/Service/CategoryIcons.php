<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaSupplySync\Service;

use App\Model\Shared;
use RuntimeException;

/** Category-only image preparation; callers own the source lock and receipt persistence. */
final class CategoryIcons
{
    private const DEFAULT_ICON = '/favicon.ico';
    private array $created = [];

    public function __construct(
        private SharedGateway $gateway,
        private ImageCache $images,
        private ?PlannedCategoryMapper $mapper = null,
    ) {
    }

    /** Only missing nodes from a capability-bearing, frozen mirror target enter here. */
    public function forCreation(Shared $source, array $missingNodes): array
    {
        if (!array_is_list($missingNodes) || $missingNodes === [] || count($missingNodes) > 100) {
            throw new RuntimeException('分类图新建节点不正确。');
        }
        $nodes = [];
        $fingerprint = SourceIdentity::fingerprint($source);
        $fetch = [];
        foreach ($missingNodes as $node) {
            if (!is_array($node) || !$this->keys($node, ['id', 'pid', 'name', 'sort'])) {
                throw new RuntimeException('分类图新建节点不正确。');
            }
            // Reuse the strict metadata node validator without requiring a complete path.
            $validated = UpstreamCategoryTree::iconNodes([
                'schema' => 3, 'capability' => 'pika_category_icons', 'categories' => [$node + ['icon' => '']],
            ], [$node['id'] ?? null]);
            $id = $node['id'];
            if (isset($nodes[$id])) throw new RuntimeException('分类图新建节点重复。');
            unset($validated[$id]['icon']);
            $nodes[$id] = $validated[$id];
            $cached = $this->created[$fingerprint][$id] ?? null;
            if ($cached !== null && $cached['node'] !== $nodes[$id]) {
                throw new RuntimeException('分类图新建节点已偏离冻结方案。');
            }
            if ($cached === null) $fetch[] = $id;
        }
        if ($fetch !== []) {
            $remote = $this->gateway->categoryIcons($source, $fetch);
            // Check the entire response before downloading any image.
            foreach ($fetch as $id) {
                $node = $remote[$id];
                unset($node['icon']);
                if ($node !== $nodes[$id]) throw new RuntimeException('上游分类已偏离冻结方案。');
            }
            foreach ($fetch as $id) {
                $icon = $remote[$id]['icon'];
                $local = $this->isDefault($icon) ? self::DEFAULT_ICON : $this->images->localize($source, $icon);
                $this->created[$fingerprint][$id] = ['node' => $nodes[$id], 'icon' => $local];
            }
        }
        $result = [];
        foreach ($nodes as $id => $_) $result[$id] = $this->created[$fingerprint][$id]['icon'];
        return $result;
    }

    /** Read-only database/metadata preview. The private plan is persisted by the CLI. */
    public function preview(Shared $source, array $ids): array
    {
        $ids = UpstreamCategoryTree::iconIds($ids);
        if (count($ids) > 16) throw new RuntimeException('分类图补齐最多允许 16 项。');
        $targets = $this->mapper()->mirrorIconTargets($source, $ids);
        $remote = $this->gateway->categoryIcons($source, $ids);
        foreach ($targets as &$target) {
            $node = $remote[$target['upstream_id']];
            if ($node['pid'] !== $target['upstream_pid'] || $node['name'] !== $target['name']) {
                throw new RuntimeException('上游分类已偏离现有镜像映射。');
            }
            $target['source_icon'] = $node['icon'];
        }
        unset($target);
        $plan = ['schema' => 1, 'source_id' => (int)$source->id,
            'source_fingerprint' => SourceIdentity::fingerprint($source),
            'ids' => $ids, 'targets' => $targets, 'skipped' => $this->skipped($targets)];
        $this->validatePlan($source, $plan);
        return $plan;
    }

    /** Download all selected images, with no category write and no fresh metadata request. */
    public function prepare(Shared $source, array $plan): array
    {
        $this->validatePlan($source, $plan);
        $current = $this->mapper()->mirrorIconTargets($source, $plan['ids']);
        foreach ($plan['targets'] as $index => $target) {
            unset($target['source_icon']);
            if ($current[$index] !== $target) throw new RuntimeException('分类图预览后目标已改变。');
        }
        $changes = [];
        $cache = [];
        foreach ($plan['targets'] as $target) {
            if (!$this->eligible($target)) continue;
            $newIcon = $this->images->localize($source, $target['source_icon']);
            $changes[] = $this->change($target, $newIcon);
            $cache[] = $this->cacheIdentity($newIcon);
        }
        return ['schema' => 1, 'plan' => $plan, 'changes' => $changes, 'cache' => $cache];
    }

    /** The caller must durably save this exact receipt before the forward CAS. */
    public function apply(Shared $source, array $receipt, bool $rollback = false): array
    {
        $this->validateReceipt($source, $receipt);
        if (!$rollback) {
            foreach ($receipt['cache'] as $identity) {
                if ($this->cacheIdentity($identity['path']) !== $identity) {
                    throw new RuntimeException('分类图缓存已改变，整批未写入。');
                }
            }
        }
        $result = $receipt['changes'] === []
            ? ['status' => 'no_changes', 'changed' => 0, 'total' => 0]
            : $this->mapper()->compareAndSetMirrorIcons($source, $receipt['changes'], $rollback);
        return $result + ['skipped' => $receipt['plan']['skipped']];
    }

    private function validatePlan(Shared $source, array $plan): void
    {
        if (!$this->keys($plan, ['schema', 'source_id', 'source_fingerprint', 'ids', 'targets', 'skipped'])
            || $plan['schema'] !== 1 || $plan['source_id'] !== (int)$source->id
            || !is_string($plan['source_fingerprint'])
            || !hash_equals(SourceIdentity::fingerprint($source), $plan['source_fingerprint'])
            || !is_array($plan['ids']) || count($plan['ids']) > 16
            || UpstreamCategoryTree::iconIds($plan['ids']) !== $plan['ids']
            || !is_array($plan['targets']) || !array_is_list($plan['targets'])
            || count($plan['targets']) !== count($plan['ids'])) {
            throw new RuntimeException('分类图计划身份或格式不正确。');
        }
        $locals = [];
        foreach ($plan['targets'] as $index => $target) {
            if (!is_array($target)
                || !$this->keys($target, ['upstream_id', 'upstream_pid', 'local_id', 'pid', 'name', 'icon', 'source_icon'])
                || $target['upstream_id'] !== $plan['ids'][$index]
                || !is_int($target['local_id']) || $target['local_id'] < 1 || $target['local_id'] > 0x7fffffff
                || isset($locals[$target['local_id']])
                || ($target['pid'] !== null && (!is_int($target['pid']) || $target['pid'] < 1 || $target['pid'] > 0x7fffffff))
                || !is_string($target['icon']) || strlen($target['icon']) > 2048) {
                throw new RuntimeException('分类图计划目标不正确。');
            }
            UpstreamCategoryTree::iconNodes(['schema' => 3, 'capability' => 'pika_category_icons',
                'categories' => [['id' => $target['upstream_id'], 'pid' => $target['upstream_pid'],
                    'name' => $target['name'], 'sort' => 0, 'icon' => $target['source_icon']]]], [$target['upstream_id']]);
            $locals[$target['local_id']] = true;
        }
        if ($plan['skipped'] !== $this->skipped($plan['targets'])) {
            throw new RuntimeException('分类图计划计数不正确。');
        }
    }

    private function validateReceipt(Shared $source, array $receipt): void
    {
        if (!$this->keys($receipt, ['schema', 'plan', 'changes', 'cache']) || $receipt['schema'] !== 1
            || !is_array($receipt['plan']) || !is_array($receipt['changes']) || !array_is_list($receipt['changes'])
            || !is_array($receipt['cache']) || !array_is_list($receipt['cache'])) {
            throw new RuntimeException('分类图回执格式不正确。');
        }
        $this->validatePlan($source, $receipt['plan']);
        $targets = array_values(array_filter($receipt['plan']['targets'], fn(array $target): bool => $this->eligible($target)));
        if (count($targets) !== count($receipt['changes']) || count($targets) !== count($receipt['cache'])) {
            throw new RuntimeException('分类图回执数量不正确。');
        }
        foreach ($targets as $index => $target) {
            $change = $receipt['changes'][$index];
            $cache = $receipt['cache'][$index];
            if (!is_array($change) || !is_array($cache) || !$this->keys($cache, ['path', 'sha256', 'bytes'])
                || !$this->localIcon($cache['path']) || !is_string($cache['sha256'])
                || preg_match('/^[a-f0-9]{64}$/D', $cache['sha256']) !== 1
                || !is_int($cache['bytes']) || $cache['bytes'] < 1 || $cache['bytes'] > 5242880
                || $change !== $this->change($target, $cache['path'])) {
                throw new RuntimeException('分类图回执绑定不正确。');
            }
        }
    }

    private function change(array $target, string $newIcon): array
    {
        return ['upstream_id' => $target['upstream_id'], 'upstream_pid' => $target['upstream_pid'],
            'local_id' => $target['local_id'], 'pid' => $target['pid'], 'name' => $target['name'],
            'old_icon' => $target['icon'], 'new_icon' => $newIcon];
    }

    private function skipped(array $targets): array
    {
        $counts = ['manual' => 0, 'upstream_default' => 0];
        foreach ($targets as $target) {
            if ($target['icon'] !== self::DEFAULT_ICON) $counts['manual']++;
            elseif ($this->isDefault($target['source_icon'])) $counts['upstream_default']++;
        }
        return $counts;
    }

    private function eligible(array $target): bool
    {
        return $target['icon'] === self::DEFAULT_ICON && !$this->isDefault($target['source_icon']);
    }

    private function isDefault(string $icon): bool
    {
        return $icon === '' || $icon === self::DEFAULT_ICON;
    }

    private function mapper(): PlannedCategoryMapper
    {
        if ($this->mapper === null) throw new RuntimeException('分类图维护映射器未配置。');
        return $this->mapper;
    }

    private function localIcon(mixed $path): bool
    {
        return is_string($path)
            && preg_match('~^/assets/cache/pika-supply-sync/[a-f0-9]{64}(?:-[a-f0-9]{64})?\.(?:jpg|png|gif|webp|avif)$~D', $path) === 1;
    }

    /** No directory creation or permission change while validating a saved receipt. */
    private function cacheIdentity(string $publicPath): array
    {
        if (!$this->localIcon($publicPath) || !defined('BASE_PATH')) {
            throw new RuntimeException('分类图缓存路径不正确。');
        }
        $root = realpath((string)BASE_PATH);
        if ($root === false) throw new RuntimeException('分类图站点路径不可用。');
        $path = $root;
        foreach (explode('/', ltrim($publicPath, '/')) as $part) {
            $path .= '/' . $part;
            if (is_link($path)) throw new RuntimeException('分类图缓存不能包含链接。');
        }
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1
            || $stat['size'] < 1 || $stat['size'] > 5242880 || realpath($path) !== $path) {
            throw new RuntimeException('分类图缓存不可读取。');
        }
        $hash = hash_file('sha256', $path);
        if (!is_string($hash)) throw new RuntimeException('分类图缓存校验失败。');
        return ['path' => $publicPath, 'sha256' => $hash, 'bytes' => $stat['size']];
    }

    private function keys(array $value, array $keys): bool
    {
        return count($value) === count($keys) && array_diff($keys, array_keys($value)) === [];
    }
}
