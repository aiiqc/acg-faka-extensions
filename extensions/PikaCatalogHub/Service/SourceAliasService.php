<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaCatalogHub\Service;

use App\Model\Commodity;
use App\Model\Shared;
use Pika\LocalExtensions\PikaSupplySync\Service\PlannedCategoryMapper;
use RuntimeException;

final class SourceAliasService
{
    private ConfigRepository $config;

    public function __construct(?ConfigRepository $config = null)
    {
        $this->config = $config ?? new ConfigRepository();
    }

    public function normalize(int $sourceId, mixed $alias): string
    {
        $candidate = $this->candidate($sourceId, $alias);
        foreach ($candidate['aliases'] as $entry) {
            if ($entry['source_id'] === $sourceId) {
                return $entry['alias'];
            }
        }
        throw new RuntimeException('货源别名规范化失败。');
    }

    /** The caller must hold the per-source lock through isChange() and rename(). */
    public function isChange(int $sourceId, string $alias): bool
    {
        $alias = $this->normalize($sourceId, $alias);
        return $this->currentAlias($sourceId) !== $alias
            || (new PlannedCategoryMapper())->sourceNeedsRename($sourceId, $alias);
    }

    /** @return array{schema:int,aliases:list<array{source_id:int,alias:string}>,rules:list<array>} */
    public function rename(int $sourceId, string $alias): array
    {
        $alias = $this->normalize($sourceId, $alias);
        if (!$this->isChange($sourceId, $alias)) {
            return $this->config->get();
        }
        $this->assertNoActiveTask($sourceId);
        $mapper = new PlannedCategoryMapper();
        // A missing registry must never turn an imported source into a new tree.
        // The mapper handles an existing pending receipt before reading the map.
        try {
            $mapper->assertNoPendingRename();
            if (!$mapper->hasSourceMapping($sourceId)
                && ($this->hasImportedHistory($sourceId) || $this->hasManagedCommodity($sourceId))) {
                throw new RuntimeException('该货源的分类映射不完整，已停止联动改名。');
            }
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== '货源联动改名尚未完成，请先在对应货源重新保存名称以恢复。') {
                throw $exception;
            }
        }
        // Mirror mappings have no alias source-node. The mapper validates
        // their identity but updates only this backend display-name setting.
        $mapper->renameSource(
            $sourceId,
            $alias,
            fn(): ?string => $this->currentAlias($sourceId),
            function (string $next) use ($sourceId): void { $this->save($sourceId, $next); },
        );
        return $this->config->get();
    }

    public function assertNoPendingRename(): void
    {
        (new PlannedCategoryMapper())->assertNoPendingRename();
    }

    /** Must be called under the same SourceLock as the mode save/analysis. */
    public function assertCategoryMode(int $sourceId, string $mode): void
    {
        (new PlannedCategoryMapper())->assertCategoryMode($sourceId, $mode);
    }

    /** @return array{source_id:int,category_id:int,alias:string,category_name:string,source_node_count:int}|null */
    public function managedCategory(int $categoryId): ?array
    {
        if ($categoryId < 1 || $categoryId > 0x7fffffff) {
            throw new RuntimeException('分类 ID 不正确。');
        }
        // Only identifiers are selected; supplier credentials never enter this lookup.
        $sourceIds = Shared::query()->orderBy('id')->limit(257)->pluck('id')->map(static fn($id): int => (int)$id)->all();
        if (count($sourceIds) > 256) {
            throw new RuntimeException('共享店铺数量超过联动改名识别上限。');
        }
        $result = (new PlannedCategoryMapper())->managedSourceCategory($categoryId, $sourceIds);
        if ($result !== null) {
            $result['alias'] = $this->currentAlias($result['source_id']) ?? $result['category_name'];
        }
        return $result;
    }

    public function classificationAlias(int $sourceId, mixed $displayAlias): string
    {
        $displayAlias = $this->normalize($sourceId, $displayAlias);
        $current = $this->currentAlias($sourceId);
        if ($current !== null && !hash_equals($current, $displayAlias)) {
            throw new RuntimeException('请先保存货源显示名称，再开始智能分析。');
        }

        $mapper = new PlannedCategoryMapper();
        $classificationAlias = $mapper->classificationAlias($sourceId, $displayAlias);
        if (!$mapper->hasSourceMapping($sourceId)
            && ($this->hasImportedHistory($sourceId) || $this->hasManagedCommodity($sourceId))) {
            throw new RuntimeException('该货源的分类映射不完整，已停止分析。');
        }
        if ($current === null) {
            $settings = $this->rename($sourceId, $displayAlias);
            $current = $this->aliasFrom($settings, $sourceId);
        }
        if (!is_string($current)) {
            throw new RuntimeException('货源显示名称保存失败。');
        }
        return $classificationAlias;
    }

    private function assertNoActiveTask(int $sourceId): void
    {
        $jobs = new JobStore();
        foreach ($jobs->list() as $job) {
            if ((int)($job['source_id'] ?? 0) !== $sourceId) {
                continue;
            }
            if (JobStore::canCancelFailedImport($job)) {
                throw new RuntimeException('该货源还有可继续的导入任务，请先继续或取消任务，再修改名称。');
            }
            if (!$jobs->isTerminal((string)($job['state'] ?? ''))) {
                throw new RuntimeException('该货源已有未完成的后台任务。');
            }
        }
    }

    private function hasImportedHistory(int $sourceId): bool
    {
        foreach ((new JobStore())->list() as $job) {
            if ((int)($job['source_id'] ?? 0) === $sourceId
                && ($job['phase'] ?? null) === 'import'
                && (int)($job['progress']['processed'] ?? 0) > 0) {
                return true;
            }
        }
        return false;
    }

    private function hasManagedCommodity(int $sourceId): bool
    {
        $rows = Commodity::query()
            ->where('owner', 0)
            ->where('shared_id', $sourceId)
            ->where('code', 'like', 'PKS1____________________')
            ->orderBy('id')
            ->limit(10001)
            ->get(['code']);
        if ($rows->count() > 10000) {
            return true;
        }
        foreach ($rows as $row) {
            if (preg_match('/^PKS1[A-F0-9]{20}$/D', (string)($row->code ?? '')) === 1) {
                return true;
            }
        }
        return false;
    }

    /** @return array{schema:int,aliases:list<array{source_id:int,alias:string}>,rules:list<array>} */
    private function save(int $sourceId, string $alias): array
    {
        $alias = $this->normalize($sourceId, $alias);
        $settings = $this->config->upsertAlias($sourceId, $alias);
        if ($this->aliasFrom($settings, $sourceId) !== $alias) {
            throw new RuntimeException('货源别名保存失败。');
        }
        return $settings;
    }

    /** @return array{schema:int,aliases:list<array{source_id:int,alias:string}>,rules:list<array>} */
    private function candidate(int $sourceId, mixed $alias): array
    {
        if ($sourceId < 1 || $sourceId > 0x7fffffff) {
            throw new RuntimeException('共享店铺 ID 不正确。');
        }
        $settings = $this->config->get();
        $entry = ['source_id' => $sourceId];
        foreach ($settings['aliases'] as $existing) {
            if ($existing['source_id'] === $sourceId) {
                $entry = $existing;
                break;
            }
        }
        $settings['aliases'] = array_values(array_filter(
            $settings['aliases'],
            static fn(array $entry): bool => $entry['source_id'] !== $sourceId,
        ));
        $settings['aliases'][] = array_replace($entry, ['alias' => $alias]);
        return ConfigSchema::normalize($settings);
    }

    private function currentAlias(int $sourceId): ?string
    {
        return $this->aliasFrom($this->config->get(), $sourceId);
    }

    /** @param array{aliases:list<array{source_id:int,alias:string}>} $settings */
    private function aliasFrom(array $settings, int $sourceId): ?string
    {
        foreach ($settings['aliases'] as $entry) {
            if ($entry['source_id'] === $sourceId) {
                return $entry['alias'];
            }
        }
        return null;
    }

}
