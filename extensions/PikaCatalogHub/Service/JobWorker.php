<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaCatalogHub\Service;

use App\Model\Shared;
use Pika\LocalExtensions\PikaSupplySync\Service\CategoryIcons;
use Pika\LocalExtensions\PikaSupplySync\Service\CatalogPlanner;
use Pika\LocalExtensions\PikaSupplySync\Service\CommodityImportFailure;
use Pika\LocalExtensions\PikaSupplySync\Service\CommodityImporter;
use Pika\LocalExtensions\PikaSupplySync\Service\ImageCache;
use Pika\LocalExtensions\PikaSupplySync\Service\Options;
use Pika\LocalExtensions\PikaSupplySync\Service\PlannedCategoryMapper;
use Pika\LocalExtensions\PikaSupplySync\Service\PriceAdjuster;
use Pika\LocalExtensions\PikaSupplySync\Service\RemoteItem;
use Pika\LocalExtensions\PikaSupplySync\Service\RunBudget;
use Pika\LocalExtensions\PikaSupplySync\Service\SafeHttpClient;
use Pika\LocalExtensions\PikaSupplySync\Service\SharedGateway;
use Pika\LocalExtensions\PikaSupplySync\Service\SourceIdentity;
use Pika\LocalExtensions\PikaSupplySync\Service\SourceLock;
use Pika\LocalExtensions\PikaSupplySync\Service\SourcePolicy;
use Pika\LocalExtensions\PikaSupplySync\Service\UpstreamFailure;
use Pika\LocalExtensions\PikaSupplySync\Service\UpstreamCategoryTree;
use RuntimeException;

final class JobWorker
{
    private const MAX_BATCH = 20;

    private JobService $jobs;
    /** @var array<string,\Closure> */
    private array $runtime;
    private ?array $detailDiagnostic = null;

    /**
     * The runtime callback seam keeps the state machine testable without a
     * network or database. Production callers omit it and use the exact
     * Acg-Faka/Pika services assembled by productionRuntime().
     *
     * @param array<string,callable>|null $runtime
     */
    public function __construct(?JobService $jobs = null, ?array $runtime = null)
    {
        $this->jobs = $jobs ?? new JobService();
        $runtime ??= $this->productionRuntime();
        $runtime += ['detail_diagnostics' => static fn(): ?array => null];
        $required = [
            'lock_source',
            'load_source',
            'fingerprint',
            'fetch_catalog',
            'classify',
            'assert_plan_capacity',
            'import_planned_item',
            'begin_source',
            'end_source',
            'detail_diagnostics',
        ];
        $keys = array_keys($runtime);
        sort($keys, SORT_STRING);
        $expected = $required;
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new RuntimeException('货源中心后台执行器依赖格式不正确。');
        }
        foreach ($runtime as $name => $callback) {
            if (!is_callable($callback)) {
                throw new RuntimeException("货源中心后台执行器依赖 {$name} 不可调用。");
            }
            $this->runtime[$name] = \Closure::fromCallable($callback);
        }
    }

    /**
     * Process at most one queued task. Imports are additionally bounded to
     * twenty items so pause/cancel requests are observed between products.
     *
     * @return array<string,mixed>
     */
    public function runOne(int $batchLimit = self::MAX_BATCH): array
    {
        $this->detailDiagnostic = null;
        if ($batchLimit < 1 || $batchLimit > self::MAX_BATCH) {
            throw new RuntimeException('货源中心后台单批数量必须是 1-20。');
        }

        $candidate = $this->oldestQueued();
        if ($candidate === null) {
            return $this->emptyResult('idle');
        }

        try {
            $lease = ($this->runtime['lock_source'])($candidate['source_id']);
        } catch (\Throwable) {
            return $this->emptyResult('failed', 'SOURCE_LOCK_FAILED', $candidate['task_id']);
        }
        if (!is_object($lease)) {
            // Never claim a job if the shared SourceLock is busy. This keeps
            // the steady-state stock synchronizer and initial import mutually
            // exclusive for the same upstream.
            return $this->emptyResult('busy');
        }

        $task = null;
        $sourceStarted = false;
        try {
            try {
                $task = $this->jobs->beginWork($candidate['task_id'], $candidate['revision']);
            } catch (\Throwable) {
                // An admin control request may win the optimistic revision
                // race after the queue was listed. Treat only an observed
                // revision/state change as that race; persistence failures
                // must remain visible to systemd instead of looking "busy".
                try {
                    $latest = $this->jobs->get($candidate['task_id']);
                } catch (\Throwable) {
                    return $this->emptyResult('failed', 'TASK_CLAIM_FAILED', $candidate['task_id']);
                }
                if ($latest['revision'] !== $candidate['revision']
                    || !in_array($latest['state'], [
                        JobStore::STATE_QUEUED_ANALYSIS,
                        JobStore::STATE_QUEUED_IMPORT,
                    ], true)) {
                    return $this->emptyResult('busy');
                }
                return $this->emptyResult('failed', 'TASK_CLAIM_FAILED', $candidate['task_id']);
            }

            $source = $this->invoke('SOURCE_UNAVAILABLE', fn() => ($this->runtime['load_source'])($task['source_id']));
            if (!is_object($source) || (int)($source->id ?? 0) !== $task['source_id']) {
                throw new JobWorkerFailure('SOURCE_UNAVAILABLE');
            }
            $fingerprint = $this->invoke('SOURCE_IDENTITY_INVALID', fn() => ($this->runtime['fingerprint'])($source));
            if (!is_string($fingerprint) || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
                throw new JobWorkerFailure('SOURCE_IDENTITY_INVALID');
            }
            $this->invoke('SOURCE_IDENTITY_CHANGED', fn() => $this->jobs->assertSourceFingerprint(
                $task['task_id'],
                $fingerprint,
            ));

            $this->invoke('SOURCE_BUDGET_FAILED', fn() => ($this->runtime['begin_source'])($task['source_id']));
            $sourceStarted = true;
            if ($task['state'] === JobStore::STATE_ANALYZING) {
                return $this->runAnalysis($task, $source);
            }
            if ($task['state'] === JobStore::STATE_IMPORTING) {
                return $this->runImport($task, $source, $fingerprint, $batchLimit);
            }
            throw new JobWorkerFailure('TASK_STATE_INVALID');
        } catch (JobWorkerFailure $failure) {
            if (is_array($task)) {
                return $this->failSafely($task['task_id'], $failure->safeCode);
            }
            return $this->emptyResult('failed', $failure->safeCode);
        } catch (\Throwable) {
            if (is_array($task)) {
                return $this->failSafely($task['task_id'], 'WORKER_INTERNAL_ERROR');
            }
            return $this->emptyResult('failed', 'WORKER_INTERNAL_ERROR');
        } finally {
            if ($sourceStarted) {
                try {
                    ($this->runtime['end_source'])();
                } catch (\Throwable) {
                    // RunBudget::endSource() is non-throwing in production.
                    // A test/runtime cleanup failure must not replace the
                    // already-persisted task state with untrusted text.
                }
            }
            if (method_exists($lease, 'release')) {
                try {
                    $lease->release();
                } catch (\Throwable) {
                    // The real SourceLock also releases from its destructor.
                }
            }
        }
    }

    /** @param array<string,mixed> $task @return array<string,mixed> */
    private function runAnalysis(array $task, object $source): array
    {
        $mode = $task['category_mode'] ?? 'smart';
        $mirror = $mode === 'mirror';
        $current = $this->jobs->get($task['task_id']);
        if ($this->controlPending($current)) {
            return $this->result($this->acknowledgeControl($current));
        }

        /** @var array<string,array{code:string,name:string,category:string,stock:int,item:array}> $catalog */
        $catalog = $this->invoke($mirror ? 'MIRROR_TREE_UNAVAILABLE' : 'ANALYSIS_FETCH_FAILED', fn() => ($this->runtime['fetch_catalog'])($source, $mode));
        if (!is_array($catalog) || $catalog === []) {
            throw new JobWorkerFailure($mirror ? 'MIRROR_TREE_INVALID' : 'ANALYSIS_CATALOG_INVALID');
        }

        // The full catalog request may take up to the upstream timeout. Honor
        // pause/cancel before writing the immutable snapshot.
        $current = $this->jobs->get($task['task_id']);
        if ($this->controlPending($current)) {
            return $this->result($this->acknowledgeControl($current));
        }

        /** @var array<string,mixed> $suggestion */
        $suggestion = $mirror
            ? $this->invoke('MIRROR_TREE_INVALID', fn() => (new UpstreamCategoryTree())->suggest($catalog))
            : $this->invoke('CLASSIFICATION_FAILED', fn() => ($this->runtime['classify'])($catalog));
        if (!is_array($suggestion)
            || !is_string($suggestion['plan_hash'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $suggestion['plan_hash']) !== 1
            || !is_array($suggestion['categories'] ?? null)) {
            throw new JobWorkerFailure('CLASSIFICATION_INVALID');
        }

        $targets = [];
        foreach ($suggestion['categories'] as $category) {
            if (!is_array($category) || !is_string($category['name'] ?? null) || !is_array($category['target'] ?? null)) {
                throw new JobWorkerFailure('CLASSIFICATION_INVALID');
            }
            $key = UpstreamCategoryTree::categoryKey($category['name'], $category['target']);
            if (isset($targets[$key])) {
                throw new JobWorkerFailure($mirror ? 'MIRROR_TREE_INVALID' : 'CLASSIFICATION_INVALID');
            }
            $targets[$key] = $category['target'];
        }
        $snapshot = [];
        foreach ($catalog as $row) {
            if (!is_array($row)) {
                throw new JobWorkerFailure('ANALYSIS_CATALOG_INVALID');
            }
            $category = $row['category'] ?? null;
            if (!is_string($category)) {
                throw new JobWorkerFailure($mirror ? 'MIRROR_TREE_INVALID' : 'CLASSIFICATION_INVALID');
            }
            $target = $mirror ? ($row['target'] ?? null) : ($targets['category:' . $category] ?? null);
            if (!is_array($target)) {
                throw new JobWorkerFailure($mirror ? 'MIRROR_TREE_INVALID' : 'CLASSIFICATION_INVALID');
            }
            $key = UpstreamCategoryTree::categoryKey($category, $target);
            if (!isset($targets[$key]) || $targets[$key] !== $target) {
                throw new JobWorkerFailure($mirror ? 'MIRROR_TREE_INVALID' : 'CLASSIFICATION_INVALID');
            }
            // Product names, item details, credentials and URLs deliberately
            // never enter the durable snapshot.
            $snapshot[] = [
                'code' => $row['code'] ?? null,
                'category' => $category,
                'stock' => $row['stock'] ?? null,
                'target' => $target,
            ];
        }

        if ($mirror) {
            $this->invoke('MIRROR_CATEGORY_CAPACITY_FAILED', fn() => ($this->runtime['assert_plan_capacity'])(
                $source,
                $task['source_alias'],
                $snapshot,
            ));
        }

        $current = $this->jobs->get($task['task_id']);
        if ($this->controlPending($current)) {
            return $this->result($this->acknowledgeControl($current));
        }
        /** @var array<string,mixed> $stored */
        $stored = $this->invoke('ANALYSIS_PERSIST_FAILED', fn() => $this->jobs->storeAnalysisData(
            $task['task_id'],
            $current['revision'],
            $suggestion['plan_hash'],
            $snapshot,
            $suggestion['categories'],
        ));
        return $this->result($stored);
    }

    /** @param array<string,mixed> $task @return array<string,mixed> */
    private function runImport(
        array $task,
        object $source,
        string $fingerprint,
        int $batchLimit,
    ): array {
        /** @var array<string,mixed> $payload */
        $payload = $this->invoke('IMPORT_SNAPSHOT_INVALID', fn() => $this->jobs->loadImportSnapshot(
            $task['task_id'],
            $task['revision'],
            $fingerprint,
        ));
        if (!is_array($payload['items'] ?? null)
            || !is_string($payload['plan_hash'] ?? null)
            || !is_scalar($payload['premium_percent'] ?? null)) {
            throw new JobWorkerFailure('IMPORT_SNAPSHOT_INVALID');
        }

        $items = $payload['items'];
        $progress = $task['progress'];
        $itemFailures = $task['item_failures'];
        if (!is_array($itemFailures)) {
            throw new JobWorkerFailure('IMPORT_FAILURE_HISTORY_UNAVAILABLE');
        }
        $retrying = JobStore::hasActiveRetry($task);
        if (!$retrying && JobStore::itemFailureLimitReached($itemFailures, $progress['processed'])) {
            throw new JobWorkerFailure('IMPORT_ITEM_FAILURE_LIMIT');
        }
        if (!is_array($progress)
            || ($progress['total'] ?? null) !== count($items)
            || !is_int($progress['processed'] ?? null)
            || $progress['processed'] < 0
            || $progress['processed'] > count($items)) {
            throw new JobWorkerFailure('IMPORT_PROGRESS_INVALID');
        }
        $plannedItems = $retrying
            ? array_map(static fn(int $index): array => $items[$index], array_slice($task['retry']['indices'], $task['retry']['cursor']))
            : $items;
        $this->invoke('IMPORT_CATEGORY_CAPACITY_FAILED', fn() => ($this->runtime['assert_plan_capacity'])(
            $source,
            $task['source_alias'],
            $plannedItems,
        ));
        $options = $this->invoke('IMPORT_OPTIONS_INVALID', fn() => Options::fromArray([
            'mode' => Options::MODE_FULL,
            'source_ids' => (string)$task['source_id'],
            'premium_percent' => $payload['premium_percent'],
            'batch_limit' => $batchLimit,
            'zero_fuse_percent' => 10,
            'zero_fuse_min' => 5,
            'dry_run' => false,
        ]));

        if ($retrying) {
            return $this->runRetryImport($task, $source, $items, $payload['plan_hash'], $options, $batchLimit);
        }

        $processedThisRun = 0;
        while ($progress['processed'] < count($items) && $processedThisRun < $batchLimit) {
            $current = $this->jobs->get($task['task_id']);
            if ($this->controlPending($current)) {
                return $this->result($this->acknowledgeControl($current, $progress));
            }
            if ($current['state'] !== JobStore::STATE_IMPORTING) {
                throw new JobWorkerFailure('TASK_STATE_INVALID');
            }

            $item = $items[$progress['processed']];
            if (!is_array($item) || !is_array($item['target'] ?? null)) {
                throw new JobWorkerFailure('IMPORT_SNAPSHOT_INVALID');
            }
            // The production callback fetches/normalizes the remote detail
            // first, then resolves the category and writes the commodity in
            // one transaction. Keeping this as one seam prevents a stale
            // category ID from crossing an administrator edit boundary.
            $isolatedFailure = null;
            $this->detailDiagnostic = null;
            try {
                $outcome = ($this->runtime['import_planned_item'])(
                    $source,
                    $item,
                    $task['source_alias'],
                    $item['target'],
                    $payload['plan_hash'],
                    $options,
                );
            } catch (JobWorkerFailure $failure) {
                throw $failure;
            } catch (CommodityImportFailure $failure) {
                $this->observeDetail($progress['processed'], $failure);
                if ($failure->safeDiagnostics !== null) {
                    // Never serialize the exception, its previous chain, or supplier data.
                    try {
                        error_log((string)json_encode([
                            'event' => 'catalog_detail_failure',
                            'task_hash' => substr(hash('sha256', $task['task_id']), 0, 16),
                            'error_code' => $failure->safeCode,
                            'index' => $progress['processed'],
                        ] + $failure->safeDiagnostics, JSON_UNESCAPED_SLASHES));
                    } catch (\Throwable) {
                        // Diagnostic delivery must not change the checkpoint or original failure.
                    }
                }
                if (!CommodityImportFailure::isIsolatableItemCode($failure->safeCode)) {
                    throw new JobWorkerFailure($failure->safeCode, $failure);
                }
                $isolatedFailure = [
                    'index' => $progress['processed'],
                    'code' => $failure->safeCode,
                    'attempts' => $failure->safeDiagnostics['attempts'] ?? 0,
                ];
            } catch (\Throwable $exception) {
                $this->observeDetail($progress['processed']);
                throw new JobWorkerFailure('ITEM_IMPORT_FAILED', $exception);
            }
            if ($isolatedFailure === null && (!is_string($outcome) || !in_array($outcome, [
                CommodityImporter::OUTCOME_CREATED,
                CommodityImporter::OUTCOME_REATTACHED,
                CommodityImporter::OUTCOME_ALREADY_MANAGED,
                CommodityImporter::OUTCOME_HELD_EXISTING_UNMANAGED,
            ], true))) {
                throw new JobWorkerFailure('ITEM_IMPORT_OUTCOME_INVALID');
            }
            if ($isolatedFailure === null) {
                $this->observeDetail($progress['processed']);
            }

            $progress['processed']++;
            if ($isolatedFailure !== null) {
                $progress['failed']++;
                $itemFailures[] = $isolatedFailure;
            } elseif (in_array($outcome, [
                CommodityImporter::OUTCOME_CREATED,
                CommodityImporter::OUTCOME_REATTACHED,
            ], true)) {
                $progress['succeeded']++;
            } else {
                // An already-managed no-op and an unmanaged held product are
                // safely skipped, keeping restarted tasks idempotent.
                $progress['skipped']++;
            }
            $processedThisRun++;

            // Re-read the revision because an administrator may request pause
            // or cancel while the per-item transaction is in flight.
            $current = $this->jobs->get($task['task_id']);
            /** @var array<string,mixed> $checkpoint */
            $checkpoint = $this->checkpointAfterItem($task['task_id'], $current, $progress, $itemFailures);
            $progress = $checkpoint['progress'];
            $itemFailures = $checkpoint['item_failures'];
            if (in_array($checkpoint['state'], [JobStore::STATE_PAUSED, JobStore::STATE_CANCELLED, JobStore::STATE_FAILED], true)) {
                return $this->result($checkpoint);
            }
        }

        $current = $this->jobs->get($task['task_id']);
        if ($this->controlPending($current)) {
            return $this->result($this->acknowledgeControl($current, $progress));
        }
        if ($progress['processed'] === count($items)) {
            if ($progress['failed'] > 0) {
                return $this->result($this->invoke('IMPORT_COMPLETE_FAILED', fn() => $this->jobs->fail(
                    $task['task_id'],
                    $current['revision'],
                    'IMPORT_FINISHED_WITH_ISSUES',
                )));
            }
            /** @var array<string,mixed> $completed */
            $completed = $this->invoke('IMPORT_COMPLETE_FAILED', fn() => $this->jobs->complete(
                $task['task_id'],
                $current['revision'],
            ));
            return $this->result($completed);
        }

        /** @var array<string,mixed> $yielded */
        $yielded = $this->invoke('IMPORT_YIELD_FAILED', fn() => $this->jobs->yieldImport(
            $task['task_id'],
            $current['revision'],
            $progress,
        ));
        return $this->result($yielded);
    }

    /** Retry only the frozen unresolved indexes; never advance the original scan cursor. */
    private function runRetryImport(
        array $task,
        object $source,
        array $items,
        string $planHash,
        Options $options,
        int $batchLimit,
    ): array {
        for ($processedThisRun = 0; $processedThisRun < $batchLimit; $processedThisRun++) {
            $current = $this->jobs->get($task['task_id']);
            if ($this->controlPending($current)) {
                return $this->result($this->acknowledgeControl($current));
            }
            if ($current['state'] !== JobStore::STATE_IMPORTING) {
                return $this->result($current);
            }
            if (!JobStore::hasActiveRetry($current)) {
                break;
            }
            $index = $current['retry']['indices'][$current['retry']['cursor']];
            if (!isset($items[$index]) || !is_array($items[$index]['target'] ?? null)
                || !in_array($index, array_column($current['item_failures'], 'index'), true)) {
                throw new JobWorkerFailure('IMPORT_RETRY_CHECKPOINT_INVALID');
            }
            $item = $items[$index];
            $isolatedFailure = null;
            $this->detailDiagnostic = null;
            try {
                $outcome = ($this->runtime['import_planned_item'])(
                    $source, $item, $task['source_alias'], $item['target'], $planHash, $options,
                );
            } catch (JobWorkerFailure $failure) {
                throw $failure;
            } catch (CommodityImportFailure $failure) {
                $this->observeDetail($index, $failure);
                $this->logRetryFailure($task['task_id'], $index, $failure);
                if (!CommodityImportFailure::isIsolatableItemCode($failure->safeCode)) {
                    throw new JobWorkerFailure($failure->safeCode, $failure);
                }
                $isolatedFailure = [
                    'index' => $index,
                    'code' => $failure->safeCode,
                    'attempts' => $failure->safeDiagnostics['attempts'] ?? 0,
                ];
            } catch (\Throwable $exception) {
                $this->observeDetail($index);
                throw new JobWorkerFailure('ITEM_IMPORT_FAILED', $exception);
            }
            if ($isolatedFailure !== null) {
                $result = 'failed';
            } else {
                $result = match ($outcome) {
                    CommodityImporter::OUTCOME_CREATED, CommodityImporter::OUTCOME_REATTACHED => 'succeeded',
                    CommodityImporter::OUTCOME_ALREADY_MANAGED, CommodityImporter::OUTCOME_HELD_EXISTING_UNMANAGED => 'skipped',
                    default => throw new JobWorkerFailure('ITEM_IMPORT_OUTCOME_INVALID'),
                };
                $this->observeDetail($index);
            }

            $current = $this->jobs->get($task['task_id']);
            $checkpoint = $this->checkpointAfterRetryItem($current, $index, $result, $isolatedFailure);
            if (in_array($checkpoint['state'], [JobStore::STATE_PAUSED, JobStore::STATE_CANCELLED, JobStore::STATE_FAILED], true)) {
                return $this->result($checkpoint);
            }
        }
        $current = $this->jobs->get($task['task_id']);
        return $this->result($this->invoke('IMPORT_RETRY_FINISH_FAILED', fn() => $this->jobs->finishRetry(
            $task['task_id'], $current['revision'],
        )));
    }

    private function checkpointAfterRetryItem(array $job, int $index, string $outcome, ?array $failure): array
    {
        try {
            return $this->jobs->checkpointRetry(
                $job['task_id'], $job['revision'], $index, $outcome, $failure, $this->detailDiagnostic,
            );
        } catch (\Throwable $firstFailure) {
            try {
                $latest = $this->jobs->get($job['task_id']);
            } catch (\Throwable) {
                throw new JobWorkerFailure('IMPORT_CHECKPOINT_FAILED', $firstFailure);
            }
            // Only reconcile an observed control revision race, never replay the import.
            if (!$this->controlPending($latest) || $latest['revision'] === $job['revision']) {
                throw new JobWorkerFailure('IMPORT_CHECKPOINT_FAILED', $firstFailure);
            }
            return $this->invoke('IMPORT_CHECKPOINT_FAILED', fn() => $this->jobs->checkpointRetry(
                $job['task_id'], $latest['revision'], $index, $outcome, $failure, $this->detailDiagnostic,
            ));
        }
    }

    private function observeDetail(int $index, ?CommodityImportFailure $failure = null): void
    {
        $safe = $failure?->safeDiagnostics;
        try {
            $observed = ($this->runtime['detail_diagnostics'])();
            if (is_array($observed)) {
                $safe = $observed;
            }
        } catch (\Throwable) {
            // Observability must not change the import or checkpoint outcome.
        }
        $this->detailDiagnostic = is_array($safe)
            ? ['index' => $index, 'diagnostics' => UpstreamFailure::sanitize($safe)]
            : null;
    }

    private function logRetryFailure(string $taskId, int $index, CommodityImportFailure $failure): void
    {
        try {
            error_log((string)json_encode([
                'event' => 'catalog_detail_failure',
                'task_hash' => $this->taskHash($taskId),
                'index' => $index,
                'error_code' => $failure->safeCode,
            ] + ($this->detailDiagnostic['diagnostics'] ?? []), JSON_UNESCAPED_SLASHES));
        } catch (\Throwable) {
            // Preserve the original failure even if the journal is unavailable.
        }
    }

    /** @return array{task_id:string,source_id:int,revision:int,created_at:string}|null */
    private function oldestQueued(): ?array
    {
        $queued = array_values(array_filter(
            $this->jobs->list(),
            static fn(array $job): bool => in_array($job['state'], [
                JobStore::STATE_QUEUED_ANALYSIS,
                JobStore::STATE_QUEUED_IMPORT,
            ], true),
        ));
        usort($queued, static function (array $left, array $right): int {
            $created = strcmp($left['created_at'], $right['created_at']);
            return $created !== 0 ? $created : strcmp($left['task_id'], $right['task_id']);
        });
        if ($queued === []) {
            return null;
        }
        return [
            'task_id' => $queued[0]['task_id'],
            'source_id' => $queued[0]['source_id'],
            'revision' => $queued[0]['revision'],
            'created_at' => $queued[0]['created_at'],
        ];
    }

    /** @param array<string,mixed> $job */
    private function controlPending(array $job): bool
    {
        return in_array($job['state'], [
            JobStore::STATE_PAUSE_REQUESTED,
            JobStore::STATE_CANCEL_REQUESTED,
        ], true);
    }

    /** @param array<string,mixed> $job @param array<string,mixed>|null $progress @return array<string,mixed> */
    private function acknowledgeControl(array $job, ?array $progress = null): array
    {
        if (JobStore::hasActiveRetry($job)) {
            return $this->jobs->finishRetry($job['task_id'], $job['revision']);
        }
        return $this->jobs->checkpoint(
            $job['task_id'],
            $job['revision'],
            $progress ?? $job['progress'],
        );
    }

    /**
     * A pause/cancel request can increment revision after the post-transaction
     * read but before checkpoint(). Retry exactly once only for that observed
     * control race, preserving the completed item's progress. Other failures
     * remain fail-stop and are never blindly retried.
     *
     * @param array<string,mixed> $job
     * @param array<string,mixed> $progress
     * @return array<string,mixed>
     */
    private function checkpointAfterItem(string $taskId, array $job, array $progress, array $itemFailures): array
    {
        try {
            return $this->jobs->checkpoint($taskId, $job['revision'], $progress, $itemFailures, $this->detailDiagnostic);
        } catch (\Throwable $firstFailure) {
            try {
                $latest = $this->jobs->get($taskId);
            } catch (\Throwable) {
                throw new JobWorkerFailure('IMPORT_CHECKPOINT_FAILED', $firstFailure);
            }
            if (!$this->controlPending($latest) || $latest['revision'] === $job['revision']) {
                throw new JobWorkerFailure('IMPORT_CHECKPOINT_FAILED', $firstFailure);
            }
            return $this->invoke('IMPORT_CHECKPOINT_FAILED', fn() => $this->jobs->checkpoint(
                $taskId,
                $latest['revision'],
                $progress,
                $itemFailures,
                $this->detailDiagnostic,
            ));
        }
    }

    /** @return mixed */
    private function invoke(string $safeCode, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (JobWorkerFailure $failure) {
            throw $failure;
        } catch (\Throwable $exception) {
            throw new JobWorkerFailure($safeCode, $exception);
        }
    }

    /** @return array<string,mixed> */
    private function failSafely(string $taskId, string $errorCode): array
    {
        try {
            $current = $this->jobs->get($taskId);
            if ($this->controlPending($current)) {
                return $this->result($this->acknowledgeControl($current));
            }
            if (in_array($current['state'], [
                JobStore::STATE_PAUSED,
                JobStore::STATE_CANCELLED,
                JobStore::STATE_COMPLETED,
                JobStore::STATE_FAILED,
            ], true)) {
                return $this->result($current);
            }
            return $this->result($this->jobs->fail($taskId, $current['revision'], $errorCode, $this->detailDiagnostic));
        } catch (\Throwable) {
            return $this->emptyResult('failed', 'WORKER_STATE_FAILED', $taskId);
        }
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    private function result(array $job): array
    {
        $progress = is_array($job['progress'] ?? null) ? $job['progress'] : [];
        $counts = is_array($job['counts'] ?? null) ? $job['counts'] : [];
        return [
            'status' => (string)($job['state'] ?? 'failed'),
            'task_hash' => $this->taskHash((string)($job['task_id'] ?? '')),
            'counts' => [
                'items' => (int)($counts['items'] ?? $progress['total'] ?? 0),
                'categories' => (int)($counts['categories'] ?? 0),
                'processed' => (int)($progress['processed'] ?? 0),
                'succeeded' => (int)($progress['succeeded'] ?? 0),
                'failed' => (int)($progress['failed'] ?? 0),
                'skipped' => (int)($progress['skipped'] ?? 0),
            ],
            'error_code' => is_string($job['error_code'] ?? null) ? $job['error_code'] : null,
        ];
    }

    /** @return array<string,mixed> */
    private function emptyResult(string $status, ?string $errorCode = null, string $taskId = ''): array
    {
        return [
            'status' => $status,
            'task_hash' => $taskId === '' ? null : $this->taskHash($taskId),
            'counts' => [
                'items' => 0,
                'categories' => 0,
                'processed' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'skipped' => 0,
            ],
            'error_code' => $errorCode,
        ];
    }

    private function taskHash(string $taskId): string
    {
        return substr(hash('sha256', $taskId), 0, 16);
    }

    /** @return array<string,callable> */
    private function productionRuntime(): array
    {
        $budget = new RunBudget();
        $policy = new SourcePolicy();
        $http = new SafeHttpClient($policy, null, $budget);
        $gateway = new SharedGateway($http, $policy);
        $planner = new CatalogPlanner();
        $tree = new UpstreamCategoryTree();
        $classifier = new ClassificationSuggester();
        $images = new ImageCache($http, $budget);
        $categoryIcons = new CategoryIcons($gateway, $images);
        $categoryMapper = new PlannedCategoryMapper(null,
            static fn(Shared $source, array $nodes): array => $categoryIcons->forCreation($source, $nodes));
        $importer = new CommodityImporter(
            $gateway,
            new PriceAdjuster(),
            new RemoteItem($images, $budget),
            $policy,
        );

        return [
            'lock_source' => static function (int $sourceId): ?SourceLock {
                $lock = new SourceLock();
                return $lock->acquire($sourceId) ? $lock : null;
            },
            'load_source' => static fn(int $sourceId): ?Shared => Shared::query()->find($sourceId),
            'fingerprint' => static fn(Shared $source): string => SourceIdentity::fingerprint($source),
            'fetch_catalog' => static function (Shared $source, string $mode = 'smart') use ($tree, $gateway, $planner): array {
                if ($mode !== 'mirror') return $planner->flatten($gateway->items($source));
                $snapshot = $gateway->categoryTree($source);
                return $tree->flatten($snapshot, $gateway->supportsCategoryIcons());
            },
            'classify' => static fn(array $catalog): array => $classifier->suggest($catalog),
            'assert_plan_capacity' => static function (
                Shared $source,
                string $alias,
                array $items,
            ) use ($categoryMapper): void {
                $categoryMapper->assertPlanCapacity($source, $alias, $items);
            },
            'import_planned_item' => static fn(
                Shared $source,
                array $item,
                string $alias,
                array $target,
                string $planHash,
                Options $options,
            ): string => $importer->importPlanned(
                $source,
                $item,
                $categoryMapper,
                $alias,
                $target,
                $planHash,
                $options,
            ),
            'begin_source' => static fn(int $sourceId) => $budget->beginSource($sourceId),
            'end_source' => static fn() => $budget->endSource(),
            'detail_diagnostics' => static fn(): ?array => $importer->detailDiagnostics(),
        ];
    }
}

final class JobWorkerFailure extends RuntimeException
{
    public readonly string $safeCode;

    public function __construct(string $safeCode, ?\Throwable $previous = null)
    {
        if (preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $safeCode) !== 1) {
            $safeCode = 'WORKER_INTERNAL_ERROR';
        }
        $this->safeCode = $safeCode;
        parent::__construct($safeCode, 0, $previous);
    }
}
