<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaSupplySync\Service;

use App\Model\Category;
use App\Model\Shared;
use App\Util\Date;
use Illuminate\Database\Capsule\Manager as DB;
use Pika\LocalExtensions\Manager\AtomicJson;
use Pika\LocalExtensions\Manager\PathGuard;
use RuntimeException;

final class PlannedCategoryMapper
{
    private const SCHEMA = 2;
    private const MAX_NODES = 2048;
    private const MAX_BYTES = 1048576;
    private const MAX_PLAN_ITEMS = 10000;
    private const MAX_ICON_TARGETS = 16;
    private const DEFAULT_ICON = '/favicon.ico';

    /** @var \Closure(callable):mixed */
    private \Closure $transaction;

    /** @var \Closure(Shared,array):array|null */
    private ?\Closure $iconProvider;

    /** @param callable(callable):mixed|null $transaction @param callable(Shared,array):array|null $iconProvider */
    public function __construct(?callable $transaction = null, ?callable $iconProvider = null)
    {
        $this->transaction = $transaction === null
            ? static fn(callable $callback): mixed => DB::transaction($callback)
            : \Closure::fromCallable($transaction);
        $this->iconProvider = $iconProvider === null ? null : \Closure::fromCallable($iconProvider);
    }

    /** Existing mirror ownership selects the opt-in route; the response still proves current capability. */
    public function hasMirrorMapping(int $sourceId): bool
    {
        if ($sourceId < 1 || $sourceId > 4294967295) {
            throw new RuntimeException('共享店铺 ID 不正确');
        }
        // Legacy synchronization accepts unsigned IDs; mirror metadata is signed-int bounded.
        if ($sourceId > 0x7fffffff) return false;
        $path = PathGuard::stateRoot() . '/extensions/PikaCatalogHub/category-map.json';
        if (!file_exists($path) && !is_link($path)) {
            return false;
        }
        return $this->withRenameMapLock(function (string $path) use ($sourceId): bool {
            $registry = $this->read($path);
            if ($this->mirrorNodes($registry, $sourceId) === []) {
                return false;
            }
            $this->assertSourceMode($registry, $sourceId, true);
            return true;
        });
    }

    /**
     * Read the exact managed rows and their complete ancestry without changing
     * the map, a confirmed plan, or any category. Root rows retain a null pid.
     * @param list<int> $upstreamIds
     * @return list<array{upstream_id:int,upstream_pid:int,local_id:int,pid:?int,name:string,icon:string}>
     */
    public function mirrorIconTargets(Shared $source, array $upstreamIds): array
    {
        $sourceId = $this->iconSourceId($source);
        $this->assertIconIds($upstreamIds);
        return $this->withRenameMapLock(function (string $path) use ($source, $sourceId, $upstreamIds): array {
            $registry = $this->read($path);
            $this->assertSourceMode($registry, $sourceId, true);
            return ($this->transaction)(function () use ($source, $sourceId, $upstreamIds, $registry): array {
                SourceIdentity::lockAndVerify($source);
                return $this->lockedMirrorIconTargets($registry, $sourceId, $upstreamIds);
            });
        });
    }

    /**
     * A bounded icon-only compare-and-set. All identities and old/new states
     * are checked before the first write; a conflict rolls back the whole batch.
     * The original forward changes are also the rollback input.
     * @param list<array{upstream_id:int,upstream_pid:int,local_id:int,pid:?int,name:string,old_icon:string,new_icon:string}> $changes
     * @return array{status:string,changed:int,total:int}
     */
    public function compareAndSetMirrorIcons(Shared $source, array $changes, bool $rollback = false): array
    {
        $sourceId = $this->iconSourceId($source);
        if (!array_is_list($changes)) {
            throw new RuntimeException('分类图变更列表不正确。');
        }
        $ids = [];
        foreach ($changes as $change) {
            if (!is_array($change)
                || count($change) !== 7
                || array_diff(['upstream_id', 'upstream_pid', 'local_id', 'pid', 'name', 'old_icon', 'new_icon'], array_keys($change)) !== []
                || !is_int($change['upstream_pid']) || $change['upstream_pid'] < 0 || $change['upstream_pid'] > 0x7fffffff
                || !is_int($change['local_id']) || $change['local_id'] < 1 || $change['local_id'] > 0x7fffffff
                || ($change['pid'] !== null && (!is_int($change['pid']) || $change['pid'] < 1 || $change['pid'] > 0x7fffffff))
                || $change['old_icon'] !== self::DEFAULT_ICON
                || !$this->isLocalIcon($change['new_icon'])) {
                throw new RuntimeException('分类图变更字段不正确。');
            }
            $this->nodeName($change['name'], '分类图目标名称');
            $ids[] = $change['upstream_id'];
        }
        $this->assertIconIds($ids);
        return $this->withRenameMapLock(function (string $path) use ($source, $sourceId, $changes, $ids, $rollback): array {
            $registry = $this->read($path);
            $this->assertSourceMode($registry, $sourceId, true);
            return ($this->transaction)(function () use ($source, $sourceId, $changes, $ids, $rollback, $registry): array {
                SourceIdentity::lockAndVerify($source);
                $targets = $this->lockedMirrorIconTargets($registry, $sourceId, $ids);
                $states = [];
                foreach ($changes as $index => $change) {
                    $target = $targets[$index];
                    foreach (['upstream_id', 'upstream_pid', 'local_id', 'pid', 'name'] as $field) {
                        if ($target[$field] !== $change[$field]) {
                            throw new RuntimeException('分类图目标身份已改变，整批未写入。');
                        }
                    }
                    $state = $target['icon'] === $change['old_icon'] ? 'old'
                        : ($target['icon'] === $change['new_icon'] ? 'new' : null);
                    if ($state === null) {
                        throw new RuntimeException('分类图已被手动修改，整批未写入。');
                    }
                    $states[$state] = true;
                }
                if (count($states) !== 1) {
                    throw new RuntimeException('分类图变更状态混合，整批未写入。');
                }
                $total = count($changes);
                $expected = $rollback ? 'new' : 'old';
                if (!isset($states[$expected])) {
                    return ['status' => $rollback ? 'already_rolled_back' : 'already_applied', 'changed' => 0, 'total' => $total];
                }
                foreach ($changes as $change) {
                    $updated = Category::query()->where('id', $change['local_id'])->where('owner', 0)
                        ->where('pid', $change['pid'])->where('name', $change['name'])
                        ->where('icon', $rollback ? $change['new_icon'] : $change['old_icon'])
                        ->update(['icon' => $rollback ? $change['old_icon'] : $change['new_icon']]);
                    if ($updated !== 1) {
                        throw new RuntimeException('分类图条件写入发生冲突，整批已拒绝。');
                    }
                }
                return ['status' => $rollback ? 'rolled_back' : 'applied', 'changed' => $total, 'total' => $total];
            });
        });
    }

    private function iconSourceId(Shared $source): int
    {
        $sourceId = (int)($source->id ?? 0);
        if ($sourceId < 1 || $sourceId > 0x7fffffff) {
            throw new RuntimeException('共享店铺 ID 不正确');
        }
        return $sourceId;
    }

    /** @param list<int> $ids */
    private function assertIconIds(array $ids): void
    {
        if (!array_is_list($ids) || count($ids) < 1 || count($ids) > self::MAX_ICON_TARGETS) {
            throw new RuntimeException('分类图目标数量不正确。');
        }
        $seen = [];
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1 || $id > 0x7fffffff || isset($seen[$id])) {
                throw new RuntimeException('分类图目标 ID 不正确。');
            }
            $seen[$id] = true;
        }
    }

    private function isLocalIcon(mixed $icon): bool
    {
        return is_string($icon)
            && preg_match('~^/assets/cache/pika-supply-sync/[a-f0-9]{64}(?:-[a-f0-9]{64})?\.(?:jpg|png|gif|webp|avif)$~D', $icon) === 1;
    }

    /** Caller holds the map lock and has verified the source inside its transaction. */
    private function lockedMirrorIconTargets(array $registry, int $sourceId, array $ids): array
    {
        $nodes = [];
        foreach ($ids as $id) {
            $key = $this->mirrorKey($sourceId, $id);
            do {
                $entry = $registry['nodes'][$key] ?? null;
                if (!is_array($entry) || ($entry['mode'] ?? null) !== 'mirror'
                    || $entry['source_id'] !== $sourceId) {
                    throw new RuntimeException('分类图目标没有精确的受管镜像映射。');
                }
                $nodes[$key] = $entry;
                $key = $entry['parent_key'];
            } while ($key !== null);
        }
        $rows = Category::query()->whereIn('id', array_column($nodes, 'id'))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($nodes as $entry) {
            $row = $rows->get($entry['id']);
            $pid = $entry['parent_key'] === null ? null : $nodes[$entry['parent_key']]['id'];
            if (!$row || (int)$row->owner !== 0 || ($row->pid === null ? null : (int)$row->pid) !== $pid
                || (string)$row->name !== $entry['name']) {
                throw new RuntimeException('分类图目标或祖先结构已改变，整批未写入。');
            }
        }
        $targets = [];
        foreach ($ids as $id) {
            $entry = $nodes[$this->mirrorKey($sourceId, $id)];
            $row = $rows->get($entry['id']);
            $targets[] = ['upstream_id' => $id,
                'upstream_pid' => $entry['parent_key'] === null ? 0 : $nodes[$entry['parent_key']]['upstream_id'],
                'local_id' => $entry['id'],
                'pid' => $row->pid === null ? null : (int)$row->pid,
                'name' => (string)$row->name, 'icon' => (string)$row->icon];
        }
        return $targets;
    }

    /**
     * Resolve the confirmed target path
     * `group[/family]/source alias/upstream category`, or the explicit upstream
     * ID path, without adopting another category that merely has the same name.
     *
     * @param array{group:string,family:string}|array{mode:string,path:list<array>} $target
     */
    public function resolve(
        Shared $source,
        string $alias,
        array $target,
        string $sourceCategory,
        string $planHash,
    ): Category
    {
        $category = $this->withResolvedCategory(
            $source,
            $alias,
            $target,
            $sourceCategory,
            $planHash,
            static fn(Category $resolved): Category => $resolved,
        );
        if (!$category instanceof Category) {
            throw new RuntimeException('智能分类解析结果不正确');
        }
        return $category;
    }

    public function hasSourceMapping(int $sourceId): bool
    {
        if ($sourceId < 1 || $sourceId > 0x7fffffff) {
            throw new RuntimeException('共享店铺 ID 不正确');
        }
        $directory = LocalPath::directory(
            'runtime/local-extensions/extensions/PikaCatalogHub',
            0700,
        );
        $path = $directory . '/category-map.json';
        $lockPath = $path . '.lock';
        $lock = $this->openLock($lockPath);
        try {
            $this->assertHandle($lock, $lockPath, '映射锁');
            if (!flock($lock, LOCK_SH)) {
                throw new RuntimeException('无法锁定智能分类映射');
            }
            $this->assertHandle($lock, $lockPath, '映射锁');
            $registry = $this->read($path);
            return $this->sourceAliases($registry, $sourceId) !== []
                || $this->mirrorNodes($registry, $sourceId) !== [];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function classificationAlias(int $sourceId, string $fallback): string
    {
        if ($sourceId < 1 || $sourceId > 0x7fffffff) {
            throw new RuntimeException('共享店铺 ID 不正确');
        }
        $fallback = $this->text($fallback, 64, '货源显示名称');
        $directory = LocalPath::directory(
            'runtime/local-extensions/extensions/PikaCatalogHub',
            0700,
        );
        $path = $directory . '/category-map.json';
        $lockPath = $path . '.lock';
        $lock = $this->openLock($lockPath);
        try {
            $this->assertHandle($lock, $lockPath, '映射锁');
            if (!flock($lock, LOCK_SH)) {
                throw new RuntimeException('无法锁定智能分类映射');
            }
            $this->assertHandle($lock, $lockPath, '映射锁');
            $registry = $this->read($path);
            $sourceNodes = $this->sourceNodes($registry, $sourceId);
            $aliases = $this->sourceAliases($registry, $sourceId);
            if (count($aliases) > 1) {
                throw new RuntimeException('该货源的分类映射包含不一致的历史名称，已停止分析。');
            }
            $this->assertSourceGraph($registry, $sourceId, $sourceNodes);
            return $aliases[0] ?? $fallback;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Read-only mode gate; the caller serializes configuration with SourceLock. */
    public function assertCategoryMode(int $sourceId, string $mode): void
    {
        if ($sourceId < 1 || $sourceId > 0x7fffffff || !in_array($mode, ['smart', 'mirror'], true)) {
            throw new RuntimeException('共享店铺或分类模式不正确。');
        }
        $this->withRenameMapLock(function (string $path) use ($sourceId, $mode): void {
            $this->assertSourceMode($this->read($path), $sourceId, $mode === 'mirror');
        });
    }

    public function assertNoPendingRename(): void
    {
        $receipt = $this->renameReceipt();
        if (($receipt['phase'] ?? null) === 'pending') {
            throw new RuntimeException('货源联动改名尚未完成，请先在对应货源重新保存名称以恢复。');
        }
    }

    /** Read-only check; the caller serializes saves with SourceLock. */
    public function sourceNeedsRename(int $sourceId, string $alias): bool
    {
        $receipt = $this->renameReceipt();
        if (($receipt['phase'] ?? null) === 'pending') {
            return true;
        }
        return $this->withRenameMapLock(function (string $path) use ($sourceId, $alias): bool {
            $registry = $this->read($path);
            $nodes = $this->sourceNodes($registry, $sourceId);
            $this->assertSourceGraph($registry, $sourceId, $nodes);
            foreach ($nodes as $node) {
                $row = Category::query()->find($node['id']);
                if (!$row || (int)$row->owner !== 0
                    || (int)$row->pid !== (int)$registry['nodes'][$node['parent_key']]['id']) {
                    throw new RuntimeException('受管货源分类结构已改变，已停止联动改名。');
                }
                if ($node['name'] !== $alias || (string)$row->name !== $alias) {
                    return true;
                }
            }
            return false;
        });
    }

    /** @param list<int> $sourceIds @return array<string,mixed>|null */
    public function managedSourceCategory(int $categoryId, array $sourceIds): ?array
    {
        return $this->withRenameMapLock(function (string $path) use ($categoryId, $sourceIds): ?array {
            // Identification must remain possible while this source needs recovery.
            $registry = $this->read($path, allowRenameReceipt: true);
            $receipt = $this->renameReceipt();
            if (($receipt['phase'] ?? null) === 'pending') {
                $sourceIds[] = $receipt['source_id'];
            }
            foreach (array_unique($sourceIds) as $sourceId) {
                $nodes = $this->sourceNodes($registry, $sourceId);
                foreach ($nodes as $node) {
                    if ($node['id'] !== $categoryId) {
                        continue;
                    }
                    $row = Category::query()->find($categoryId);
                    if (!$row || (int)$row->owner !== 0
                        || (int)$row->pid !== (int)($registry['nodes'][$node['parent_key']]['id'] ?? 0)) {
                        throw new RuntimeException('受管货源分类结构已改变，已停止联动改名。');
                    }
                    return ['source_id' => $sourceId, 'category_id' => $categoryId,
                        'category_name' => (string)$row->name, 'source_node_count' => count($nodes)];
                }
            }
            return null;
        });
    }

    /**
     * The caller holds SourceLock. Only this operation's node delta is journaled.
     * DB rows are committed before either JSON projection is published. A failed
     * commit is reconciled from the locked rows, never by restoring a whole map.
     * @param callable():?string $readAlias
     * @param callable(string):void $writeAlias
     */
    public function renameSource(int $sourceId, string $alias, callable $readAlias, callable $writeAlias): void
    {
        $alias = $this->text($alias, 64, '货源显示名称');
        $this->withRenameMapLock(function (string $path) use ($sourceId, $alias, $readAlias, $writeAlias): void {
            $receipt = $this->renameReceipt();
            if (($receipt['phase'] ?? null) === 'pending') {
                if ($receipt['source_id'] !== $sourceId) {
                    throw new RuntimeException('另一货源的联动改名尚未完成，请先恢复该操作。');
                }
                $this->finishSourceRename($path, $receipt, $readAlias, $writeAlias, true);
            }
            $registry = $this->read($path);
            $nodes = $this->sourceNodes($registry, $sourceId);
            $this->assertSourceGraph($registry, $sourceId, $nodes);
            if ($nodes === []) {
                $writeAlias($alias);
                return;
            }
            $changes = [];
            foreach ($nodes as $oldKey => $node) {
                $newKey = $this->nodeKey('source', $sourceId, $node['parent_key'], $alias);
                $changes[] = ['kind' => 'source', 'old_key' => $oldKey, 'new_key' => $newKey,
                    'old' => $node, 'new' => array_replace($node, ['name' => $alias]),
                    'pid' => (int)$registry['nodes'][$node['parent_key']]['id']];
                foreach ($registry['nodes'] as $childKey => $child) {
                    if ($child['parent_key'] !== $oldKey) {
                        continue;
                    }
                    $changes[] = ['kind' => 'source-category', 'old_key' => $childKey,
                        'new_key' => $this->nodeKey('source-category', $sourceId, $newKey, $child['name']),
                        'old' => $child, 'new' => array_replace($child, ['parent_key' => $newKey]),
                        'pid' => $node['id']];
                }
            }
            $receipt = ['schema' => 1, 'operation' => 'source_rename', 'operation_id' => bin2hex(random_bytes(16)),
                'phase' => 'pending', 'source_id' => $sourceId, 'old_alias' => $readAlias(),
                'new_alias' => $alias, 'changes' => $changes, 'rows' => []];
            $prepared = false;
            try {
                ($this->transaction)(function () use ($registry, &$receipt, &$prepared): void {
                    $rows = Category::query()->whereIn('id', array_column(array_column($receipt['changes'], 'old'), 'id'))
                        ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                    foreach ($receipt['changes'] as $change) {
                        $row = $rows->get($change['old']['id']);
                        if (!$row || (int)$row->owner !== 0 || (int)$row->pid !== $change['pid']) {
                            throw new RuntimeException('受管货源分类结构已改变，已停止联动改名。');
                        }
                        if ($change['kind'] === 'source') {
                            $receipt['rows'][] = ['id' => (int)$row->id, 'pid' => (int)$row->pid,
                                'old_name' => $this->text((string)$row->name, 64, '现有货源分类名称'),
                                'new_name' => $receipt['new_alias']];
                        }
                    }
                    $candidate = $this->applyRenameDelta($registry, $receipt);
                    $this->encodeRegistry($candidate);
                    $this->saveRenameReceipt($receipt);
                    $prepared = true;
                    foreach ($receipt['rows'] as $row) {
                        Category::query()->where('id', $row['id'])->update(['name' => $row['new_name']]);
                    }
                });
            } catch (\Throwable $failure) {
                // A commit may have reached the server even if PDO reports failure.
                // Keep the pending receipt unless a fresh, locked read proves rollback.
                if ($prepared) {
                    try {
                        if ($this->renameRowState($receipt) === 'old') {
                            $receipt['phase'] = 'aborted';
                            $this->saveRenameReceipt($receipt);
                        }
                    } catch (\Throwable) {
                        // The receipt is the recovery boundary; never guess here.
                    }
                }
                throw $failure;
            }
            $this->finishSourceRename($path, $receipt, $readAlias, $writeAlias);
        });
    }

    private function finishSourceRename(string $path, array $receipt, callable $readAlias, callable $writeAlias, bool $allowAbort = false): void
    {
        $state = $this->renameRowState($receipt);
        if ($state === 'old') {
            $registry = $this->read($path, allowRenameReceipt: true);
            foreach ($receipt['changes'] as $change) {
                if (($registry['nodes'][$change['old_key']] ?? null) !== $change['old']
                    || ($change['old_key'] !== $change['new_key'] && isset($registry['nodes'][$change['new_key']]))) {
                    throw new RuntimeException('货源联动改名恢复遇到映射冲突，已停止。');
                }
            }
            if ($readAlias() !== $receipt['old_alias']) {
                throw new RuntimeException('货源联动改名恢复遇到名称冲突，已停止。');
            }
            $receipt['phase'] = 'aborted';
            $this->saveRenameReceipt($receipt);
            if (!$allowAbort) {
                throw new RuntimeException('货源联动改名未提交，请重新保存名称。');
            }
            return;
        }
        $registry = $this->read($path, allowRenameReceipt: true);
        $next = $this->applyRenameDelta($registry, $receipt);
        $alias = $readAlias();
        if ($alias !== $receipt['old_alias'] && $alias !== $receipt['new_alias']) {
            throw new RuntimeException('货源联动改名恢复遇到名称冲突，已停止。');
        }
        if ($next !== $registry) {
            $this->writeBeforeCommit($path, $next);
        }
        $writeAlias($receipt['new_alias']);
        $receipt['phase'] = 'complete';
        $this->saveRenameReceipt($receipt);
    }

    private function renameRowState(array $receipt): string
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
            throw new RuntimeException('货源联动改名提交状态尚未确认，请重新保存名称以恢复。');
        }
        return DB::transaction(function () use ($receipt): string {
            $rows = Category::query()->whereIn('id', array_column(array_column($receipt['changes'], 'old'), 'id'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            // Leaf names belong to the original category operation, not this
            // nickname operation. Only their identity and structure are checked.
            foreach ($receipt['changes'] as $change) {
                $row = $rows->get($change['old']['id']);
                if (!$row || (int)$row->owner !== 0 || (int)$row->pid !== $change['pid']) {
                    throw new RuntimeException('货源联动改名恢复遇到分类结构冲突，已停止。');
                }
            }
            $old = true;
            $new = true;
            foreach ($receipt['rows'] as $expected) {
                $row = $rows->get($expected['id']);
                if (!$row || (int)$row->owner !== 0 || (int)$row->pid !== $expected['pid']) {
                    throw new RuntimeException('货源联动改名恢复遇到分类结构冲突，已停止。');
                }
                $old = $old && (string)$row->name === $expected['old_name'];
                $new = $new && (string)$row->name === $expected['new_name'];
            }
            if ($new) {
                return 'new';
            }
            if ($old) {
                return 'old';
            }
            throw new RuntimeException('货源联动改名结果不一致，已保留恢复记录并停止。');
        });
    }

    private function applyRenameDelta(array $registry, array $receipt): array
    {
        foreach ($receipt['changes'] as $change) {
            $old = $registry['nodes'][$change['old_key']] ?? null;
            $new = $registry['nodes'][$change['new_key']] ?? null;
            if ($change['old_key'] === $change['new_key']) {
                if ($old !== $change['old']) {
                    throw new RuntimeException('货源联动改名映射冲突，已停止。');
                }
                continue;
            }
            if ($old === $change['old'] && $new === null) {
                unset($registry['nodes'][$change['old_key']]);
                $registry['nodes'][$change['new_key']] = $change['new'];
            } elseif ($old !== null || $new !== $change['new']) {
                throw new RuntimeException('货源联动改名映射冲突，已停止。');
            }
        }
        $registry['schema'] = self::SCHEMA;
        ksort($registry['nodes'], SORT_STRING);
        return $registry;
    }

    private function withRenameMapLock(callable $callback): mixed
    {
        $directory = LocalPath::directory('runtime/local-extensions/extensions/PikaCatalogHub', 0700);
        $path = $directory . '/category-map.json';
        $lock = $this->openLock($path . '.lock');
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('无法锁定智能分类映射');
            }
            $this->assertHandle($lock, $path . '.lock', '映射锁');
            return $callback($path);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function renameReceiptPath(): string
    {
        return LocalPath::directory('runtime/local-extensions/extensions/PikaCatalogHub', 0700)
            . '/source-rename-receipt.json';
    }

    private function renameReceipt(): array
    {
        $receipt = AtomicJson::read($this->renameReceiptPath(), []);
        if ($receipt !== []) {
            $this->validateRenameReceipt($receipt);
        }
        return $receipt;
    }

    private function saveRenameReceipt(array $receipt): void
    {
        $this->validateRenameReceipt($receipt);
        $this->encodeRegistry($receipt);
        AtomicJson::update($this->renameReceiptPath(), [], static fn(array $current): array => $receipt);
    }

    private function validateRenameReceipt(array $receipt): void
    {
        $invalid = static fn(): RuntimeException => new RuntimeException('货源联动改名恢复记录无效，已停止。');
        if (($receipt['schema'] ?? null) !== 1 || ($receipt['operation'] ?? null) !== 'source_rename'
            || !is_string($receipt['operation_id'] ?? null)
            || preg_match('/^[a-f0-9]{32}$/D', $receipt['operation_id']) !== 1
            || !in_array($receipt['phase'] ?? null, ['pending', 'complete', 'aborted'], true)
            || !is_int($receipt['source_id'] ?? null) || $receipt['source_id'] < 1
            || $receipt['source_id'] > 0x7fffffff || !array_key_exists('old_alias', $receipt)
            || !is_array($receipt['changes'] ?? null) || !array_is_list($receipt['changes'])
            || count($receipt['changes']) < 1 || count($receipt['changes']) > self::MAX_NODES
            || !is_array($receipt['rows'] ?? null) || !array_is_list($receipt['rows']) || $receipt['rows'] === []) {
            throw $invalid();
        }
        $this->text($receipt['new_alias'] ?? null, 64, '恢复名称');
        if ($receipt['old_alias'] !== null) {
            $this->text($receipt['old_alias'], 64, '恢复原名称');
        }
        $sourceRows = [];
        $parents = [];
        $ids = [];
        foreach ($receipt['changes'] as $change) {
            if (!is_array($change) || !in_array($change['kind'] ?? null, ['source', 'source-category'], true)
                || !is_array($change['old'] ?? null) || !is_array($change['new'] ?? null)
                || !is_int($change['pid'] ?? null) || $change['pid'] < 1) {
                throw $invalid();
            }
            foreach (['old', 'new'] as $side) {
                $node = $change[$side];
                if (!is_int($node['id'] ?? null) || $node['id'] < 1 || !is_string($node['parent_key'] ?? null)
                    || preg_match('/^[a-f0-9]{64}$/D', $node['parent_key']) !== 1
                    || array_diff(array_keys($node), ['id', 'name', 'parent_key']) !== []
                    || ($change[$side . '_key'] ?? null) !== $this->nodeKey(
                        $change['kind'], $receipt['source_id'], $node['parent_key'], $this->nodeName($node['name'] ?? null, '恢复分类名称'),
                    )) {
                    throw $invalid();
                }
            }
            if ($change['old']['id'] !== $change['new']['id'] || isset($ids[$change['old']['id']])) {
                throw $invalid();
            }
            $ids[$change['old']['id']] = true;
            if ($change['kind'] === 'source') {
                if ($change['new']['name'] !== $receipt['new_alias']
                    || $change['old']['parent_key'] !== $change['new']['parent_key']) {
                    throw $invalid();
                }
                $sourceRows[$change['old']['id']] = $change['pid'];
                $parents[$change['old_key']] = ['key' => $change['new_key'], 'id' => $change['old']['id']];
            } elseif ($change['old']['name'] !== $change['new']['name']) {
                throw $invalid();
            }
        }
        foreach ($receipt['changes'] as $change) {
            if ($change['kind'] === 'source-category'
                && (($parents[$change['old']['parent_key']]['key'] ?? null) !== $change['new']['parent_key']
                    || ($parents[$change['old']['parent_key']]['id'] ?? null) !== $change['pid'])) {
                throw $invalid();
            }
        }
        foreach ($receipt['rows'] as $row) {
            if (!is_array($row) || !is_int($row['id'] ?? null)
                || ($sourceRows[$row['id']] ?? null) !== ($row['pid'] ?? null)
                || ($row['new_name'] ?? null) !== $receipt['new_alias']) {
                throw $invalid();
            }
            $this->text($row['old_name'] ?? null, 64, '恢复分类原名称');
            unset($sourceRows[$row['id']]);
        }
        if ($sourceRows !== []) {
            throw $invalid();
        }
    }

    /**
     * @param array{nodes:array<string,array{id:int,name:string,parent_key:?string}>} $registry
     * @param array<string,array{id:int,name:string,parent_key:string}> $sourceNodes
     */
    private function assertSourceGraph(array $registry, int $sourceId, array $sourceNodes): void
    {
        $invalid = static fn(): RuntimeException => new RuntimeException(
            '该货源的分类映射不完整，已停止分析。',
        );
        foreach ($sourceNodes as $sourceKey => $sourceNode) {
            $parentKey = $sourceNode['parent_key'];
            $parent = $registry['nodes'][$parentKey] ?? null;
            if (!is_array($parent)) {
                throw $invalid();
            }
            $validGroup = $parent['parent_key'] === null
                && hash_equals($parentKey, $this->nodeKey('group', 0, '', $parent['name']));
            $validFamily = false;
            if (is_string($parent['parent_key'])) {
                $groupKey = $parent['parent_key'];
                $group = $registry['nodes'][$groupKey] ?? null;
                $validFamily = is_array($group)
                    && $group['parent_key'] === null
                    && hash_equals($groupKey, $this->nodeKey('group', 0, '', $group['name']))
                    && hash_equals($parentKey, $this->nodeKey('family', 0, $groupKey, $parent['name']));
            }
            if (!$validGroup && !$validFamily) {
                throw $invalid();
            }

            $children = 0;
            foreach ($registry['nodes'] as $key => $entry) {
                if ($entry['parent_key'] !== $sourceKey) {
                    continue;
                }
                $children++;
                if (!hash_equals($key, $this->nodeKey(
                    'source-category',
                    $sourceId,
                    $sourceKey,
                    $entry['name'],
                ))) {
                    throw $invalid();
                }
            }
            if ($children === 0) {
                throw $invalid();
            }
        }

        $sourceKeys = array_fill_keys(array_keys($sourceNodes), true);
        foreach ($registry['nodes'] as $key => $entry) {
            $parentKey = $entry['parent_key'];
            if (is_string($parentKey)
                && hash_equals($key, $this->nodeKey('source-category', $sourceId, $parentKey, $entry['name']))
                && !isset($sourceKeys[$parentKey])) {
                throw $invalid();
            }
        }
    }

    /**
     * @param array{nodes:array<string,array{id:int,name:string,parent_key:?string}>} $registry
     * @return array<string,array{id:int,name:string,parent_key:string}>
     */
    private function sourceNodes(array $registry, int $sourceId): array
    {
        $nodes = [];
        foreach ($registry['nodes'] as $key => $entry) {
            $parentKey = $entry['parent_key'];
            if (is_string($parentKey)
                && hash_equals($key, $this->nodeKey('source', $sourceId, $parentKey, $entry['name']))) {
                $nodes[$key] = $entry;
            }
        }
        return $nodes;
    }

    /** Mirror categories are never linked-rename source alias nodes. */
    private function mirrorNodes(array $registry, int $sourceId): array
    {
        return array_filter($registry['nodes'], static fn(array $node): bool =>
            ($node['mode'] ?? null) === 'mirror' && $node['source_id'] === $sourceId);
    }

    private function assertSourceMode(array $registry, int $sourceId, bool $mirror): void
    {
        $sourceNodes = $this->sourceNodes($registry, $sourceId);
        if ($mirror) {
            $this->assertSourceGraph($registry, $sourceId, $sourceNodes);
        }
        if (($mirror && $sourceNodes !== []) || (!$mirror && $this->mirrorNodes($registry, $sourceId) !== [])) {
            throw new RuntimeException('该货源已有另一分类模式的映射，已停止入库。');
        }
    }

    /**
     * @param array{nodes:array<string,array{id:int,name:string,parent_key:?string}>} $registry
     * @return list<string>
     */
    private function sourceAliases(array $registry, int $sourceId): array
    {
        $aliases = [];
        foreach ($this->sourceNodes($registry, $sourceId) as $entry) {
            if (!in_array($entry['name'], $aliases, true)) {
                $aliases[] = $entry['name'];
            }
        }
        return $aliases;
    }

    /**
     * Validate the complete immutable import snapshot against the current
     * registry before the worker writes its first commodity. This method never
     * creates a category or publishes/upgrades the registry.
     *
     * @param list<array{category:mixed,target:mixed}> $items
     */
    public function assertPlanCapacity(Shared $source, string $alias, array $items): void
    {
        $sourceId = (int)($source->id ?? 0);
        if ($sourceId < 1 || $sourceId > 0x7fffffff) {
            throw new RuntimeException('共享店铺 ID 不正确');
        }
        $alias = $this->text($alias, 64, '货源别名');
        if ($items === [] || !array_is_list($items) || count($items) > self::MAX_PLAN_ITEMS) {
            throw new RuntimeException('分类方案商品必须是 1-10000 项列表');
        }

        $projectedNodes = [];
        $mirror = null;
        foreach ($items as $item) {
            if (!is_array($item) || !is_array($item['target'] ?? null)) {
                throw new RuntimeException('分类方案商品格式不正确');
            }
            $target = $item['target'];
            $sourceCategory = $this->sourceCategory($item['category'] ?? null);
            $itemMirror = ($target['mode'] ?? null) === 'mirror';
            if ($mirror !== null && $mirror !== $itemMirror) {
                throw new RuntimeException('同一分类方案不能混合分类模式。');
            }
            $mirror = $itemMirror;
            foreach ($this->plannedNodes($sourceId, $alias, $target, $sourceCategory) as $key => $entry) {
                if (isset($projectedNodes[$key]) && $projectedNodes[$key] !== $entry) {
                    throw new RuntimeException('上游分类 ID 的结构冲突，已停止入库。');
                }
                $projectedNodes[$key] = $entry;
            }
        }

        $directory = LocalPath::directory(
            'runtime/local-extensions/extensions/PikaCatalogHub',
            0700,
        );
        $path = $directory . '/category-map.json';
        $lockPath = $path . '.lock';
        if (is_link($path)) {
            throw new RuntimeException('智能分类映射路径不安全');
        }

        $lock = $this->openLock($lockPath);
        try {
            $this->assertHandle($lock, $lockPath, '映射锁');
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('无法锁定智能分类映射');
            }
            $this->assertHandle($lock, $lockPath, '映射锁');
            $registry = $this->read($path);
            $this->assertSourceMode($registry, $sourceId, $mirror === true);
            $this->assertProjectedCapacity($registry, array_keys($projectedNodes));
            $registry['schema'] = self::SCHEMA;
            foreach ($projectedNodes as $key => $entry) {
                unset($entry['sort']);
                if ($mirror && isset($registry['nodes'][$key])) {
                    $expected = array_replace($entry, ['id' => $registry['nodes'][$key]['id']]);
                    $actual = $registry['nodes'][$key];
                    ksort($actual);
                    ksort($expected);
                    if ($actual !== $expected) {
                        throw new RuntimeException('上游分类结构已改变，初次导入映射不能自动改名或移动。');
                    }
                }
                if (!isset($registry['nodes'][$key])) {
                    $registry['nodes'][$key] = $entry;
                }
            }
            ksort($registry['nodes'], SORT_STRING);
            $registry['last_plan_hash'] = str_repeat('f', 64);
            $this->encodeRegistry($registry);
            if (!flock($lock, LOCK_UN)) {
                throw new RuntimeException('无法解锁智能分类映射');
            }
        } finally {
            fclose($lock);
        }
    }

    /**
     * Resolve and lock the complete confirmed hierarchy, then execute the
     * commodity write in the same database transaction. The callback must not
     * perform remote I/O; callers fetch and normalize remote details first.
     *
     * @param array{group:string,family:string}|array{mode:string,path:list<array>} $target
     * @return mixed
     */
    public function withResolvedCategory(
        Shared $source,
        string $alias,
        array $target,
        string $sourceCategory,
        string $planHash,
        callable $callback,
    ): mixed
    {
        $sourceId = (int)($source->id ?? 0);
        if ($sourceId < 1 || $sourceId > 0x7fffffff) {
            throw new RuntimeException('共享店铺 ID 不正确');
        }
        $alias = $this->text($alias, 64, '货源别名');
        $sourceCategory = $this->sourceCategory($sourceCategory);
        $plannedNodes = $this->plannedNodes($sourceId, $alias, $target, $sourceCategory);
        $mirror = ($target['mode'] ?? null) === 'mirror';
        if (preg_match('/^[a-f0-9]{64}$/D', $planHash) !== 1) {
            throw new RuntimeException('分类方案哈希不正确');
        }
        // Any image I/O is completed before acquiring the publishing map lock
        // or opening the category/commodity transaction.
        $preparedIcons = $mirror && ($target['category_icons'] ?? false) === true && $this->iconProvider !== null
            ? $this->prepareMirrorIcons($source, $plannedNodes, $target['path']) : [];

        $directory = LocalPath::directory(
            'runtime/local-extensions/extensions/PikaCatalogHub',
            0700,
        );
        $path = $directory . '/category-map.json';
        $lockPath = $path . '.lock';
        if (is_link($path)) {
            throw new RuntimeException('智能分类映射路径不安全');
        }

        $lock = $this->openLock($lockPath);
        try {
            $this->assertHandle($lock, $lockPath, '映射锁');
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('无法锁定智能分类映射');
            }
            $this->assertHandle($lock, $lockPath, '映射锁');

            $registryExisted = file_exists($path);
            $originalRegistryBytes = null;
            $originalRegistry = $this->read($path, $originalRegistryBytes);
            $this->assertSourceMode($originalRegistry, $sourceId, $mirror);
            $registry = $originalRegistry;
            $registryHash = $this->registryHash($registry);
            $registry['schema'] = self::SCHEMA;
            $writeAttempted = false;
            $callbackCompleted = false;
            try {
                $result = ($this->transaction)(function () use (
                    $source,
                    $plannedNodes,
                    $preparedIcons,
                    $planHash,
                    $path,
                    $registryHash,
                    $callback,
                    &$registry,
                    &$writeAttempted,
                    &$callbackCompleted,
                ): mixed {
                    SourceIdentity::lockAndVerify($source);

                    $this->assertProjectedCapacity(
                        $registry,
                        array_keys($plannedNodes),
                    );
                    foreach ($plannedNodes as $key => $entry) {
                        $parentKey = $entry['parent_key'];
                        $metadata = ($entry['mode'] ?? null) === 'mirror'
                            ? array_intersect_key($entry, array_flip(['mode', 'source_id', 'upstream_id'])) : [];
                        $leaf = $this->resolveNode($registry, $key, $entry['name'],
                            $parentKey === null ? null : (int)$registry['nodes'][$parentKey]['id'],
                            $parentKey, $metadata, $entry['sort'] ?? 0,
                            $preparedIcons[$entry['upstream_id'] ?? 0] ?? self::DEFAULT_ICON);
                    }

                    // Commodity persistence happens before publishing a new
                    // mapping, but while every mapped category row remains
                    // locked in this transaction.
                    $result = $callback($leaf);
                    $registry['last_plan_hash'] = $planHash;
                    if (!hash_equals($registryHash, $this->registryHash($registry))) {
                        $writeAttempted = true;
                        $this->writeBeforeCommit($path, $registry);
                    }
                    // From this point onward, an exception may originate from
                    // PDO::commit() after the server already applied the
                    // transaction. Preserve the new registry in that unknown
                    // outcome; the caller still receives the exception.
                    $callbackCompleted = true;
                    return $result;
                });
            } catch (\Throwable $failure) {
                // A failure before the callback completed is a known rollback
                // path. A failure after callback completion is a commit-result
                // unknown path: restoring the old registry could point a
                // committed commodity at an untracked hierarchy and create a
                // duplicate on retry. Keep the new registry in that case;
                // missing IDs self-heal on the next strict resolve.
                if ($writeAttempted && !$callbackCompleted) {
                    try {
                        $this->restoreRegistry(
                            $path,
                            $originalRegistry,
                            $registryExisted,
                            $originalRegistryBytes,
                        );
                    } catch (\Throwable) {
                        throw new RuntimeException('智能分类映射回滚失败，已停止入库', 0, $failure);
                    }
                }
                throw $failure;
            }

            if (!flock($lock, LOCK_UN)) {
                throw new RuntimeException('无法解锁智能分类映射');
            }
            return $result;
        } finally {
            fclose($lock);
        }
    }

    /** @param list<array{id:int,pid:int,name:string,sort:int}> $path */
    private function prepareMirrorIcons(Shared $source, array $plannedNodes, array $path): array
    {
        $sourceId = $this->iconSourceId($source);
        $missing = $this->withRenameMapLock(function (string $mapPath) use ($sourceId, $plannedNodes, $path): array {
            $registry = $this->read($mapPath);
            $this->assertSourceMode($registry, $sourceId, true);
            $this->assertProjectedCapacity($registry, array_keys($plannedNodes));
            $missing = [];
            foreach ($path as $node) {
                $key = $this->mirrorKey($sourceId, $node['id']);
                $entry = $registry['nodes'][$key] ?? null;
                if ($entry === null) {
                    $missing[] = $node;
                    continue;
                }
                $expected = $plannedNodes[$key];
                if ($entry['name'] !== $expected['name'] || $entry['parent_key'] !== $expected['parent_key']
                    || ($entry['mode'] ?? null) !== 'mirror' || $entry['source_id'] !== $sourceId
                    || $entry['upstream_id'] !== $node['id']) {
                    throw new RuntimeException('上游分类结构已改变，初次导入映射不能自动改名或移动。');
                }
                $row = Category::query()->find($entry['id']);
                if ($row === null) {
                    $missing[] = $node;
                    continue;
                }
                $pid = $entry['parent_key'] === null ? null : $registry['nodes'][$entry['parent_key']]['id'];
                if ((int)$row->owner !== 0 || ($row->pid === null ? null : (int)$row->pid) !== $pid
                    || (string)$row->name !== $entry['name']) {
                    throw new RuntimeException('智能分类映射已偏离确认方案，已停止入库');
                }
            }
            return $missing;
        });
        if ($missing === []) {
            return [];
        }
        if (DB::connection()->transactionLevel() !== 0) {
            throw new RuntimeException('分类图下载不能在数据库事务内执行。');
        }
        $icons = ($this->iconProvider)($source, $missing);
        if (!is_array($icons)) {
            throw new RuntimeException('分类图准备结果不正确。');
        }
        $ids = array_fill_keys(array_column($missing, 'id'), true);
        if (count($icons) !== count($ids)) {
            throw new RuntimeException('分类图准备结果不完整。');
        }
        foreach ($icons as $id => $icon) {
            if (!is_int($id) || !isset($ids[$id]) || ($icon !== self::DEFAULT_ICON && !$this->isLocalIcon($icon))) {
                throw new RuntimeException('分类图准备结果不正确。');
            }
        }
        return $icons;
    }

    /** @return resource */
    private function openLock(string $path)
    {
        clearstatcache(true, $path);
        $pathMetadata = @lstat($path);
        if (is_array($pathMetadata)) {
            $this->assertSafeExistingLockPath($path, $pathMetadata);
            $handle = @fopen($path, 'r+b');
            if ($handle === false) {
                throw new RuntimeException('无法打开智能分类映射锁');
            }
            return $handle;
        }

        $previousUmask = umask(0177);
        try {
            $handle = @fopen($path, 'x+b');
        } finally {
            umask($previousUmask);
        }
        if ($handle !== false) {
            return $handle;
        }

        // A concurrent worker may have created the lock after lstat(). Only a
        // lock that satisfies the complete existing-file contract is reused.
        clearstatcache(true, $path);
        $pathMetadata = @lstat($path);
        if (!is_array($pathMetadata)) {
            throw new RuntimeException('无法打开智能分类映射锁');
        }
        $this->assertSafeExistingLockPath($path, $pathMetadata);
        $handle = @fopen($path, 'r+b');
        if ($handle === false) {
            throw new RuntimeException('无法打开智能分类映射锁');
        }
        return $handle;
    }

    /** @param array<string,int> $metadata */
    private function assertSafeExistingLockPath(string $path, array $metadata): void
    {
        if (
            is_link($path)
            || ($metadata['mode'] & 0170000) !== 0100000
            || ($metadata['mode'] & 0777) !== 0600
            || (int)$metadata['uid'] !== PathGuard::runtimeOwner()
            || (int)$metadata['nlink'] !== 1
        ) {
            throw new RuntimeException('智能分类映射锁权限或类型不安全');
        }
    }

    /** @param array<string,mixed> $registry */
    private function resolveNode(
        array &$registry,
        string $key,
        string $name,
        ?int $pid,
        ?string $parentKey,
        array $metadata = [],
        int $sort = 0,
        string $icon = self::DEFAULT_ICON,
    ): Category {
        $entry = $registry['nodes'][$key] ?? null;
        if (is_array($entry)) {
            if ($metadata !== [] && ($entry['name'] !== $name || $entry['parent_key'] !== $parentKey
                || ($entry['mode'] ?? null) !== $metadata['mode']
                || ($entry['source_id'] ?? null) !== $metadata['source_id']
                || ($entry['upstream_id'] ?? null) !== $metadata['upstream_id'])) {
                throw new RuntimeException('上游分类结构已改变，初次导入映射不能自动改名或移动。');
            }
            $category = Category::query()
                ->whereKey((int)($entry['id'] ?? 0))
                ->lockForUpdate()
                ->first();
            if ($category !== null) {
                $actualPid = $category->pid === null ? null : (int)$category->pid;
                if (
                    (int)$category->owner !== 0
                    || $actualPid !== $pid
                    || !hash_equals($name, (string)$category->name)
                    || ($entry['name'] ?? null) !== $name
                    || ($entry['parent_key'] ?? null) !== $parentKey
                ) {
                    throw new RuntimeException('智能分类映射已偏离确认方案，已停止入库');
                }
                return $category;
            }
            unset($registry['nodes'][$key]);
        }

        $category = $this->create($name, $pid, $sort, $icon);
        $registry['nodes'][$key] = [
            'id' => (int)$category->id,
            'name' => $name,
            'parent_key' => $parentKey,
        ] + $metadata;
        if (count($registry['nodes']) > self::MAX_NODES) {
            throw new RuntimeException('智能分类映射节点超过安全上限');
        }
        ksort($registry['nodes'], SORT_STRING);
        return $category;
    }

    /** @param array<string,mixed> $registry @param list<string> $keys */
    private function assertProjectedCapacity(array $registry, array $keys): void
    {
        $projected = count($registry['nodes']);
        foreach ($keys as $key) {
            if (!isset($registry['nodes'][$key])) {
                $projected++;
            }
        }
        if ($projected > self::MAX_NODES) {
            throw new RuntimeException('智能分类映射节点预计超过安全上限');
        }
    }

    /** @return array{group:string,family?:string,source:string,source_category:string} */
    private function plannedKeys(
        int $sourceId,
        string $alias,
        string $group,
        string $family,
        string $sourceCategory,
    ): array {
        $groupKey = $this->nodeKey('group', 0, '', $group);
        $parentKey = $groupKey;
        $keys = ['group' => $groupKey];
        if ($family !== '') {
            $parentKey = $this->nodeKey('family', 0, $groupKey, $family);
            $keys['family'] = $parentKey;
        }
        $sourceKey = $this->nodeKey('source', $sourceId, $parentKey, $alias);
        $keys['source'] = $sourceKey;
        $keys['source_category'] = $this->nodeKey(
            'source-category',
            $sourceId,
            $sourceKey,
            $sourceCategory,
        );
        return $keys;
    }

    /** Parent-first entries; the last entry is always the commodity's leaf. */
    private function plannedNodes(int $sourceId, string $alias, array $target, string $sourceCategory): array
    {
        if (($target['mode'] ?? null) === 'mirror') {
            $target = UpstreamCategoryTree::normalizeTarget($target);
            if ($sourceCategory !== $target['path'][array_key_last($target['path'])]['name']) {
                throw new RuntimeException('上游分类名称与确认层级不一致。');
            }
            $nodes = [];
            foreach ($target['path'] as $node) {
                $key = $this->mirrorKey($sourceId, $node['id']);
                $nodes[$key] = ['id' => PHP_INT_MAX, 'name' => $node['name'],
                    'parent_key' => $node['pid'] === 0 ? null : $this->mirrorKey($sourceId, $node['pid']),
                    'mode' => 'mirror', 'source_id' => $sourceId, 'upstream_id' => $node['id'],
                    'sort' => $node['sort']];
            }
            return $nodes;
        }
        if (array_key_exists('mode', $target) && $target['mode'] !== 'smart') {
            throw new RuntimeException('分类模式不正确。');
        }
        $group = $this->text($target['group'] ?? null, 64, '一级分类');
        $family = $this->text($target['family'] ?? '', 64, '二级分类', true);
        $keys = $this->plannedKeys($sourceId, $alias, $group, $family, $sourceCategory);
        $nodes = [$keys['group'] => ['id' => PHP_INT_MAX, 'name' => $group, 'parent_key' => null]];
        $parent = $keys['group'];
        if ($family !== '') {
            $nodes[$keys['family']] = ['id' => PHP_INT_MAX, 'name' => $family, 'parent_key' => $parent];
            $parent = $keys['family'];
        }
        $nodes[$keys['source']] = ['id' => PHP_INT_MAX, 'name' => $alias, 'parent_key' => $parent];
        $nodes[$keys['source_category']] = ['id' => PHP_INT_MAX, 'name' => $sourceCategory, 'parent_key' => $keys['source']];
        return $nodes;
    }

    private function mirrorKey(int $sourceId, int $upstreamId): string
    {
        return hash('sha256', 'mirror' . "\0" . $sourceId . "\0" . $upstreamId);
    }

    private function create(string $name, ?int $pid, int $sort = 0, string $icon = self::DEFAULT_ICON): Category
    {
        $category = new Category();
        $category->name = $name;
        $category->sort = $sort;
        $category->create_time = Date::current();
        $category->owner = 0;
        $category->icon = $icon;
        $category->status = 1;
        $category->hide = 0;
        $category->pid = $pid;
        if (!$category->save()) {
            throw new RuntimeException('智能商品分类创建失败');
        }
        return $category;
    }

    /** @return array{schema:int,nodes:array<string,array{id:int,name:string,parent_key:?string}>,last_plan_hash:string} */
    private function read(string $path, ?string &$rawBytes = null, bool $allowRenameReceipt = false): array
    {
        if (!$allowRenameReceipt) {
            $this->assertNoPendingRename();
        }
        if (!file_exists($path)) {
            $rawBytes = null;
            return ['schema' => self::SCHEMA, 'nodes' => [], 'last_plan_hash' => ''];
        }
        if (is_link($path)) {
            throw new RuntimeException('智能分类映射不能是符号链接');
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('无法读取智能分类映射');
        }
        try {
            $metadata = $this->assertHandle($handle, $path, '映射文件');
            if ((int)$metadata['size'] > self::MAX_BYTES) {
                throw new RuntimeException('智能分类映射超过安全上限');
            }
            $raw = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
        try {
            $decoded = json_decode((string)$raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('智能分类映射格式损坏');
        }
        if (
            !is_array($decoded)
            || !in_array($decoded['schema'] ?? null, [1, self::SCHEMA], true)
            || !is_array($decoded['nodes'] ?? null)
            || array_is_list($decoded['nodes'])
            || !is_string($decoded['last_plan_hash'] ?? null)
            || array_diff(array_keys($decoded), ['schema', 'nodes', 'last_plan_hash']) !== []
            || count($decoded['nodes']) > self::MAX_NODES
        ) {
            throw new RuntimeException('智能分类映射字段不正确');
        }
        $localIdentities = [];
        foreach ($decoded['nodes'] as $key => $entry) {
            if (
                !is_string($key)
                || preg_match('/^[a-f0-9]{64}$/D', $key) !== 1
                || !is_array($entry)
                || array_diff(array_keys($entry), ['id', 'name', 'parent_key', 'mode', 'source_id', 'upstream_id']) !== []
                || array_diff(['id', 'name', 'parent_key'], array_keys($entry)) !== []
                || !is_int($entry['id'] ?? null)
                || $entry['id'] < 1
                || !is_string($entry['name'] ?? null)
                || (!is_null($entry['parent_key'] ?? null)
                    && (!is_string($entry['parent_key'])
                        || preg_match('/^[a-f0-9]{64}$/D', $entry['parent_key']) !== 1))
            ) {
                throw new RuntimeException('智能分类映射节点不正确');
            }
            $hasMirrorMetadata = array_intersect(['mode', 'source_id', 'upstream_id'], array_keys($entry)) !== [];
            if ($hasMirrorMetadata && ($decoded['schema'] !== self::SCHEMA
                || ($entry['mode'] ?? null) !== 'mirror'
                || !is_int($entry['source_id'] ?? null) || $entry['source_id'] < 1 || $entry['source_id'] > 0x7fffffff
                || !is_int($entry['upstream_id'] ?? null) || $entry['upstream_id'] < 1 || $entry['upstream_id'] > 0x7fffffff
                || !hash_equals($key, $this->mirrorKey($entry['source_id'], $entry['upstream_id'])))) {
                throw new RuntimeException('上游分类映射身份不正确');
            }
            if (isset($localIdentities[$entry['id']]) && ($hasMirrorMetadata || $localIdentities[$entry['id']]['mirror'])) {
                throw new RuntimeException('上游分类映射复用了其他分类身份');
            }
            $localIdentities[$entry['id']] = ['mirror' => $hasMirrorMetadata];
            if ($decoded['schema'] === 1) {
                $this->text($entry['name'], 64, '分类映射名称');
            } else {
                $this->nodeName($entry['name'], '分类映射名称');
            }
        }
        foreach ($decoded['nodes'] as $key => $entry) {
            if (($entry['mode'] ?? null) !== 'mirror') {
                continue;
            }
            $visited = [$key => true];
            $parentKey = $entry['parent_key'];
            while ($parentKey !== null) {
                $parent = $decoded['nodes'][$parentKey] ?? null;
                if (isset($visited[$parentKey]) || !is_array($parent)
                    || ($parent['mode'] ?? null) !== 'mirror' || $parent['source_id'] !== $entry['source_id']) {
                    throw new RuntimeException('上游分类映射祖先缺失、循环或来源冲突');
                }
                $visited[$parentKey] = true;
                $parentKey = $parent['parent_key'];
            }
        }
        if (
            $decoded['last_plan_hash'] !== ''
            && preg_match('/^[a-f0-9]{64}$/D', $decoded['last_plan_hash']) !== 1
        ) {
            throw new RuntimeException('智能分类映射方案哈希不正确');
        }
        $rawBytes = (string)$raw;
        return $decoded;
    }

    /** @param array<string,mixed> $registry */
    private function writeBeforeCommit(string $path, array $registry): void
    {
        $schema = $registry['schema'] ?? null;
        if ($schema !== self::SCHEMA) {
            throw new RuntimeException('智能分类映射写入版本不正确');
        }
        $this->publishBytes($path, $this->encodeRegistry($registry));
    }

    /** @param array<string,mixed> $registry */
    private function encodeRegistry(array $registry): string
    {
        try {
            $json = json_encode(
                $registry,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . "\n";
        } catch (\JsonException) {
            throw new RuntimeException('无法编码智能分类映射');
        }
        if (strlen($json) > self::MAX_BYTES) {
            throw new RuntimeException('智能分类映射超过安全上限');
        }
        return $json;
    }

    private function publishBytes(string $path, string $bytes): void
    {
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new RuntimeException('智能分类映射超过安全上限');
        }
        $temp = dirname($path) . '/.category-map.tmp-' . bin2hex(random_bytes(12));
        $published = false;
        try {
            $handle = fopen($temp, 'xb');
            if ($handle === false) {
                throw new RuntimeException('无法创建智能分类映射临时文件');
            }
            try {
                if (!chmod($temp, 0600)) {
                    throw new RuntimeException('无法保护智能分类映射临时文件');
                }
                $this->assertHandle($handle, $temp, '映射临时文件');
                $offset = 0;
                while ($offset < strlen($bytes)) {
                    $written = fwrite($handle, substr($bytes, $offset));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('无法写入智能分类映射');
                    }
                    $offset += $written;
                }
                if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                    throw new RuntimeException('无法刷盘智能分类映射');
                }
            } finally {
                fclose($handle);
            }
            if (!rename($temp, $path)) {
                throw new RuntimeException('无法发布智能分类映射');
            }
            $published = true;
            clearstatcache(true, $path);
            $publishedHandle = fopen($path, 'rb');
            if ($publishedHandle === false) {
                throw new RuntimeException('无法验证智能分类映射');
            }
            try {
                $this->assertHandle($publishedHandle, $path, '已发布映射');
                $publishedBytes = stream_get_contents($publishedHandle);
                if (!is_string($publishedBytes)
                    || !hash_equals(hash('sha256', $bytes), hash('sha256', $publishedBytes))) {
                    throw new RuntimeException('智能分类映射写入校验失败');
                }
            } finally {
                fclose($publishedHandle);
            }
        } finally {
            if (!$published && file_exists($temp)) {
                @unlink($temp);
            }
        }
    }

    /** @param array<string,mixed> $registry */
    private function restoreRegistry(
        string $path,
        array $registry,
        bool $existed,
        ?string $originalBytes = null,
    ): void
    {
        if ($existed) {
            // The rollback path receives only bytes already validated by
            // read(), and restores those exact bytes instead of re-encoding
            // legacy schema-1 state.
            if ($originalBytes === null) {
                throw new RuntimeException('无法精确回滚智能分类映射');
            }
            $this->publishBytes($path, $originalBytes);
            return;
        }

        clearstatcache(true, $path);
        if (!file_exists($path)) {
            return;
        }
        if (is_link($path)) {
            throw new RuntimeException('无法精确回滚智能分类映射');
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('无法打开待回滚智能分类映射');
        }
        $tombstone = dirname($path) . '/.category-map.rollback-' . bin2hex(random_bytes(12));
        try {
            $metadata = $this->assertHandle($handle, $path, '待回滚映射');
            if (file_exists($tombstone) || is_link($tombstone) || !rename($path, $tombstone)) {
                throw new RuntimeException('无法隔离待回滚智能分类映射');
            }
            clearstatcache(true, $tombstone);
            $moved = lstat($tombstone);
            if (
                !is_array($moved)
                || is_link($tombstone)
                || $moved['dev'] !== $metadata['dev']
                || $moved['ino'] !== $metadata['ino']
            ) {
                throw new RuntimeException('待回滚智能分类映射身份发生变化');
            }
            if (!unlink($tombstone)) {
                if (!file_exists($path) && !is_link($path)) {
                    @rename($tombstone, $path);
                }
                throw new RuntimeException('无法删除待回滚智能分类映射');
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string,mixed> $registry */
    private function registryHash(array $registry): string
    {
        return hash('sha256', (string)json_encode(
            $registry,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    /** @param resource $handle @return array<string,int> */
    private function assertHandle($handle, string $path, string $label): array
    {
        clearstatcache(true, $path);
        $metadata = fstat($handle);
        $pathMetadata = lstat($path);
        if (
            !is_array($metadata)
            || !is_array($pathMetadata)
            || ($metadata['mode'] & 0170000) !== 0100000
            || ($metadata['mode'] & 0777) !== 0600
            || (int)$metadata['uid'] !== PathGuard::runtimeOwner()
            || (int)$metadata['nlink'] !== 1
            || is_link($path)
            || $metadata['dev'] !== $pathMetadata['dev']
            || $metadata['ino'] !== $pathMetadata['ino']
        ) {
            throw new RuntimeException("智能分类{$label}权限或类型不安全");
        }
        return $metadata;
    }

    private function nodeKey(string $kind, int $sourceId, string $parentKey, string $name): string
    {
        return hash('sha256', $kind . "\0" . $sourceId . "\0" . $parentKey . "\0" . $name);
    }

    private function text(mixed $value, int $max, string $label, bool $allowEmpty = false): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            throw new RuntimeException("{$label}格式不正确");
        }
        $value = trim($value);
        if ($value === '' && $allowEmpty) {
            return '';
        }
        if (
            $value === ''
            || mb_strlen($value, 'UTF-8') > $max
            || preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $value) === 1
            || preg_match('#[\\/]#u', $value) === 1
        ) {
            throw new RuntimeException("{$label}格式不正确");
        }
        return $value;
    }

    private function sourceCategory(mixed $value): string
    {
        return $this->nodeName($value, '上游分类名称');
    }

    private function nodeName(mixed $value, string $label): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            throw new RuntimeException("{$label}格式不正确");
        }
        $value = trim($value);
        if (
            $value === ''
            || mb_strlen($value, 'UTF-8') > 128
            || preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $value) === 1
            || preg_match('/\A[\s\p{Z}]|[\s\p{Z}]\z/u', $value) === 1
        ) {
            throw new RuntimeException("{$label}格式不正确");
        }
        return $value;
    }
}
