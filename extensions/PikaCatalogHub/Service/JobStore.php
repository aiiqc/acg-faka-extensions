<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaCatalogHub\Service;

use Pika\LocalExtensions\Manager\AtomicJson;
use Pika\LocalExtensions\Manager\PathGuard;
use Pika\LocalExtensions\PikaSupplySync\Service\CommodityImportFailure;
use Pika\LocalExtensions\PikaSupplySync\Service\UpstreamCategoryTree;
use Pika\LocalExtensions\PikaSupplySync\Service\UpstreamFailure;
use RuntimeException;

final class JobStore
{
    public const STATE_QUEUED_ANALYSIS = 'queued_analysis';
    public const STATE_ANALYZING = 'analyzing';
    public const STATE_AWAITING_CONFIRMATION = 'awaiting_confirmation';
    public const STATE_QUEUED_IMPORT = 'queued_import';
    public const STATE_IMPORTING = 'importing';
    public const STATE_PAUSE_REQUESTED = 'pause_requested';
    public const STATE_PAUSED = 'paused';
    public const STATE_CANCEL_REQUESTED = 'cancel_requested';
    public const STATE_CANCELLED = 'cancelled';
    public const STATE_COMPLETED = 'completed';
    public const STATE_FAILED = 'failed';

    private const SCHEMA = 4;
    public const MAX_ITEM_FAILURES = 100;
    public const MAX_CONSECUTIVE_ITEM_FAILURES = 5;
    private const MAX_JOBS = 64;
    private const MAX_STATE_BYTES = 1048576;
    private const DEFAULT_STATE = ['schema' => self::SCHEMA, 'jobs' => [], 'snapshot_gc' => null];
    private const STATES = [
        self::STATE_QUEUED_ANALYSIS,
        self::STATE_ANALYZING,
        self::STATE_AWAITING_CONFIRMATION,
        self::STATE_QUEUED_IMPORT,
        self::STATE_IMPORTING,
        self::STATE_PAUSE_REQUESTED,
        self::STATE_PAUSED,
        self::STATE_CANCEL_REQUESTED,
        self::STATE_CANCELLED,
        self::STATE_COMPLETED,
        self::STATE_FAILED,
    ];
    private const TERMINAL_STATES = [
        self::STATE_CANCELLED,
        self::STATE_COMPLETED,
        self::STATE_FAILED,
    ];

    /** @param array<string,mixed> $job */
    public static function hasPendingRetry(array $job): bool
    {
        $retry = $job['retry'] ?? null;
        return is_array($retry)
            && ($retry['halted'] ?? null) === false
            && is_int($retry['cursor'] ?? null)
            && is_array($retry['indices'] ?? null)
            && $retry['cursor'] >= 0
            && $retry['cursor'] < count($retry['indices']);
    }

    /** @param array<string,mixed> $job */
    public static function hasActiveRetry(array $job): bool
    {
        return self::hasPendingRetry($job)
            && !in_array($job['state'] ?? null, self::TERMINAL_STATES, true);
    }

    /** @param array<string,mixed> $job */
    public static function hasUsableDetailResumeAuthorization(array $job): bool
    {
        $authorization = $job['detail_resume_authorization'] ?? null;
        $snapshot = $job['snapshot'] ?? null;
        $legacyEvent = is_array($authorization) ? ($authorization['legacy_event'] ?? null) : null;
        $diagnostic = is_array($authorization) ? ($authorization['diagnostic'] ?? null) : null;
        return is_array($authorization)
            && is_array($snapshot)
            && ($job['state'] ?? null) === self::STATE_FAILED
            && ($job['phase'] ?? null) === 'import'
            && ($job['error_code'] ?? null) === CommodityImportFailure::DETAIL_RESPONSE_INVALID
            && ($authorization['consumed'] ?? null) === false
            && ($authorization['task_id'] ?? null) === ($job['task_id'] ?? null)
            && ($authorization['task_hash'] ?? null) === substr(hash('sha256', (string)($job['task_id'] ?? '')), 0, 16)
            && is_int($authorization['authorized_revision'] ?? null)
            && $authorization['authorized_revision'] < ($job['revision'] ?? 0)
            && ($authorization['index'] ?? null) === self::expectedDetailIndex($job)
            && ($authorization['snapshot_sha256'] ?? null) === ($snapshot['sha256'] ?? null)
            && ($authorization['source_fingerprint'] ?? null) === ($job['source_fingerprint'] ?? null)
            && is_array($legacyEvent)
            && ($legacyEvent['error_code'] ?? null) === CommodityImportFailure::DETAIL_RESPONSE_INVALID
            && is_array($legacyEvent['diagnostics'] ?? null)
            && ($legacyEvent['diagnostics']['category'] ?? null) === 'content_type'
            && is_array($diagnostic)
            && ($diagnostic['category'] ?? null) === 'none'
            && ($diagnostic['http_status'] ?? null) === 200
            && ($diagnostic['curl_code'] ?? null) === 0
            && ($diagnostic['attempts'] ?? null) === 1
            && in_array($diagnostic['mime_category'] ?? null, ['application_json', 'text_json'], true)
            && ($diagnostic['mime_count'] ?? null) === 1
            && ($diagnostic['json_valid'] ?? null) === true
            && ($diagnostic['mime_compatibility'] ?? null) === false;
    }

    /** Only a saved, pre-write JSON failure at the original scan cursor is eligible. */
    private static function hasLegacyJsonFailure(array $job): bool
    {
        $wrapper = $job['last_detail_diagnostic'] ?? null;
        $diagnostics = is_array($wrapper) ? ($wrapper['diagnostics'] ?? null) : null;
        return ($job['error_code'] ?? null) === CommodityImportFailure::DETAIL_RESPONSE_INVALID
            && ($job['retry'] ?? null) === null
            && is_array($wrapper)
            && ($wrapper['index'] ?? null) === ($job['progress']['processed'] ?? null)
            && is_array($diagnostics)
            && ($diagnostics['category'] ?? null) === 'json'
            && ($diagnostics['http_status'] ?? null) === 200
            && ($diagnostics['curl_code'] ?? null) === 0
            && ($diagnostics['json_valid'] ?? null) === false
            && is_int($diagnostics['attempts'] ?? null)
            && $diagnostics['attempts'] >= 1 && $diagnostics['attempts'] <= 3;
    }

    /** @param array<string,mixed> $job */
    public static function canResumeFailedImport(array $job): bool
    {
        $progress = $job['progress'] ?? null;
        return ($job['state'] ?? null) === self::STATE_FAILED
            && ($job['phase'] ?? null) === 'import'
            && is_array($job['snapshot'] ?? null)
            && ($job['premium_percent'] ?? null) !== null
            && is_array($job['item_failures'] ?? null)
            && is_array($progress)
            && (($progress['processed'] ?? 0) < ($progress['total'] ?? 0) || self::hasPendingRetry($job))
            && (CommodityImportFailure::isResumableDetailCode($job['error_code'] ?? null)
                || self::hasUsableDetailResumeAuthorization($job)
                || self::hasLegacyJsonFailure($job));
    }

    /** @param array<string,mixed> $job */
    public static function canCancelFailedImport(array $job): bool
    {
        $progress = $job['progress'] ?? null;
        return self::canRetryFailedImport($job)
            || (($job['state'] ?? null) === self::STATE_FAILED
                && ($job['phase'] ?? null) === 'import'
                && is_array($job['snapshot'] ?? null)
                && ($job['premium_percent'] ?? null) !== null
                && is_array($progress)
                    && (($progress['processed'] ?? 0) < ($progress['total'] ?? 0) || self::hasPendingRetry($job))
                    && (CommodityImportFailure::isResumableDetailCode($job['error_code'] ?? null)
                        || self::hasUsableDetailResumeAuthorization($job)
                        || self::hasLegacyJsonFailure($job)));
    }

    /** @param array<string,mixed> $job */
    public static function canRetryFailedImport(array $job): bool
    {
        $failures = $job['item_failures'] ?? null;
        $progress = $job['progress'] ?? null;
        $state = $job['state'] ?? null;
        $retry = $job['retry'] ?? null;
        $eligibleState = $state === self::STATE_FAILED
            || ($state === self::STATE_PAUSED
                && is_array($retry)
                && ($retry['halted'] ?? null) === true
                && ($job['error_code'] ?? null) === 'IMPORT_ITEM_FAILURE_LIMIT');
        return $eligibleState
            && ($job['phase'] ?? null) === 'import'
            && in_array($job['error_code'] ?? null, ['IMPORT_FINISHED_WITH_ISSUES', 'IMPORT_ITEM_FAILURE_LIMIT'], true)
            && is_array($job['snapshot'] ?? null)
            && ($job['premium_percent'] ?? null) !== null
            && is_array($failures) && $failures !== []
            && is_array($progress)
            && ($progress['failed'] ?? null) === count($failures)
            && ($retry === null
                || (is_array($retry) && (($retry['cursor'] ?? -1) === count($retry['indices'] ?? [])
                    || (($retry['halted'] ?? null) === true
                        && ($job['error_code'] ?? null) === 'IMPORT_ITEM_FAILURE_LIMIT'))))
            && !self::hasActiveRetry($job);
    }

    /**
     * @param array<string,mixed> $job
     * @param (callable(array<string,mixed>,bool):void)|null $retireTerminal
     * @return array<string,mixed>
     */
    public function create(array $job, ?callable $retireTerminal = null): array
    {
        $this->validateJob($job);
        $taskId = $job['task_id'];
        $sourceId = $job['source_id'];
        $next = AtomicJson::update($this->path(), self::DEFAULT_STATE, function (array $state) use (
            $job,
            $taskId,
            $sourceId,
            $retireTerminal,
        ): array {
            $state = $this->normalizeState($state);
            $this->validateState($state);
            if ($state['snapshot_gc'] !== null) {
                throw new RuntimeException('后台任务快照清理尚未完成。');
            }
            if (isset($state['jobs'][$taskId])) {
                throw new RuntimeException('后台任务编号已存在。');
            }
            foreach ($state['jobs'] as $existing) {
                if ($existing['source_id'] === $sourceId && !$this->isTerminal($existing['state'])) {
                    throw new RuntimeException('该货源已有未完成的后台任务。');
                }
            }
            $state['jobs'][$taskId] = $job;
            $retired = $this->retirementCandidate($state, $taskId);
            if ($retired !== null) {
                if ($retireTerminal === null) {
                    throw new RuntimeException('后台任务历史已达到安全上限，且没有可用的终态清理器。');
                }
                $retireTerminal($retired, false);
                unset($state['jobs'][$retired['task_id']]);
                $state['snapshot_gc'] = $this->snapshotGc($retired);
            }
            ksort($state['jobs'], SORT_STRING);
            $this->validateState($state);
            return $state;
        });
        return $next['jobs'][$taskId];
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>|null
     */
    private function retirementCandidate(array $state, string $newTaskId): ?array
    {
        if (!$this->overCapacity($state)) {
            return null;
        }
        $candidates = array_values(array_filter(
            $state['jobs'],
            fn(array $candidate): bool => $candidate['task_id'] !== $newTaskId
                && $this->isTerminal($candidate['state'])
                // A failed import with a resumable checkpoint is still user work.
                // Only an explicit cancellation or completion may retire it.
                && !self::canCancelFailedImport($candidate),
        ));
        usort($candidates, static function (array $left, array $right): int {
            $created = strcmp($left['created_at'], $right['created_at']);
            return $created !== 0 ? $created : strcmp($left['task_id'], $right['task_id']);
        });

        if ($candidates === []) {
            throw new RuntimeException('任务历史已满，请先完成或取消未完成及可继续的任务，再创建新分析。');
        }

        foreach ($candidates as $candidate) {
            $without = $state;
            unset($without['jobs'][$candidate['task_id']]);
            $without['snapshot_gc'] = $this->snapshotGc($candidate);
            if (!$this->overCapacity($without)) {
                return $candidate;
            }
        }
        throw new RuntimeException('任务历史已满，请先完成或取消未完成及可继续的任务，再创建新分析。');
    }

    /** @param array<string,mixed> $state */
    private function overCapacity(array $state): bool
    {
        return count($state['jobs']) > self::MAX_JOBS
            || $this->encodedSize($state) > self::MAX_STATE_BYTES;
    }

    /** @param array<string,mixed> $terminal @return array<string,mixed> */
    private function snapshotGc(array $terminal): array
    {
        $snapshot = $terminal['snapshot'];
        return [
            'task_id' => $terminal['task_id'],
            'source_fingerprint' => $terminal['source_fingerprint'],
            'plan_hash' => $snapshot['plan_hash'] ?? null,
            'sha256' => $snapshot['sha256'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    public function get(string $taskId): array
    {
        $taskId = $this->taskId($taskId);
        $state = $this->normalizeState(AtomicJson::read($this->path(), self::DEFAULT_STATE));
        $this->validateState($state);
        if (!isset($state['jobs'][$taskId])) {
            throw new RuntimeException('后台任务不存在。');
        }
        return $state['jobs'][$taskId];
    }

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        $state = $this->normalizeState(AtomicJson::read($this->path(), self::DEFAULT_STATE));
        $this->validateState($state);
        $jobs = array_values($state['jobs']);
        usort($jobs, static function (array $left, array $right): int {
            $created = strcmp($right['created_at'], $left['created_at']);
            return $created !== 0 ? $created : strcmp($right['task_id'], $left['task_id']);
        });
        return $jobs;
    }

    /**
     * @param callable(array<string,mixed>):array<string,mixed> $mutator
     * @return array<string,mixed>
     */
    public function update(
        string $taskId,
        int $expectedRevision,
        callable $mutator,
        ?string $idempotentAction = null,
        bool $requireSnapshotGcClear = false,
        bool $freshDetailDiagnostic = false,
    ): array {
        $taskId = $this->taskId($taskId);
        if ($expectedRevision < 1) {
            throw new RuntimeException('后台任务 revision 不正确。');
        }
        if ($idempotentAction !== null) {
            $this->action($idempotentAction);
        }

        $next = AtomicJson::update(
            $this->path(),
            self::DEFAULT_STATE,
            function (array $state) use (
                $taskId,
                $expectedRevision,
                $mutator,
                $idempotentAction,
                $requireSnapshotGcClear,
                $freshDetailDiagnostic,
            ): array {
                $state = $this->normalizeState($state);
                $this->validateState($state);
                if ($requireSnapshotGcClear && $state['snapshot_gc'] !== null) {
                    throw new RuntimeException('后台任务快照清理尚未完成，拒绝开始新工作。');
                }
                if (!isset($state['jobs'][$taskId])) {
                    throw new RuntimeException('后台任务不存在。');
                }
                $current = $state['jobs'][$taskId];
                if ($current['revision'] !== $expectedRevision) {
                    if ($idempotentAction !== null
                        && $current['last_action'] === $idempotentAction
                        && $this->actionAlreadyApplied($current['state'], $idempotentAction)) {
                        return $state;
                    }
                    throw new RuntimeException('后台任务已被其他操作更新，请刷新后重试。');
                }

                $updated = $mutator($current);
                if (!is_array($updated)) {
                    throw new RuntimeException('后台任务更新结果格式不正确。');
                }
                if ($updated === $current) {
                    return $state;
                }
                foreach (['task_id', 'source_id', 'source_alias', 'source_fingerprint', 'created_at'] as $immutable) {
                    if (($updated[$immutable] ?? null) !== $current[$immutable]) {
                        throw new RuntimeException('后台任务不可变身份字段不能修改。');
                    }
                }
                if (($updated['revision'] ?? null) !== $current['revision'] + 1) {
                    throw new RuntimeException('后台任务 revision 必须精确递增。');
                }
                $this->validateTransition($current, $updated, $freshDetailDiagnostic);
                $this->validateJob($updated);
                $state['jobs'][$taskId] = $updated;
                $state = $this->normalizeState($state);
                $this->validateState($state);
                return $state;
            },
        );
        return $next['jobs'][$taskId];
    }

    public function isTerminal(string $state): bool
    {
        return in_array($state, self::TERMINAL_STATES, true);
    }

    /**
     * Recovers work that can only remain in a running/control-requested state
     * when the previous globally-locked worker was interrupted. The caller
     * must hold worker.run.lock and prove the immutable snapshot binding before
     * this single state-file transaction is allowed to change any job.
     *
     * @param callable(array<string,mixed>,string):void $bindingGuard
     * @return list<array<string,mixed>>
     */
    public function recoverInterrupted(callable $bindingGuard): array
    {
        $interrupted = [
            self::STATE_ANALYZING,
            self::STATE_IMPORTING,
            self::STATE_PAUSE_REQUESTED,
            self::STATE_CANCEL_REQUESTED,
        ];
        if (!array_filter(
            $this->list(),
            static fn(array $job): bool => in_array($job['state'], $interrupted, true),
        )) {
            return [];
        }

        $recoveredIds = [];
        $next = AtomicJson::update(
            $this->path(),
            self::DEFAULT_STATE,
            function (array $state) use ($bindingGuard, $interrupted, &$recoveredIds): array {
                $state = $this->normalizeState($state);
                $this->validateState($state);
                $now = gmdate('c');
                foreach ($state['jobs'] as $taskId => $current) {
                    if (!in_array($current['state'], $interrupted, true)) {
                        continue;
                    }
                    $target = match ($current['state']) {
                        self::STATE_ANALYZING => self::STATE_QUEUED_ANALYSIS,
                        self::STATE_IMPORTING => self::STATE_QUEUED_IMPORT,
                        self::STATE_PAUSE_REQUESTED => self::STATE_PAUSED,
                        self::STATE_CANCEL_REQUESTED => self::STATE_CANCELLED,
                        default => throw new RuntimeException('后台任务恢复状态不正确。'),
                    };
                    $bindingGuard($current, $target);
                    $updated = array_replace($current, [
                        'state' => $target,
                        'revision' => $current['revision'] + 1,
                        'updated_at' => $now,
                    ]);
                    $this->validateTransition($current, $updated);
                    $this->validateJob($updated);
                    $state['jobs'][$taskId] = $updated;
                    $recoveredIds[] = $taskId;
                }
                $this->validateState($state);
                return $state;
            },
        );

        return array_values(array_map(
            static fn(string $taskId): array => $next['jobs'][$taskId],
            $recoveredIds,
        ));
    }

    /** @return array<string,mixed>|null */
    public function pendingSnapshotGc(): ?array
    {
        $state = $this->normalizeState(AtomicJson::read($this->path(), self::DEFAULT_STATE));
        $this->validateState($state);
        return $state['snapshot_gc'];
    }

    /** @param array<string,mixed> $expected */
    public function clearSnapshotGc(array $expected): void
    {
        $this->validateSnapshotGc($expected);
        AtomicJson::update($this->path(), self::DEFAULT_STATE, function (array $state) use ($expected): array {
            $state = $this->normalizeState($state);
            $this->validateState($state);
            if ($state['snapshot_gc'] === null) {
                return $state;
            }
            if ($state['snapshot_gc'] !== $expected) {
                throw new RuntimeException('后台任务快照清理回执已变化。');
            }
            $state['snapshot_gc'] = null;
            $this->validateState($state);
            return $state;
        });
    }

    /** @param array<string,mixed> $current @param array<string,mixed> $updated */
    private function validateTransition(
        array $current,
        array $updated,
        bool $freshDetailDiagnostic = false,
    ): void
    {
        if (($current['category_mode'] ?? 'smart') !== ($updated['category_mode'] ?? 'smart')) {
            throw new RuntimeException('后台任务分类模式不能在创建后修改。');
        }
        $retryStart = $this->isRetryStart($current, $updated);
        $retryOutcome = null;
        if (($updated['retry'] ?? null) !== ($current['retry'] ?? null) && !$retryStart) {
            $retryOutcome = $this->retryAdvanceOutcome($current, $updated);
            if ($retryOutcome === null) {
                throw new RuntimeException('补处理轮不能被替换或跳跃推进。');
            }
        }
        $authorizationGrant = $this->isDetailResumeAuthorizationGrant($current, $updated);
        $failedResume = $this->isFailedResume($current, $updated);
        if (($updated['detail_resume_authorization'] ?? null) !== ($current['detail_resume_authorization'] ?? null)
            && !$authorizationGrant && !$failedResume) {
            throw new RuntimeException('详情恢复授权只能由精确授权或一次继续消费。');
        }

        $oldFailures = $current['item_failures'];
        $newFailures = $updated['item_failures'] ?? null;
        if ($retryOutcome !== null) {
            // retryAdvanceOutcome validates the one-index ledger rewrite.
        } elseif ($oldFailures === null) {
            if ($newFailures !== null || $updated['progress']['failed'] !== $current['progress']['failed']) {
                throw new RuntimeException('旧任务缺少逐件失败明细，不能重建或增加失败检查点。');
            }
        } elseif (!is_array($newFailures)
            || array_slice($newFailures, 0, count($oldFailures)) !== $oldFailures) {
            throw new RuntimeException('逐件失败清单不能删除或改写已有记录。');
        } else {
            foreach (array_slice($newFailures, count($oldFailures)) as $failure) {
                if (!is_array($failure) || ($failure['index'] ?? -1) < $current['progress']['processed']) {
                    throw new RuntimeException('逐件失败索引不能回写已经确认的商品。');
                }
            }
        }
        if ($current['phase'] === 'import') {
            foreach (['snapshot', 'premium_percent', 'mappings'] as $binding) {
                if (($updated[$binding] ?? null) !== $current[$binding]) {
                    throw new RuntimeException('导入检查点的快照与确认绑定不能修改。');
                }
            }
            if ($retryOutcome === null) {
                foreach (['processed', 'succeeded', 'failed', 'skipped'] as $counter) {
                    if (($updated['progress'][$counter] ?? -1) < $current['progress'][$counter]) {
                        throw new RuntimeException('导入检查点计数不能倒退。');
                    }
                }
            }
            if (($updated['progress']['total'] ?? null) !== $current['progress']['total']) {
                throw new RuntimeException('导入检查点商品总数不能变化。');
            }
        }
        $this->validateDetailDiagnosticTransition(
            $current,
            $updated,
            $retryOutcome,
            $authorizationGrant,
            $freshDetailDiagnostic,
        );
        $allowed = match ($current['state']) {
            self::STATE_QUEUED_ANALYSIS => [
                self::STATE_QUEUED_ANALYSIS, self::STATE_ANALYZING, self::STATE_PAUSED,
                self::STATE_CANCELLED, self::STATE_FAILED,
            ],
            self::STATE_ANALYZING => [
                self::STATE_ANALYZING, self::STATE_QUEUED_ANALYSIS, self::STATE_AWAITING_CONFIRMATION, self::STATE_PAUSE_REQUESTED,
                self::STATE_CANCEL_REQUESTED, self::STATE_FAILED,
            ],
            self::STATE_AWAITING_CONFIRMATION => [
                self::STATE_AWAITING_CONFIRMATION, self::STATE_QUEUED_IMPORT,
                self::STATE_CANCELLED, self::STATE_FAILED,
            ],
            self::STATE_QUEUED_IMPORT => [
                self::STATE_QUEUED_IMPORT, self::STATE_IMPORTING, self::STATE_PAUSED,
                self::STATE_CANCELLED, self::STATE_FAILED,
            ],
            self::STATE_IMPORTING => [
                self::STATE_IMPORTING, self::STATE_QUEUED_IMPORT, self::STATE_PAUSE_REQUESTED, self::STATE_CANCEL_REQUESTED,
                self::STATE_PAUSED, self::STATE_COMPLETED, self::STATE_FAILED,
            ],
            self::STATE_PAUSE_REQUESTED => [
                self::STATE_PAUSE_REQUESTED, self::STATE_PAUSED, self::STATE_CANCEL_REQUESTED,
                self::STATE_FAILED,
            ],
            self::STATE_PAUSED => [
                self::STATE_PAUSED,
                $current['phase'] === 'analysis' ? self::STATE_QUEUED_ANALYSIS : self::STATE_QUEUED_IMPORT,
                self::STATE_CANCELLED,
                self::STATE_FAILED,
            ],
            self::STATE_CANCEL_REQUESTED => [
                self::STATE_CANCEL_REQUESTED, self::STATE_CANCELLED, self::STATE_FAILED,
            ],
            self::STATE_CANCELLED, self::STATE_COMPLETED => [$current['state']],
            self::STATE_FAILED => [self::STATE_FAILED, self::STATE_QUEUED_IMPORT, self::STATE_CANCELLED],
            default => [],
        };
        if (!in_array($updated['state'] ?? null, $allowed, true)) {
            throw new RuntimeException('后台任务状态转换不合法。');
        }
        if ($current['state'] === self::STATE_FAILED && $updated['state'] === self::STATE_QUEUED_IMPORT
            && !$retryStart && !$failedResume) {
            throw new RuntimeException('失败导入任务没有精确恢复资格。');
        }
        if ($current['state'] === self::STATE_FAILED && $updated['state'] === self::STATE_CANCELLED
            && !$this->isFailedCancellation($current, $updated)) {
            throw new RuntimeException('失败导入任务的取消状态不合法。');
        }
        if ($current['state'] === self::STATE_IMPORTING && $updated['state'] === self::STATE_PAUSED
            && !$this->isCompletedRetryPause($current, $updated)) {
            throw new RuntimeException('只有完成补处理轮后才能直接暂停原扫描。');
        }
        if ($current['phase'] !== $updated['phase']
            && !($current['state'] === self::STATE_AWAITING_CONFIRMATION
                && $updated['state'] === self::STATE_QUEUED_IMPORT
                && $current['phase'] === 'analysis'
                && $updated['phase'] === 'import')) {
            throw new RuntimeException('后台任务阶段转换不合法。');
        }
    }

    /** @param array<string,mixed> $current @param array<string,mixed> $updated */
    private function isRetryStart(array $current, array $updated): bool
    {
        if (!self::canRetryFailedImport($current)
            || ($updated['state'] ?? null) !== self::STATE_QUEUED_IMPORT
            || ($updated['error_code'] ?? null) !== null
            || ($updated['last_action'] ?? null) !== 'retry_failed'
            || !is_array($updated['retry'] ?? null)) {
            return false;
        }
        $retry = $current['retry'];
        if (is_array($retry) && $retry['cursor'] < count($retry['indices'])) {
            // canRetryFailedImport admits only the old, halted threshold state here.
            $expected = $retry;
            $expected['halted'] = false;
        } else {
            $indices = array_column($current['item_failures'], 'index');
            sort($indices, SORT_NUMERIC);
            $expected = [
                'origin_error_code' => $current['error_code'],
                'indices' => array_values($indices),
                'cursor' => 0,
                'succeeded' => 0,
                'skipped' => 0,
                'failed' => 0,
                'consecutive_failed' => 0,
                'halted' => false,
            ];
        }
        return $updated['retry'] === $expected
            && $this->sameFields($current, $updated, [
                'snapshot', 'categories', 'counts', 'progress', 'premium_percent', 'mappings', 'item_failures',
                'last_detail_diagnostic', 'detail_compatibility_count', 'detail_resume_authorization',
            ]);
    }

    /**
     * @param array<string,mixed> $current
     * @param array<string,mixed> $updated
     * @return 'succeeded'|'skipped'|'failed'|null
     */
    private function retryAdvanceOutcome(array $current, array $updated): ?string
    {
        if (!self::hasActiveRetry($current)
            || !in_array($current['state'], [
                self::STATE_IMPORTING, self::STATE_PAUSE_REQUESTED, self::STATE_CANCEL_REQUESTED,
            ], true)
            || !is_array($current['retry']) || !is_array($updated['retry'] ?? null)
            || !is_array($current['item_failures']) || !is_array($updated['item_failures'] ?? null)) {
            return null;
        }
        $oldRetry = $current['retry'];
        $newRetry = $updated['retry'];
        foreach (['origin_error_code', 'indices'] as $preserved) {
            if (($newRetry[$preserved] ?? null) !== $oldRetry[$preserved]) {
                return null;
            }
        }
        $cursor = $oldRetry['cursor'];
        $index = $oldRetry['indices'][$cursor] ?? null;
        if (!is_int($index) || ($newRetry['cursor'] ?? null) !== $cursor + 1) {
            return null;
        }
        $position = null;
        foreach ($current['item_failures'] as $failurePosition => $failure) {
            if (($failure['index'] ?? null) === $index) {
                $position = $failurePosition;
                break;
            }
        }
        if (!is_int($position)) {
            return null;
        }

        $outcome = null;
        $expectedProgress = $current['progress'];
        $expectedFailures = $current['item_failures'];
        $expectedRetry = $oldRetry;
        if (count($updated['item_failures']) === count($current['item_failures']) - 1) {
            array_splice($expectedFailures, $position, 1);
            $expectedProgress['failed']--;
            if (($updated['progress']['succeeded'] ?? null) === $current['progress']['succeeded'] + 1
                && ($updated['progress']['skipped'] ?? null) === $current['progress']['skipped']) {
                $outcome = 'succeeded';
            } elseif (($updated['progress']['skipped'] ?? null) === $current['progress']['skipped'] + 1
                && ($updated['progress']['succeeded'] ?? null) === $current['progress']['succeeded']) {
                $outcome = 'skipped';
            } else {
                return null;
            }
            $expectedProgress[$outcome]++;
            $expectedRetry[$outcome]++;
            $expectedRetry['consecutive_failed'] = 0;
        } elseif (count($updated['item_failures']) === count($current['item_failures'])) {
            $replacement = $updated['item_failures'][$position] ?? null;
            if (!is_array($replacement) || ($replacement['index'] ?? null) !== $index) {
                return null;
            }
            $expectedFailures[$position] = $replacement;
            $outcome = 'failed';
            $expectedRetry['failed']++;
            $expectedRetry['consecutive_failed']++;
        } else {
            return null;
        }
        $expectedRetry['cursor']++;
        $expectedRetry['halted'] = false;
        $expectedState = match ($current['state']) {
            self::STATE_PAUSE_REQUESTED => self::STATE_PAUSED,
            self::STATE_CANCEL_REQUESTED => self::STATE_CANCELLED,
            default => $current['state'],
        };
        $expectedError = $current['error_code'];
        if ($updated['retry'] !== $expectedRetry
            || $updated['progress'] !== $expectedProgress
            || $updated['item_failures'] !== array_values($expectedFailures)
            || ($updated['state'] ?? null) !== $expectedState
            || ($updated['error_code'] ?? null) !== $expectedError
            || !$this->sameFields($current, $updated, [
                'snapshot', 'categories', 'counts', 'premium_percent', 'mappings', 'last_action',
                'detail_resume_authorization',
            ])) {
            return null;
        }
        return $outcome;
    }

    /** @param array<string,mixed> $current @param array<string,mixed> $updated */
    private function isDetailResumeAuthorizationGrant(array $current, array $updated): bool
    {
        $authorization = $updated['detail_resume_authorization'] ?? null;
        return ($current['state'] ?? null) === self::STATE_FAILED
            && ($current['phase'] ?? null) === 'import'
            && ($current['error_code'] ?? null) === CommodityImportFailure::DETAIL_RESPONSE_INVALID
            && is_array($current['snapshot'] ?? null)
            && is_array($authorization)
            && ($authorization['authorized_revision'] ?? null) === $current['revision']
            && ($authorization['index'] ?? null) === self::expectedDetailIndex($current)
            && ($authorization['consumed'] ?? null) === false
            && ($updated['state'] ?? null) === self::STATE_FAILED
            && ($updated['error_code'] ?? null) === $current['error_code']
            && ($updated['last_detail_diagnostic'] ?? null) === [
                'index' => $authorization['index'] ?? null,
                'diagnostics' => $authorization['diagnostic'] ?? null,
            ]
            && $this->sameFields($current, $updated, [
                'snapshot', 'categories', 'counts', 'progress', 'premium_percent', 'mappings', 'item_failures',
                'retry', 'detail_compatibility_count', 'last_action',
            ]);
    }

    /** @param array<string,mixed> $current @param array<string,mixed> $updated */
    private function isFailedResume(array $current, array $updated): bool
    {
        if (!self::canResumeFailedImport($current)
            || ($updated['state'] ?? null) !== self::STATE_QUEUED_IMPORT
            || ($updated['error_code'] ?? null) !== null
            || ($updated['last_action'] ?? null) !== 'resume') {
            return false;
        }
        $expectedAuthorization = $current['detail_resume_authorization'];
        if (self::hasUsableDetailResumeAuthorization($current)) {
            $expectedAuthorization['consumed'] = true;
        }
        return ($updated['detail_resume_authorization'] ?? null) === $expectedAuthorization
            && $this->sameFields($current, $updated, [
                'snapshot', 'categories', 'counts', 'progress', 'premium_percent', 'mappings', 'item_failures',
                'retry', 'last_detail_diagnostic', 'detail_compatibility_count',
            ]);
    }

    /** @param array<string,mixed> $current @param array<string,mixed> $updated */
    private function isFailedCancellation(array $current, array $updated): bool
    {
        return self::canCancelFailedImport($current)
            && ($updated['state'] ?? null) === self::STATE_CANCELLED
            && ($updated['error_code'] ?? null) === ($current['error_code'] ?? null)
            && ($updated['last_action'] ?? null) === 'cancel'
            && $this->sameFields($current, $updated, [
                'snapshot', 'categories', 'counts', 'progress', 'premium_percent', 'mappings', 'item_failures',
                'retry', 'last_detail_diagnostic', 'detail_compatibility_count', 'detail_resume_authorization',
            ]);
    }

    /** @param array<string,mixed> $current @param array<string,mixed> $updated */
    private function isCompletedRetryPause(array $current, array $updated): bool
    {
        $retry = $current['retry'] ?? null;
        return is_array($retry)
            && ($retry['halted'] ?? true) === false
            && ($retry['cursor'] ?? null) === count($retry['indices'] ?? [])
            && $current['progress']['processed'] < $current['progress']['total']
            && ($updated['error_code'] ?? null) === null
            && $this->sameFields($current, $updated, [
                'snapshot', 'categories', 'counts', 'progress', 'premium_percent', 'mappings', 'item_failures',
                'retry', 'last_detail_diagnostic', 'detail_compatibility_count', 'detail_resume_authorization',
                'last_action',
            ]);
    }

    /**
     * @param array<string,mixed> $current
     * @param array<string,mixed> $updated
     * @param 'succeeded'|'skipped'|'failed'|null $retryOutcome
     */
    private function validateDetailDiagnosticTransition(
        array $current,
        array $updated,
        ?string $retryOutcome,
        bool $authorizationGrant,
        bool $freshDetailDiagnostic,
    ): void {
        $oldCount = $current['detail_compatibility_count'];
        $newCount = $updated['detail_compatibility_count'] ?? null;
        if (($updated['last_detail_diagnostic'] ?? null) === $current['last_detail_diagnostic']) {
            $sameDiagnosticRetrySuccess = $freshDetailDiagnostic
                && in_array($retryOutcome, ['succeeded', 'skipped'], true)
                && is_array($updated['last_detail_diagnostic'] ?? null)
                && ($updated['last_detail_diagnostic']['index'] ?? null)
                    === $current['retry']['indices'][$current['retry']['cursor']]
                && ($updated['last_detail_diagnostic']['diagnostics']['mime_compatibility'] ?? false) === true;
            if ($newCount !== $oldCount + ($sameDiagnosticRetrySuccess ? 1 : 0)) {
                throw new RuntimeException('详情兼容提示不能脱离新诊断递增。');
            }
            return;
        }
        $diagnostic = $updated['last_detail_diagnostic'] ?? null;
        if (!is_array($diagnostic) || !$freshDetailDiagnostic) {
            throw new RuntimeException('最近详情诊断不能被清除。');
        }
        $expectedIndex = null;
        if ($authorizationGrant) {
            $expectedIndex = self::expectedDetailIndex($current);
        } elseif ($retryOutcome !== null) {
            $expectedIndex = $current['retry']['indices'][$current['retry']['cursor']];
        } elseif ($current['phase'] === 'import' && in_array($current['state'], [
            self::STATE_IMPORTING, self::STATE_PAUSE_REQUESTED, self::STATE_CANCEL_REQUESTED,
        ], true)) {
            $advanced = ($updated['progress']['processed'] ?? -1) === $current['progress']['processed'] + 1;
            $sameProgress = ($updated['progress'] ?? null) === $current['progress'];
            if ($advanced) {
                $expectedIndex = $current['progress']['processed'];
            } elseif ($sameProgress && ($updated['state'] ?? null) === self::STATE_FAILED) {
                $expectedIndex = self::expectedDetailIndex($current);
            }
        }
        if (($diagnostic['index'] ?? null) !== $expectedIndex) {
            throw new RuntimeException('详情诊断没有绑定当前商品检查点。');
        }
        $successfulCheckpoint = in_array($retryOutcome, ['succeeded', 'skipped'], true)
            || ($retryOutcome === null
                && ($updated['progress']['processed'] ?? -1) === $current['progress']['processed'] + 1
                && (($updated['progress']['succeeded'] ?? -1) === $current['progress']['succeeded'] + 1
                    || ($updated['progress']['skipped'] ?? -1) === $current['progress']['skipped'] + 1));
        $shouldCount = ($diagnostic['diagnostics']['mime_compatibility'] ?? false) === true
            && $successfulCheckpoint;
        if ($newCount !== $oldCount + ($shouldCount ? 1 : 0)) {
            throw new RuntimeException('详情兼容提示计数没有随成功检查点精确递增。');
        }
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right @param list<string> $keys */
    private function sameFields(array $left, array $right, array $keys): bool
    {
        foreach ($keys as $key) {
            if (($left[$key] ?? null) !== ($right[$key] ?? null)) {
                return false;
            }
        }
        return true;
    }

    private function actionAlreadyApplied(string $state, string $action): bool
    {
        return match ($action) {
            'pause' => in_array($state, [self::STATE_PAUSE_REQUESTED, self::STATE_PAUSED], true),
            'resume' => in_array($state, [self::STATE_QUEUED_ANALYSIS, self::STATE_QUEUED_IMPORT], true),
            'retry_failed' => $state === self::STATE_QUEUED_IMPORT,
            'cancel' => in_array($state, [self::STATE_CANCEL_REQUESTED, self::STATE_CANCELLED], true),
            default => false,
        };
    }

    /** @param array<string,mixed> $state */
    private function validateState(array $state): void
    {
        $this->onlyKeys($state, ['schema', 'jobs', 'snapshot_gc'], '后台任务状态');
        if (($state['schema'] ?? null) !== self::SCHEMA
            || !is_array($state['jobs'] ?? null)
            || ($state['jobs'] !== [] && array_is_list($state['jobs']))
            || count($state['jobs']) > self::MAX_JOBS) {
            throw new RuntimeException('后台任务状态格式不正确。');
        }
        $activeSources = [];
        foreach ($state['jobs'] as $taskId => $job) {
            if (!is_string($taskId) || !is_array($job) || ($job['task_id'] ?? null) !== $taskId) {
                throw new RuntimeException('后台任务索引格式不正确。');
            }
            $this->validateJob($job);
            if (!$this->isTerminal($job['state'])) {
                if (isset($activeSources[$job['source_id']])) {
                    throw new RuntimeException('同一货源存在多个未完成后台任务。');
                }
                $activeSources[$job['source_id']] = true;
            }
        }
        if ($state['snapshot_gc'] !== null) {
            if (!is_array($state['snapshot_gc'])) {
                throw new RuntimeException('后台任务快照清理回执格式不正确。');
            }
            $this->validateSnapshotGc($state['snapshot_gc']);
            if (isset($state['jobs'][$state['snapshot_gc']['task_id']])) {
                throw new RuntimeException('后台任务快照清理回执仍引用活动历史。');
            }
        }
        if ($this->encodedSize($state) > self::MAX_STATE_BYTES) {
            throw new RuntimeException('后台任务状态超过 1MB 安全上限。');
        }
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function normalizeState(array $state): array
    {
        $schema = $state['schema'] ?? null;
        if (!in_array($schema, [1, 2, 3], true)) {
            return $state;
        }
        $this->onlyKeys($state, $schema === 1 ? ['schema', 'jobs'] : ['schema', 'jobs', 'snapshot_gc'], '后台任务状态');
        if (!is_array($state['jobs'] ?? null)
            || ($schema !== 1 && !array_key_exists('snapshot_gc', $state))) {
            throw new RuntimeException('后台任务旧版状态格式不正确。');
        }
        $jobs = [];
        foreach ($state['jobs'] as $taskId => $job) {
            if (!is_array($job)) {
                throw new RuntimeException('后台任务旧版记录格式不正确。');
            }
            $legacyKeys = [
                'task_id', 'source_id', 'source_alias', 'source_fingerprint', 'state', 'phase', 'revision',
                'created_at', 'updated_at', 'snapshot', 'categories', 'counts', 'progress', 'premium_percent',
                'mappings', 'error_code', 'last_action',
            ];
            if ($schema === 3) {
                $legacyKeys[] = 'item_failures';
            }
            $this->onlyKeys($job, $legacyKeys, '后台任务旧版记录');
            if ($schema < 3) {
                // Legacy aggregate failures have no provable item indexes.
                $job['item_failures'] = $job['progress']['failed'] === 0 ? [] : null;
            }
            $job['retry'] = null;
            $job['last_detail_diagnostic'] = null;
            $job['detail_compatibility_count'] = 0;
            $job['detail_resume_authorization'] = null;
            $this->validateJob($job);
            $jobs[$taskId] = $job;
        }
        return [
            'schema' => self::SCHEMA,
            'jobs' => $jobs,
            'snapshot_gc' => $schema === 1 ? null : ($state['snapshot_gc'] ?? null),
        ];
    }

    /** @param array<string,mixed> $gc */
    private function validateSnapshotGc(array $gc): void
    {
        $this->onlyKeys(
            $gc,
            ['task_id', 'source_fingerprint', 'plan_hash', 'sha256'],
            '后台任务快照清理回执',
        );
        $this->taskId($gc['task_id'] ?? null);
        $this->sha256($gc['source_fingerprint'] ?? null, '后台任务快照清理货源指纹');
        if (($gc['plan_hash'] === null) !== ($gc['sha256'] === null)) {
            throw new RuntimeException('后台任务快照清理回执绑定不完整。');
        }
        if ($gc['plan_hash'] !== null) {
            $this->sha256($gc['plan_hash'], '后台任务快照清理方案哈希');
            $this->sha256($gc['sha256'], '后台任务快照清理 SHA256');
        }
    }

    /** @param array<string,mixed> $state */
    private function encodedSize(array $state): int
    {
        try {
            return strlen(json_encode(
                $state,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . "\n");
        } catch (\JsonException $exception) {
            throw new RuntimeException('后台任务状态无法编码。', 0, $exception);
        }
    }

    /** @param array<string,mixed> $job */
    private function validateJob(array $job): void
    {
        $keys = [
            'task_id', 'source_id', 'source_alias', 'source_fingerprint', 'state', 'phase', 'revision',
            'created_at', 'updated_at', 'snapshot', 'categories', 'counts', 'progress', 'premium_percent',
            'mappings', 'error_code', 'last_action', 'item_failures', 'retry', 'last_detail_diagnostic',
            'detail_compatibility_count', 'detail_resume_authorization',
        ];
        if (array_key_exists('category_mode', $job)) {
            $keys[] = 'category_mode';
        }
        $this->onlyKeys($job, $keys, '后台任务');
        $mode = $job['category_mode'] ?? 'smart';
        if (!in_array($mode, ['smart', 'mirror'], true)
            || (array_key_exists('category_mode', $job) && $job['category_mode'] === null)) {
            throw new RuntimeException('后台任务分类模式不正确。');
        }
        $this->taskId($job['task_id'] ?? null);
        if (!is_int($job['source_id'] ?? null) || $job['source_id'] < 1 || $job['source_id'] > 0x7fffffff) {
            throw new RuntimeException('后台任务货源 ID 不正确。');
        }
        $this->text($job['source_alias'] ?? null, 64, '后台任务货源别名', true);
        $this->sha256($job['source_fingerprint'] ?? null, '后台任务货源指纹');
        if (!is_string($job['state'] ?? null) || !in_array($job['state'], self::STATES, true)) {
            throw new RuntimeException('后台任务状态不正确。');
        }
        if (!is_string($job['phase'] ?? null) || !in_array($job['phase'], ['analysis', 'import'], true)) {
            throw new RuntimeException('后台任务阶段不正确。');
        }
        if (!is_int($job['revision'] ?? null) || $job['revision'] < 1 || $job['revision'] > 0x7fffffff) {
            throw new RuntimeException('后台任务 revision 不正确。');
        }
        $this->timestamp($job['created_at'] ?? null);
        $this->timestamp($job['updated_at'] ?? null);
        $this->snapshot($job['snapshot'] ?? null);
        $this->categories($job['categories'] ?? null, $mode);
        $this->counts($job['counts'] ?? null);
        $this->progress($job['progress'] ?? null);
        $this->itemFailures($job);
        $this->retry($job['retry'] ?? null, $job);
        $this->detailDiagnostic($job['last_detail_diagnostic'] ?? null, $job);
        if (!is_int($job['detail_compatibility_count'] ?? null)
            || $job['detail_compatibility_count'] < 0
            || $job['detail_compatibility_count'] > 0x7fffffff) {
            throw new RuntimeException('详情兼容提示计数不正确。');
        }
        $this->detailResumeAuthorization($job['detail_resume_authorization'] ?? null, $job);
        if ($job['premium_percent'] !== null) {
            $this->premium($job['premium_percent']);
        }
        $this->mappings($job['mappings'] ?? null, $mode);
        if ($mode === 'mirror' && $job['phase'] === 'import') {
            $frozen = [];
            foreach ($job['categories'] as $category) {
                $key = UpstreamCategoryTree::categoryKey($category['name'], $category['target']);
                $frozen[$key] = [
                    'source_category' => $category['name'],
                    'target' => $category['target'],
                    'confidence' => $category['confidence'],
                ];
            }
            if (count($frozen) !== count($job['mappings'])) {
                throw new RuntimeException('保留上游分类结构确认映射不完整。');
            }
            foreach ($job['mappings'] as $mapping) {
                $key = UpstreamCategoryTree::categoryKey($mapping['source_category'], $mapping['target']);
                if (($frozen[$key] ?? null) !== $mapping) {
                    throw new RuntimeException('保留上游分类结构确认映射与冻结建议不一致。');
                }
            }
        }
        if ($job['error_code'] !== null
            && (!is_string($job['error_code']) || preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $job['error_code']) !== 1)) {
            throw new RuntimeException('后台任务错误代码格式不正确。');
        }
        if ($job['last_action'] !== null) {
            $this->action($job['last_action']);
        }
        if ($job['phase'] === 'analysis' && in_array($job['state'], [self::STATE_QUEUED_IMPORT, self::STATE_IMPORTING], true)) {
            throw new RuntimeException('后台任务状态与阶段不一致。');
        }
        if ($job['phase'] === 'import' && in_array($job['state'], [self::STATE_QUEUED_ANALYSIS, self::STATE_ANALYZING, self::STATE_AWAITING_CONFIRMATION], true)) {
            throw new RuntimeException('后台任务状态与阶段不一致。');
        }
    }

    /** @param array<string,mixed> $job */
    private function retry(mixed $retry, array $job): void
    {
        if ($retry === null) {
            return;
        }
        if (!is_array($retry)) {
            throw new RuntimeException('补处理轮状态格式不正确。');
        }
        $this->onlyKeys($retry, [
            'origin_error_code', 'indices', 'cursor', 'succeeded', 'skipped', 'failed',
            'consecutive_failed', 'halted',
        ], '补处理轮状态');
        if (!in_array($retry['origin_error_code'] ?? null, ['IMPORT_FINISHED_WITH_ISSUES', 'IMPORT_ITEM_FAILURE_LIMIT'], true)
            || !is_array($retry['indices'] ?? null) || !array_is_list($retry['indices'])
            || $retry['indices'] === [] || count($retry['indices']) > self::MAX_ITEM_FAILURES
            || !is_int($retry['cursor'] ?? null) || $retry['cursor'] < 0
            || $retry['cursor'] > count($retry['indices'])
            || !is_bool($retry['halted'] ?? null)) {
            throw new RuntimeException('补处理轮状态格式不正确。');
        }
        $previous = -1;
        foreach ($retry['indices'] as $index) {
            if (!is_int($index) || $index <= $previous || $index < 0
                || $index >= ($job['progress']['processed'] ?? 0)) {
                throw new RuntimeException('补处理轮商品索引不正确。');
            }
            $previous = $index;
        }
        foreach (['succeeded', 'skipped', 'failed', 'consecutive_failed'] as $counter) {
            if (!is_int($retry[$counter] ?? null) || $retry[$counter] < 0
                || $retry[$counter] > count($retry['indices'])) {
                throw new RuntimeException('补处理轮计数不正确。');
            }
        }
        if ($retry['succeeded'] + $retry['skipped'] + $retry['failed'] !== $retry['cursor']
            || $retry['consecutive_failed'] > $retry['failed']) {
            throw new RuntimeException('补处理轮计数不一致。');
        }
        $limitReached = $retry['failed'] >= self::MAX_ITEM_FAILURES
            || $retry['consecutive_failed'] >= self::MAX_CONSECUTIVE_ITEM_FAILURES;
        // Read old stopped rounds without rewriting them. New bounded rounds do
        // not halt on isolated item failures, even when their counters exceed five.
        if ($retry['halted'] && !$limitReached) {
            throw new RuntimeException('补处理轮停止标记与失败计数不一致。');
        }
        if ($retry['halted'] && !in_array($job['state'] ?? null, [
            self::STATE_FAILED, self::STATE_PAUSED, self::STATE_CANCELLED,
        ], true)) {
            throw new RuntimeException('补处理轮失败上限没有停止任务。');
        }
        $unresolved = [];
        foreach (($job['item_failures'] ?? []) as $failure) {
            if (is_array($failure) && is_int($failure['index'] ?? null)) {
                $unresolved[$failure['index']] = true;
            }
        }
        for ($position = $retry['cursor']; $position < count($retry['indices']); $position++) {
            if (!isset($unresolved[$retry['indices'][$position]])) {
                throw new RuntimeException('补处理轮尚未处理的索引不在未解决清单。');
            }
        }
    }

    /** @param array<string,mixed> $job */
    private function detailDiagnostic(mixed $wrapper, array $job): void
    {
        if ($wrapper === null) {
            return;
        }
        if (!is_array($wrapper)) {
            throw new RuntimeException('详情诊断摘要格式不正确。');
        }
        $this->onlyKeys($wrapper, ['index', 'diagnostics'], '详情诊断摘要');
        if (!is_int($wrapper['index'] ?? null) || $wrapper['index'] < 0
            || $wrapper['index'] >= ($job['progress']['total'] ?? 0)) {
            throw new RuntimeException('详情诊断商品索引不正确。');
        }
        $this->safeDiagnostics($wrapper['diagnostics'] ?? null);
    }

    private function safeDiagnostics(mixed $diagnostics): void
    {
        if (!is_array($diagnostics)) {
            throw new RuntimeException('详情诊断安全字段格式不正确。');
        }
        $base = ['category', 'http_status', 'curl_code', 'elapsed_ms', 'attempts'];
        $extended = [...$base, 'mime_category', 'mime_count', 'json_valid', 'mime_compatibility'];
        $jsonFailure = [...$extended, 'json_error_code', 'json_error'];
        $keys = array_keys($diagnostics);
        if (array_key_exists('response_structure', $diagnostics)) {
            $structure = $diagnostics['response_structure'];
            if (!is_array($structure) || UpstreamFailure::sanitizeResponseStructure($structure) !== $structure
                || $structure === [] || ($diagnostics['http_status'] ?? null) !== 200
                || ($diagnostics['curl_code'] ?? null) !== 0 || ($diagnostics['json_valid'] ?? null) !== true
                || !is_int($diagnostics['attempts'] ?? null) || $diagnostics['attempts'] < 1
                || !in_array($diagnostics['category'] ?? null, [
                    'none', 'business', 'schema', 'item_unavailable', 'item_invalid',
                ], true)) {
                throw new RuntimeException('详情响应结构摘要不正确。');
            }
            foreach (['data', 'first_children'] as $prefix) {
                if (array_key_exists($prefix . '_count', $structure)) {
                    $type = $structure[$prefix . '_type'] ?? null;
                    $count = $structure[$prefix . '_count'];
                    if (!(($type === 'empty_array_or_object' && $count === 0)
                        || (in_array($type, ['list', 'object'], true) && $count > 0))) {
                        throw new RuntimeException('详情响应结构数量与类型不一致。');
                    }
                }
            }
            if ((isset($structure['first_children_type'])
                    && !in_array($structure['data_type'] ?? null, ['list', 'object'], true))
                || (isset($structure['first_item_type'])
                    && (!in_array($structure['first_children_type'] ?? null, ['list', 'object', 'empty_array_or_object'], true)
                        || ($structure['first_children_type'] === 'empty_array_or_object' && $structure['first_item_type'] !== 'missing')
                        || ($structure['first_children_type'] === 'list' && $structure['first_item_type'] === 'missing')))) {
                throw new RuntimeException('详情响应结构层级不一致。');
            }
            $keys = array_values(array_diff($keys, ['response_structure']));
        }
        sort($keys, SORT_STRING);
        $baseSorted = $base;
        $extendedSorted = $extended;
        sort($baseSorted, SORT_STRING);
        sort($extendedSorted, SORT_STRING);
        sort($jsonFailure, SORT_STRING);
        if (array_key_exists('response_structure', $diagnostics) && $keys !== $extendedSorted) {
            throw new RuntimeException('详情响应结构缺少本次有效 JSON 观测。');
        }
        if ($keys !== $baseSorted && $keys !== $extendedSorted && $keys !== $jsonFailure) {
            throw new RuntimeException('详情诊断安全字段格式不正确。');
        }
        if (!in_array($diagnostics['category'] ?? null, [
            'none', 'transport', 'http_retryable', 'http_rejected', 'credentials', 'business', 'content_type',
            'json', 'schema', 'response_size', 'budget', 'unknown', 'item_unavailable', 'item_invalid',
        ], true)) {
            throw new RuntimeException('详情诊断分类不正确。');
        }
        foreach (['http_status' => 599, 'curl_code' => 999, 'elapsed_ms' => 480000, 'attempts' => 3] as $key => $max) {
            if (!is_int($diagnostics[$key] ?? null) || $diagnostics[$key] < 0 || $diagnostics[$key] > $max) {
                throw new RuntimeException('详情诊断计数不正确。');
            }
        }
        if ($diagnostics['http_status'] !== 0 && $diagnostics['http_status'] < 100) {
            throw new RuntimeException('详情诊断 HTTP 状态不正确。');
        }
        if ($keys === $baseSorted) {
            return;
        }
        if (!in_array($diagnostics['mime_category'] ?? null, [
            'application_json', 'text_json', 'json_suffix', 'text_html', 'text_plain', 'missing', 'empty',
            'other', 'malformed', 'conflicting', 'unknown',
        ], true)
            || (!is_int($diagnostics['mime_count'] ?? null) && $diagnostics['mime_count'] !== null)
            || (is_int($diagnostics['mime_count'])
                && ($diagnostics['mime_count'] < 0 || $diagnostics['mime_count'] > 65535))
            || (!is_bool($diagnostics['json_valid'] ?? null) && $diagnostics['json_valid'] !== null)
            || !is_bool($diagnostics['mime_compatibility'] ?? null)) {
            throw new RuntimeException('详情诊断兼容字段不正确。');
        }
        $compatibility = $diagnostics['mime_category'] !== 'unknown'
            && (!in_array($diagnostics['mime_category'], ['application_json', 'text_json'], true)
                || ($diagnostics['mime_count'] !== null && $diagnostics['mime_count'] !== 1));
        if ($diagnostics['mime_compatibility'] !== $compatibility) {
            throw new RuntimeException('详情诊断兼容判断与 MIME 摘要不一致。');
        }
        if ($keys === $jsonFailure && (UpstreamFailure::sanitize($diagnostics) !== $diagnostics
            || $diagnostics['category'] !== 'json' || $diagnostics['http_status'] !== 200
            || $diagnostics['curl_code'] !== 0 || $diagnostics['json_valid'] !== false
            || $diagnostics['attempts'] < 1)) {
            throw new RuntimeException('JSON 解析失败摘要不正确。');
        }
    }

    /** @param array<string,mixed> $job */
    private function detailResumeAuthorization(mixed $authorization, array $job): void
    {
        if ($authorization === null) {
            return;
        }
        if (!is_array($authorization)) {
            throw new RuntimeException('详情恢复授权格式不正确。');
        }
        $this->onlyKeys($authorization, [
            'task_id', 'task_hash', 'authorized_revision', 'index', 'snapshot_sha256', 'source_fingerprint',
            'legacy_event', 'diagnostic', 'consumed',
        ], '详情恢复授权');
        if (($authorization['task_id'] ?? null) !== ($job['task_id'] ?? null)
            || ($authorization['task_hash'] ?? null) !== substr(hash('sha256', (string)($job['task_id'] ?? '')), 0, 16)
            || !is_int($authorization['authorized_revision'] ?? null)
            || $authorization['authorized_revision'] < 1
            || $authorization['authorized_revision'] >= ($job['revision'] ?? 0)
            || !is_int($authorization['index'] ?? null) || $authorization['index'] < 0
            || $authorization['index'] >= ($job['progress']['total'] ?? 0)
            || !is_array($job['snapshot'] ?? null)
            || ($authorization['snapshot_sha256'] ?? null) !== $job['snapshot']['sha256']
            || ($authorization['source_fingerprint'] ?? null) !== ($job['source_fingerprint'] ?? null)
            || !is_bool($authorization['consumed'] ?? null)) {
            throw new RuntimeException('详情恢复授权绑定不正确。');
        }
        $legacyEvent = $authorization['legacy_event'] ?? null;
        if (!is_array($legacyEvent)) {
            throw new RuntimeException('详情恢复授权缺少历史失败事件。');
        }
        $this->onlyKeys($legacyEvent, ['error_code', 'diagnostics'], '详情恢复授权历史事件');
        $this->safeDiagnostics($legacyEvent['diagnostics'] ?? null);
        if (($legacyEvent['error_code'] ?? null) !== CommodityImportFailure::DETAIL_RESPONSE_INVALID
            || array_keys($legacyEvent['diagnostics']) !== [
                'category', 'http_status', 'curl_code', 'elapsed_ms', 'attempts',
            ]
            || $legacyEvent['diagnostics']['category'] !== 'content_type') {
            throw new RuntimeException('详情恢复授权历史事件不正确。');
        }
        $this->safeDiagnostics($authorization['diagnostic'] ?? null);
        $diagnostic = $authorization['diagnostic'];
        if (($diagnostic['category'] ?? null) !== 'none'
            || !array_key_exists('mime_compatibility', $diagnostic)
            || $diagnostic['mime_compatibility'] !== false
            || ($diagnostic['json_valid'] ?? null) !== true
            || !in_array($diagnostic['mime_category'] ?? null, ['application_json', 'text_json'], true)
            || ($diagnostic['mime_count'] ?? null) !== 1
            || $diagnostic['http_status'] !== 200
            || $diagnostic['curl_code'] !== 0
            || $diagnostic['attempts'] !== 1) {
            throw new RuntimeException('详情恢复授权的新诊断不满足单次严格 JSON 观察。');
        }
        if (!$authorization['consumed']) {
            if (!in_array($job['state'] ?? null, [self::STATE_FAILED, self::STATE_CANCELLED], true)
                || ($job['phase'] ?? null) !== 'import'
                || ($job['error_code'] ?? null) !== CommodityImportFailure::DETAIL_RESPONSE_INVALID
                || $authorization['index'] !== self::expectedDetailIndex($job)
                || (($job['state'] ?? null) === self::STATE_CANCELLED
                    && ($job['last_action'] ?? null) !== 'cancel')) {
                throw new RuntimeException('详情恢复授权不再匹配失败检查点。');
            }
        }
    }

    /** @param array<string,mixed> $job */
    private static function expectedDetailIndex(array $job): int
    {
        $retry = $job['retry'] ?? null;
        if (is_array($retry) && ($retry['halted'] ?? true) === false
            && is_int($retry['cursor'] ?? null) && is_array($retry['indices'] ?? null)
            && isset($retry['indices'][$retry['cursor']]) && is_int($retry['indices'][$retry['cursor']])) {
            return $retry['indices'][$retry['cursor']];
        }
        return is_int($job['progress']['processed'] ?? null) ? $job['progress']['processed'] : -1;
    }

    /** @param array<string,mixed> $job */
    private function itemFailures(array $job): void
    {
        $failures = $job['item_failures'];
        $progress = $job['progress'];
        if ($failures === null) {
            if ($progress['failed'] === 0) {
                throw new RuntimeException('无失败旧任务不能缺少失败清单。');
            }
            return;
        }
        if (!is_array($failures) || !array_is_list($failures)
            || count($failures) > self::MAX_ITEM_FAILURES || count($failures) !== $progress['failed']) {
            throw new RuntimeException('逐件失败清单与失败计数不一致。');
        }
        if ($failures !== [] && ($job['phase'] !== 'import' || !is_array($job['snapshot'])
            || $job['snapshot']['item_count'] !== $progress['total']
            || !hash_equals($job['source_fingerprint'], $job['snapshot']['source_fingerprint']))) {
            throw new RuntimeException('逐件失败清单没有绑定当前导入快照。');
        }
        $previous = -1;
        foreach ($failures as $failure) {
            if (!is_array($failure)) {
                throw new RuntimeException('逐件失败记录格式不正确。');
            }
            $this->onlyKeys($failure, ['index', 'code', 'attempts'], '逐件失败记录');
            if (!is_int($failure['index'] ?? null) || $failure['index'] <= $previous
                || $failure['index'] >= $progress['processed']
                || !is_string($failure['code'] ?? null)
                || !CommodityImportFailure::isIsolatableItemCode($failure['code'])
                || !is_int($failure['attempts'] ?? null) || $failure['attempts'] < 0 || $failure['attempts'] > 3) {
                throw new RuntimeException('逐件失败记录的索引、错误码或尝试次数不正确。');
            }
            $previous = $failure['index'];
        }
        if ($job['state'] === self::STATE_COMPLETED && $progress['failed'] !== 0) {
            throw new RuntimeException('有逐件失败的任务不能标记完成成功。');
        }
        $cancelledEvidence = $job['state'] === self::STATE_CANCELLED && $job['last_action'] === 'cancel';
        if ($job['error_code'] === 'IMPORT_FINISHED_WITH_ISSUES'
            && (!($job['state'] === self::STATE_FAILED || $cancelledEvidence)
                || $progress['failed'] === 0 || $progress['processed'] !== $progress['total'])) {
            throw new RuntimeException('有异常结束的任务计数不正确。');
        }
        if ($job['error_code'] === 'IMPORT_ITEM_FAILURE_LIMIT'
            && (!in_array($job['state'], [self::STATE_FAILED, self::STATE_PAUSED], true)
                && !$cancelledEvidence
                || (!self::itemFailureLimitReached($failures, $progress['processed'])
                    && !(is_array($job['retry']) && $job['retry']['halted'])))) {
            throw new RuntimeException('逐件失败阈值状态没有对应的失败清单。');
        }
    }

    /** @param list<array{index:int,code:string,attempts:int}> $failures */
    public static function itemFailureLimitReached(array $failures, int $processed): bool
    {
        if (count($failures) >= self::MAX_ITEM_FAILURES) {
            return true;
        }
        $consecutive = 0;
        for ($position = count($failures) - 1; $position >= 0; $position--) {
            if (($failures[$position]['index'] ?? null) !== $processed - $consecutive - 1) {
                break;
            }
            if (++$consecutive >= self::MAX_CONSECUTIVE_ITEM_FAILURES) {
                return true;
            }
        }
        return false;
    }

    private function snapshot(mixed $snapshot): void
    {
        if ($snapshot === null) {
            return;
        }
        if (!is_array($snapshot)) {
            throw new RuntimeException('后台任务快照摘要格式不正确。');
        }
        $this->onlyKeys($snapshot, ['sha256', 'plan_hash', 'source_fingerprint', 'item_count'], '后台任务快照摘要');
        $this->sha256($snapshot['sha256'] ?? null, '后台任务快照摘要');
        $this->sha256($snapshot['plan_hash'] ?? null, '后台任务分类方案');
        $this->sha256($snapshot['source_fingerprint'] ?? null, '后台任务货源指纹');
        if (!is_int($snapshot['item_count'] ?? null) || $snapshot['item_count'] < 1 || $snapshot['item_count'] > 10000) {
            throw new RuntimeException('后台任务快照商品数量不正确。');
        }
    }

    private function categories(mixed $categories, string $mode): void
    {
        if (!is_array($categories) || !array_is_list($categories) || count($categories) > 200) {
            throw new RuntimeException('后台任务分类摘要格式不正确。');
        }
        $seen = [];
        foreach ($categories as $category) {
            if (!is_array($category)) {
                throw new RuntimeException('后台任务分类摘要格式不正确。');
            }
            $this->onlyKeys($category, ['name', 'count', 'target', 'confidence'], '后台任务分类摘要');
            $name = $this->text($category['name'] ?? null, 128, '后台任务分类名称');
            $this->target($category['target'] ?? null);
            $this->assertCategoryMode($name, $category['target'], $mode);
            $key = UpstreamCategoryTree::categoryKey($name, $category['target']);
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
        }
    }

    private function counts(mixed $counts): void
    {
        if (!is_array($counts)) {
            throw new RuntimeException('后台任务分类计数格式不正确。');
        }
        $this->onlyKeys($counts, ['items', 'categories', 'high', 'low'], '后台任务分类计数');
        foreach (['items', 'categories', 'high', 'low'] as $key) {
            if (!is_int($counts[$key] ?? null) || $counts[$key] < 0 || $counts[$key] > 10000) {
                throw new RuntimeException('后台任务分类计数格式不正确。');
            }
        }
        if ($counts['high'] + $counts['low'] !== $counts['categories']) {
            throw new RuntimeException('后台任务分类计数不一致。');
        }
    }

    private function progress(mixed $progress): void
    {
        if (!is_array($progress)) {
            throw new RuntimeException('后台任务进度格式不正确。');
        }
        $this->onlyKeys($progress, ['total', 'processed', 'succeeded', 'failed', 'skipped'], '后台任务进度');
        foreach (['total', 'processed', 'succeeded', 'failed', 'skipped'] as $key) {
            if (!is_int($progress[$key] ?? null) || $progress[$key] < 0 || $progress[$key] > 10000) {
                throw new RuntimeException('后台任务进度格式不正确。');
            }
        }
        if ($progress['processed'] > $progress['total']
            || $progress['succeeded'] + $progress['failed'] + $progress['skipped'] !== $progress['processed']) {
            throw new RuntimeException('后台任务进度计数不一致。');
        }
    }

    private function mappings(mixed $mappings, string $mode): void
    {
        if (!is_array($mappings) || !array_is_list($mappings) || count($mappings) > 200) {
            throw new RuntimeException('后台任务确认映射格式不正确。');
        }
        $seen = [];
        foreach ($mappings as $mapping) {
            if (!is_array($mapping)) {
                throw new RuntimeException('后台任务确认映射格式不正确。');
            }
            $this->onlyKeys($mapping, ['source_category', 'target', 'confidence'], '后台任务确认映射');
            $category = $this->text($mapping['source_category'] ?? null, 128, '后台任务确认分类名称');
            $this->target($mapping['target'] ?? null);
            $this->assertCategoryMode($category, $mapping['target'], $mode);
            $key = UpstreamCategoryTree::categoryKey($category, $mapping['target']);
            if (isset($seen[$key])) {
                throw new RuntimeException('后台任务确认分类名称不能重复。');
            }
            $seen[$key] = true;
            if (!is_string($mapping['confidence'] ?? null)
                || !in_array($mapping['confidence'], ['high', 'low'], true)) {
                throw new RuntimeException('后台任务确认映射可信度不正确。');
            }
        }
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

    private function taskId(mixed $taskId): string
    {
        if (!is_string($taskId) || preg_match('/^[a-f0-9]{48}$/D', $taskId) !== 1) {
            throw new RuntimeException('后台任务编号格式不正确。');
        }
        return $taskId;
    }

    private function sha256(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new RuntimeException("{$label}格式不正确。");
        }
        return $value;
    }

    private function timestamp(mixed $value): void
    {
        if (!is_string($value) || strlen($value) > 35 || \DateTimeImmutable::createFromFormat(DATE_ATOM, $value) === false) {
            throw new RuntimeException('后台任务时间格式不正确。');
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

    private function target(mixed $target): void
    {
        if (!is_array($target)) {
            throw new RuntimeException('后台任务分类目标格式不正确。');
        }
        if (($target['mode'] ?? null) === 'mirror') {
            if (UpstreamCategoryTree::normalizeTarget($target) !== $target) {
                throw new RuntimeException('后台任务上游分类路径不是规范结构。');
            }
            return;
        }
        $this->onlyKeys($target, ['group', 'family'], '后台任务分类目标');
        $this->categorySegment($target['group'] ?? null, '后台任务一级分类');
        $this->categorySegment($target['family'] ?? null, '后台任务二级分类', true);
    }

    private function assertCategoryMode(string $name, array $target, string $mode): void
    {
        if (($target['mode'] ?? 'smart') !== $mode
            || ($mode === 'mirror' && $target['path'][array_key_last($target['path'])]['name'] !== $name)) {
            throw new RuntimeException('后台任务分类模式或上游分类名称不一致。');
        }
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

    private function premium(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^(?:0|[1-9]\d{0,2}|1000)(?:\.\d{1,4})?$/D', $value) !== 1) {
            throw new RuntimeException('后台任务加价百分比格式不正确。');
        }
        $number = (float)$value;
        if (!is_finite($number) || $number < 0 || $number > 1000) {
            throw new RuntimeException('后台任务加价百分比超出 0-1000 范围。');
        }
        return $value;
    }

    private function action(string $action): string
    {
        if (!in_array($action, ['create', 'confirm', 'pause', 'resume', 'retry_failed', 'cancel'], true)) {
            throw new RuntimeException('后台任务动作不正确。');
        }
        return $action;
    }

    private function path(): string
    {
        return PathGuard::stateDirectory('extensions/PikaCatalogHub/jobs', 0o750) . '/jobs.json';
    }
}
