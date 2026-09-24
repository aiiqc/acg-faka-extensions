<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaCatalogHub\Service;

use Pika\LocalExtensions\PikaSupplySync\Service\CommodityImportFailure;
use Pika\LocalExtensions\PikaSupplySync\Service\UpstreamCategoryTree;
use Pika\LocalExtensions\PikaSupplySync\Service\UpstreamFailure;
use RuntimeException;

final class JobService
{
    private JobStore $jobs;
    private SnapshotStore $snapshots;

    public function __construct(?JobStore $jobs = null, ?SnapshotStore $snapshots = null)
    {
        $this->jobs = $jobs ?? new JobStore();
        $this->snapshots = $snapshots ?? new SnapshotStore();
    }

    /** @return array<string,mixed> */
    public function createAnalysis(int $sourceId, string $alias, string $sourceFingerprint, string $categoryMode = 'smart'): array
    {
        if (!in_array($categoryMode, ['smart', 'mirror'], true)) {
            throw new RuntimeException('后台任务分类模式不正确。');
        }
        if ($sourceId < 1 || $sourceId > 0x7fffffff) {
            throw new RuntimeException('后台任务货源 ID 不正确。');
        }
        $alias = $this->text($alias, 64, '后台任务货源别名', true);
        $sourceFingerprint = $this->sha256($sourceFingerprint, '后台任务货源指纹');
        $this->drainSnapshotGc();
        $now = gmdate('c');
        $job = [
            'task_id' => bin2hex(random_bytes(24)),
            'source_id' => $sourceId,
            'source_alias' => $alias,
            'source_fingerprint' => $sourceFingerprint,
            'state' => JobStore::STATE_QUEUED_ANALYSIS,
            'phase' => 'analysis',
            'revision' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'snapshot' => null,
            'categories' => [],
            'counts' => ['items' => 0, 'categories' => 0, 'high' => 0, 'low' => 0],
            'progress' => ['total' => 0, 'processed' => 0, 'succeeded' => 0, 'failed' => 0, 'skipped' => 0],
            'item_failures' => [],
            'retry' => null,
            'last_detail_diagnostic' => null,
            'detail_compatibility_count' => 0,
            'detail_resume_authorization' => null,
            'premium_percent' => null,
            'mappings' => [],
            'error_code' => null,
            'last_action' => 'create',
        ];
        if ($categoryMode === 'mirror') {
            $job['category_mode'] = $categoryMode;
        }
        $created = $this->jobs->create(
            $job,
            function (array $terminal, bool $delete): void {
                $snapshot = $terminal['snapshot'];
                if ($snapshot === null) {
                    $this->snapshots->retireUnboundTerminal(
                        $terminal['task_id'],
                        $terminal['source_fingerprint'],
                        $delete,
                    );
                    return;
                }
                $this->snapshots->retireBoundTerminal(
                    $terminal['task_id'],
                    $terminal['source_fingerprint'],
                    $snapshot['plan_hash'],
                    $snapshot['sha256'],
                    $delete,
                );
            },
        );
        try {
            $this->drainSnapshotGc();
        } catch (\Throwable) {
            // The new job is already atomically committed. A durable single-slot
            // GC receipt keeps the retired terminal snapshot attributable, and
            // the globally locked worker will retry before claiming any work.
        }
        return $this->present($created);
    }

    /** @return array<string,mixed> */
    public function create(int $sourceId, string $alias, string $sourceFingerprint, string $categoryMode = 'smart'): array
    {
        return $this->createAnalysis($sourceId, $alias, $sourceFingerprint, $categoryMode);
    }

    public function drainSnapshotGc(): bool
    {
        $gc = $this->jobs->pendingSnapshotGc();
        if ($gc === null) {
            return false;
        }
        if ($gc['plan_hash'] === null) {
            $this->snapshots->retireUnboundTerminal(
                $gc['task_id'],
                $gc['source_fingerprint'],
                true,
            );
        } else {
            $this->snapshots->retireBoundTerminal(
                $gc['task_id'],
                $gc['source_fingerprint'],
                $gc['plan_hash'],
                $gc['sha256'],
                true,
            );
        }
        $this->jobs->clearSnapshotGc($gc);
        return true;
    }

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        return array_map($this->present(...), $this->jobs->list());
    }

    /** @return array<string,mixed> */
    public function get(string $taskId): array
    {
        return $this->present($this->jobs->get($taskId));
    }

    /** @return array<string,mixed> */
    public function status(string $taskId): array
    {
        return $this->get($taskId);
    }

    public function assertSourceFingerprint(string $taskId, string $currentFingerprint): void
    {
        $currentFingerprint = $this->sha256($currentFingerprint, '当前货源指纹');
        $job = $this->jobs->get($taskId);
        if (!hash_equals($job['source_fingerprint'], $currentFingerprint)) {
            throw new RuntimeException('货源身份已变化，请取消当前任务并重新分析。');
        }
    }

    /**
     * Claims the oldest queued task. A later worker can call this without
     * learning upstream credentials because jobs contain only the local source ID.
     *
     * @return array<string,mixed>|null
     */
    public function claimNext(): ?array
    {
        $queued = array_values(array_filter(
            $this->jobs->list(),
            static fn(array $job): bool => in_array(
                $job['state'],
                [JobStore::STATE_QUEUED_ANALYSIS, JobStore::STATE_QUEUED_IMPORT],
                true,
            ),
        ));
        usort($queued, static function (array $left, array $right): int {
            $created = strcmp($left['created_at'], $right['created_at']);
            return $created !== 0 ? $created : strcmp($left['task_id'], $right['task_id']);
        });
        if ($queued === []) {
            return null;
        }
        return $this->beginWork($queued[0]['task_id'], $queued[0]['revision']);
    }

    /**
     * Called only after the process owns the global worker.run.lock. Import
     * work is recoverable only with an exact immutable snapshot binding;
     * analysis work must have no JobStore binding and any canonical orphan is
     * removed before the task is queued again or its control is acknowledged.
     *
     * @return list<array<string,mixed>>
     */
    public function recoverInterrupted(): array
    {
        $recovered = $this->jobs->recoverInterrupted(function (array $job, string $target): void {
            if ($job['phase'] === 'analysis') {
                if ($job['snapshot'] !== null) {
                    throw new RuntimeException('分析任务存在不应出现的快照绑定，拒绝自动恢复。');
                }
                $this->snapshots->discardUnboundAnalysis($job['task_id'], $job['source_fingerprint']);
                return;
            }
            if ($job['phase'] !== 'import' || !is_array($job['snapshot'])) {
                throw new RuntimeException('导入任务缺少可验证的快照绑定，拒绝自动恢复。');
            }
            $metadata = $this->snapshots->readMetadata(
                $job['task_id'],
                $job['source_fingerprint'],
                $job['snapshot']['plan_hash'],
                $job['snapshot']['sha256'],
            );
            if ($metadata !== $job['snapshot']) {
                throw new RuntimeException('导入任务快照摘要绑定不一致，拒绝自动恢复。');
            }
        });
        return array_map($this->present(...), $recovered);
    }

    /** @return array<string,mixed> */
    public function beginWork(string $taskId, int $revision): array
    {
        $updated = $this->jobs->update(
            $taskId,
            $revision,
            function (array $job): array {
                if ($job['state'] === JobStore::STATE_QUEUED_ANALYSIS) {
                    return $this->changed($job, ['state' => JobStore::STATE_ANALYZING]);
                }
                if ($job['state'] === JobStore::STATE_QUEUED_IMPORT) {
                    return $this->changed($job, ['state' => JobStore::STATE_IMPORTING]);
                }
                throw new RuntimeException('后台任务当前状态不能开始工作。');
            },
            null,
            true,
        );
        return $this->present($updated);
    }

    /**
     * Writes the immutable, product-name-free catalog snapshot. The returned
     * SHA256 is then supplied to storeAnalysis after the classifier summary is ready.
     *
     * @param list<array{code:mixed,category:mixed,stock:mixed,target:mixed}> $items
     * @return array{sha256:string,plan_hash:string,source_fingerprint:string,item_count:int}
     */
    public function storeAnalysisSnapshot(
        string $taskId,
        int $revision,
        string $planHash,
        array $items,
    ): array {
        $job = $this->jobs->get($taskId);
        if ($job['revision'] !== $revision) {
            throw new RuntimeException('后台任务已被其他操作更新，请刷新后重试。');
        }
        if ($job['state'] !== JobStore::STATE_ANALYZING || $job['phase'] !== 'analysis') {
            throw new RuntimeException('只有正在分析的任务可以保存目录快照。');
        }
        return $this->snapshots->write($taskId, $job['source_fingerprint'], $planHash, $items, $job['category_mode'] ?? 'smart');
    }

    /**
     * @param list<array{name:mixed,count:mixed,target:mixed,confidence:mixed}> $categories
     * @return array<string,mixed>
     */
    public function storeAnalysis(
        string $taskId,
        int $revision,
        string $snapshotSha256,
        string $planHash,
        array $categories,
        int $total,
    ): array {
        $categories = $this->categories($categories);
        if ($total < 1 || $total > 10000) {
            throw new RuntimeException('后台任务分析商品总数不正确。');
        }
        $categoryTotal = array_sum(array_column($categories, 'count'));
        if ($categoryTotal !== $total) {
            throw new RuntimeException('后台任务分类数量与商品总数不一致。');
        }
        $current = $this->jobs->get($taskId);
        $this->assertCategoryMode($categories, $current['category_mode'] ?? 'smart', 'name');
        if ($current['state'] === JobStore::STATE_AWAITING_CONFIRMATION
            && $current['snapshot'] !== null
            && hash_equals($current['snapshot']['sha256'], $snapshotSha256)
            && hash_equals($current['snapshot']['plan_hash'], $planHash)) {
            if ($current['categories'] !== $categories || $current['counts']['items'] !== $total) {
                throw new RuntimeException('重复分析结果与原任务不一致。');
            }
            return $this->present($current);
        }
        if ($current['revision'] !== $revision) {
            throw new RuntimeException('后台任务已被其他操作更新，请刷新后重试。');
        }
        if ($current['state'] !== JobStore::STATE_ANALYZING || $current['phase'] !== 'analysis') {
            throw new RuntimeException('后台任务当前状态不能保存分析结果。');
        }
        $snapshot = $this->snapshots->read(
            $taskId,
            $snapshotSha256,
            $current['source_fingerprint'],
            $planHash,
        );
        if (count($snapshot['items']) !== $total
            || ($snapshot['category_mode'] ?? 'smart') !== ($current['category_mode'] ?? 'smart')) {
            throw new RuntimeException('后台任务快照数量与分析结果不一致。');
        }
        if (($current['category_mode'] ?? 'smart') === 'mirror') {
            $frozen = (new UpstreamCategoryTree())->suggest($snapshot['items']);
            if (!hash_equals($planHash, $frozen['plan_hash']) || $categories !== $this->categories($frozen['categories'])) {
                throw new RuntimeException('保留上游分类结构的分析结果与冻结快照不一致。');
            }
        }
        $categoryMap = [];
        foreach ($categories as $category) {
            $key = UpstreamCategoryTree::categoryKey($category['name'], $category['target']);
            $categoryMap[$key] = ['count' => 0, 'target' => $category['target']];
        }
        foreach ($snapshot['items'] as $item) {
            $key = UpstreamCategoryTree::categoryKey($item['category'], $item['target']);
            if (!isset($categoryMap[$key])
                || $categoryMap[$key]['target'] !== $item['target']) {
                throw new RuntimeException('后台任务快照与分类建议不一致。');
            }
            $categoryMap[$key]['count']++;
        }
        foreach ($categories as $category) {
            $key = UpstreamCategoryTree::categoryKey($category['name'], $category['target']);
            if ($categoryMap[$key]['count'] !== $category['count']) {
                throw new RuntimeException('后台任务快照分类计数与分析结果不一致。');
            }
        }
        $metadata = [
            'sha256' => $snapshotSha256,
            'plan_hash' => $planHash,
            'source_fingerprint' => $current['source_fingerprint'],
            'item_count' => $total,
        ];
        $high = count(array_filter($categories, static fn(array $row): bool => $row['confidence'] === 'high'));
        $low = count($categories) - $high;

        $updated = $this->jobs->update($taskId, $revision, function (array $job) use (
            $metadata,
            $categories,
            $total,
            $high,
            $low,
        ): array {
            if ($job['state'] !== JobStore::STATE_ANALYZING) {
                throw new RuntimeException('后台任务当前状态不能保存分析结果。');
            }
            return $this->changed($job, [
                'state' => JobStore::STATE_AWAITING_CONFIRMATION,
                'snapshot' => $metadata,
                'categories' => $categories,
                'counts' => [
                    'items' => $total,
                    'categories' => count($categories),
                    'high' => $high,
                    'low' => $low,
                ],
                'progress' => [
                    'total' => $total,
                    'processed' => 0,
                    'succeeded' => 0,
                    'failed' => 0,
                    'skipped' => 0,
                ],
                'error_code' => null,
            ]);
        });
        return $this->present($updated);
    }

    /**
     * Convenience entry point for a worker. It writes the immutable snapshot
     * and then records only its digest and aggregate category suggestions.
     *
     * @param list<array{code:mixed,category:mixed,stock:mixed,target:mixed}> $items
     * @param list<array{name:mixed,count:mixed,target:mixed,confidence:mixed}> $categories
     * @return array<string,mixed>
     */
    public function storeAnalysisData(
        string $taskId,
        int $revision,
        string $planHash,
        array $items,
        array $categories,
    ): array {
        try {
            $metadata = $this->storeAnalysisSnapshot($taskId, $revision, $planHash, $items);
            return $this->storeAnalysis(
                $taskId,
                $revision,
                $metadata['sha256'],
                $planHash,
                $categories,
                $metadata['item_count'],
            );
        } catch (\Throwable $primaryFailure) {
            try {
                $current = $this->jobs->get($taskId);
                if ($current['phase'] === 'analysis' && $current['snapshot'] === null) {
                    $this->snapshots->discardUnboundAnalysis(
                        $current['task_id'],
                        $current['source_fingerprint'],
                    );
                }
            } catch (\Throwable $cleanupFailure) {
                throw new RuntimeException(
                    '后台任务未绑定快照清理失败。',
                    0,
                    $primaryFailure,
                );
            }
            throw $primaryFailure;
        }
    }

    /**
     * @param list<array{source_category:mixed,target:mixed,confidence:mixed}> $mappings
     * @return array<string,mixed>
     */
    public function confirmImport(
        string $taskId,
        int $revision,
        string $planHash,
        mixed $premiumPercent,
        array $mappings,
    ): array {
        $premium = $this->premium($premiumPercent);
        $mappings = $this->mappings($mappings);
        $this->sha256($planHash, '分类方案哈希');
        $current = $this->jobs->get($taskId);
        $this->assertCategoryMode($mappings, $current['category_mode'] ?? 'smart', 'source_category');
        if ($current['last_action'] === 'confirm'
            && in_array($current['state'], [
                JobStore::STATE_QUEUED_IMPORT,
                JobStore::STATE_IMPORTING,
                JobStore::STATE_PAUSE_REQUESTED,
                JobStore::STATE_PAUSED,
                JobStore::STATE_CANCEL_REQUESTED,
                JobStore::STATE_CANCELLED,
                JobStore::STATE_COMPLETED,
            ], true)) {
            if ($current['premium_percent'] !== $premium
                || $current['mappings'] !== $mappings
                || $current['snapshot'] === null
                || !hash_equals($current['snapshot']['plan_hash'], $planHash)) {
                throw new RuntimeException('重复确认参数与原任务不一致。');
            }
            return $this->present($current);
        }
        $updated = $this->jobs->update(
            $taskId,
            $revision,
            function (array $job) use ($planHash, $premium, $mappings): array {
                if ($job['last_action'] === 'confirm'
                    && in_array($job['state'], [
                        JobStore::STATE_QUEUED_IMPORT,
                        JobStore::STATE_IMPORTING,
                        JobStore::STATE_PAUSE_REQUESTED,
                        JobStore::STATE_PAUSED,
                        JobStore::STATE_CANCEL_REQUESTED,
                        JobStore::STATE_CANCELLED,
                        JobStore::STATE_COMPLETED,
                    ], true)) {
                    if ($job['premium_percent'] !== $premium || $job['mappings'] !== $mappings) {
                        throw new RuntimeException('重复确认参数与原任务不一致。');
                    }
                    return $job;
                }
                if ($job['state'] !== JobStore::STATE_AWAITING_CONFIRMATION || $job['snapshot'] === null) {
                    throw new RuntimeException('后台任务尚未等待分类确认。');
                }
                if (!hash_equals($job['snapshot']['plan_hash'], $planHash)) {
                    throw new RuntimeException('分类方案已变化，请重新分析后确认。');
                }
                $this->assertCategoryMode($mappings, $job['category_mode'] ?? 'smart', 'source_category');
                $expected = array_map(static fn(array $row): string => UpstreamCategoryTree::categoryKey($row['name'], $row['target']), $job['categories']);
                sort($expected, SORT_STRING);
                $actual = array_map(static fn(array $row): string => UpstreamCategoryTree::categoryKey($row['source_category'], $row['target']), $mappings);
                sort($actual, SORT_STRING);
                if ($expected !== $actual) {
                    throw new RuntimeException('确认映射必须完整覆盖本次上游分类。');
                }
                if (($job['category_mode'] ?? 'smart') === 'mirror') {
                    $frozen = $this->mappings(array_map(static fn(array $row): array => [
                        'source_category' => $row['name'],
                        'target' => $row['target'],
                        'confidence' => $row['confidence'],
                    ], $job['categories']));
                    if ($mappings !== $frozen) {
                        throw new RuntimeException('保留上游分类结构必须确认原始冻结方案，不能修改分类路径或名称。');
                    }
                }
                return $this->changed($job, [
                    'state' => JobStore::STATE_QUEUED_IMPORT,
                    'phase' => 'import',
                    'premium_percent' => $premium,
                    'mappings' => $mappings,
                    'last_action' => 'confirm',
                ]);
            },
        );
        return $this->present($updated);
    }

    /** @param list<array{source_category:mixed,target:mixed,confidence:mixed}> $mappings @return array<string,mixed> */
    public function confirm(
        string $taskId,
        int $revision,
        string $planHash,
        mixed $premiumPercent,
        array $mappings,
    ): array {
        return $this->confirmImport($taskId, $revision, $planHash, $premiumPercent, $mappings);
    }

    /** @return array<string,mixed> */
    public function control(string $taskId, int $revision, string $action): array
    {
        if (!in_array($action, ['pause', 'resume', 'retry_failed', 'cancel'], true)) {
            throw new RuntimeException('后台任务控制动作不正确。');
        }
        $updated = $this->jobs->update(
            $taskId,
            $revision,
            function (array $job) use ($action): array {
                if ($job['last_action'] === $action && $this->alreadyControlled($job['state'], $action)) {
                    return $job;
                }
                $state = $job['state'];
                if ($action === 'pause') {
                    if (in_array($state, [JobStore::STATE_QUEUED_ANALYSIS, JobStore::STATE_QUEUED_IMPORT], true)) {
                        return $this->changed($job, ['state' => JobStore::STATE_PAUSED, 'last_action' => 'pause']);
                    }
                    if (in_array($state, [JobStore::STATE_ANALYZING, JobStore::STATE_IMPORTING], true)) {
                        return $this->changed($job, ['state' => JobStore::STATE_PAUSE_REQUESTED, 'last_action' => 'pause']);
                    }
                    throw new RuntimeException('后台任务当前状态不能暂停。');
                }
                if ($action === 'resume') {
                    if ($state === JobStore::STATE_PAUSED) {
                        if (is_array($job['retry']) && $job['retry']['halted']) {
                            throw new RuntimeException('旧补处理轮已停止，请使用补处理按钮明确继续既有清单。');
                        }
                        return $this->changed($job, [
                            'state' => $job['phase'] === 'analysis'
                                ? JobStore::STATE_QUEUED_ANALYSIS
                                : JobStore::STATE_QUEUED_IMPORT,
                            'last_action' => 'resume',
                        ]);
                    }
                    if (!$this->canResumeFailedImport($job)) {
                        throw new RuntimeException('只有已暂停任务或详情读取失败的未完成导入任务可以继续。');
                    }
                    $metadata = $this->snapshots->readMetadata(
                        $job['task_id'],
                        $job['source_fingerprint'],
                        $job['snapshot']['plan_hash'],
                        $job['snapshot']['sha256'],
                    );
                    if ($metadata !== $job['snapshot']) {
                        throw new RuntimeException('导入任务快照摘要绑定不一致，拒绝继续。');
                    }
                    $authorization = $job['detail_resume_authorization'];
                    if ($this->hasUsableDetailResumeAuthorization($job)) {
                        $authorization['consumed'] = true;
                    }
                    return $this->changed($job, [
                        'state' => JobStore::STATE_QUEUED_IMPORT,
                        'error_code' => null,
                        'last_action' => 'resume',
                        'detail_resume_authorization' => $authorization,
                    ]);
                }
                if ($action === 'retry_failed') {
                    if (!JobStore::canRetryFailedImport($job)) {
                        throw new RuntimeException('只有有未解决清单的异常结束或失败上限任务可以补处理。');
                    }
                    $metadata = $this->snapshots->readMetadata(
                        $job['task_id'],
                        $job['source_fingerprint'],
                        $job['snapshot']['plan_hash'],
                        $job['snapshot']['sha256'],
                    );
                    if ($metadata !== $job['snapshot']) {
                        throw new RuntimeException('导入任务快照摘要绑定不一致，拒绝继续。');
                    }
                    if (is_array($job['retry']) && $job['retry']['cursor'] < count($job['retry']['indices'])) {
                        // Continue the exact legacy round; never revisit its committed prefix.
                        $retry = $job['retry'];
                        $retry['halted'] = false;
                        return $this->changed($job, [
                            'state' => JobStore::STATE_QUEUED_IMPORT,
                            'error_code' => null,
                            'last_action' => 'retry_failed',
                            'retry' => $retry,
                        ]);
                    }
                    $indices = array_column($job['item_failures'], 'index');
                    sort($indices, SORT_NUMERIC);
                    if ($indices === [] || count(array_unique($indices, SORT_REGULAR)) !== count($indices)) {
                        throw new RuntimeException('补处理轮商品索引不正确。');
                    }
                    return $this->changed($job, [
                        'state' => JobStore::STATE_QUEUED_IMPORT,
                        'error_code' => null,
                        'last_action' => 'retry_failed',
                        'retry' => [
                            'origin_error_code' => $job['error_code'],
                            'indices' => array_values($indices),
                            'cursor' => 0,
                            'succeeded' => 0,
                            'skipped' => 0,
                            'failed' => 0,
                            'consecutive_failed' => 0,
                            'halted' => false,
                        ],
                    ]);
                }
                if ($this->canCancelFailedImport($job) || in_array($state, [
                    JobStore::STATE_QUEUED_ANALYSIS,
                    JobStore::STATE_AWAITING_CONFIRMATION,
                    JobStore::STATE_QUEUED_IMPORT,
                    JobStore::STATE_PAUSED,
                ], true)) {
                    return $this->changed($job, ['state' => JobStore::STATE_CANCELLED, 'last_action' => 'cancel']);
                }
                if (in_array($state, [
                    JobStore::STATE_ANALYZING,
                    JobStore::STATE_IMPORTING,
                    JobStore::STATE_PAUSE_REQUESTED,
                ], true)) {
                    return $this->changed($job, ['state' => JobStore::STATE_CANCEL_REQUESTED, 'last_action' => 'cancel']);
                }
                throw new RuntimeException('后台任务当前状态不能取消。');
            },
            $action,
        );
        return $this->present($updated);
    }

    /**
     * Internal operator-only compatibility grant for one exact legacy detail
     * checkpoint. No HTTP controller accepts or constructs this evidence.
     *
     * @param array<string,mixed> $verifiedEvidence
     * @return array<string,mixed>
     */
    public function authorizeLegacyDetailResume(
        string $taskId,
        int $revision,
        array $verifiedEvidence,
    ): array {
        $this->onlyKeys($verifiedEvidence, [
            'task_id', 'task_hash', 'revision', 'index', 'snapshot_sha256', 'source_fingerprint',
            'legacy_event', 'diagnostic',
        ], '详情恢复核验证据');
        if (($verifiedEvidence['task_id'] ?? null) !== $taskId
            || ($verifiedEvidence['task_hash'] ?? null) !== substr(hash('sha256', $taskId), 0, 16)
            || ($verifiedEvidence['revision'] ?? null) !== $revision) {
            throw new RuntimeException('详情恢复核验证据没有绑定当前任务 revision。');
        }
        $updated = $this->jobs->update($taskId, $revision, function (array $job) use ($verifiedEvidence): array {
            if ($job['state'] !== JobStore::STATE_FAILED
                || $job['phase'] !== 'import'
                || $job['error_code'] !== CommodityImportFailure::DETAIL_RESPONSE_INVALID
                || !is_array($job['snapshot'])
                || $job['premium_percent'] === null
                || !is_array($job['item_failures'])) {
                throw new RuntimeException('当前任务不是可核验的历史详情响应失败检查点。');
            }
            $index = $this->expectedDetailIndex($job);
            if (($verifiedEvidence['index'] ?? null) !== $index
                || ($verifiedEvidence['snapshot_sha256'] ?? null) !== $job['snapshot']['sha256']
                || ($verifiedEvidence['source_fingerprint'] ?? null) !== $job['source_fingerprint']) {
                throw new RuntimeException('详情恢复核验证据与任务、快照或商品索引不一致。');
            }
            $legacyEvent = $verifiedEvidence['legacy_event'] ?? null;
            if (!is_array($legacyEvent)) {
                throw new RuntimeException('详情恢复核验证据缺少历史失败事件。');
            }
            $this->onlyKeys($legacyEvent, ['error_code', 'diagnostics'], '详情恢复历史失败事件');
            $legacyDiagnostic = $this->normalizeDetailDiagnostic([
                'index' => $index,
                'diagnostics' => $legacyEvent['diagnostics'] ?? null,
            ], $job, $index);
            if (($legacyEvent['error_code'] ?? null) !== CommodityImportFailure::DETAIL_RESPONSE_INVALID
                || $legacyDiagnostic['diagnostics']['category'] !== 'content_type') {
                throw new RuntimeException('详情恢复历史失败事件与旧错误码不一致。');
            }
            $diagnostic = $this->normalizeDetailDiagnostic([
                'index' => $index,
                'diagnostics' => $verifiedEvidence['diagnostic'] ?? null,
            ], $job, $index);
            $safe = $diagnostic['diagnostics'];
            if ($safe['category'] !== 'none'
                || ($safe['http_status'] ?? null) !== 200
                || ($safe['curl_code'] ?? null) !== 0
                || ($safe['attempts'] ?? null) !== 1
                || !in_array($safe['mime_category'] ?? null, ['application_json', 'text_json'], true)
                || ($safe['mime_count'] ?? null) !== 1
                || ($safe['json_valid'] ?? null) !== true
                || ($safe['mime_compatibility'] ?? null) !== false) {
                throw new RuntimeException('详情恢复新诊断不是单次严格 JSON 成功观察。');
            }
            return $this->changed($job, [
                'last_detail_diagnostic' => $diagnostic,
                'detail_resume_authorization' => [
                    'task_id' => $job['task_id'],
                    'task_hash' => substr(hash('sha256', $job['task_id']), 0, 16),
                    'authorized_revision' => $job['revision'],
                    'index' => $index,
                    'snapshot_sha256' => $job['snapshot']['sha256'],
                    'source_fingerprint' => $job['source_fingerprint'],
                    'legacy_event' => [
                        'error_code' => CommodityImportFailure::DETAIL_RESPONSE_INVALID,
                        'diagnostics' => $legacyDiagnostic['diagnostics'],
                    ],
                    'diagnostic' => $safe,
                    'consumed' => false,
                ],
            ]);
        }, null, false, true);
        return $this->present($updated);
    }

    /** @param array<string,mixed> $progress @return array<string,mixed> */
    public function checkpoint(
        string $taskId,
        int $revision,
        array $progress,
        ?array $itemFailures = null,
        ?array $detailDiagnostic = null,
    ): array {
        $progress = $this->progress($progress);
        $updated = $this->jobs->update($taskId, $revision, function (array $job) use (
            $progress,
            $itemFailures,
            $detailDiagnostic,
        ): array {
            if (!in_array($job['state'], [
                JobStore::STATE_ANALYZING,
                JobStore::STATE_IMPORTING,
                JobStore::STATE_PAUSE_REQUESTED,
                JobStore::STATE_CANCEL_REQUESTED,
            ], true)) {
                throw new RuntimeException('后台任务当前状态不能保存进度。');
            }
            if (JobStore::hasActiveRetry($job)) {
                throw new RuntimeException('补处理轮必须使用独立逐项检查点。');
            }
            $old = $job['progress'];
            if ($progress['processed'] < $old['processed']
                || $progress['succeeded'] < $old['succeeded']
                || $progress['failed'] < $old['failed']
                || $progress['skipped'] < $old['skipped']) {
                throw new RuntimeException('后台任务进度不能倒退。');
            }
            if ($job['phase'] === 'import' && $progress['total'] !== $old['total']) {
                throw new RuntimeException('导入任务商品总数不能变化。');
            }
            if ($job['phase'] === 'analysis' && $progress['total'] < $old['total']) {
                throw new RuntimeException('分析任务商品总数不能倒退。');
            }
            $failures = $itemFailures ?? $job['item_failures'];
            $changes = ['progress' => $progress, 'item_failures' => $failures];
            if ($detailDiagnostic !== null) {
                if ($job['phase'] !== 'import' || $progress['processed'] !== $old['processed'] + 1) {
                    throw new RuntimeException('详情诊断必须随一个导入商品检查点提交。');
                }
                $diagnostic = $this->normalizeDetailDiagnostic($detailDiagnostic, $job, $old['processed']);
                $changes['last_detail_diagnostic'] = $diagnostic;
                if (($diagnostic['diagnostics']['mime_compatibility'] ?? false) === true
                    && ($progress['succeeded'] === $old['succeeded'] + 1
                        || $progress['skipped'] === $old['skipped'] + 1)) {
                    $changes['detail_compatibility_count'] = $job['detail_compatibility_count'] + 1;
                }
            }
            if ($job['state'] === JobStore::STATE_PAUSE_REQUESTED) {
                $changes['state'] = JobStore::STATE_PAUSED;
            } elseif ($job['state'] === JobStore::STATE_CANCEL_REQUESTED) {
                $changes['state'] = JobStore::STATE_CANCELLED;
            } elseif (is_array($failures) && JobStore::itemFailureLimitReached($failures, $progress['processed'])) {
                $changes['state'] = JobStore::STATE_FAILED;
                $changes['error_code'] = 'IMPORT_ITEM_FAILURE_LIMIT';
            } elseif ($job['phase'] === 'import' && $progress['failed'] > 0 && $progress['processed'] === $progress['total']) {
                $changes['state'] = JobStore::STATE_FAILED;
                $changes['error_code'] = 'IMPORT_FINISHED_WITH_ISSUES';
            }
            return $this->changed($job, $changes);
        }, null, false, $detailDiagnostic !== null);
        return $this->present($updated);
    }

    /** @return array<string,mixed> */
    public function checkpointRetry(
        string $taskId,
        int $revision,
        int $index,
        string $outcome,
        ?array $failure = null,
        ?array $detailDiagnostic = null,
    ): array {
        if (!in_array($outcome, ['succeeded', 'skipped', 'failed'], true)) {
            throw new RuntimeException('补处理商品结果不正确。');
        }
        $updated = $this->jobs->update($taskId, $revision, function (array $job) use (
            $index,
            $outcome,
            $failure,
            $detailDiagnostic,
        ): array {
            if ($job['phase'] !== 'import'
                || !in_array($job['state'], [
                    JobStore::STATE_IMPORTING,
                    JobStore::STATE_PAUSE_REQUESTED,
                    JobStore::STATE_CANCEL_REQUESTED,
                ], true)
                || !JobStore::hasActiveRetry($job)) {
                throw new RuntimeException('当前任务没有可提交的补处理商品。');
            }
            $retry = $job['retry'];
            $expectedIndex = $retry['indices'][$retry['cursor']];
            if ($index !== $expectedIndex) {
                throw new RuntimeException('补处理商品索引没有匹配冻结检查点。');
            }
            $position = null;
            foreach ($job['item_failures'] as $failurePosition => $savedFailure) {
                if ($savedFailure['index'] === $index) {
                    $position = $failurePosition;
                    break;
                }
            }
            if (!is_int($position)) {
                throw new RuntimeException('补处理商品已不在未解决清单。');
            }

            $progress = $job['progress'];
            $failures = $job['item_failures'];
            $retry['cursor']++;
            if ($outcome === 'failed') {
                if (!is_array($failure)) {
                    throw new RuntimeException('补处理失败缺少安全失败记录。');
                }
                $this->onlyKeys($failure, ['index', 'code', 'attempts'], '补处理失败记录');
                if (($failure['index'] ?? null) !== $index
                    || !CommodityImportFailure::isIsolatableItemCode($failure['code'] ?? null)
                    || !is_int($failure['attempts'] ?? null)
                    || $failure['attempts'] < 0 || $failure['attempts'] > 3) {
                    throw new RuntimeException('补处理失败记录不正确。');
                }
                $failures[$position] = $failure;
                $retry['failed']++;
                $retry['consecutive_failed']++;
            } else {
                if ($failure !== null) {
                    throw new RuntimeException('补处理成功结果不能附带失败记录。');
                }
                array_splice($failures, $position, 1);
                $progress['failed']--;
                $progress[$outcome]++;
                $retry[$outcome]++;
                $retry['consecutive_failed'] = 0;
            }
            // An explicitly frozen list is already bounded to MAX_ITEM_FAILURES.
            // Isolated item failures must not starve the unvisited tail of this round.
            $retry['halted'] = false;
            $changes = [
                'progress' => $progress,
                'item_failures' => array_values($failures),
                'retry' => $retry,
            ];
            if ($job['state'] === JobStore::STATE_PAUSE_REQUESTED) {
                $changes['state'] = JobStore::STATE_PAUSED;
            } elseif ($job['state'] === JobStore::STATE_CANCEL_REQUESTED) {
                $changes['state'] = JobStore::STATE_CANCELLED;
            }
            if ($detailDiagnostic !== null) {
                $diagnostic = $this->normalizeDetailDiagnostic($detailDiagnostic, $job, $index);
                $changes['last_detail_diagnostic'] = $diagnostic;
                if ($outcome !== 'failed'
                    && ($diagnostic['diagnostics']['mime_compatibility'] ?? false) === true) {
                    $changes['detail_compatibility_count'] = $job['detail_compatibility_count'] + 1;
                }
            }
            return $this->changed($job, $changes);
        }, null, false, $detailDiagnostic !== null);
        return $this->present($updated);
    }

    /** @return array<string,mixed> */
    public function finishRetry(string $taskId, int $revision): array
    {
        $updated = $this->jobs->update($taskId, $revision, function (array $job): array {
            if ($job['phase'] !== 'import'
                || !is_array($job['retry'])
                || !in_array($job['state'], [
                    JobStore::STATE_IMPORTING,
                    JobStore::STATE_PAUSE_REQUESTED,
                    JobStore::STATE_CANCEL_REQUESTED,
                ], true)) {
                throw new RuntimeException('当前任务没有可结束的补处理轮。');
            }
            if ($job['state'] === JobStore::STATE_PAUSE_REQUESTED) {
                return $this->changed($job, ['state' => JobStore::STATE_PAUSED]);
            }
            if ($job['state'] === JobStore::STATE_CANCEL_REQUESTED) {
                return $this->changed($job, ['state' => JobStore::STATE_CANCELLED]);
            }
            if ($job['retry']['halted']) {
                return $this->changed($job, [
                    'state' => JobStore::STATE_FAILED,
                    'error_code' => 'IMPORT_ITEM_FAILURE_LIMIT',
                ]);
            }
            if ($job['retry']['cursor'] < count($job['retry']['indices'])) {
                return $this->changed($job, ['state' => JobStore::STATE_QUEUED_IMPORT]);
            }
            if ($job['progress']['processed'] < $job['progress']['total']) {
                return $this->changed($job, ['state' => JobStore::STATE_PAUSED, 'error_code' => null]);
            }
            if ($job['progress']['failed'] > 0) {
                return $this->changed($job, [
                    'state' => JobStore::STATE_FAILED,
                    'error_code' => 'IMPORT_FINISHED_WITH_ISSUES',
                ]);
            }
            return $this->changed($job, ['state' => JobStore::STATE_COMPLETED, 'error_code' => null]);
        });
        return $this->present($updated);
    }

    /**
     * Ends one bounded import invocation. Normal work returns to queued_import
     * so the next systemd oneshot can claim it; pending controls are acknowledged
     * at the same atomic checkpoint instead of being requeued.
     *
     * @param array<string,mixed> $progress
     * @return array<string,mixed>
     */
    public function yieldImport(string $taskId, int $revision, array $progress): array
    {
        $progress = $this->progress($progress);
        $updated = $this->jobs->update($taskId, $revision, function (array $job) use ($progress): array {
            if ($job['phase'] !== 'import' || !in_array($job['state'], [
                JobStore::STATE_IMPORTING,
                JobStore::STATE_PAUSE_REQUESTED,
                JobStore::STATE_CANCEL_REQUESTED,
            ], true)) {
                throw new RuntimeException('后台任务当前状态不能结束本轮导入。');
            }
            if (JobStore::hasActiveRetry($job)) {
                throw new RuntimeException('补处理轮必须使用独立结束检查点。');
            }
            $this->assertProgressAdvance($job['progress'], $progress, true);
            $nextState = match ($job['state']) {
                JobStore::STATE_IMPORTING => JobStore::STATE_QUEUED_IMPORT,
                JobStore::STATE_PAUSE_REQUESTED => JobStore::STATE_PAUSED,
                JobStore::STATE_CANCEL_REQUESTED => JobStore::STATE_CANCELLED,
                default => throw new RuntimeException('后台任务当前状态不能结束本轮导入。'),
            };
            return $this->changed($job, ['progress' => $progress, 'state' => $nextState]);
        });
        return $this->present($updated);
    }

    /**
     * Worker-only payload. It verifies the current source identity and immutable
     * snapshot, then overlays smart mappings or verifies the unchanged mirror
     * paths. This method is never used by task status or admin-list responses.
     *
     * @return array{task_id:string,revision:int,plan_hash:string,premium_percent:string,items:list<array{code:string,category:string,stock:int,target:array<string,mixed>}>}
     */
    public function loadImportSnapshot(
        string $taskId,
        int $revision,
        string $currentSourceFingerprint,
    ): array {
        $currentSourceFingerprint = $this->sha256($currentSourceFingerprint, '当前货源指纹');
        $job = $this->jobs->get($taskId);
        if ($job['revision'] !== $revision) {
            throw new RuntimeException('后台任务已被其他操作更新，请刷新后重试。');
        }
        if ($job['state'] !== JobStore::STATE_IMPORTING
            || $job['phase'] !== 'import'
            || $job['snapshot'] === null
            || $job['premium_percent'] === null) {
            throw new RuntimeException('只有正在导入的任务可以读取确认快照。');
        }
        if (!hash_equals($job['source_fingerprint'], $currentSourceFingerprint)) {
            throw new RuntimeException('货源身份已变化，请取消当前任务并重新分析。');
        }
        $snapshot = $this->snapshots->read(
            $taskId,
            $job['snapshot']['sha256'],
            $currentSourceFingerprint,
            $job['snapshot']['plan_hash'],
        );
        $mode = $job['category_mode'] ?? 'smart';
        if (($snapshot['category_mode'] ?? 'smart') !== $mode) {
            throw new RuntimeException('确认快照分类模式与后台任务不一致。');
        }
        $this->assertCategoryMode($job['mappings'], $mode, 'source_category');
        $mappings = [];
        foreach ($job['mappings'] as $mapping) {
            $mappings[UpstreamCategoryTree::categoryKey($mapping['source_category'], $mapping['target'])] = $mapping['target'];
        }
        $items = [];
        foreach ($snapshot['items'] as $item) {
            $key = UpstreamCategoryTree::categoryKey($item['category'], $item['target']);
            if (!isset($mappings[$key])) {
                throw new RuntimeException('确认映射未覆盖快照中的上游分类。');
            }
            if ($mode === 'mirror' && $item['target'] !== $mappings[$key]) {
                throw new RuntimeException('保留上游分类结构的确认映射与冻结快照不一致。');
            }
            $item['target'] = $mappings[$key];
            $items[] = $item;
        }
        return [
            'task_id' => $job['task_id'],
            'revision' => $job['revision'],
            'plan_hash' => $job['snapshot']['plan_hash'],
            'premium_percent' => $job['premium_percent'],
            'items' => $items,
        ];
    }

    /** @return array<string,mixed> */
    public function fail(
        string $taskId,
        int $revision,
        string $errorCode,
        ?array $detailDiagnostic = null,
    ): array
    {
        if (preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $errorCode) !== 1) {
            throw new RuntimeException('后台任务错误代码格式不正确。');
        }
        $current = $this->jobs->get($taskId);
        if ($detailDiagnostic === null
            && $current['state'] === JobStore::STATE_FAILED
            && $current['error_code'] === $errorCode) {
            return $this->present($current);
        }
        $updated = $this->jobs->update($taskId, $revision, function (array $job) use (
            $errorCode,
            $detailDiagnostic,
        ): array {
            if ($this->jobs->isTerminal($job['state'])) {
                throw new RuntimeException('已结束后台任务不能标记失败。');
            }
            $changes = ['state' => JobStore::STATE_FAILED, 'error_code' => $errorCode];
            if ($detailDiagnostic !== null) {
                $changes['last_detail_diagnostic'] = $this->normalizeDetailDiagnostic(
                    $detailDiagnostic,
                    $job,
                    $this->expectedDetailIndex($job),
                );
            }
            return $this->changed($job, $changes);
        }, null, false, $detailDiagnostic !== null);
        return $this->present($updated);
    }

    /** @return array<string,mixed> */
    public function complete(string $taskId, int $revision): array
    {
        $current = $this->jobs->get($taskId);
        if ($current['state'] === JobStore::STATE_COMPLETED) {
            return $this->present($current);
        }
        $updated = $this->jobs->update($taskId, $revision, function (array $job): array {
            if ($job['state'] !== JobStore::STATE_IMPORTING) {
                throw new RuntimeException('只有正在导入的任务可以完成。');
            }
            if (JobStore::hasActiveRetry($job)) {
                throw new RuntimeException('补处理轮尚未完成，不能完成原任务。');
            }
            $progress = $job['progress'];
            if ($progress['processed'] !== $progress['total'] || $progress['failed'] !== 0) {
                throw new RuntimeException('后台任务尚未无错误处理完全部商品。');
            }
            return $this->changed($job, ['state' => JobStore::STATE_COMPLETED, 'error_code' => null]);
        });
        return $this->present($updated);
    }

    /** @param array<string,mixed> $job @param array<string,mixed> $changes @return array<string,mixed> */
    private function changed(array $job, array $changes): array
    {
        $next = array_replace($job, $changes);
        if ($next === $job) {
            return $job;
        }
        $next['revision'] = $job['revision'] + 1;
        $next['updated_at'] = gmdate('c');
        return $next;
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    private function present(array $job): array
    {
        $snapshot = $job['snapshot'];
        if (is_array($snapshot)) {
            $snapshot = [
                'sha256' => $snapshot['sha256'],
                'plan_hash' => $snapshot['plan_hash'],
                'item_count' => $snapshot['item_count'],
            ];
        }
        return [
            'task_id' => $job['task_id'],
            'source_id' => $job['source_id'],
            'source_alias' => $job['source_alias'],
            'category_mode' => $job['category_mode'] ?? 'smart',
            'state' => $job['state'],
            'phase' => $job['phase'],
            'revision' => $job['revision'],
            'created_at' => $job['created_at'],
            'updated_at' => $job['updated_at'],
            'snapshot' => $snapshot,
            'categories' => $job['categories'],
            'counts' => $job['counts'],
            'progress' => $job['progress'],
            'item_failures' => $job['item_failures'],
            'retry' => is_array($job['retry'])
                ? $job['retry'] + ['active' => JobStore::hasActiveRetry($job)]
                : null,
            'last_detail_diagnostic' => $job['last_detail_diagnostic'],
            'detail_compatibility_count' => $job['detail_compatibility_count'],
            'premium_percent' => $job['premium_percent'],
            'mappings' => $job['mappings'],
            'error_code' => $job['error_code'],
            'can_resume' => $job['state'] === JobStore::STATE_PAUSED
                ? !(is_array($job['retry']) && $job['retry']['halted'])
                : $this->canResumeFailedImport($job),
            'can_retry_failed' => JobStore::canRetryFailedImport($job),
            'can_cancel' => $this->canCancelFailedImport($job),
        ];
    }

    /** @param array<string,mixed> $job */
    private function canResumeFailedImport(array $job): bool
    {
        return JobStore::canResumeFailedImport($job);
    }

    /** @param array<string,mixed> $job */
    private function canCancelFailedImport(array $job): bool
    {
        return JobStore::canCancelFailedImport($job);
    }

    /** @param array<string,mixed> $job */
    private function hasUsableDetailResumeAuthorization(array $job): bool
    {
        return JobStore::hasUsableDetailResumeAuthorization($job);
    }

    /** @param array<string,mixed> $job */
    private function expectedDetailIndex(array $job): int
    {
        if (JobStore::hasPendingRetry($job)) {
            return $job['retry']['indices'][$job['retry']['cursor']];
        }
        return $job['progress']['processed'];
    }

    /**
     * @param array<string,mixed>|null $wrapper
     * @param array<string,mixed> $job
     * @return array{index:int,diagnostics:array<string,mixed>}|null
     */
    private function normalizeDetailDiagnostic(
        ?array $wrapper,
        array $job,
        ?int $expectedIndex = null,
    ): ?array {
        if ($wrapper === null) {
            return null;
        }
        $this->onlyKeys($wrapper, ['index', 'diagnostics'], '详情诊断摘要');
        $index = $wrapper['index'] ?? null;
        if (!is_int($index) || $index < 0 || $index >= $job['progress']['total']
            || ($expectedIndex !== null && $index !== $expectedIndex)
            || !is_array($wrapper['diagnostics'] ?? null)) {
            throw new RuntimeException('详情诊断没有绑定当前商品检查点。');
        }
        $diagnostics = $wrapper['diagnostics'];
        $base = ['category', 'http_status', 'curl_code', 'elapsed_ms', 'attempts'];
        $extended = [...$base, 'mime_category', 'mime_count', 'json_valid', 'mime_compatibility'];
        $jsonFailure = [...$extended, 'json_error_code', 'json_error'];
        $structure = [...$extended, 'response_structure'];
        $keys = array_keys($diagnostics);
        sort($keys, SORT_STRING);
        sort($base, SORT_STRING);
        sort($extended, SORT_STRING);
        sort($jsonFailure, SORT_STRING);
        sort($structure, SORT_STRING);
        if ($keys !== $base && $keys !== $extended && $keys !== $jsonFailure && $keys !== $structure) {
            throw new RuntimeException('详情诊断包含未知字段或缺少必需字段。');
        }
        $safe = UpstreamFailure::sanitize($diagnostics);
        if ($safe !== $diagnostics) {
            throw new RuntimeException('详情诊断包含未规范化或不安全的值。');
        }
        if ($keys === $extended || $keys === $jsonFailure || $keys === $structure) {
            $compatibility = $safe['mime_category'] !== 'unknown'
                && (!in_array($safe['mime_category'], ['application_json', 'text_json'], true)
                    || ($safe['mime_count'] !== null && $safe['mime_count'] !== 1));
            if ($safe['mime_compatibility'] !== $compatibility) {
                throw new RuntimeException('详情诊断兼容判断与 MIME 摘要不一致。');
            }
        }
        if ($keys === $jsonFailure && ($safe['category'] !== 'json' || $safe['http_status'] !== 200
            || $safe['curl_code'] !== 0 || $safe['json_valid'] !== false || $safe['attempts'] < 1)) {
            throw new RuntimeException('JSON 解析失败摘要不正确。');
        }
        return ['index' => $index, 'diagnostics' => $safe];
    }

    /** @param list<array{name:mixed,count:mixed,target:mixed,confidence:mixed}> $categories @return list<array{name:string,count:int,target:array{group:string,family:string},confidence:string}> */
    private function categories(array $categories): array
    {
        if ($categories === [] || count($categories) > 200 || !array_is_list($categories)) {
            throw new RuntimeException('后台任务分类摘要必须是 1-200 项列表。');
        }
        $normalized = [];
        $seen = [];
        foreach ($categories as $category) {
            if (!is_array($category)) {
                throw new RuntimeException('后台任务分类摘要格式不正确。');
            }
            $this->onlyKeys($category, ['name', 'count', 'target', 'confidence'], '后台任务分类摘要');
            $name = $this->text($category['name'] ?? null, 128, '后台任务分类名称');
            $target = $this->target($category['target'] ?? null);
            $key = UpstreamCategoryTree::categoryKey($name, $target);
            if (isset($seen[$key])) {
                throw new RuntimeException('后台任务分类名称不能重复。');
            }
            $seen[$key] = true;
            if (!is_int($category['count'] ?? null) || $category['count'] < 1 || $category['count'] > 10000) {
                throw new RuntimeException('后台任务分类商品数量不正确。');
            }
            if (!is_string($category['confidence'] ?? null)
                || !in_array($category['confidence'], ['high', 'low'], true)) {
                throw new RuntimeException('后台任务分类可信度不正确。');
            }
            $normalized[] = [
                'name' => $name,
                'count' => $category['count'],
                'target' => $target,
                'confidence' => $category['confidence'],
            ];
        }
        usort($normalized, static fn(array $left, array $right): int => strcmp(
            UpstreamCategoryTree::categoryKey($left['name'], $left['target']),
            UpstreamCategoryTree::categoryKey($right['name'], $right['target']),
        ));
        return $normalized;
    }

    /** @param list<array{source_category:mixed,target:mixed,confidence:mixed}> $mappings @return list<array{source_category:string,target:array{group:string,family:string},confidence:string}> */
    private function mappings(array $mappings): array
    {
        if ($mappings === [] || count($mappings) > 200 || !array_is_list($mappings)) {
            throw new RuntimeException('后台任务确认映射必须是 1-200 项列表。');
        }
        $normalized = [];
        $seen = [];
        foreach ($mappings as $mapping) {
            if (!is_array($mapping)) {
                throw new RuntimeException('后台任务确认映射格式不正确。');
            }
            $this->onlyKeys($mapping, ['source_category', 'target', 'confidence'], '后台任务确认映射');
            $category = $this->text($mapping['source_category'] ?? null, 128, '后台任务确认分类名称');
            $target = $this->target($mapping['target'] ?? null);
            $key = UpstreamCategoryTree::categoryKey($category, $target);
            if (isset($seen[$key])) {
                throw new RuntimeException('后台任务确认分类名称不能重复。');
            }
            $seen[$key] = true;
            if (!is_string($mapping['confidence'] ?? null)
                || !in_array($mapping['confidence'], ['high', 'low'], true)) {
                throw new RuntimeException('后台任务确认映射可信度不正确。');
            }
            $normalized[] = [
                'source_category' => $category,
                'target' => $target,
                'confidence' => $mapping['confidence'],
            ];
        }
        usort($normalized, static fn(array $left, array $right): int => strcmp(
            UpstreamCategoryTree::categoryKey($left['source_category'], $left['target']),
            UpstreamCategoryTree::categoryKey($right['source_category'], $right['target']),
        ));
        return $normalized;
    }

    /** @param list<array<string,mixed>> $rows */
    private function assertCategoryMode(array $rows, string $mode, string $nameKey): void
    {
        foreach ($rows as $row) {
            $target = $row['target'];
            if (($target['mode'] ?? 'smart') !== $mode
                || ($mode === 'mirror' && $target['path'][array_key_last($target['path'])]['name'] !== $row[$nameKey])) {
                throw new RuntimeException('后台任务分类模式或上游分类名称不一致。');
            }
        }
    }

    /** @return array<string,mixed> */
    private function target(mixed $target): array
    {
        if (!is_array($target)) {
            throw new RuntimeException('后台任务分类目标格式不正确。');
        }
        if (($target['mode'] ?? null) === 'mirror') {
            return UpstreamCategoryTree::normalizeTarget($target);
        }
        $this->onlyKeys($target, ['group', 'family'], '后台任务分类目标');
        return [
            'group' => $this->categorySegment($target['group'] ?? null, '后台任务一级分类'),
            'family' => $this->categorySegment($target['family'] ?? null, '后台任务二级分类', true),
        ];
    }

    private function categorySegment(mixed $value, string $label, bool $allowEmpty = false): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            throw new RuntimeException("{$label}格式不正确。");
        }
        if ($value === '' && $allowEmpty) {
            return '';
        }
        if ($value === ''
            || mb_strlen($value, 'UTF-8') > 64
            || preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $value) === 1
            || preg_match('#[\\\\/]#u', $value) === 1
            || preg_match('/\A[\s\p{Z}]|[\s\p{Z}]\z/u', $value) === 1) {
            throw new RuntimeException("{$label}格式不正确。");
        }
        return $value;
    }

    /** @param array<string,mixed> $progress @return array{total:int,processed:int,succeeded:int,failed:int,skipped:int} */
    private function progress(array $progress): array
    {
        $this->onlyKeys($progress, ['total', 'processed', 'succeeded', 'failed', 'skipped'], '后台任务进度');
        $normalized = [];
        foreach (['total', 'processed', 'succeeded', 'failed', 'skipped'] as $key) {
            if (!is_int($progress[$key] ?? null) || $progress[$key] < 0 || $progress[$key] > 10000) {
                throw new RuntimeException('后台任务进度格式不正确。');
            }
            $normalized[$key] = $progress[$key];
        }
        if ($normalized['processed'] > $normalized['total']
            || $normalized['succeeded'] + $normalized['failed'] + $normalized['skipped'] !== $normalized['processed']) {
            throw new RuntimeException('后台任务进度计数不一致。');
        }
        return $normalized;
    }

    /** @param array<string,int> $old @param array<string,int> $next */
    private function assertProgressAdvance(array $old, array $next, bool $fixedTotal): void
    {
        if ($next['processed'] < $old['processed']
            || $next['succeeded'] < $old['succeeded']
            || $next['failed'] < $old['failed']
            || $next['skipped'] < $old['skipped']) {
            throw new RuntimeException('后台任务进度不能倒退。');
        }
        if (($fixedTotal && $next['total'] !== $old['total'])
            || (!$fixedTotal && $next['total'] < $old['total'])) {
            throw new RuntimeException($fixedTotal ? '导入任务商品总数不能变化。' : '分析任务商品总数不能倒退。');
        }
    }

    private function alreadyControlled(string $state, string $action): bool
    {
        return match ($action) {
            'pause' => in_array($state, [JobStore::STATE_PAUSE_REQUESTED, JobStore::STATE_PAUSED], true),
            'resume' => in_array($state, [JobStore::STATE_QUEUED_ANALYSIS, JobStore::STATE_QUEUED_IMPORT], true),
            'retry_failed' => $state === JobStore::STATE_QUEUED_IMPORT,
            'cancel' => in_array($state, [JobStore::STATE_CANCEL_REQUESTED, JobStore::STATE_CANCELLED], true),
            default => false,
        };
    }

    private function premium(mixed $value): string
    {
        if (!is_scalar($value)) {
            throw new RuntimeException('后台任务加价百分比必须是数字。');
        }
        $value = trim((string)$value);
        if (preg_match('/^(?:0|[1-9]\d{0,3})(?:\.\d{1,4})?$/D', $value) !== 1) {
            throw new RuntimeException('后台任务加价百分比格式不正确。');
        }
        $number = (float)$value;
        if (!is_finite($number) || $number < 0 || $number > 1000) {
            throw new RuntimeException('后台任务加价百分比超出 0-1000 范围。');
        }
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }
        return $value === '' ? '0' : $value;
    }

    /** @param array<string,mixed> $value */
    private function onlyKeys(array $value, array $allowed, string $label): void
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        $expected = $allowed;
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new RuntimeException("{$label}包含未知字段或缺少必需字段。");
        }
    }

    private function text(mixed $value, int $maxLength, string $label, bool $rejectSlashes = false): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')
            || preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $value) === 1) {
            throw new RuntimeException("{$label}格式不正确。");
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > $maxLength
            || ($rejectSlashes && preg_match('#[\\\\/]#u', $value) === 1)
            || preg_match('#https?://#iu', $value) === 1) {
            throw new RuntimeException("{$label}格式不正确。");
        }
        return $value;
    }

    private function sha256(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new RuntimeException("{$label}格式不正确。");
        }
        return $value;
    }
}
