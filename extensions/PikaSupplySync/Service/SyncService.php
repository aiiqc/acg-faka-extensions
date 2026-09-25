<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaSupplySync\Service;

use App\Model\Commodity;
use App\Model\PriceTemplate;
use App\Model\Shared;
use App\Util\Date;
use App\Util\Ini;
use App\Util\SharedCurrency;
use Illuminate\Database\Capsule\Manager as DB;
use Kernel\Util\Decimal;
use Pika\LocalExtensions\Manager\AtomicJson;
use Pika\LocalExtensions\Manager\PathGuard;
use RuntimeException;

final class SyncService
{
    private const MAX_LOCAL_ITEMS = 10000;
    private RunBudget $budget;
    /** @var array<string,mixed> */
    private array $logContext = [];
    private string $phase = 'preflight';
    /** @var (\Closure(): Options)|null */
    private ?\Closure $targetOptionsReader = null;

    public function __construct(
        private SharedGateway $gateway,
        private PriceAdjuster $prices,
        private SourcePolicy $sourcePolicy,
        private ImageCache $images,
        private ExtensionLogger $logger,
        ?RunBudget $budget = null,
    ) {
        $this->budget = $budget ?? new RunBudget();
    }

    /** Targeted CLI overrides may narrow, never restore revoked saved scope. */
    public static function targetedOptions(Options $saved, Options $requested): Options
    {
        if ($saved->mode !== Options::MODE_BASIC || count($requested->sourceIds) !== 1
            || ($saved->sourceIds !== [] && !in_array($requested->sourceIds[0], $saved->sourceIds, true))) {
            throw new RuntimeException('定向货源或 basic 模式不再获保存配置允许');
        }
        $current = clone $saved;
        $current->sourceIds = $requested->sourceIds;
        $current->batchLimit = min($saved->batchLimit, $requested->batchLimit);
        $current->dryRun = $requested->dryRun;
        return $current;
    }

    /** @return array{mode:string,dry_run:bool,sources:array<int,array>} */
    public function run(Options $options, ?array $targetHashes = null, ?callable $targetOptionsReader = null): array
    {
        if ($targetHashes !== null) {
            if (!array_is_list($targetHashes) || count($targetHashes) < 1 || count($targetHashes) > 2) {
                throw new RuntimeException('定向同步需要 1-2 个不同的商品编号哈希');
            }
            foreach ($targetHashes as $hash) {
                if (!is_string($hash) || preg_match('/^[a-f0-9]{12}$/D', $hash) !== 1) {
                    throw new RuntimeException('定向商品编号哈希必须是 12 位小写十六进制');
                }
            }
            if ($targetOptionsReader === null || count(array_unique($targetHashes)) !== count($targetHashes)
                || count($options->sourceIds) !== 1 || $options->mode !== Options::MODE_BASIC
                || $options->batchLimit < count($targetHashes)
                || $options->syncFields !== array_fill_keys(Options::SYNC_FIELDS, true)
                || !$options->followsUpstreamConfig($options->sourceIds[0])) {
                throw new RuntimeException('定向同步需要唯一货源、basic、足够批量及已保存的六项完整跟随授权');
            }
        }
        $this->targetOptionsReader = $targetHashes === null ? null : \Closure::fromCallable($targetOptionsReader);
        $this->images->beginRun();
        $this->logContext = ['mode' => $options->mode, 'dry_run' => $options->dryRun];
        if ($targetHashes !== null) $this->logContext += ['targeted' => true, 'target_code_hashes' => $targetHashes];
        $sourceIds = $options->sourceIds;
        if ($sourceIds === []) {
            $sourceIds = Shared::query()->orderBy('id')->limit(101)->pluck('id')->map(static fn($id): int => (int)$id)->all();
            if (count($sourceIds) > 100) {
                throw new RuntimeException('共享店铺超过 100 个安全上限，请在配置中明确指定 source_ids');
            }
        }

        $stateStore = new StateStore();
        $sourceIds = $stateStore->orderSources($sourceIds);
        $results = [];
        foreach ($sourceIds as $sourceId) {
            $this->phase = 'preflight';
            $this->gateway->resetRequestDiagnostics();
            $sourceBudgetActive = false;
            $stopRound = false;
            try {
                $this->budget->beginSource($sourceId);
                $sourceBudgetActive = true;
                $result = $this->runSource($sourceId, $options, $targetHashes);
                $results[] = $result;
                $stopRound = ($result['budget_scope'] ?? '') === 'round';
            } catch (BudgetExceeded $exception) {
                $result = $this->error($sourceId, $this->message($exception), null, $exception);
                $results[] = $result;
                $stopRound = !$exception->isSource();
            } finally {
                if ($sourceBudgetActive) {
                    $this->budget->endSource();
                }
            }
            if ($sourceBudgetActive && $targetHashes === null) {
                $stateStore->markSourceAttempted($sourceId);
            }
            if ($stopRound) {
                break;
            }
        }
        return [
            'mode' => $options->mode,
            'dry_run' => $options->dryRun,
            'sources' => $results,
        ] + ($targetHashes === null ? [] : ['targeted' => true, 'target_code_hashes' => $targetHashes]);
    }

    /** @return array<string,mixed> */
    private function runSource(int $sourceId, Options $options, ?array $targetHashes = null): array
    {
        $lock = new SourceLock();
        if (!$lock->acquire($sourceId)) {
            if ($targetHashes !== null) return $this->error($sourceId, '货源已锁定，定向同步未执行');
            return ['source_id' => $sourceId, 'status' => 'locked'];
        }

        try {
            $source = Shared::query()->find($sourceId);
            if (!$source) {
                return $this->error($sourceId, '共享店铺不存在');
            }
            if ($targetHashes !== null) {
                try {
                    $this->assertTargetAuthorization($sourceId, count($targetHashes));
                } catch (\Throwable $exception) {
                    return $this->error($sourceId, $this->message($exception, $source));
                }
            }

            if ($options->syncFields !== null && !in_array(true, $options->syncFields, true)) {
                $result = ['source_id' => $sourceId, 'status' => 'ok', 'mode' => $options->mode,
                    'dry_run' => $options->dryRun, 'selection_empty' => true,
                    'catalog_unknown' => 0,
                    'planned' => ['sync' => 0, 'import' => 0, 'zero' => 0, 'hold_zero' => 0, 'held_unknown' => 0],
                    'applied' => ['sync' => 0, 'import' => 0, 'zero' => 0, 'held_unknown' => 0, 'held_race' => 0,
                        'already_managed' => 0, 'held_existing_unmanaged' => 0], 'failed' => 0];
                $this->log($result);
                return $result;
            }

            if ($options->mode === Options::MODE_FULL) {
                try {
                    $managedByCatalogHub = $this->catalogHubManagesSource($sourceId);
                } catch (\Throwable $exception) {
                    return $this->error($sourceId, $this->message($exception, $source));
                }
                if ($managedByCatalogHub) {
                    return $this->error(
                        $sourceId,
                        '该货源已由 PikaCatalogHub 智能货源中心管理；'
                        . '请将 PikaSupplySync 同步模式改为 basic，新商品请在智能货源中心重新分析。',
                    );
                }
            }

            $planner = new CatalogPlanner();
            $this->phase = 'catalog';
            try {
                $this->sourcePolicy->assertSafe($source);
                $compact = $options->mode === Options::MODE_BASIC && (int)$source->type === 0
                    && (new PlannedCategoryMapper())->hasMirrorMapping($sourceId);
                $allowManualNullStock = $options->mode === Options::MODE_BASIC && (int)$source->type === 0
                    && !$compact && $targetHashes === null;
                if ($compact) {
                    // Keep the legacy currency validation even though this projection has no prices.
                    SharedCurrency::factor($source);
                    $catalog = (new UpstreamCategoryTree())->flatten($this->gateway->categoryTree($source));
                } else {
                    $catalog = $planner->flatten($this->gateway->items($source), $allowManualNullStock);
                }
                $this->budget->checkpoint();
            } catch (BudgetExceeded $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                $diagnostic = $exception instanceof UpstreamFailure ? $exception->diagnostics : null;
                $message = ($diagnostic['category'] ?? null) === 'response_size'
                    ? '远端商品目录超过 16 MiB 安全上限，本货源本轮未执行商品写入；缩小批量不会减少整份目录大小。'
                    : $this->message($exception, $source);
                return $this->error($sourceId, $message, $diagnostic);
            }
            if ($catalog === []) {
                if ($targetHashes !== null) return $this->error($sourceId, '远端目录为空，定向同步未执行');
                $result = [
                    'source_id' => $sourceId,
                    'status' => 'held_empty_catalog',
                    'catalog_total' => 0,
                    'catalog_unknown' => 0,
                    'message' => '远端商品目录为空，未执行任何商品写入',
                ];
                $this->log($result);
                return $result;
            }

            $this->phase = 'planning';
            try {
                $stateStore = new StateStore();
                $state = $stateStore->read($sourceId);
                $local = $this->localMap($sourceId);
                $targets = $targetHashes === null ? [] : $this->targetRows($sourceId, $targetHashes, $local, $catalog);
                if (!$options->syncs('inventory')) {
                    foreach ($local as &$row) $row['inventory_sync'] = 0;
                    unset($row);
                }
                $plan = $planner->plan(
                    $catalog,
                    $local,
                    (string)$state['cursor'],
                    $options,
                    (string)$state['priority_cursor'],
                    array_keys($targets),
                );
                if ($targetHashes !== null && ($plan['fuse'] || $plan['counts'] !== [
                    'sync' => count($targets), 'import' => 0, 'zero' => 0, 'hold_zero' => 0, 'held_unknown' => 0,
                ] || array_column($plan['actions'], 'code') !== array_map('strval', array_keys($targets)))) {
                    throw new RuntimeException('定向计划未完整匹配或触发全货源熔断，本批未执行商品写入');
                }
                $this->budget->checkpoint();
            } catch (BudgetExceeded $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                return $this->error($sourceId, $this->message($exception, $source));
            }

            $result = [
                'source_id' => $sourceId,
                'status' => $plan['counts']['held_unknown'] > 0 ? 'partial' : 'ok',
                'mode' => $options->mode,
                'dry_run' => $options->dryRun,
                'catalog_total' => count($catalog),
                'catalog_unknown' => count(array_filter($catalog, static fn(array $item): bool => $item['stock'] === null)),
                'local_total' => count($local),
                'planned' => $plan['counts'],
                'applied' => [
                    'sync' => 0,
                    'import' => 0,
                    'zero' => 0,
                    'held_unknown' => 0,
                    'held_race' => 0,
                    'already_managed' => 0,
                    'held_existing_unmanaged' => 0,
                ],
                'failed' => 0,
                'cover_failed' => 0,
                'errors' => [],
                'mass_zero_fuse' => $plan['fuse'],
                'mass_zero_ratio' => $plan['fuse_ratio'],
                'next_cursor_hash' => $plan['next_cursor'] === ''
                    ? ''
                    : substr(hash('sha256', (string)$plan['next_cursor']), 0, 12),
            ];
            if ($targetHashes !== null) $result += ['targeted' => true, 'target_code_hashes' => $targetHashes,
                'verified_code_hashes' => []];

            if ($options->dryRun) {
                $this->log($result);
                return $result;
            }

            $mapping = is_array($state['categories'] ?? null) ? $state['categories'] : [];
            $categoryMapper = new CategoryMapper();
            $remoteItem = new RemoteItem($this->images, $this->budget);
            $importer = new CommodityImporter($this->gateway, $this->prices, $remoteItem, $this->sourcePolicy);
            $budgetExhausted = false;
            $completedCursor = (string)$state['cursor'];
            $completedPriorityCursor = (string)$state['priority_cursor'];

            $this->phase = 'actions';
            foreach ($plan['actions'] as $action) {
                $type = $action['type'];
                $code = $action['code'];
                $lane = $action['lane'] ?? null;
                if (!in_array($lane, ['normal', 'priority'], true)) {
                    throw new RuntimeException('同步计划游标通道不正确');
                }
                $coverFailed = false;
                try {
                    $this->budget->checkpoint();
                    if ($type === 'hold_zero' || $type === 'held_unknown') {
                        if ($type === 'held_unknown') {
                            $result['applied']['held_unknown']++;
                        }
                        if ($lane === 'priority') {
                            $completedPriorityCursor = $code;
                        } else {
                            $completedCursor = $code;
                        }
                        continue;
                    }
                    if ($type === 'import') {
                        $category = $categoryMapper->resolve($source, $catalog[$code]['category'], $mapping);
                        $outcome = $importer->import(
                            $source,
                            $catalog[$code],
                            (int)$category->id,
                            $options,
                        );
                        $result['applied'] = $this->recordImportOutcome($result['applied'], $outcome);
                        if ($outcome === CommodityImporter::OUTCOME_HELD_EXISTING_UNMANAGED) {
                            $result['status'] = 'partial';
                        }
                    } elseif ($type === 'zero') {
                        $this->zeroStock($source, $code, $options);
                        $result['applied']['zero']++;
                    } else {
                        $outcome = $this->syncExisting($source, $code, $remoteItem, $options, $coverFailed,
                            $targets[$code] ?? null, $allowManualNullStock);
                        if ($targetHashes !== null && $outcome !== 'synced') {
                            if ($outcome === 'held_race') $result['applied']['held_race']++;
                            throw new RuntimeException('定向商品未完成全部所选字段，本批停止');
                        }
                        if ($targetHashes !== null) $result['verified_code_hashes'][] = substr(hash('sha256', $code), 0, 12);
                        if ($outcome === 'held_race' || $outcome === 'held_unknown') {
                            $result['applied'][$outcome]++;
                        } elseif ($outcome !== 'skipped_selection' && $outcome !== 'held_selection') {
                            $result['applied'][$type]++;
                        }
                        $selectionHeld = in_array($outcome, ['partial_selection', 'held_selection'], true);
                        if ($selectionHeld) {
                            $result['failed']++;
                            if (count($result['errors']) < 20) $result['errors'][] = [
                                'code_hash' => substr(hash('sha256', $code), 0, 12),
                                'message' => '规格或价格变更待确认：无法精确匹配的部分保留本地，其他已选字段按单品开关处理',
                            ];
                            $result['selection_held'] = ($result['selection_held'] ?? 0) + 1;
                        }
                        if ($coverFailed) {
                            $result['cover_failed']++;
                            // failed counts items, not independent field failures.
                            if (!$selectionHeld) $result['failed']++;
                            if (count($result['errors']) < 20) $result['errors'][] = [
                                'code_hash' => substr(hash('sha256', $code), 0, 12),
                                'message' => '图片未刷新：已保留原图，其他字段仍按有效开关和安全门处理',
                            ];
                        }
                    }
                    if ($lane === 'priority') {
                        $completedPriorityCursor = $code;
                    } else {
                        $completedCursor = $code;
                    }
                } catch (BudgetExceeded $exception) {
                    $budgetExhausted = true;
                    $result['status'] = 'partial';
                    $result['budget_scope'] = $exception->scope;
                    unset($result['failure_diagnostic']);
                    if ($exception->safeDiagnostics !== null) {
                        $result['failure_diagnostic'] = UpstreamFailure::sanitizeObservation($exception->safeDiagnostics);
                    }
                    if (count($result['errors']) < 20) {
                        $result['errors'][] = [
                            'code_hash' => substr(hash('sha256', $code), 0, 12),
                            'message' => $this->message($exception, $source),
                        ];
                    }
                    break;
                } catch (\Throwable $exception) {
                    unset($result['failure_diagnostic']);
                    if ($exception instanceof UpstreamFailure) {
                        // ImageCache may reuse another source's failed download. Keep only
                        // its safe class here; timings/attempts belong to this source's collector.
                        $result['failure_diagnostic'] = array_intersect_key(
                            UpstreamFailure::sanitizeObservation($exception->diagnostics), array_flip(['category', 'stage']));
                    }
                    $result['failed']++;
                    if ($coverFailed || $exception instanceof RemoteCoverUnavailable) $result['cover_failed']++;
                    if (count($result['errors']) < 20) {
                        $result['errors'][] = [
                            'code_hash' => substr(hash('sha256', $code), 0, 12),
                            'message' => $this->message($exception, $source),
                        ];
                    }
                    if ($targetHashes !== null) break;
                }
            }

            if ($result['failed'] > 0 || $result['applied']['held_existing_unmanaged'] > 0
                || $result['applied']['held_unknown'] > 0) {
                $result['status'] = 'partial';
            }
            if ($targetHashes !== null) {
                if ($result['applied']['sync'] !== count($targets)) $result['status'] = 'partial';
                $this->log($result);
                return $result;
            }
            $persistedCursor = $budgetExhausted ? $completedCursor : $plan['next_cursor'];
            $persistedPriorityCursor = $budgetExhausted
                ? $completedPriorityCursor
                : $plan['next_priority_cursor'];
            $result['next_cursor_hash'] = $persistedCursor === ''
                ? ''
                : substr(hash('sha256', $persistedCursor), 0, 12);
            $stateStore->write($sourceId, [
                // A budget can stop in the middle of either lane. Commit only
                // each lane's last completed action; the triggering action and
                // every unattempted suffix remain eligible on the next run.
                'cursor' => $persistedCursor,
                'priority_cursor' => $persistedPriorityCursor,
                'categories' => $mapping,
                'catalog_hash' => $this->catalogHash($catalog),
                'last_run' => Date::current(),
                'last_result' => [
                    'status' => $result['status'],
                    'catalog_total' => $result['catalog_total'],
                    'catalog_unknown' => $result['catalog_unknown'],
                    'planned' => $result['planned'],
                    'applied' => $result['applied'],
                    'failed' => $result['failed'],
                    'mass_zero_fuse' => $result['mass_zero_fuse'],
                ],
            ]);
            $this->log($result);
            return $result;
        } finally {
            $lock->release();
        }
    }

    /** Resolve against complete local and remote sets before any detail or save. */
    private function targetRows(int $sourceId, array $hashes, array $local, array $catalog): array
    {
        $targets = [];
        foreach ($hashes as $hash) {
            $matches = static fn(array $rows): array => array_values(array_filter(array_map('strval', array_keys($rows)),
                static fn(string $code): bool => substr(hash('sha256', $code), 0, 12) === $hash));
            $localCodes = $matches($local);
            $remoteCodes = $matches($catalog);
            if (count($localCodes) !== 1 || $localCodes !== $remoteCodes
                || !$local[$localCodes[0]]['managed'] || $catalog[$localCodes[0]]['stock'] === null
                || (int)$catalog[$localCodes[0]]['stock'] <= 0) {
                throw new RuntimeException('定向商品缺失、哈希不唯一、不受管或无库存，本批未执行商品写入');
            }
            $code = $localCodes[0];
            $row = Commodity::query()->whereKey($local[$code]['id'])->where('owner', 0)
                ->where('shared_id', $sourceId)->where('shared_code', $code)->first();
            if (!$row) throw new RuntimeException('定向商品身份已变更，本批未执行商品写入');
            $target = ['id' => (int)$row->id, 'code' => (string)$row->code,
                'premium_type' => (int)$row->shared_premium_type, 'premium' => (string)$row->shared_premium,
                'target_count' => count($hashes)];
            $this->assertTargetUnchanged($row, $target);
            $targets[$code] = $target;
        }
        ksort($targets, SORT_STRING);
        return $targets;
    }

    private function assertTargetUnchanged(Commodity $row, array $target): void
    {
        if ((int)$row->id !== $target['id'] || (string)$row->code !== $target['code']
            || (int)$row->shared_premium_type !== $target['premium_type'] || (string)$row->shared_premium !== $target['premium']
            || (int)$row->shared_sync !== 0 || preg_match('/^PKS1[A-F0-9]{20}$/D', (string)$row->code) !== 1
            || !in_array((int)$row->shared_premium_type, [PriceTemplate::TYPE_FIXED, PriceTemplate::TYPE_PERCENT], true)
            || (int)$row->shared_amount_sync !== 1 || (int)$row->shared_config_sync !== 1 || (int)$row->inventory_sync !== 1) {
            throw new RuntimeException('定向商品身份、加价或同步开关不再匹配，本件未写入');
        }
    }

    private function assertTargetAuthorization(int $sourceId, int $count = 1): void
    {
        $current = $this->targetOptionsReader === null ? null : ($this->targetOptionsReader)();
        if (!$current instanceof Options || $current->mode !== Options::MODE_BASIC
            || $current->sourceIds !== [$sourceId] || $current->batchLimit < $count
            || $current->syncFields !== array_fill_keys(Options::SYNC_FIELDS, true)
            || !$current->followsUpstreamConfig($sourceId)) {
            throw new RuntimeException('定向完整跟随配置授权不再有效，本件未写入');
        }
    }

    /** @param array<string,int> $applied @return array<string,int> */
    private function recordImportOutcome(array $applied, string $outcome): array
    {
        $key = match ($outcome) {
            CommodityImporter::OUTCOME_CREATED => 'import',
            CommodityImporter::OUTCOME_ALREADY_MANAGED => 'already_managed',
            CommodityImporter::OUTCOME_HELD_EXISTING_UNMANAGED => 'held_existing_unmanaged',
            default => throw new RuntimeException('商品导入返回了未知结果'),
        };
        if (!array_key_exists($key, $applied) || !is_int($applied[$key]) || $applied[$key] < 0) {
            throw new RuntimeException('商品导入计数器格式不正确');
        }
        $applied[$key]++;
        return $applied;
    }

    /** @return array<string,array{id:int,status:int,stock:int,managed:bool,inventory_sync:int}> */
    private function localMap(int $sourceId): array
    {
        $map = [];
        $rows = Commodity::query()
            ->where('owner', 0)
            ->where('shared_id', $sourceId)
            ->orderBy('id')
            ->limit(self::MAX_LOCAL_ITEMS + 1)
            ->get([
                'id', 'code', 'shared_code', 'status', 'stock', 'shared_sync',
                'shared_premium_type', 'inventory_sync',
            ]);
        if ($rows->count() > self::MAX_LOCAL_ITEMS) {
            throw new RuntimeException('本地同一货源商品超过 10000 条安全上限');
        }
        foreach ($rows as $row) {
            $code = trim((string)$row->shared_code);
            if ($code === '') {
                throw new RuntimeException("本地商品 {$row->id} 缺少 shared_code");
            }
            if (isset($map[$code])) {
                throw new RuntimeException('同一货源存在重复 shared_code：' . substr(hash('sha256', $code), 0, 12));
            }
            $premiumType = (int)$row->shared_premium_type;
            $map[$code] = [
                'id' => (int)$row->id,
                'status' => (int)$row->status,
                'stock' => (int)$row->stock,
                'managed' => (int)$row->shared_sync === 0
                    && preg_match('/^PKS1[A-F0-9]{20}$/D', (string)$row->code) === 1
                    && in_array($premiumType, [PriceTemplate::TYPE_FIXED, PriceTemplate::TYPE_PERCENT], true),
                'inventory_sync' => (int)$row->inventory_sync,
            ];
        }
        ksort($map, SORT_STRING);
        return $map;
    }

    private function zeroStock(Shared $source, string $code, Options $options): void
    {
        if (!$options->syncs('inventory')) throw new RuntimeException('库存同步未选中，拒绝清零');
        DB::transaction(function () use ($source, $code): void {
            SourceIdentity::lockAndVerify($source);
            $commodity = Commodity::query()
                ->where('owner', 0)
                ->where('shared_id', (int)$source->id)
                ->where('shared_code', $code)
                ->lockForUpdate()
                ->first();
            if (!$commodity) {
                throw new RuntimeException('本地商品不存在');
            }
            if (
                (int)$commodity->inventory_sync !== 1
                || (int)$commodity->shared_sync !== 0
                || preg_match('/^PKS1[A-F0-9]{20}$/D', (string)$commodity->code) !== 1
                || !in_array((int)$commodity->shared_premium_type, [PriceTemplate::TYPE_FIXED, PriceTemplate::TYPE_PERCENT], true)
            ) {
                throw new RuntimeException('本地商品不允许由插件同步库存');
            }
            $commodity->stock = 0;
            $commodity->shared_stock = [];
            if (!$commodity->save()) {
                throw new RuntimeException('本地商品库存清零失败');
            }
        });
    }

    private function syncExisting(
        Shared $source,
        string $code,
        RemoteItem $normalizer,
        Options $options,
        bool &$coverFailed,
        ?array $target = null,
        bool $allowManualNullStock = false,
    ): string
    {
        $existing = Commodity::query()
            ->where('owner', 0)
            ->where('shared_id', (int)$source->id)
            ->where('shared_code', $code)
            ->first(['id', 'code', 'shared_sync', 'shared_premium_type', 'shared_premium',
                'shared_amount_sync', 'shared_config_sync', 'inventory_sync']);
        if (!$existing) {
            throw new RuntimeException('本地商品不存在');
        }
        if ($target !== null) {
            $this->assertTargetUnchanged($existing, $target);
            $this->assertTargetAuthorization((int)$source->id, $target['target_count']);
        }
        if ($options->syncFields !== null && !$this->hasSelectedField($existing, $options)) {
            return 'skipped_selection';
        }
        $this->sourcePolicy->assertSafe($source);
        $remote = $this->gateway->item($source, $code);
        // A native manual item may become unknown after the catalog snapshot.
        // Hold before normalization can fetch a cover or prepare any field update.
        if ($allowManualNullStock && CatalogPlanner::isManualNullStock($remote)) {
            return 'held_unknown';
        }
        $remoteConfigSnapshot = $remote['config'] ?? [];
        $fullConfigValid = is_array($remoteConfigSnapshot) && ConfigSelection::validFullSnapshot($remoteConfigSnapshot);
        $coverLoaded = $options->syncs('cover') && (int)$existing->shared_config_sync === 1;
        $item = $normalizer->normalize($source, $remote, $code, $coverLoaded, true);
        $coverFailed = ($item['cover_unavailable'] ?? false) === true;
        if ($target !== null && $coverFailed) throw new RuntimeException('定向商品图片未刷新，本件未写入');

        return DB::transaction(function () use ($source, $code, $existing, $item, $options, $coverLoaded, $fullConfigValid, $target): string {
            SourceIdentity::lockAndVerify($source);
            $commodity = Commodity::query()
                ->whereKey((int)$existing->id)
                ->where('owner', 0)
                ->where('shared_id', (int)$source->id)
                ->where('shared_code', $code)
                ->lockForUpdate()
                ->first();
            if (!$commodity) {
                throw new RuntimeException('本地商品在同步期间已变更');
            }
            if ($target !== null) {
                $this->assertTargetUnchanged($commodity, $target);
                $this->assertTargetAuthorization((int)$source->id, $target['target_count']);
                if ($options->syncFields !== array_fill_keys(Options::SYNC_FIELDS, true)
                    || !$options->followsUpstreamConfig((int)$source->id)) {
                    throw new RuntimeException('定向完整跟随授权在读取期间变更，本件未写入');
                }
            }
            if (!$coverLoaded && $options->syncs('cover') && (int)$commodity->shared_config_sync === 1) {
                throw new RuntimeException('商品配置同步开关在读取期间变更，本件未写入');
            }

            // The catalog snapshot said this item was in stock. A zero detail
            // response is held without any write so it cannot bypass the fuse.
            if ($options->syncs('inventory') && (int)$commodity->inventory_sync === 1 && (int)$item['stock'] <= 0) {
                return 'held_race';
            }

            $premiumType = (int)$commodity->shared_premium_type;
            if (!in_array($premiumType, [PriceTemplate::TYPE_FIXED, PriceTemplate::TYPE_PERCENT], true)) {
                throw new RuntimeException('插件周期同步只支持官方固定或百分比加价');
            }
            if ((int)$commodity->shared_sync !== 0) {
                throw new RuntimeException('本地商品仍由官方核心同步，插件拒绝重复写入');
            }
            if (preg_match('/^PKS1[A-F0-9]{20}$/D', (string)$commodity->code) !== 1) {
                throw new RuntimeException('本地商品不是 PikaSupplySync 导入，插件拒绝接管');
            }
            $amountSync = (int)$commodity->shared_amount_sync === 1;
            $configSync = (int)$commodity->shared_config_sync === 1;
            if ($options->syncFields !== null) {
                $amountSync = $amountSync && $options->syncs('price');
                $configSync = $configSync && $options->syncs('options');
            }
            if ($amountSync && $configSync && $options->followsUpstreamConfig((int)$source->id) && !$fullConfigValid) {
                throw new RuntimeException('完整配置跟随原始结构校验失败，本件未写入');
            }
            $prices = ['price' => null, 'user_price' => null, 'config' => []];
            $draftPremium = null;
            if ($amountSync || $configSync) {
                $prices = $this->prices->adjustPrice(
                    $item['config'],
                    (string)$item['price'],
                    (string)$item['user_price'],
                    $premiumType,
                    (float)$commodity->shared_premium,
                );
                $remoteConfig = $item['config'] === '' ? [] : Ini::toArray($item['config']);
                if (!empty($remoteConfig['sku'])) {
                    $prices['config']['sku_cost'] = $remoteConfig['sku'];
                }
                if (!empty($remoteConfig['category'])) {
                    $prices['config']['category_cost'] = $remoteConfig['category'];
                }
                if ($amountSync) {
                    $draftPremium = $item['draft_premium'] > 0
                        ? $this->prices->adjustAmount(
                            $premiumType,
                            (float)$commodity->shared_premium,
                            $item['draft_premium']
                        )
                        : 0;
                }
            }
            $heldSelection = false;
            $updates = $options->syncFields === null ? $this->buildUpdateValues(
                $item,
                $prices,
                $draftPremium,
                $amountSync,
                $configSync,
                (int)$commodity->inventory_sync === 1,
            ) : $this->buildSelectedValues($commodity, $item, $prices, $draftPremium, $options, $heldSelection);
            if ($updates === []) return $heldSelection ? 'held_selection' : 'skipped_selection';
            foreach ($updates as $field => $value) {
                $commodity->{$field} = $value;
            }
            if (!$commodity->save()) {
                throw new RuntimeException('商品同步保存失败');
            }
            if ($target !== null) {
                $fresh = Commodity::query()->whereKey((int)$commodity->id)->where('owner', 0)
                    ->where('shared_id', (int)$source->id)->where('shared_code', $code)->first();
                if (!$fresh) throw new RuntimeException('定向保存后回读身份不匹配');
                $this->assertTargetUnchanged($fresh, $target);
                // Compare the same response's saved candidate, not a second HTTP
                // request. Money uses core Decimal(2); other values use model casts.
                foreach (['price', 'user_price', 'draft_premium', 'name', 'cover', 'description', 'config',
                    'draft_status', 'widget', 'stock', 'shared_stock', 'api_status', 'shared_sync'] as $field) {
                    if (in_array($field, ['price', 'user_price', 'draft_premium'], true)) {
                        $expected = (new Decimal($commodity->getAttributes()[$field], 2))->getAmount();
                        $actual = (new Decimal($fresh->getRawOriginal($field), 2))->getAmount();
                    } else {
                        $expected = $commodity->{$field};
                        $actual = $fresh->{$field};
                    }
                    if ($actual !== $expected) throw new RuntimeException('定向保存后字段回读不匹配，本批停止');
                }
            }
            return $heldSelection ? 'partial_selection' : 'synced';
        });
    }

    private function hasSelectedField(Commodity $commodity, Options $options): bool
    {
        return ((int)$commodity->shared_amount_sync === 1 && $options->syncs('price'))
            || ((int)$commodity->inventory_sync === 1 && $options->syncs('inventory'))
            || ((int)$commodity->shared_config_sync === 1 && ($options->syncs('name') || $options->syncs('cover')
                || $options->syncs('description') || $options->syncs('options')));
    }

    private function buildSelectedValues(Commodity $commodity, array $item, array $prices,
        string|int|float|null $draftPremium, Options $options, bool &$held): array
    {
        $updates = [];
        $price = $options->syncs('price') && (int)$commodity->shared_amount_sync === 1;
        $config = (int)$commodity->shared_config_sync === 1;
        $specifications = $options->syncs('options') && $config;
        if ($price) {
            $updates['price'] = $prices['price'];
            $updates['user_price'] = $prices['user_price'];
            $updates['draft_premium'] = $draftPremium;
        }
        foreach (['name', 'cover', 'description'] as $field) {
            if ($config && $options->syncs($field) && ($field !== 'cover' || empty($item['cover_unavailable']))) {
                $updates[$field] = $item[$field];
            }
        }
        if ($price || $specifications) {
            $local = (string)$commodity->config === '' ? [] : Ini::toArray((string)$commodity->config);
            $followConfig = $options->followsUpstreamConfig((int)$commodity->shared_id);
            $projection = ConfigSelection::project($local, $prices['config'], $price, $specifications,
                $followConfig);
            if ($followConfig && $price && $specifications && $projection['held']) {
                throw new RuntimeException('完整配置跟随校验失败，本件未写入');
            }
            $held = $projection['held'];
            if ($projection['config'] !== $local) $updates['config'] = Ini::toConfig($projection['config']);
            if ($specifications && !$held) {
                $updates['draft_status'] = $item['draft_status'];
                $updates['widget'] = $item['widget'];
            }
        }
        if ($options->syncs('inventory') && (int)$commodity->inventory_sync === 1) {
            $updates['stock'] = $item['stock'];
            $updates['shared_stock'] = [];
        }
        // With no effective selection, even housekeeping columns remain untouched.
        return $updates === [] ? [] : $updates + ['api_status' => 1, 'shared_sync' => 0];
    }

    /**
     * Pure whitelist projection used by the transaction above. Unknown remote
     * keys can never become model attributes.
     *
     * @return array<string,mixed>
     */
    private function buildUpdateValues(
        array $item,
        array $prices,
        string|int|float|null $draftPremium,
        bool $amountSync,
        bool $configSync,
        bool $inventorySync,
    ): array {
        $updates = ['api_status' => 1, 'shared_sync' => 0];
        if ($amountSync) {
            $updates['price'] = $prices['price'];
            $updates['user_price'] = $prices['user_price'];
            $updates['draft_premium'] = $draftPremium;
        }
        if ($configSync) {
            $updates['config'] = Ini::toConfig($prices['config']);
            $updates['name'] = $item['name'];
            $updates['description'] = $item['description'];
            if (empty($item['cover_unavailable'])) $updates['cover'] = $item['cover'];
            $updates['draft_status'] = $item['draft_status'];
            $updates['widget'] = $item['widget'];
        }
        if ($inventorySync) {
            $updates['stock'] = $item['stock'];
            $updates['shared_stock'] = [];
        }
        return $updates;
    }

    private function catalogHash(array $catalog): string
    {
        $rows = [];
        foreach ($catalog as $code => $item) {
            $rows[] = [$code, $item['stock'] === null ? null : (int)$item['stock'], (string)$item['category']];
        }
        return hash('sha256', (string)json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function catalogHubManagesSource(int $sourceId): bool
    {
        $root = PathGuard::stateRoot();
        $categoryMap = $this->readOptionalCatalogHubState(
            $root . '/extensions/PikaCatalogHub/category-map.json',
        );
        $jobState = $this->readOptionalCatalogHubState(
            $root . '/extensions/PikaCatalogHub/jobs/jobs.json',
        );

        $mapped = $categoryMap === null
            ? false
            : $this->categoryMapContainsSource($categoryMap, $sourceId);
        $active = $jobState === null
            ? false
            : $this->jobStateContainsActiveSource($jobState, $sourceId);
        return $mapped || $active;
    }

    /** @return array<string,mixed>|null */
    private function readOptionalCatalogHubState(string $path): ?array
    {
        clearstatcache(true, $path);
        if (is_link($path)) {
            throw new RuntimeException('智能货源中心状态路径不安全，已停止 full 模式');
        }
        if (!file_exists($path)) {
            return null;
        }
        try {
            return AtomicJson::read($path, []);
        } catch (\Throwable $exception) {
            throw new RuntimeException('智能货源中心状态不可信，已停止 full 模式', 0, $exception);
        }
    }

    /** @param array<string,mixed> $state */
    private function categoryMapContainsSource(array $state, int $sourceId): bool
    {
        $schema = $state['schema'] ?? null;
        if (!$this->hasExactKeys($state, ['schema', 'nodes', 'last_plan_hash'])
            || !in_array($schema, [1, 2], true)
            || !is_array($state['nodes'] ?? null)
            || ($state['nodes'] !== [] && array_is_list($state['nodes']))
            || count($state['nodes']) > 2048
            || !is_string($state['last_plan_hash'] ?? null)
            || ($state['last_plan_hash'] !== ''
                && preg_match('/^[a-f0-9]{64}$/D', $state['last_plan_hash']) !== 1)) {
            throw new RuntimeException('智能分类映射格式不正确，已停止 full 模式');
        }

        $managed = false;
        foreach ($state['nodes'] as $key => $entry) {
            $validNodeName = $schema === 1
                ? $this->isLegacyCategoryNodeName($entry['name'] ?? null)
                : $this->isCategoryNodeName($entry['name'] ?? null);
            if (!is_string($key)
                || preg_match('/^[a-f0-9]{64}$/D', $key) !== 1
                || !is_array($entry)
                || !$this->hasExactKeys($entry, ['id', 'name', 'parent_key'])
                || !is_int($entry['id'] ?? null)
                || $entry['id'] < 1
                || $entry['id'] > 0x7fffffff
                || !$validNodeName
                || (!is_null($entry['parent_key'] ?? null)
                    && (!is_string($entry['parent_key'])
                        || preg_match('/^[a-f0-9]{64}$/D', $entry['parent_key']) !== 1))) {
                throw new RuntimeException('智能分类映射节点不正确，已停止 full 模式');
            }
            if ($entry['parent_key'] !== null) {
                $sourceKey = hash(
                    'sha256',
                    'source' . "\0" . $sourceId . "\0" . $entry['parent_key'] . "\0" . $entry['name'],
                );
                if (hash_equals($key, $sourceKey)) {
                    $managed = true;
                }
            }
        }
        return $managed;
    }

    /** @param array<string,mixed> $state */
    private function jobStateContainsActiveSource(array $state, int $sourceId): bool
    {
        $states = [
            'queued_analysis', 'analyzing', 'awaiting_confirmation', 'queued_import', 'importing',
            'pause_requested', 'paused', 'cancel_requested', 'cancelled', 'completed', 'failed',
        ];
        $terminal = ['cancelled', 'completed', 'failed'];
        $schema = $state['schema'] ?? null;
        $snapshotGc = null;
        if ($schema === 1) {
            if (!$this->hasExactKeys($state, ['schema', 'jobs'])) {
                throw new RuntimeException('智能货源中心任务状态格式不正确，已停止 full 模式');
            }
        } elseif (in_array($schema, [2, 3, 4], true)) {
            if (!$this->hasExactKeys($state, ['schema', 'jobs', 'snapshot_gc'])) {
                throw new RuntimeException('智能货源中心任务状态格式不正确，已停止 full 模式');
            }
            $snapshotGc = $state['snapshot_gc'];
            if ($snapshotGc !== null) {
                if (!is_array($snapshotGc)
                    || !$this->hasExactKeys(
                        $snapshotGc,
                        ['task_id', 'source_fingerprint', 'plan_hash', 'sha256'],
                    )
                    || !is_string($snapshotGc['task_id'] ?? null)
                    || preg_match('/^[a-f0-9]{48}$/D', $snapshotGc['task_id']) !== 1
                    || !is_string($snapshotGc['source_fingerprint'] ?? null)
                    || preg_match('/^[a-f0-9]{64}$/D', $snapshotGc['source_fingerprint']) !== 1) {
                    throw new RuntimeException('智能货源中心快照清理状态格式不正确，已停止 full 模式');
                }
                $planHash = $snapshotGc['plan_hash'];
                $snapshotSha256 = $snapshotGc['sha256'];
                if (($planHash === null) !== ($snapshotSha256 === null)
                    || ($planHash !== null
                        && (!is_string($planHash)
                            || preg_match('/^[a-f0-9]{64}$/D', $planHash) !== 1
                            || !is_string($snapshotSha256)
                            || preg_match('/^[a-f0-9]{64}$/D', $snapshotSha256) !== 1))) {
                    throw new RuntimeException('智能货源中心快照清理绑定不正确，已停止 full 模式');
                }
            }
        } else {
            throw new RuntimeException('智能货源中心任务状态格式不正确，已停止 full 模式');
        }

        if (!is_array($state['jobs'] ?? null)
            || ($state['jobs'] !== [] && array_is_list($state['jobs']))
            || count($state['jobs']) > 64) {
            throw new RuntimeException('智能货源中心任务状态格式不正确，已停止 full 模式');
        }
        if ($snapshotGc !== null && array_key_exists($snapshotGc['task_id'], $state['jobs'])) {
            throw new RuntimeException('智能货源中心快照清理仍引用任务历史，已停止 full 模式');
        }

        $active = false;
        $activeSources = [];
        foreach ($state['jobs'] as $taskId => $job) {
            if (!is_string($taskId)
                || preg_match('/^[a-f0-9]{48}$/D', $taskId) !== 1
                || !is_array($job)
                || ($job['task_id'] ?? null) !== $taskId
                || !is_int($job['source_id'] ?? null)
                || $job['source_id'] < 1
                || $job['source_id'] > 0x7fffffff
                || !is_string($job['state'] ?? null)
                || !in_array($job['state'], $states, true)) {
                throw new RuntimeException('智能货源中心任务身份不正确，已停止 full 模式');
            }
            if (!in_array($job['state'], $terminal, true)) {
                if (isset($activeSources[$job['source_id']])) {
                    throw new RuntimeException('同一货源存在多个活动任务，已停止 full 模式');
                }
                $activeSources[$job['source_id']] = true;
                if ($job['source_id'] === $sourceId) {
                    $active = true;
                }
            }
        }
        return $active;
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private function hasExactKeys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        return $actual === $expected;
    }

    private function isCategoryNodeName(mixed $value): bool
    {
        return is_string($value)
            && mb_check_encoding($value, 'UTF-8')
            && $value !== ''
            && mb_strlen($value, 'UTF-8') <= 128
            && preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $value) !== 1
            && preg_match('/\A[\s\p{Z}]|[\s\p{Z}]\z/u', $value) !== 1;
    }

    private function isLegacyCategoryNodeName(mixed $value): bool
    {
        return is_string($value)
            && mb_check_encoding($value, 'UTF-8')
            && $value !== ''
            && mb_strlen($value, 'UTF-8') <= 64
            && preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $value) !== 1
            && preg_match('#[\\\\/]#u', $value) !== 1
            && preg_match('/\A[\s\p{Z}]|[\s\p{Z}]\z/u', $value) !== 1;
    }

    private function error(int $sourceId, string $message, ?array $catalogDiagnostic = null,
        ?BudgetExceeded $budgetFailure = null): array
    {
        $result = ['source_id' => $sourceId, 'status' => 'error', 'message' => $message];
        if ($catalogDiagnostic !== null) {
            $result['catalog_diagnostic'] = array_intersect_key(UpstreamFailure::sanitize($catalogDiagnostic),
                array_flip(['category', 'http_status', 'curl_code', 'elapsed_ms', 'attempts']));
        }
        if ($budgetFailure !== null) {
            $result['budget_scope'] = $budgetFailure->scope;
            if ($budgetFailure->safeDiagnostics !== null) {
                $result['failure_diagnostic'] = UpstreamFailure::sanitizeObservation($budgetFailure->safeDiagnostics);
            }
        }
        $this->log($result);
        return $result;
    }

    private function message(\Throwable $exception, ?Shared $source = null): string
    {
        $secrets = $source === null ? [] : [(string)$source->app_id, (string)$source->app_key];
        $message = Redactor::text($exception->getMessage(), $secrets);
        return $message === '' ? '同步失败，未返回具体原因' : $message;
    }

    private function log(array &$result): void
    {
        try {
            $result['phase'] = $this->phase;
            $remaining = $this->budget->diagnosticRemaining();
            if ($remaining !== []) $result['remaining_budget'] = $remaining;
            $requests = $this->gateway->requestDiagnostics();
            if ($requests !== []) $result['request_diagnostics'] = $requests;
        } catch (\Throwable) {
            // Diagnostic collection must not mask the original source outcome.
        }
        $copy = $result;
        unset($copy['errors']);
        $this->logger->write($copy + $this->logContext);
    }
}
