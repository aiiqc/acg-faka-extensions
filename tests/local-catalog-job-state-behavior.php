<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\Manager {
    final class PathGuard
    {
        public static string $root = '';

        public static function siteRoot(): string
        {
            return self::$root . '/site';
        }

        public static function stateRoot(): string
        {
            return self::$root;
        }

        public static function stateDirectory(string $relative, int $mode = 0o750): string
        {
            if (preg_match('#^[A-Za-z0-9._/-]+$#D', $relative) !== 1
                || !in_array($mode, [0o700, 0o750], true)) {
                throw new \RuntimeException('unexpected job state directory request');
            }
            $path = self::$root . '/' . trim($relative, '/');
            if (!is_dir($path) && !mkdir($path, $mode, true) && !is_dir($path)) {
                throw new \RuntimeException('unable to create job state directory');
            }
            if (!chmod($path, $mode)) {
                throw new \RuntimeException('unable to protect job state directory');
            }
            return $path;
        }

        public static function runtimeOwner(): int
        {
            $owner = fileowner(self::$root);
            if (!is_int($owner)) {
                throw new \RuntimeException('unable to resolve job fixture owner');
            }
            return $owner;
        }
    }
}

namespace {
    use Pika\LocalExtensions\Manager\AtomicJson;
    use Pika\LocalExtensions\Manager\PathGuard;
    use Pika\LocalExtensions\PikaCatalogHub\Service\JobService;
    use Pika\LocalExtensions\PikaCatalogHub\Service\JobStore;
    use Pika\LocalExtensions\PikaCatalogHub\Service\SnapshotStore;
    use Pika\LocalExtensions\PikaSupplySync\Service\CommodityImportFailure;
    use Pika\LocalExtensions\PikaSupplySync\Service\UpstreamCategoryTree;

    function jobExpect(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function jobFails(callable $callback, string $message): void
    {
        try {
            $callback();
        } catch (Throwable) {
            return;
        }
        throw new RuntimeException($message);
    }

    function jobTarget(string $group, string $family = ''): array
    {
        return ['group' => $group, 'family' => $family];
    }

    /** @return array<string,mixed> */
    function jobHistoryRecord(
        string $taskId,
        int $sourceId,
        string $state,
        string $createdAt,
        ?array $snapshot = null,
    ): array {
        return [
            'task_id' => $taskId,
            'source_id' => $sourceId,
            'source_alias' => '历史货源' . $sourceId,
            'source_fingerprint' => hash('sha256', 'history-source-' . $sourceId),
            'state' => $state,
            'phase' => 'analysis',
            'revision' => 1,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'snapshot' => $snapshot,
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
    }

    /** @return array<string,mixed> */
    function denseJobHistoryRecord(
        string $taskId,
        int $sourceId,
        string $state,
        string $createdAt,
    ): array {
        $record = jobHistoryRecord($taskId, $sourceId, $state, $createdAt);
        $categories = [];
        $mappings = [];
        for ($index = 0; $index < 200; $index++) {
            $name = sprintf('分类%03d-', $index) . str_repeat('甲', 110);
            $target = jobTarget('平台分类', '系列' . $index);
            $categories[] = [
                'name' => $name,
                'count' => 1,
                'target' => $target,
                'confidence' => 'high',
            ];
            $mappings[] = [
                'source_category' => $name,
                'target' => $target,
                'confidence' => 'high',
            ];
        }
        $record['categories'] = $categories;
        $record['mappings'] = $mappings;
        $record['counts'] = ['items' => 200, 'categories' => 200, 'high' => 200, 'low' => 0];
        return $record;
    }

    /** @param array<string,array<string,mixed>> $jobs */
    function encodedJobHistorySize(array $jobs, ?array $gc = null): int
    {
        ksort($jobs, SORT_STRING);
        return strlen(json_encode(
            ['schema' => 4, 'jobs' => $jobs, 'snapshot_gc' => $gc],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n");
    }

    /** @param array<string,array<string,mixed>> $jobs @param array<string,mixed>|null $gc */
    function writeJobHistoryState(string $path, array $jobs, ?array $gc = null, int $schema = 4): void
    {
        ksort($jobs, SORT_STRING);
        if ($schema < 4) {
            foreach ($jobs as &$job) {
                unset(
                    $job['retry'],
                    $job['last_detail_diagnostic'],
                    $job['detail_compatibility_count'],
                    $job['detail_resume_authorization'],
                );
            }
            unset($job);
        }
        if ($schema < 3) {
            foreach ($jobs as &$job) {
                unset($job['item_failures']);
            }
            unset($job);
        }
        $state = $schema === 1
            ? ['schema' => 1, 'jobs' => $jobs]
            : ['schema' => $schema, 'jobs' => $jobs, 'snapshot_gc' => $gc];
        file_put_contents($path, json_encode(
            $state,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n");
        chmod($path, 0o600);
    }

    function removeJobFixture(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        if (!is_array($entries)) {
            throw new RuntimeException('unable to inspect job fixture');
        }
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                removeJobFixture($path . '/' . $entry);
            }
        }
        rmdir($path);
    }

    $fixture = sys_get_temp_dir() . '/pika-catalog-job-state-' . bin2hex(random_bytes(8));
    if (!mkdir($fixture, 0o700, true) || !mkdir($fixture . '/site', 0o700)) {
        throw new RuntimeException('unable to create job fixture');
    }
    PathGuard::$root = $fixture;

    try {
        require_once dirname(__DIR__) . '/manager/site/local-extensions/src/AtomicJson.php';
        require_once dirname(__DIR__) . '/extensions/PikaSupplySync/bootstrap.php';
        $registered = require dirname(__DIR__) . '/extensions/PikaCatalogHub/bootstrap.php';
        jobExpect($registered === true, 'CatalogHub bootstrap did not register for job tests');
        jobExpect(class_exists(JobService::class), 'JobService did not autoload');

        $service = new JobService();
        $sourceFingerprint = hash('sha256', 'source-7-identity');
        $created = $service->createAnalysis(7, '货源A', $sourceFingerprint);
        jobExpect($created['state'] === JobStore::STATE_QUEUED_ANALYSIS, 'new analysis task is not queued');
        jobExpect($created['phase'] === 'analysis' && $created['revision'] === 1, 'new task identity is wrong');
        jobExpect($created['category_mode'] === 'smart'
            && !array_key_exists('category_mode', (new JobStore())->get($created['task_id'])),
            'legacy smart default changed its serialized job shape');
        jobExpect($created['retry'] === null
            && $created['last_detail_diagnostic'] === null
            && $created['detail_compatibility_count'] === 0
            && !$created['can_retry_failed'],
            'new task exposed stale retry or detail-diagnostic state');
        jobExpect(preg_match('/^[a-f0-9]{48}$/D', $created['task_id']) === 1, 'task ID is predictable or malformed');
        jobExpect(!array_key_exists('source_fingerprint', $created), 'task response exposed source fingerprint');
        jobFails(
            static fn() => $service->createAnalysis(7, 'same-source', $sourceFingerprint),
            'a source accepted two non-terminal tasks',
        );
        $service->assertSourceFingerprint($created['task_id'], $sourceFingerprint);
        jobFails(
            static fn() => $service->assertSourceFingerprint($created['task_id'], hash('sha256', 'changed-source')),
            'changed source identity was accepted',
        );
        jobFails(
            static fn() => $service->beginWork($created['task_id'], 999),
            'optimistic revision mismatch was accepted',
        );

        $claimed = $service->claimNext();
        jobExpect(is_array($claimed) && $claimed['task_id'] === $created['task_id'], 'claimNext chose the wrong task');
        jobExpect($claimed['state'] === JobStore::STATE_ANALYZING && $claimed['revision'] === 2, 'analysis claim did not transition');

        $planHash = hash('sha256', 'job-test-plan');
        $items = [
            ['code' => '2', 'category' => '未知分类', 'stock' => 0, 'target' => jobTarget('其他')],
            ['code' => '1', 'category' => 'AI Chat-GPT', 'stock' => 8, 'target' => jobTarget('AI工具', 'GPT')],
        ];
        $metadata = $service->storeAnalysisSnapshot(
            $created['task_id'],
            $claimed['revision'],
            $planHash,
            $items,
        );
        jobExpect($metadata['item_count'] === 2 && strlen($metadata['sha256']) === 64, 'snapshot metadata is wrong');
        $metadataAgain = $service->storeAnalysisSnapshot(
            $created['task_id'],
            $claimed['revision'],
            $planHash,
            $items,
        );
        jobExpect($metadataAgain === $metadata, 'identical snapshot retry was not idempotent');
        $changedItems = $items;
        $changedItems[0]['stock'] = 1;
        jobFails(
            static fn() => $service->storeAnalysisSnapshot(
                $created['task_id'],
                $claimed['revision'],
                $planHash,
                $changedItems,
            ),
            'same task accepted a different snapshot payload',
        );
        $snapshotPath = $fixture . '/extensions/PikaCatalogHub/snapshots/' . $created['task_id'] . '.json';
        $stat = lstat($snapshotPath);
        jobExpect(is_array($stat) && ($stat['mode'] & 0o777) === 0o600, 'snapshot mode is not 0600');
        jobExpect(($stat['mode'] & 0o170000) === 0o100000 && $stat['nlink'] === 1, 'snapshot is not a single-link regular file');
        jobExpect($stat['uid'] === fileowner($fixture), 'snapshot owner is wrong');
        $snapshotRaw = (string)file_get_contents($snapshotPath);
        jobExpect(!str_contains($snapshotRaw, 'product name'), 'snapshot leaked a product name');
        jobExpect(!preg_match('/(?:https?:\/\/|merchant|app[_-]?key|secret|token)/i', $snapshotRaw), 'snapshot leaked a sensitive field');
        jobExpect(strpos($snapshotRaw, '"code":"1"') < strpos($snapshotRaw, '"code":"2"'), 'snapshot order is not canonical');

        $store = new SnapshotStore();
        $loaded = $store->read(
            $created['task_id'],
            $metadata['sha256'],
            $metadata['source_fingerprint'],
            $metadata['plan_hash'],
        );
        jobExpect($loaded['items'][0]['code'] === '1', 'snapshot read did not preserve canonical items');
        jobFails(
            static fn() => $store->read(
                $created['task_id'],
                str_repeat('0', 64),
                $metadata['source_fingerprint'],
                $metadata['plan_hash'],
            ),
            'snapshot SHA binding mismatch was accepted',
        );
        $snapshotHardlink = $snapshotPath . '.hardlink-test';
        jobExpect(link($snapshotPath, $snapshotHardlink), 'unable to create snapshot hardlink fixture');
        jobFails(
            static fn() => $store->readMetadata(
                $created['task_id'],
                $metadata['source_fingerprint'],
                $metadata['plan_hash'],
            ),
            'hard-linked snapshot was accepted',
        );
        unlink($snapshotHardlink);
        clearstatcache(true, $snapshotPath);
        chmod($snapshotPath, 0o644);
        jobFails(
            static fn() => $store->readMetadata(
                $created['task_id'],
                $metadata['source_fingerprint'],
                $metadata['plan_hash'],
            ),
            'unsafe snapshot permissions were accepted',
        );
        chmod($snapshotPath, 0o600);
        jobFails(
            static fn() => $store->write(
                str_repeat('a', 48),
                str_repeat('b', 64),
                str_repeat('c', 64),
                [[
                    'code' => '1',
                    'category' => '分类',
                    'stock' => 1,
                    'target' => jobTarget('其他'),
                    'name' => 'must not be accepted',
                ]],
            ),
            'snapshot accepted a product name field',
        );
        $invalidCategorySegments = [
            'Bad/Path',
            'Bad\\Path',
            ' Leading',
            'Trailing ',
            "Bad\x1FPath",
            str_repeat('界', 65),
        ];
        foreach ($invalidCategorySegments as $index => $invalidSegment) {
            jobFails(
                static fn() => $store->write(
                    substr(hash('sha256', 'invalid-snapshot-group-' . $index), 0, 48),
                    str_repeat('b', 64),
                    str_repeat('c', 64),
                    [[
                        'code' => '1',
                        'category' => '分类',
                        'stock' => 1,
                        'target' => jobTarget($invalidSegment),
                    ]],
                ),
                'snapshot accepted an invalid group path segment',
            );
            jobFails(
                static fn() => $store->write(
                    substr(hash('sha256', 'invalid-snapshot-family-' . $index), 0, 48),
                    str_repeat('b', 64),
                    str_repeat('c', 64),
                    [[
                        'code' => '1',
                        'category' => '分类',
                        'stock' => 1,
                        'target' => jobTarget('其他', $invalidSegment),
                    ]],
                ),
                'snapshot accepted an invalid family path segment',
            );
        }

        $categories = [
            ['name' => '未知分类', 'count' => 1, 'target' => jobTarget('其他'), 'confidence' => 'low'],
            ['name' => 'AI Chat-GPT', 'count' => 1, 'target' => jobTarget('AI工具', 'GPT'), 'confidence' => 'high'],
        ];
        $analyzed = $service->storeAnalysis(
            $created['task_id'],
            $claimed['revision'],
            $metadata['sha256'],
            $planHash,
            $categories,
            2,
        );
        jobExpect($analyzed['state'] === JobStore::STATE_AWAITING_CONFIRMATION, 'analysis did not await confirmation');
        jobExpect($analyzed['counts'] === ['items' => 2, 'categories' => 2, 'high' => 1, 'low' => 1], 'analysis counts are wrong');
        jobExpect(
            $service->storeAnalysis(
                $created['task_id'],
                $claimed['revision'],
                $metadata['sha256'],
                $planHash,
                $categories,
                2,
            ) === $analyzed,
            'identical analysis result retry was not idempotent',
        );
        $changedCategories = $categories;
        $changedCategories[0]['target'] = jobTarget('Different');
        jobFails(
            static fn() => $service->storeAnalysis(
                $created['task_id'],
                $claimed['revision'],
                $metadata['sha256'],
                $planHash,
                $changedCategories,
                2,
            ),
            'analysis retry accepted different category suggestions',
        );
        $responseJson = json_encode($analyzed, JSON_THROW_ON_ERROR);
        jobExpect(!str_contains($responseJson, '"code"'), 'task summary exposed snapshot item codes');
        jobExpect(!str_contains($responseJson, $metadata['source_fingerprint']), 'task summary exposed source fingerprint');
        jobFails(
            static fn() => $service->confirmImport(
                $created['task_id'],
                $analyzed['revision'],
                $planHash,
                10,
                [[
                    'source_category' => 'AI Chat-GPT',
                    'target' => jobTarget('AI工具', 'GPT'),
                    'confidence' => 'high',
                ]],
            ),
            'partial category confirmation was accepted',
        );

        $mappings = [
            ['source_category' => '未知分类', 'target' => jobTarget('其他'), 'confidence' => 'low'],
            ['source_category' => 'AI Chat-GPT', 'target' => jobTarget('AI工具', 'GPT'), 'confidence' => 'high'],
        ];
        foreach ($invalidCategorySegments as $invalidSegment) {
            $invalidMappings = $mappings;
            $invalidMappings[0]['target']['group'] = $invalidSegment;
            jobFails(
                static fn() => $service->confirmImport(
                    $created['task_id'],
                    $analyzed['revision'],
                    $planHash,
                    10,
                    $invalidMappings,
                ),
                'confirmation queued an invalid group path segment',
            );

            $invalidMappings = $mappings;
            $invalidMappings[0]['target']['family'] = $invalidSegment;
            jobFails(
                static fn() => $service->confirmImport(
                    $created['task_id'],
                    $analyzed['revision'],
                    $planHash,
                    10,
                    $invalidMappings,
                ),
                'confirmation queued an invalid family path segment',
            );
        }
        $confirmed = $service->confirmImport(
            $created['task_id'],
            $analyzed['revision'],
            $planHash,
            '10.00',
            $mappings,
        );
        jobExpect($confirmed['state'] === JobStore::STATE_QUEUED_IMPORT, 'confirmed task was not queued for import');
        jobExpect($confirmed['premium_percent'] === '10', 'premium percent was not canonicalized');
        $confirmedAgain = $service->confirmImport(
            $created['task_id'],
            $analyzed['revision'],
            $planHash,
            10,
            $mappings,
        );
        jobExpect($confirmedAgain === $confirmed, 'repeated confirmation was not idempotent');
        jobFails(
            static fn() => $service->confirmImport(
                $created['task_id'],
                $analyzed['revision'],
                $planHash,
                20,
                $mappings,
            ),
            'repeated confirmation accepted different pricing',
        );

        $paused = $service->control($created['task_id'], $confirmed['revision'], 'pause');
        jobExpect($paused['state'] === JobStore::STATE_PAUSED, 'queued task did not pause immediately');
        $pausedAgain = $service->control($created['task_id'], $confirmed['revision'], 'pause');
        jobExpect($pausedAgain === $paused, 'repeated pause was not idempotent');
        $resumed = $service->control($created['task_id'], $paused['revision'], 'resume');
        jobExpect($resumed['state'] === JobStore::STATE_QUEUED_IMPORT, 'paused import did not resume to queue');
        $importing = $service->beginWork($created['task_id'], $resumed['revision']);
        jobExpect($importing['state'] === JobStore::STATE_IMPORTING, 'import task did not begin');
        $importPayload = $service->loadImportSnapshot(
            $created['task_id'],
            $importing['revision'],
            $sourceFingerprint,
        );
        jobExpect($importPayload['premium_percent'] === '10', 'worker import payload lost pricing');
        jobExpect($importPayload['items'][0]['target'] === jobTarget('AI工具', 'GPT'), 'worker mapping overlay is wrong');
        jobFails(
            static fn() => $service->loadImportSnapshot(
                $created['task_id'],
                $importing['revision'],
                hash('sha256', 'changed-source'),
            ),
            'worker import accepted changed source identity',
        );
        $progress = $service->yieldImport($created['task_id'], $importing['revision'], [
            'total' => 2, 'processed' => 1, 'succeeded' => 1, 'failed' => 0, 'skipped' => 0,
        ]);
        jobExpect($progress['state'] === JobStore::STATE_QUEUED_IMPORT, 'bounded import did not yield back to queue');
        $importingAfterYield = $service->beginWork($created['task_id'], $progress['revision']);
        jobFails(
            static fn() => $service->yieldImport($created['task_id'], $importingAfterYield['revision'], [
                'total' => 2, 'processed' => 0, 'succeeded' => 0, 'failed' => 0, 'skipped' => 0,
            ]),
            'worker progress was allowed to move backwards',
        );
        $pauseRequested = $service->control($created['task_id'], $importingAfterYield['revision'], 'pause');
        jobExpect($pauseRequested['state'] === JobStore::STATE_PAUSE_REQUESTED, 'running import did not request pause');
        $pauseAcknowledged = $service->checkpoint($created['task_id'], $pauseRequested['revision'], $progress['progress']);
        jobExpect($pauseAcknowledged['state'] === JobStore::STATE_PAUSED, 'worker did not acknowledge pause at checkpoint');
        $queuedAgain = $service->control($created['task_id'], $pauseAcknowledged['revision'], 'resume');
        $importingAgain = $service->beginWork($created['task_id'], $queuedAgain['revision']);
        $finishedProgress = $service->checkpoint($created['task_id'], $importingAgain['revision'], [
            'total' => 2, 'processed' => 2, 'succeeded' => 2, 'failed' => 0, 'skipped' => 0,
        ]);
        $completed = $service->complete($created['task_id'], $finishedProgress['revision']);
        jobExpect($completed['state'] === JobStore::STATE_COMPLETED, 'fully processed task did not complete');
        jobExpect($service->complete($created['task_id'], $finishedProgress['revision']) === $completed, 'repeat complete was not idempotent');

        $second = $service->createAnalysis(7, '货源A再次接入', $sourceFingerprint);
        $secondRunning = $service->beginWork($second['task_id'], $second['revision']);
        $secondSnapshot = $service->storeAnalysisSnapshot(
            $second['task_id'],
            $secondRunning['revision'],
            $planHash,
            $items,
        );
        $cancelRequested = $service->control($second['task_id'], $secondRunning['revision'], 'cancel');
        jobExpect($cancelRequested['state'] === JobStore::STATE_CANCEL_REQUESTED, 'running analysis did not request cancellation');
        jobFails(
            static fn() => $service->storeAnalysisData(
                $second['task_id'],
                $secondRunning['revision'],
                $planHash,
                $items,
                $categories,
            ),
            'analysis persistence ignored a concurrent cancellation request',
        );
        $cancelled = $service->checkpoint($second['task_id'], $cancelRequested['revision'], [
            'total' => 0, 'processed' => 0, 'succeeded' => 0, 'failed' => 0, 'skipped' => 0,
        ]);
        jobExpect($cancelled['state'] === JobStore::STATE_CANCELLED, 'worker did not acknowledge cancellation');
        $secondSnapshotPath = $fixture . '/extensions/PikaCatalogHub/snapshots/' . $second['task_id'] . '.json';
        jobExpect(!file_exists($secondSnapshotPath), 'cancellation left an unbound analysis snapshot');
        jobFails(
            static fn() => $service->control($second['task_id'], $cancelled['revision'], 'resume'),
            'cancelled task was allowed to resume',
        );

        $thirdFingerprint = hash('sha256', 'source-8');
        $third = $service->createAnalysis(8, '货源B', $thirdFingerprint);
        $thirdRunning = $service->beginWork($third['task_id'], $third['revision']);
        $service->storeAnalysisSnapshot(
            $third['task_id'],
            $thirdRunning['revision'],
            $planHash,
            $items,
        );
        $thirdPause = $service->control($third['task_id'], $thirdRunning['revision'], 'pause');
        jobFails(
            static fn() => $service->storeAnalysisData(
                $third['task_id'],
                $thirdRunning['revision'],
                $planHash,
                $items,
                $categories,
            ),
            'analysis persistence ignored a concurrent pause request',
        );
        $thirdSnapshotPath = $fixture . '/extensions/PikaCatalogHub/snapshots/' . $third['task_id'] . '.json';
        jobExpect(!file_exists($thirdSnapshotPath), 'pause left an unbound analysis snapshot');
        $thirdPaused = $service->checkpoint($third['task_id'], $thirdPause['revision'], [
            'total' => 0, 'processed' => 0, 'succeeded' => 0, 'failed' => 0, 'skipped' => 0,
        ]);
        $thirdQueued = $service->control($third['task_id'], $thirdPaused['revision'], 'resume');
        $thirdRestarted = $service->beginWork($third['task_id'], $thirdQueued['revision']);
        $changedAfterPause = $items;
        $changedAfterPause[0]['stock'] = 2;
        $thirdAnalyzed = $service->storeAnalysisData(
            $third['task_id'],
            $thirdRestarted['revision'],
            $planHash,
            $changedAfterPause,
            $categories,
        );
        jobExpect(
            $thirdAnalyzed['state'] === JobStore::STATE_AWAITING_CONFIRMATION,
            'resumed analysis could not replace the discarded orphan snapshot',
        );
        $service->control($third['task_id'], $thirdAnalyzed['revision'], 'cancel');

        $listed = $service->list();
        jobExpect(count($listed) === 3, 'task list does not contain all histories');
        jobExpect($service->get($created['task_id']) === $completed, 'task get does not return the current summary');
        $jobsPath = $fixture . '/extensions/PikaCatalogHub/jobs/jobs.json';
        jobExpect(filesize($jobsPath) < 1048576, 'small task state exceeded 1MB');
        jobExpect((fileperms($jobsPath) & 0o777) === 0o600, 'small task state is not mode 0600');

        $jobsOriginal = (string)file_get_contents($jobsPath);
        $jobsOriginalHash = hash('sha256', $jobsOriginal);
        $jobsHardlink = $jobsPath . '.hardlink-test';
        jobExpect(link($jobsPath, $jobsHardlink), 'unable to create job state hardlink fixture');
        jobFails(
            static fn() => (new JobStore())->list(),
            'hard-linked job state was accepted',
        );
        jobExpect(hash_equals($jobsOriginalHash, hash_file('sha256', $jobsPath)), 'job state hardlink rejection changed state');
        unlink($jobsHardlink);
        clearstatcache(true, $jobsPath);

        $jobsLockPath = $jobsPath . '.lock';
        clearstatcache(true, $jobsLockPath);
        $jobsLockMetadata = lstat($jobsLockPath);
        jobExpect(
            is_array($jobsLockMetadata)
                && ($jobsLockMetadata['mode'] & 0o170000) === 0o100000
                && ($jobsLockMetadata['mode'] & 0o777) === 0o600
                && $jobsLockMetadata['nlink'] === 1,
            'job lock was not securely created',
        );
        $jobsLockHardlink = $jobsLockPath . '.hardlink-test';
        jobExpect(is_file($jobsLockPath) && link($jobsLockPath, $jobsLockHardlink), 'unable to create job lock hardlink fixture');
        $jobsLockModeBeforeHardlinkRejection = fileperms($jobsLockPath) & 0o777;
        jobFails(
            static fn() => (new JobStore())->update(
                $created['task_id'],
                $completed['revision'],
                static fn(array $job): array => $job,
            ),
            'hard-linked job lock was accepted',
        );
        jobExpect(hash_equals($jobsOriginalHash, hash_file('sha256', $jobsPath)), 'job lock hardlink rejection changed state');
        jobExpect(
            (fileperms($jobsLockPath) & 0o777) === $jobsLockModeBeforeHardlinkRejection,
            'job lock hardlink rejection changed the target mode',
        );
        unlink($jobsLockHardlink);
        clearstatcache(true, $jobsLockPath);

        chmod($jobsLockPath, 0o644);
        jobFails(
            static fn() => (new JobStore())->update(
                $created['task_id'],
                $completed['revision'],
                static fn(array $job): array => $job,
            ),
            'mode 0644 job lock was accepted',
        );
        clearstatcache(true, $jobsLockPath);
        jobExpect(
            (fileperms($jobsLockPath) & 0o777) === 0o644,
            'mode 0644 job lock rejection changed the target mode',
        );
        jobExpect(hash_equals($jobsOriginalHash, hash_file('sha256', $jobsPath)), 'unsafe job lock mode changed state');
        chmod($jobsLockPath, 0o600);

        $jobsLockBackup = $jobsLockPath . '.safe-backup';
        jobExpect(rename($jobsLockPath, $jobsLockBackup), 'unable to isolate job lock for symlink test');
        $jobsLockBackupHash = hash_file('sha256', $jobsLockBackup);
        jobExpect(symlink($jobsLockBackup, $jobsLockPath), 'unable to create job lock symlink fixture');
        jobFails(
            static fn() => (new JobStore())->update(
                $created['task_id'],
                $completed['revision'],
                static fn(array $job): array => $job,
            ),
            'symbolic-link job lock was accepted',
        );
        jobExpect(
            hash_equals($jobsLockBackupHash, hash_file('sha256', $jobsLockBackup))
                && (fileperms($jobsLockBackup) & 0o777) === 0o600,
            'symbolic-link job lock rejection changed its target',
        );
        unlink($jobsLockPath);
        jobExpect(rename($jobsLockBackup, $jobsLockPath), 'unable to restore job lock after symlink test');

        $jobsLockBackup = $jobsLockPath . '.safe-backup';
        jobExpect(rename($jobsLockPath, $jobsLockBackup), 'unable to isolate job lock for type test');
        jobExpect(mkdir($jobsLockPath, 0o700), 'unable to create non-regular job lock fixture');
        jobFails(
            static fn() => (new JobStore())->update(
                $created['task_id'],
                $completed['revision'],
                static fn(array $job): array => $job,
            ),
            'non-regular job lock was accepted',
        );
        clearstatcache(true, $jobsLockPath);
        jobExpect(
            is_dir($jobsLockPath) && (fileperms($jobsLockPath) & 0o777) === 0o700,
            'non-regular job lock rejection changed the path mode or type',
        );
        rmdir($jobsLockPath);
        jobExpect(rename($jobsLockBackup, $jobsLockPath), 'unable to restore job lock after type test');

        $jobsLockHandle = fopen($jobsLockPath, 'r+b');
        jobExpect(is_resource($jobsLockHandle), 'unable to open job lock for post-flock identity test');
        $jobsLockMoved = $jobsLockPath . '.post-flock-original';
        $assertSensitiveHandle = (new ReflectionClass(AtomicJson::class))->getMethod('assertSensitiveHandle');
        try {
            jobExpect(flock($jobsLockHandle, LOCK_EX), 'unable to lock job fixture for post-flock identity test');
            jobExpect(rename($jobsLockPath, $jobsLockMoved), 'unable to replace job lock after flock');
            jobExpect(file_put_contents($jobsLockPath, 'replacement') !== false, 'unable to create replacement job lock');
            chmod($jobsLockPath, 0o600);
            jobFails(
                static fn() => $assertSensitiveHandle->invoke(
                    null,
                    $jobsLockHandle,
                    $jobsLockPath,
                    'lock',
                ),
                'post-flock job lock path replacement was accepted',
            );
        } finally {
            flock($jobsLockHandle, LOCK_UN);
            fclose($jobsLockHandle);
            if (is_file($jobsLockPath) || is_link($jobsLockPath)) {
                unlink($jobsLockPath);
            }
            if (file_exists($jobsLockMoved)) {
                jobExpect(rename($jobsLockMoved, $jobsLockPath), 'unable to restore job lock after post-flock test');
            }
        }
        jobExpect(hash_equals($jobsOriginalHash, hash_file('sha256', $jobsPath)), 'post-flock job lock rejection changed state');

        $jobsTampered = json_decode($jobsOriginal, true, 32, JSON_THROW_ON_ERROR);
        $jobsTampered['jobs'][$created['task_id']]['mappings'][0]['target']['group'] = 'Bad/Path';
        file_put_contents($jobsPath, json_encode(
            $jobsTampered,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n");
        chmod($jobsPath, 0o600);
        jobFails(
            static fn() => (new JobStore())->list(),
            'persistence layer accepted a stored invalid category path segment',
        );
        file_put_contents($jobsPath, $jobsOriginal);
        chmod($jobsPath, 0o600);

        $storeRaw = new JobStore();
        jobFails(
            static fn() => $storeRaw->update(
                $created['task_id'],
                $completed['revision'],
                static function (array $job): array {
                    $job['state'] = JobStore::STATE_QUEUED_ANALYSIS;
                    $job['phase'] = 'analysis';
                    $job['revision']++;
                    $job['updated_at'] = gmdate('c');
                    return $job;
                },
            ),
            'persistence layer accepted an illegal terminal transition',
        );
        jobFails(
            static fn() => $storeRaw->create([
                'task_id' => str_repeat('f', 48),
                'source_id' => 99,
                'source_alias' => 'bad',
                'source_fingerprint' => str_repeat('a', 64),
                'state' => JobStore::STATE_QUEUED_ANALYSIS,
                'phase' => 'analysis',
                'revision' => 1,
                'created_at' => gmdate('c'),
                'updated_at' => gmdate('c'),
                'snapshot' => null,
                'categories' => [],
                'counts' => ['items' => 0, 'categories' => 0, 'high' => 0, 'low' => 0],
                'progress' => ['total' => 0, 'processed' => 0, 'succeeded' => 0, 'failed' => 0, 'skipped' => 0],
                'premium_percent' => null,
                'mappings' => [],
                'error_code' => null,
                'last_action' => 'create',
                'merchant_key' => 'must fail closed',
            ]),
            'job state accepted an unknown sensitive field',
        );

        $legacy = json_decode($jobsOriginal, true, 32, JSON_THROW_ON_ERROR);
        writeJobHistoryState($jobsPath, $legacy['jobs'], null, 1);
        jobExpect(count((new JobStore())->list()) === 3, 'schema 1 job history did not remain readable');

        $activeId = substr(hash('sha256', 'history-active'), 0, 48);
        $activeSource = 7000;
        $history = [
            $activeId => jobHistoryRecord(
                $activeId,
                $activeSource,
                JobStore::STATE_QUEUED_ANALYSIS,
                '2019-01-01T00:00:00+00:00',
            ),
        ];
        $oldestTerminal = '';
        for ($index = 0; $index < 63; $index++) {
            $taskId = substr(hash('sha256', 'history-terminal-' . $index), 0, 48);
            if ($index === 0) {
                $oldestTerminal = $taskId;
            }
            $history[$taskId] = jobHistoryRecord(
                $taskId,
                7100 + $index,
                JobStore::STATE_COMPLETED,
                gmdate(DATE_ATOM, 1577836800 + $index),
            );
        }
        writeJobHistoryState($jobsPath, $history);
        $sameSourceHash = hash_file('sha256', $jobsPath);
        jobFails(
            static fn() => (new JobService())->createAnalysis(
                $activeSource,
                '活动货源不能被淘汰',
                hash('sha256', 'different-active-fingerprint'),
            ),
            'same-source active task triggered history retirement',
        );
        jobExpect(hash_equals($sameSourceHash, hash_file('sha256', $jobsPath)), 'same-source rejection changed job history');

        $rotated = (new JobService())->createAnalysis(8000, '新货源', hash('sha256', 'new-history-source'));
        $rotatedState = json_decode((string)file_get_contents($jobsPath), true, 32, JSON_THROW_ON_ERROR);
        jobExpect($rotatedState['schema'] === 4 && $rotatedState['snapshot_gc'] === null, 'history rotation did not drain its GC receipt');
        jobExpect(count($rotatedState['jobs']) === 64, 'history rotation did not preserve the 64-job bound');
        jobExpect(isset($rotatedState['jobs'][$activeId]), 'history rotation evicted an active task');
        jobExpect(isset($rotatedState['jobs'][$rotated['task_id']]), 'history rotation lost the new task');
        jobExpect(!isset($rotatedState['jobs'][$oldestTerminal]), 'history rotation did not evict the oldest terminal task');

        // Reproduce a near-1MB state where the oldest terminal row is too small
        // to restore the byte bound but the next terminal row is large enough.
        $smallTerminalId = substr(hash('sha256', 'capacity-small-terminal'), 0, 48);
        $largeTerminalId = substr(hash('sha256', 'capacity-large-terminal'), 0, 48);
        $capacityJobs = [
            $smallTerminalId => jobHistoryRecord(
                $smallTerminalId,
                13000,
                JobStore::STATE_COMPLETED,
                '2018-01-01T00:00:00+00:00',
            ),
            $largeTerminalId => denseJobHistoryRecord(
                $largeTerminalId,
                13001,
                JobStore::STATE_COMPLETED,
                '2018-01-02T00:00:00+00:00',
            ),
        ];
        $newDenseTaskId = '';
        for ($index = 0; $index < 32; $index++) {
            $candidateTaskId = substr(hash('sha256', 'capacity-active-' . $index), 0, 48);
            $beforeCandidate = $capacityJobs;
            $capacityJobs[$candidateTaskId] = denseJobHistoryRecord(
                $candidateTaskId,
                13100 + $index,
                JobStore::STATE_QUEUED_ANALYSIS,
                gmdate(DATE_ATOM, 1514937600 + $index),
            );
            if (encodedJobHistorySize($capacityJobs) > 1048576) {
                $newDenseTaskId = $candidateTaskId;
                $validateState = new ReflectionMethod(JobStore::class, 'validateState');
                $validateState->setAccessible(true);
                $validateState->invoke(new JobStore(), [
                    'schema' => 4,
                    'jobs' => $beforeCandidate,
                    'snapshot_gc' => null,
                ]);
                break;
            }
        }
        jobExpect($newDenseTaskId !== '', 'capacity fixture did not cross the 1MB limit');
        $withoutSmall = $capacityJobs;
        unset($withoutSmall[$smallTerminalId]);
        jobExpect(
            encodedJobHistorySize($withoutSmall, [
                'task_id' => $smallTerminalId,
                'source_fingerprint' => $capacityJobs[$smallTerminalId]['source_fingerprint'],
                'plan_hash' => null,
                'sha256' => null,
            ]) > 1048576,
            'capacity fixture unexpectedly fit after removing the small terminal row',
        );
        $withoutLarge = $capacityJobs;
        unset($withoutLarge[$largeTerminalId]);
        jobExpect(
            encodedJobHistorySize($withoutLarge, [
                'task_id' => $largeTerminalId,
                'source_fingerprint' => $capacityJobs[$largeTerminalId]['source_fingerprint'],
                'plan_hash' => null,
                'sha256' => null,
            ]) <= 1048576,
            'capacity fixture did not fit after removing the large terminal row',
        );
        $retirementCandidate = new ReflectionMethod(JobStore::class, 'retirementCandidate');
        $retirementCandidate->setAccessible(true);
        $selectedTerminal = $retirementCandidate->invoke(
            new JobStore(),
            ['schema' => 4, 'jobs' => $capacityJobs, 'snapshot_gc' => null],
            $newDenseTaskId,
        );
        jobExpect(
            is_array($selectedTerminal) && $selectedTerminal['task_id'] === $largeTerminalId,
            'capacity rotation did not skip the insufficient oldest terminal row',
        );

        $insufficientState = ['schema' => 4, 'jobs' => $capacityJobs, 'snapshot_gc' => null];
        $insufficientState['jobs'][$largeTerminalId]['state'] = JobStore::STATE_QUEUED_ANALYSIS;
        $insufficientBaseline = $insufficientState;
        unset($insufficientBaseline['jobs'][$newDenseTaskId]);
        $validateState->invoke(new JobStore(), $insufficientBaseline);
        try {
            $retirementCandidate->invoke(new JobStore(), $insufficientState, $newDenseTaskId);
            throw new RuntimeException('insufficient terminal row unexpectedly restored capacity');
        } catch (RuntimeException $failure) {
            jobExpect($failure->getMessage() === '任务历史已满，请先完成或取消未完成及可继续的任务，再创建新分析。',
                'insufficient terminal capacity did not provide actionable guidance');
        }

        $allActive = [];
        for ($index = 0; $index < 64; $index++) {
            $taskId = substr(hash('sha256', 'all-active-' . $index), 0, 48);
            $allActive[$taskId] = jobHistoryRecord(
                $taskId,
                9000 + $index,
                JobStore::STATE_QUEUED_ANALYSIS,
                gmdate(DATE_ATOM, 1609459200 + $index),
            );
        }
        writeJobHistoryState($jobsPath, $allActive);
        $allActiveHash = hash_file('sha256', $jobsPath);
        jobFails(
            static fn() => (new JobService())->createAnalysis(10000, '没有终态', hash('sha256', 'no-terminal')),
            'a full active history evicted a non-terminal task',
        );
        jobExpect(hash_equals($allActiveHash, hash_file('sha256', $jobsPath)), 'failed active-only rotation changed job history');

        // The failed label must not make a still-resumable checkpoint disposable.
        $retentionNewId = str_repeat('d', 48);
        $retentionId = array_key_first($allActive);
        $retentionJobs = $allActive;
        $retentionJobs[$retentionId] = array_replace($retentionJobs[$retentionId], [
            'state' => JobStore::STATE_FAILED, 'phase' => 'import',
            'error_code' => 'ITEM_DETAIL_FETCH_FAILED', 'premium_percent' => '10',
            'snapshot' => ['sha256' => str_repeat('a', 64), 'plan_hash' => str_repeat('b', 64),
                'source_fingerprint' => $retentionJobs[$retentionId]['source_fingerprint'], 'item_count' => 2],
            'progress' => ['total' => 2, 'processed' => 1, 'succeeded' => 1, 'failed' => 0, 'skipped' => 0],
        ]);
        $retentionState = ['schema' => 4, 'jobs' => $retentionJobs, 'snapshot_gc' => null];
        $validateState->invoke(new JobStore(), $retentionState);
        $retentionState['jobs'][$retentionNewId] = jobHistoryRecord($retentionNewId, 10001,
            JobStore::STATE_QUEUED_ANALYSIS, '2030-01-01T00:00:00+00:00');
        jobFails(static fn() => $retirementCandidate->invoke(new JobStore(), $retentionState, $retentionNewId),
            'capacity rotation discarded a resumable detail-fetch checkpoint');
        foreach (['ITEM_DETAIL_TRANSPORT_FAILED', 'ITEM_DETAIL_HTTP_RETRYABLE'] as $transientCode) {
            $retentionState['jobs'][$retentionId]['error_code'] = $transientCode;
            jobFails(static fn() => $retirementCandidate->invoke(new JobStore(), $retentionState, $retentionNewId),
                'capacity rotation discarded a typed transient checkpoint');
        }
        $retentionState['jobs'][$retentionId]['state'] = JobStore::STATE_CANCELLED;
        $retentionState['jobs'][$retentionId]['last_action'] = 'cancel';
        $cancelledCandidate = $retirementCandidate->invoke(new JobStore(), $retentionState, $retentionNewId);
        jobExpect($cancelledCandidate['task_id'] === $retentionId, 'explicitly cancelled checkpoint could not retire');

        $boundTask = substr(hash('sha256', 'bound-terminal'), 0, 48);
        $boundFingerprint = hash('sha256', 'history-source-11000');
        $boundPlan = hash('sha256', 'bound-terminal-plan');
        $boundMetadata = (new SnapshotStore())->write(
            $boundTask,
            $boundFingerprint,
            $boundPlan,
            [[
                'code' => 'bound-1',
                'category' => 'AI Chat-GPT',
                'stock' => 1,
                'target' => jobTarget('AI工具', 'GPT'),
            ]],
        );
        $boundSnapshotPath = $fixture . '/extensions/PikaCatalogHub/snapshots/' . $boundTask . '.json';
        $boundHistory = [];
        for ($index = 0; $index < 64; $index++) {
            $taskId = $index === 0
                ? $boundTask
                : substr(hash('sha256', 'bound-history-' . $index), 0, 48);
            $snapshot = $index === 0
                ? [
                    ...$boundMetadata,
                    'sha256' => str_repeat('f', 64),
                ]
                : null;
            $boundHistory[$taskId] = jobHistoryRecord(
                $taskId,
                11000 + $index,
                JobStore::STATE_COMPLETED,
                gmdate(DATE_ATOM, 1640995200 + $index),
                $snapshot,
            );
        }
        writeJobHistoryState($jobsPath, $boundHistory);
        $mismatchedStateHash = hash_file('sha256', $jobsPath);
        $boundSnapshotHash = hash_file('sha256', $boundSnapshotPath);
        jobFails(
            static fn() => (new JobService())->createAnalysis(12000, '错绑快照', hash('sha256', 'mismatched-snapshot')),
            'history rotation accepted a mismatched bound snapshot',
        );
        jobExpect(hash_equals($mismatchedStateHash, hash_file('sha256', $jobsPath)), 'snapshot mismatch changed job history');
        jobExpect(hash_equals($boundSnapshotHash, hash_file('sha256', $boundSnapshotPath)), 'snapshot mismatch deleted the wrong file');

        $boundHistory[$boundTask]['snapshot'] = $boundMetadata;
        writeJobHistoryState($jobsPath, $boundHistory);
        chmod($boundSnapshotPath, 0o644);
        $unsafeStateHash = hash_file('sha256', $jobsPath);
        jobFails(
            static fn() => (new JobService())->createAnalysis(12001, '不安全快照', hash('sha256', 'unsafe-snapshot')),
            'history rotation accepted an unsafe bound snapshot',
        );
        jobExpect(hash_equals($unsafeStateHash, hash_file('sha256', $jobsPath)), 'unsafe snapshot changed job history');
        jobExpect(file_exists($boundSnapshotPath), 'unsafe snapshot was deleted');

        chmod($boundSnapshotPath, 0o600);
        $boundRotated = (new JobService())->createAnalysis(12002, '安全轮转', hash('sha256', 'safe-rotation'));
        $boundRotatedState = json_decode((string)file_get_contents($jobsPath), true, 32, JSON_THROW_ON_ERROR);
        jobExpect(isset($boundRotatedState['jobs'][$boundRotated['task_id']]), 'bound snapshot rotation lost the new task');
        jobExpect(!isset($boundRotatedState['jobs'][$boundTask]), 'bound snapshot terminal was not retired');
        jobExpect($boundRotatedState['snapshot_gc'] === null, 'bound snapshot GC receipt was not drained');
        jobExpect(!file_exists($boundSnapshotPath), 'retired bound snapshot was not deleted');

        $raceTask = substr(hash('sha256', 'snapshot-retire-race'), 0, 48);
        $raceFingerprint = hash('sha256', 'snapshot-retire-race-source');
        $racePlan = hash('sha256', 'snapshot-retire-race-plan');
        $raceStore = new SnapshotStore();
        $raceMetadata = $raceStore->write(
            $raceTask,
            $raceFingerprint,
            $racePlan,
            [[
                'code' => 'race-1',
                'category' => '其他',
                'stock' => 0,
                'target' => jobTarget('其他'),
            ]],
        );
        $racePath = $fixture . '/extensions/PikaCatalogHub/snapshots/' . $raceTask . '.json';
        $raceOriginalPath = $racePath . '.original-test';
        $readRecord = new ReflectionMethod(SnapshotStore::class, 'readRecord');
        $readRecord->setAccessible(true);
        $raceRecord = $readRecord->invoke($raceStore, $raceTask);
        jobExpect(rename($racePath, $raceOriginalPath), 'unable to move original race snapshot');
        jobExpect(
            file_put_contents($racePath, (string)file_get_contents($raceOriginalPath)) !== false
                && chmod($racePath, 0o600),
            'unable to create replacement race snapshot',
        );
        $replacementStat = lstat($racePath);
        jobExpect(
            is_array($replacementStat)
                && ($replacementStat['dev'] !== $raceRecord['dev'] || $replacementStat['ino'] !== $raceRecord['ino']),
            'replacement race snapshot unexpectedly reused the original identity',
        );
        $retireFile = new ReflectionMethod(SnapshotStore::class, 'retireFile');
        $retireFile->setAccessible(true);
        jobFails(
            static fn() => $retireFile->invoke(
                $raceStore,
                $racePath,
                true,
                'replacement-race snapshot',
                $raceRecord['dev'],
                $raceRecord['ino'],
            ),
            'snapshot replacement race deleted an unverified file',
        );
        clearstatcache(true, $racePath);
        $restoredReplacementStat = lstat($racePath);
        jobExpect(
            is_array($restoredReplacementStat)
                && $restoredReplacementStat['dev'] === $replacementStat['dev']
                && $restoredReplacementStat['ino'] === $replacementStat['ino']
                && $restoredReplacementStat['nlink'] === 1,
            'snapshot replacement race did not safely restore the replacement',
        );
        jobExpect(
            glob(dirname($racePath) . '/.retire-' . basename($racePath) . '-*') === [],
            'snapshot replacement race left a tombstone after safe restoration',
        );
        unlink($racePath);
        jobExpect(rename($raceOriginalPath, $racePath), 'unable to restore original race snapshot fixture');
        jobExpect(
            $raceStore->retireBoundTerminal(
                $raceTask,
                $raceFingerprint,
                $racePlan,
                $raceMetadata['sha256'],
                true,
            ),
            'verified race snapshot did not retire after fixture restoration',
        );

        $gcTask = substr(hash('sha256', 'durable-gc'), 0, 48);
        $gcFingerprint = hash('sha256', 'durable-gc-source');
        $gcPlan = hash('sha256', 'durable-gc-plan');
        $gcMetadata = (new SnapshotStore())->write(
            $gcTask,
            $gcFingerprint,
            $gcPlan,
            [[
                'code' => 'gc-1',
                'category' => '其他',
                'stock' => 0,
                'target' => jobTarget('其他'),
            ]],
        );
        $gcPath = $fixture . '/extensions/PikaCatalogHub/snapshots/' . $gcTask . '.json';
        writeJobHistoryState($jobsPath, [], [
            'task_id' => $gcTask,
            'source_fingerprint' => $gcFingerprint,
            'plan_hash' => $gcPlan,
            'sha256' => $gcMetadata['sha256'],
        ]);
        jobExpect((new JobService())->drainSnapshotGc(), 'durable snapshot GC receipt did not drain');
        $drainedState = json_decode((string)file_get_contents($jobsPath), true, 32, JSON_THROW_ON_ERROR);
        jobExpect($drainedState['snapshot_gc'] === null && !file_exists($gcPath), 'durable snapshot GC did not converge');

        $missingGcTask = substr(hash('sha256', 'already-missing-gc'), 0, 48);
        $missingGcFingerprint = hash('sha256', 'already-missing-gc-source');
        $missingGcPlan = hash('sha256', 'already-missing-gc-plan');
        $missingGcMetadata = (new SnapshotStore())->write(
            $missingGcTask,
            $missingGcFingerprint,
            $missingGcPlan,
            [[
                'code' => 'missing-gc-1',
                'category' => '其他',
                'stock' => 0,
                'target' => jobTarget('其他'),
            ]],
        );
        $missingGcPath = $fixture . '/extensions/PikaCatalogHub/snapshots/' . $missingGcTask . '.json';
        writeJobHistoryState($jobsPath, [], [
            'task_id' => $missingGcTask,
            'source_fingerprint' => $missingGcFingerprint,
            'plan_hash' => $missingGcPlan,
            'sha256' => $missingGcMetadata['sha256'],
        ]);
        unlink($missingGcPath);
        jobExpect((new JobService())->drainSnapshotGc(), 'already-missing snapshot GC receipt did not drain');
        $missingGcState = json_decode((string)file_get_contents($jobsPath), true, 32, JSON_THROW_ON_ERROR);
        jobExpect($missingGcState['snapshot_gc'] === null, 'already-missing snapshot GC receipt did not converge');
        jobExpect(!(new JobService())->drainSnapshotGc(), 'cleared snapshot GC receipt was not idempotent');

        $barrierTaskId = substr(hash('sha256', 'snapshot-gc-claim-barrier'), 0, 48);
        $barrierRetiredId = substr(hash('sha256', 'snapshot-gc-retired'), 0, 48);
        $barrierJob = jobHistoryRecord(
            $barrierTaskId,
            15000,
            JobStore::STATE_QUEUED_ANALYSIS,
            '2024-01-01T00:00:00+00:00',
        );
        writeJobHistoryState($jobsPath, [$barrierTaskId => $barrierJob], [
            'task_id' => $barrierRetiredId,
            'source_fingerprint' => hash('sha256', 'snapshot-gc-retired-source'),
            'plan_hash' => null,
            'sha256' => null,
        ]);
        $barrierStateHash = hash_file('sha256', $jobsPath);
        jobFails(
            static fn() => (new JobService())->beginWork($barrierTaskId, 1),
            'worker claim crossed a pending snapshot GC receipt',
        );
        jobExpect(
            hash_equals($barrierStateHash, hash_file('sha256', $jobsPath)),
            'failed worker claim changed state while snapshot GC was pending',
        );

        PathGuard::$root = $fixture . '/item-failure-state';
        mkdir(PathGuard::$root, 0o700);
        mkdir(PathGuard::$root . '/site', 0o700);
        $failureService = new JobService();
        $failureTask = $failureService->createAnalysis(18000, '失败状态测试', hash('sha256', 'failure-state-source'));
        jobExpect($failureTask['item_failures'] === [] && !$failureTask['can_resume'] && !$failureTask['can_cancel'],
            'new API failure-list/control fields are incorrect');
        $running = $failureService->beginWork($failureTask['task_id'], $failureTask['revision']);
        $target = jobTarget('其他');
        $prepareImportTask = static function (int $sourceId, int $total, string $label) use (
            $failureService,
            $target,
        ): array {
            $task = $failureService->createAnalysis(
                $sourceId,
                $label,
                hash('sha256', 'failure-state-source-' . $sourceId),
            );
            $runningTask = $failureService->beginWork($task['task_id'], $task['revision']);
            $items = [];
            for ($index = 0; $index < $total; $index++) {
                $items[] = ['code'=>(string)$index, 'category'=>'测试分类', 'stock'=>1, 'target'=>$target];
            }
            $analyzed = $failureService->storeAnalysisData(
                $task['task_id'],
                $runningTask['revision'],
                hash('sha256', 'failure-state-plan-' . $sourceId),
                $items,
                [['name'=>'测试分类', 'count'=>$total, 'target'=>$target, 'confidence'=>'high']],
            );
            $confirmed = $failureService->confirmImport(
                $task['task_id'],
                $analyzed['revision'],
                $analyzed['snapshot']['plan_hash'],
                10,
                [['source_category'=>'测试分类', 'target'=>$target, 'confidence'=>'high']],
            );
            return $failureService->beginWork($task['task_id'], $confirmed['revision']);
        };
        $failureItems = [];
        for ($index = 0; $index < 3; $index++) {
            $failureItems[] = ['code'=>(string)$index, 'category'=>'测试分类', 'stock'=>1, 'target'=>$target];
        }
        $analyzedFailure = $failureService->storeAnalysisData($failureTask['task_id'], $running['revision'],
            hash('sha256', 'failure-state-plan'), $failureItems,
            [['name'=>'测试分类', 'count'=>3, 'target'=>$target, 'confidence'=>'high']]);
        $confirmedFailure = $failureService->confirmImport($failureTask['task_id'], $analyzedFailure['revision'],
            $analyzedFailure['snapshot']['plan_hash'], 10,
            [['source_category'=>'测试分类', 'target'=>$target, 'confidence'=>'high']]);
        $runningFailure = $failureService->beginWork($failureTask['task_id'], $confirmedFailure['revision']);
        $oneFailure = [['index'=>0,'code'=>CommodityImportFailure::DETAIL_ITEM_UNAVAILABLE,'attempts'=>1]];
        $failureProgress = ['total'=>3,'processed'=>1,'succeeded'=>0,'failed'=>1,'skipped'=>0];
        $checkpoint = $failureService->checkpoint($failureTask['task_id'], $runningFailure['revision'], $failureProgress, $oneFailure);
        $failurePath = PathGuard::$root . '/extensions/PikaCatalogHub/jobs/jobs.json';
        $failureBytes = (string)file_get_contents($failurePath);
        jobExpect(json_decode($failureBytes, true, 32, JSON_THROW_ON_ERROR)['schema'] === 4, 'new recovery state was stored under a legacy schema');
        $failureStore = new JobStore();
        $rawCheckpoint = $failureStore->get($failureTask['task_id']);
        $invalidChanges = [
            ['item_failures'=>[]],
            ['item_failures'=>[['index'=>0,'code'=>CommodityImportFailure::DETAIL_HTTP_RETRYABLE,'attempts'=>1]]],
            ['snapshot'=>array_replace($rawCheckpoint['snapshot'], ['sha256'=>str_repeat('c',64)])],
            ['state'=>JobStore::STATE_COMPLETED],
            ['state'=>JobStore::STATE_FAILED,'error_code'=>'IMPORT_FINISHED_WITH_ISSUES'],
            ['state'=>JobStore::STATE_FAILED,'error_code'=>'IMPORT_ITEM_FAILURE_LIMIT'],
        ];
        foreach ([
            ['index'=>0,'code'=>CommodityImportFailure::ITEM_DATA_INVALID,'attempts'=>0],
            ['index'=>2,'code'=>CommodityImportFailure::ITEM_DATA_INVALID,'attempts'=>0],
            ['index'=>1,'code'=>'ITEM_IMPORT_FAILED','attempts'=>0],
            ['index'=>1,'code'=>CommodityImportFailure::ITEM_DATA_INVALID,'attempts'=>4],
            ['index'=>1,'code'=>CommodityImportFailure::ITEM_DATA_INVALID,'attempts'=>0,'response'=>'TOPSECRET'],
        ] as $badFailure) {
            $invalidChanges[] = ['progress'=>['total'=>3,'processed'=>2,'succeeded'=>0,'failed'=>2,'skipped'=>0],
                'item_failures'=>[$oneFailure[0],$badFailure]];
        }
        foreach ($invalidChanges as $changes) {
            jobFails(static fn() => $failureStore->update($checkpoint['task_id'], $checkpoint['revision'],
                static fn(array $job): array => array_replace($job, $changes, ['revision'=>$job['revision']+1])),
                'invalid item failure mutation bypassed storage validation');
            jobExpect(file_get_contents($failurePath) === $failureBytes, 'rejected failure mutation changed durable state');
        }
        jobFails(static fn() => $failureService->checkpoint($checkpoint['task_id'], $checkpoint['revision'],
            ['total'=>3,'processed'=>2,'succeeded'=>0,'failed'=>2,'skipped'=>0]), 'failed counter advanced without a matching record');
        $advanced = $failureService->checkpoint($checkpoint['task_id'], $checkpoint['revision'],
            ['total'=>3,'processed'=>2,'succeeded'=>1,'failed'=>1,'skipped'=>0]);
        jobExpect($advanced['item_failures'] === $oneFailure, 'ordinary checkpoints removed prior failures');
        $finishedIssues = $failureService->checkpoint($advanced['task_id'], $advanced['revision'],
            ['total'=>3,'processed'=>3,'succeeded'=>2,'failed'=>1,'skipped'=>0]);
        jobExpect($finishedIssues['state'] === JobStore::STATE_FAILED
            && $finishedIssues['error_code'] === 'IMPORT_FINISHED_WITH_ISSUES'
            && $finishedIssues['item_failures'] === $oneFailure && !$finishedIssues['can_resume']
            && $finishedIssues['can_retry_failed'] && $finishedIssues['can_cancel'],
            'finished issue state lost its ledger or bounded retry/cancel controls');
        jobFails(static fn() => $failureService->complete($finishedIssues['task_id'], $finishedIssues['revision']),
            'complete allowed nonzero failed count');

        $retryQueued = $failureService->control(
            $finishedIssues['task_id'], $finishedIssues['revision'], 'retry_failed',
        );
        $retryDuplicate = $failureService->control(
            $finishedIssues['task_id'], $finishedIssues['revision'], 'retry_failed',
        );
        jobExpect($retryDuplicate === $retryQueued
            && $retryQueued['retry']['indices'] === [0]
            && $retryQueued['retry']['active']
            && $retryQueued['progress'] === $finishedIssues['progress'],
            'retry setup was not idempotent or changed the original scan ledger');
        $retryRunning = $failureService->beginWork($retryQueued['task_id'], $retryQueued['revision']);
        $compatDiagnostic = [
            'index' => 0,
            'diagnostics' => [
                'category' => 'none', 'http_status' => 200, 'curl_code' => 0,
                'elapsed_ms' => 17, 'attempts' => 1, 'mime_category' => 'text_html',
                'mime_count' => 1, 'json_valid' => true, 'mime_compatibility' => true,
            ],
        ];
        $retryFailed = $failureService->checkpointRetry(
            $retryRunning['task_id'], $retryRunning['revision'], 0, 'failed',
            ['index'=>0,'code'=>CommodityImportFailure::ITEM_DATA_INVALID,'attempts'=>1],
            $compatDiagnostic,
        );
        $retryRoundFailed = $failureService->finishRetry($retryFailed['task_id'], $retryFailed['revision']);
        jobExpect($retryRoundFailed['state'] === JobStore::STATE_FAILED
            && $retryRoundFailed['error_code'] === 'IMPORT_FINISHED_WITH_ISSUES'
            && $retryRoundFailed['progress'] === $finishedIssues['progress']
            && $retryRoundFailed['detail_compatibility_count'] === 0
            && $retryRoundFailed['last_detail_diagnostic'] === $compatDiagnostic,
            'failed retry changed the original ledger or counted a compatibility observation as success');
        $secondRetry = $failureService->control(
            $retryRoundFailed['task_id'], $retryRoundFailed['revision'], 'retry_failed',
        );
        $secondRetryRunning = $failureService->beginWork($secondRetry['task_id'], $secondRetry['revision']);
        $retrySettled = $failureService->checkpointRetry(
            $secondRetryRunning['task_id'], $secondRetryRunning['revision'], 0, 'succeeded', null,
            $compatDiagnostic,
        );
        jobExpect($retrySettled['progress'] === ['total'=>3,'processed'=>3,'succeeded'=>3,'failed'=>0,'skipped'=>0]
            && $retrySettled['item_failures'] === []
            && $retrySettled['detail_compatibility_count'] === 1,
            'same-value fresh retry diagnostic was not counted with its successful settlement');
        $retryCompleted = $failureService->finishRetry($retrySettled['task_id'], $retrySettled['revision']);
        jobExpect($retryCompleted['state'] === JobStore::STATE_COMPLETED,
            'fully settled retry did not complete the original task');

        $nullDiagnosticRunning = $prepareImportTask(18001, 1, '无新诊断补处理');
        $nullDiagnosticFailure = $failureService->checkpoint(
            $nullDiagnosticRunning['task_id'],
            $nullDiagnosticRunning['revision'],
            ['total'=>1,'processed'=>1,'succeeded'=>0,'failed'=>1,'skipped'=>0],
            [['index'=>0,'code'=>CommodityImportFailure::ITEM_DATA_INVALID,'attempts'=>1]],
            $compatDiagnostic,
        );
        $nullDiagnosticRetry = $failureService->control(
            $nullDiagnosticFailure['task_id'], $nullDiagnosticFailure['revision'], 'retry_failed',
        );
        $nullDiagnosticRetry = $failureService->beginWork(
            $nullDiagnosticRetry['task_id'], $nullDiagnosticRetry['revision'],
        );
        $nullDiagnosticSettled = $failureService->checkpointRetry(
            $nullDiagnosticRetry['task_id'], $nullDiagnosticRetry['revision'], 0, 'succeeded', null, null,
        );
        jobExpect($nullDiagnosticSettled['progress'] ===
            ['total'=>1,'processed'=>1,'succeeded'=>1,'failed'=>0,'skipped'=>0]
            && $nullDiagnosticSettled['detail_compatibility_count'] === 0
            && $nullDiagnosticSettled['last_detail_diagnostic'] === $compatDiagnostic,
            'local retry settlement reused an old diagnostic as a fresh compatibility observation');
        $failureService->finishRetry($nullDiagnosticSettled['task_id'], $nullDiagnosticSettled['revision']);

        $fuseRunning = $prepareImportTask(18002, 8, '补处理暂停状态');
        $fuseFailures = [];
        for ($index = 0; $index < 5; $index++) {
            $fuseFailures[] = ['index'=>$index,'code'=>CommodityImportFailure::ITEM_DATA_INVALID,'attempts'=>1];
        }
        $fuseStopped = $failureService->checkpoint(
            $fuseRunning['task_id'],
            $fuseRunning['revision'],
            ['total'=>8,'processed'=>5,'succeeded'=>0,'failed'=>5,'skipped'=>0],
            $fuseFailures,
        );
        $fuseRetry = $failureService->control($fuseStopped['task_id'], $fuseStopped['revision'], 'retry_failed');
        $fuseRetry = $failureService->beginWork($fuseRetry['task_id'], $fuseRetry['revision']);
        for ($index = 0; $index < 4; $index++) {
            $fuseRetry = $failureService->checkpointRetry(
                $fuseRetry['task_id'],
                $fuseRetry['revision'],
                $index,
                'failed',
                ['index'=>$index,'code'=>CommodityImportFailure::ITEM_DATA_INVALID,'attempts'=>1],
            );
        }
        $fusePause = $failureService->control($fuseRetry['task_id'], $fuseRetry['revision'], 'pause');
        $fusePaused = $failureService->checkpointRetry(
            $fusePause['task_id'],
            $fusePause['revision'],
            4,
            'failed',
            ['index'=>4,'code'=>CommodityImportFailure::ITEM_DATA_INVALID,'attempts'=>1],
        );
        jobExpect($fusePaused['state'] === JobStore::STATE_PAUSED
            && $fusePaused['error_code'] === null
            && $fusePaused['retry']['cursor'] === 5
            && $fusePaused['retry']['failed'] === 5
            && $fusePaused['retry']['consecutive_failed'] === 5
            && !$fusePaused['retry']['halted']
            && $fusePaused['can_resume'] && !$fusePaused['can_retry_failed']
            && $fusePaused['progress'] === $fuseStopped['progress'],
            'pause collision lost the fifth retry settlement or incorrectly halted a bounded round');
        $fuseBytes = (string)file_get_contents($failurePath);
        jobFails(static fn() => $failureService->control(
            $fusePaused['task_id'], $fusePaused['revision'], 'retry_failed',
        ), 'a completed paused retry acquired a new-round control without the original stop gate');
        jobExpect(file_get_contents($failurePath) === $fuseBytes, 'rejected new round changed durable state');
        $resumedFuse = $failureService->control(
            $fusePaused['task_id'], $fusePaused['revision'], 'resume',
        );
        jobExpect($resumedFuse['retry'] === $fusePaused['retry']
            && $resumedFuse['progress'] === $fusePaused['progress'],
            'ordinary paused resume reset the completed retry counters');
        $resumedFuse = $failureService->beginWork($resumedFuse['task_id'], $resumedFuse['revision']);
        $originalScanStopped = $failureService->fail(
            $resumedFuse['task_id'], $resumedFuse['revision'], 'IMPORT_ITEM_FAILURE_LIMIT',
        );
        $newFuseRound = $failureService->control(
            $originalScanStopped['task_id'], $originalScanStopped['revision'], 'retry_failed',
        );
        jobExpect($newFuseRound['retry']['indices'] === [0,1,2,3,4]
            && $newFuseRound['retry']['cursor'] === 0 && $newFuseRound['retry']['failed'] === 0
            && $newFuseRound['progress'] === $fuseStopped['progress'],
            'a completed round could not start a fresh bounded round after its original stop gate');
        $cancelledFuse = $failureService->control(
            $newFuseRound['task_id'], $newFuseRound['revision'], 'cancel',
        );
        jobExpect($cancelledFuse['state'] === JobStore::STATE_CANCELLED
            && !$cancelledFuse['can_resume'] && !$cancelledFuse['can_retry_failed'],
            'cancelled retry round remained resumable');

        // Schema-4 legacy halts are continued in place, never rebuilt from the unresolved list.
        foreach ([JobStore::STATE_FAILED, JobStore::STATE_PAUSED] as $legacyState) {
            $legacyRunning = $prepareImportTask(
                $legacyState === JobStore::STATE_FAILED ? 18020 : 18021, 30, '旧补处理续查',
            );
            $legacyIndices = range(0, 26, 2);
            $legacyFailures = array_map(static fn(int $index): array =>
                ['index'=>$index,'code'=>CommodityImportFailure::DETAIL_ITEM_UNAVAILABLE,'attempts'=>1],
                $legacyIndices);
            $legacyStopped = $failureService->checkpoint(
                $legacyRunning['task_id'], $legacyRunning['revision'],
                ['total'=>30,'processed'=>30,'succeeded'=>16,'failed'=>14,'skipped'=>0], $legacyFailures,
            );
            $legacyRaw = (new JobStore())->get($legacyStopped['task_id']);
            $legacyRaw['state'] = $legacyState;
            $legacyRaw['error_code'] = 'IMPORT_ITEM_FAILURE_LIMIT';
            $legacyRaw['last_action'] = $legacyState === JobStore::STATE_PAUSED ? 'pause' : 'retry_failed';
            $legacyRaw['retry'] = [
                'origin_error_code'=>'IMPORT_FINISHED_WITH_ISSUES', 'indices'=>$legacyIndices,
                'cursor'=>5, 'succeeded'=>0, 'skipped'=>0, 'failed'=>5,
                'consecutive_failed'=>5, 'halted'=>true,
            ];
            AtomicJson::update($failurePath, [], static function (array $state) use ($legacyRaw): array {
                $state['jobs'][$legacyRaw['task_id']] = $legacyRaw;
                return $state;
            });
            $legacyBefore = $failureService->get($legacyRaw['task_id']);
            jobExpect($legacyBefore['can_retry_failed'] && !$legacyBefore['can_resume'],
                'legacy halted round lost its exact explicit continuation control');
            $legacyBytes = file_get_contents($failurePath);
            jobFails(static fn() => $failureService->control(
                $legacyBefore['task_id'], $legacyBefore['revision'], 'resume',
            ), 'ordinary resume bypassed a legacy halt');
            foreach ([['cursor'=>0,'failed'=>0,'consecutive_failed'=>0],
                ['indices'=>array_slice($legacyIndices, 5),'cursor'=>0,'failed'=>0,'consecutive_failed'=>0]] as $reset) {
                jobFails(static fn() => $failureStore->update($legacyBefore['task_id'], $legacyBefore['revision'],
                    static fn(array $job): array => array_replace($job, [
                        'state'=>JobStore::STATE_QUEUED_IMPORT, 'error_code'=>null, 'last_action'=>'retry_failed',
                        'retry'=>array_replace($job['retry'], $reset, ['halted'=>false]),
                        'revision'=>$job['revision']+1,
                    ])), 'legacy retry continuation accepted a replaced list or reset counters');
            }
            jobExpect(file_get_contents($failurePath) === $legacyBytes,
                'rejected legacy continuation changed durable state');
            $legacyQueued = $failureService->control(
                $legacyBefore['task_id'], $legacyBefore['revision'], 'retry_failed',
            );
            $legacyDuplicate = $failureService->control(
                $legacyBefore['task_id'], $legacyBefore['revision'], 'retry_failed',
            );
            $expectedLegacyRetry = array_replace($legacyBefore['retry'], ['halted'=>false,'active'=>true]);
            jobExpect($legacyQueued === $legacyDuplicate && $legacyQueued['retry'] === $expectedLegacyRetry
                && $legacyQueued['progress'] === $legacyBefore['progress']
                && $legacyQueued['item_failures'] === $legacyBefore['item_failures']
                && $legacyQueued['snapshot'] === $legacyBefore['snapshot'],
                'legacy continuation reset the round, replaced its binding, or duplicated the control');
            $legacyContinued = $failureService->beginWork($legacyQueued['task_id'], $legacyQueued['revision']);
            jobFails(static fn() => $failureService->checkpointRetry(
                $legacyContinued['task_id'], $legacyContinued['revision'], $legacyIndices[0], 'succeeded',
            ), 'legacy continuation accepted an already visited head index');
            foreach (array_slice($legacyIndices, 5) as $index) {
                $failed = $index === $legacyIndices[13];
                $legacyContinued = $failureService->checkpointRetry(
                    $legacyContinued['task_id'], $legacyContinued['revision'], $index,
                    $failed ? 'failed' : 'succeeded',
                    $failed ? ['index'=>$index,'code'=>CommodityImportFailure::DETAIL_JSON_INVALID,'attempts'=>1] : null,
                );
            }
            $legacyFinished = $failureService->finishRetry($legacyContinued['task_id'], $legacyContinued['revision']);
            jobExpect($legacyFinished['error_code'] === 'IMPORT_FINISHED_WITH_ISSUES'
                && $legacyFinished['retry']['indices'] === $legacyIndices
                && $legacyFinished['retry']['cursor'] === 14 && $legacyFinished['retry']['failed'] === 6
                && $legacyFinished['retry']['succeeded'] === 8 && !$legacyFinished['retry']['halted']
                && $legacyFinished['progress'] === ['total'=>30,'processed'=>30,'succeeded'=>24,'failed'=>6,'skipped'=>0]
                && array_slice($legacyFinished['item_failures'], 0, 5) === array_slice($legacyFailures, 0, 5),
                'legacy round replayed its head, skipped its tail, or corrupted original progress');
        }

        // Even a completed prefix cannot expand a frozen round beyond one hundred indexes.
        $oversizedRunning = $prepareImportTask(18022, 102, '补处理清单上限');
        $hundredFailures = array_map(static fn(int $index): array =>
            ['index'=>$index,'code'=>CommodityImportFailure::ITEM_DATA_INVALID,'attempts'=>0], range(1, 100));
        $oversizedStopped = $failureService->checkpoint(
            $oversizedRunning['task_id'], $oversizedRunning['revision'],
            ['total'=>102,'processed'=>102,'succeeded'=>2,'failed'=>100,'skipped'=>0], $hundredFailures,
        );
        $oversizedRaw = (new JobStore())->get($oversizedStopped['task_id']);
        $oversizedRaw['retry'] = [
            'origin_error_code'=>'IMPORT_ITEM_FAILURE_LIMIT', 'indices'=>range(0, 100),
            'cursor'=>1, 'succeeded'=>1, 'skipped'=>0, 'failed'=>0, 'consecutive_failed'=>0, 'halted'=>false,
        ];
        $beforeOversized = file_get_contents($failurePath);
        AtomicJson::update($failurePath, [], static function (array $state) use ($oversizedRaw): array {
            $state['jobs'][$oversizedRaw['task_id']] = $oversizedRaw;
            return $state;
        });
        jobFails(static fn() => (new JobStore())->get($oversizedRaw['task_id']),
            'a frozen retry round accepted one hundred and one indexes');
        $restoreOversized = json_decode($beforeOversized, true, 64, JSON_THROW_ON_ERROR);
        AtomicJson::update($failurePath, [], static fn(array $state): array => $restoreOversized);

        // Persist only the response structure observed in the current valid JSON response.
        $shapeRunning = $prepareImportTask(18023, 2, '详情结构诊断状态');
        $shapeFailure = ['index'=>0,'code'=>CommodityImportFailure::DETAIL_ITEM_UNAVAILABLE,'attempts'=>1];
        $shapeStopped = $failureService->checkpoint($shapeRunning['task_id'], $shapeRunning['revision'],
            ['total'=>2,'processed'=>2,'succeeded'=>1,'failed'=>1,'skipped'=>0], [$shapeFailure]);
        $shapeQueued = $failureService->control($shapeStopped['task_id'], $shapeStopped['revision'], 'retry_failed');
        $shapeRetry = $failureService->beginWork($shapeQueued['task_id'], $shapeQueued['revision']);
        $shapeSummary = [
            'business_code'=>200, 'data_type'=>'list', 'data_count'=>1,
            'first_children_type'=>'empty_array_or_object', 'first_children_count'=>0, 'first_item_type'=>'missing',
        ];
        $shapeDiagnostic = [
            'category'=>'item_unavailable', 'http_status'=>200, 'curl_code'=>0, 'elapsed_ms'=>5, 'attempts'=>1,
            'mime_category'=>'application_json', 'mime_count'=>1, 'json_valid'=>true, 'mime_compatibility'=>false,
            'response_structure'=>$shapeSummary,
        ];
        $badShapeDiagnostics = [];
        foreach ([
            $shapeSummary + ['raw'=>'TOPSECRET'],
            ['data_count'=>1],
            array_replace($shapeSummary, ['data_type'=>'list','data_count'=>0]),
            array_replace($shapeSummary, ['first_children_count'=>1]),
            array_replace($shapeSummary, ['first_item_type'=>'object']),
            array_replace($shapeSummary, ['first_children_type'=>'list','first_children_count'=>1,'first_item_type'=>'missing']),
            array_replace($shapeSummary, ['data_type'=>'empty_array_or_object','data_count'=>0]),
        ] as $badShape) {
            $badShapeDiagnostics[] = array_replace($shapeDiagnostic, ['response_structure'=>$badShape]);
        }
        $badShapeDiagnostics[] = array_replace($shapeDiagnostic, ['json_valid'=>false]);
        $badShapeDiagnostics[] = array_replace($shapeDiagnostic, [
            'category'=>'json','json_valid'=>false,'json_error_code'=>JSON_ERROR_SYNTAX,'json_error'=>'syntax',
        ]);
        $badShapeDiagnostics[] = array_replace($shapeDiagnostic, ['http_status'=>403]);
        $badShapeDiagnostics[] = $shapeDiagnostic + ['raw'=>'TOPSECRET'];
        $shapeBytes = file_get_contents($failurePath);
        foreach ($badShapeDiagnostics as $badShapeDiagnostic) {
            jobFails(static fn() => $failureService->checkpointRetry(
                $shapeRetry['task_id'], $shapeRetry['revision'], 0, 'failed', $shapeFailure,
                ['index'=>0,'diagnostics'=>$badShapeDiagnostic],
            ), 'retry persisted an unsafe, unobserved or inconsistent response structure');
            jobExpect(file_get_contents($failurePath) === $shapeBytes,
                'rejected response structure changed the durable retry cursor or diagnostic');
        }
        $shapeCheckpoint = $failureService->checkpointRetry(
            $shapeRetry['task_id'], $shapeRetry['revision'], 0, 'failed', $shapeFailure,
            ['index'=>0,'diagnostics'=>$shapeDiagnostic],
        );
        $shapeReloaded = (new JobService())->get($shapeCheckpoint['task_id']);
        jobExpect($shapeReloaded['last_detail_diagnostic'] === ['index'=>0,'diagnostics'=>$shapeDiagnostic]
            && $shapeReloaded['retry']['cursor'] === 1 && $shapeReloaded['retry']['failed'] === 1
            && $shapeReloaded['progress'] === $shapeStopped['progress']
            && !str_contains((string)file_get_contents($failurePath), 'TOPSECRET'),
            'valid current response structure did not round-trip independently of the original scan counters');
        $failureService->finishRetry($shapeCheckpoint['task_id'], $shapeCheckpoint['revision']);

        // Old JSON observations grant only an explicit, revision-bound scan resume.
        $jsonRunning = $prepareImportTask(18010, 4, 'JSON 检查点恢复');
        $jsonProgress = ['total'=>4,'processed'=>2,'succeeded'=>1,'failed'=>1,'skipped'=>0];
        $jsonFailures = [['index'=>0,'code'=>CommodityImportFailure::DETAIL_ITEM_UNAVAILABLE,'attempts'=>1]];
        $jsonCheckpoint = $failureService->checkpoint($jsonRunning['task_id'], $jsonRunning['revision'],
            $jsonProgress, $jsonFailures);
        $oldJson = ['category'=>'json','http_status'=>200,'curl_code'=>0,'elapsed_ms'=>148,'attempts'=>1,
            'mime_category'=>'text_html','mime_count'=>1,'json_valid'=>false,'mime_compatibility'=>true];
        $jsonFailed = $failureService->fail($jsonCheckpoint['task_id'], $jsonCheckpoint['revision'],
            CommodityImportFailure::DETAIL_RESPONSE_INVALID, ['index'=>2,'diagnostics'=>$oldJson]);
        $jsonRaw = (new JobStore())->get($jsonFailed['task_id']);
        $jsonBytes = file_get_contents($failurePath);
        jobExpect($jsonFailed['can_resume'] && $jsonFailed['can_cancel'] && !$jsonFailed['can_retry_failed']
            && $jsonFailed['last_detail_diagnostic']['diagnostics'] === $oldJson
            && (new JobService())->get($jsonFailed['task_id'])['state'] === JobStore::STATE_FAILED
            && file_get_contents($failurePath) === $jsonBytes,
            'reading an eligible legacy JSON task changed state or fabricated a parse reason');
        foreach ([['category','schema'], ['http_status',403], ['curl_code',28], ['json_valid',true],
            ['attempts',0], ['attempts',4]] as [$field, $value]) {
            $invalid = $jsonRaw;
            $invalid['last_detail_diagnostic']['diagnostics'][$field] = $value;
            jobExpect(!JobStore::canResumeFailedImport($invalid), 'non-JSON proof acquired resume: ' . $field);
        }
        foreach (['index','missing','retry','phase','snapshot','finished'] as $case) {
            $invalid = $jsonRaw;
            if ($case === 'index') { $invalid['last_detail_diagnostic']['index'] = 1; }
            if ($case === 'missing') { $invalid['last_detail_diagnostic'] = null; }
            if ($case === 'retry') { $invalid['retry'] = ['cursor'=>0,'indices'=>[0],'halted'=>false]; }
            if ($case === 'phase') { $invalid['phase'] = 'analysis'; }
            if ($case === 'snapshot') { $invalid['snapshot'] = null; }
            if ($case === 'finished') { $invalid['progress']['processed'] = 4; }
            jobExpect(!JobStore::canResumeFailedImport($invalid), 'invalid JSON checkpoint acquired resume: ' . $case);
        }
        jobFails(static fn() => $failureService->control($jsonFailed['task_id'], $jsonFailed['revision'] - 1, 'resume'),
            'stale revision resumed a legacy JSON task');
        jobFails(static fn() => $failureService->assertSourceFingerprint($jsonFailed['task_id'], hash('sha256', 'changed-source')),
            'changed source fingerprint matched a legacy JSON task');
        $jsonSnapshotPath = PathGuard::$root . '/extensions/PikaCatalogHub/snapshots/' . $jsonFailed['task_id'] . '.json';
        chmod($jsonSnapshotPath, 0o640);
        jobFails(static fn() => $failureService->control($jsonFailed['task_id'], $jsonFailed['revision'], 'resume'),
            'unsafe snapshot resumed a legacy JSON task');
        chmod($jsonSnapshotPath, 0o600);
        jobExpect(file_get_contents($failurePath) === $jsonBytes, 'denied JSON resume changed durable state');
        $jsonResumed = $failureService->control($jsonFailed['task_id'], $jsonFailed['revision'], 'resume');
        $jsonDuplicate = $failureService->control($jsonFailed['task_id'], $jsonFailed['revision'], 'resume');
        jobExpect($jsonDuplicate['revision'] === $jsonResumed['revision'], 'duplicate resume queued a second attempt');
        $resumedJsonRaw = (new JobStore())->get($jsonFailed['task_id']);
        foreach (['snapshot','progress','item_failures','premium_percent','mappings','source_fingerprint',
            'last_detail_diagnostic','detail_resume_authorization','retry'] as $field) {
            jobExpect($jsonRaw[$field] === $resumedJsonRaw[$field], 'JSON resume changed original ' . $field);
        }
        $jsonClaim = $failureService->beginWork($jsonResumed['task_id'], $jsonResumed['revision']);
        $newJson = $oldJson + ['json_error_code'=>JSON_ERROR_SYNTAX,'json_error'=>'syntax'];
        $beforeJsonWrite = file_get_contents($failurePath);
        foreach (['missing_code','missing_kind','bad_kind','bad_code','unsafe_extra','success','schema'] as $case) {
            $invalid = $newJson;
            if ($case === 'missing_code') { unset($invalid['json_error_code']); }
            if ($case === 'missing_kind') { unset($invalid['json_error']); }
            if ($case === 'bad_kind') { $invalid['json_error'] = 'utf8'; }
            if ($case === 'bad_code') { $invalid['json_error_code'] = 999; }
            if ($case === 'unsafe_extra') { $invalid['body'] = 'PRIVATE-NOT-FOR-STATE'; }
            if ($case === 'success') { $invalid['json_valid'] = true; }
            if ($case === 'schema') { $invalid['category'] = 'schema'; }
            jobFails(static fn() => $failureService->fail($jsonClaim['task_id'], $jsonClaim['revision'],
                CommodityImportFailure::DETAIL_RESPONSE_INVALID, ['index'=>2,'diagnostics'=>$invalid]),
                'invalid JSON diagnostic accepted by service: ' . $case);
            $invalidJob = $jsonRaw;
            $invalidJob['last_detail_diagnostic']['diagnostics'] = $invalid;
            writeJobHistoryState($failurePath, [$invalidJob['task_id']=>$invalidJob]);
            jobFails(static fn() => (new JobStore())->list(), 'invalid JSON diagnostic accepted by store: ' . $case);
            file_put_contents($failurePath, $beforeJsonWrite);
        }
        jobExpect(file_get_contents($failurePath) === $beforeJsonWrite, 'rejected diagnostics mutated original task');
        $jsonNextFailures = [...$jsonFailures, ['index'=>2,'code'=>CommodityImportFailure::DETAIL_JSON_INVALID,'attempts'=>1]];
        $jsonNext = $failureService->checkpoint($jsonClaim['task_id'], $jsonClaim['revision'],
            ['total'=>4,'processed'=>3,'succeeded'=>1,'failed'=>2,'skipped'=>0], $jsonNextFailures,
            ['index'=>2,'diagnostics'=>$newJson]);
        jobExpect((new JobStore())->get($jsonNext['task_id'])['last_detail_diagnostic']['diagnostics'] === $newJson
            && $jsonNext['item_failures'] === $jsonNextFailures,
            'new JSON reason or prior unresolved index did not survive schema 4 round trip');
        $jsonCancel = $failureService->control($jsonNext['task_id'], $jsonNext['revision'], 'cancel');
        $failureService->checkpoint($jsonCancel['task_id'], $jsonCancel['revision'], $jsonCancel['progress']);

        $legacyRunning = $prepareImportTask(18003, 2, '历史详情恢复授权');
        $legacyCheckpoint = $failureService->checkpoint(
            $legacyRunning['task_id'],
            $legacyRunning['revision'],
            ['total'=>2,'processed'=>1,'succeeded'=>1,'failed'=>0,'skipped'=>0],
        );
        $legacyFailed = $failureService->fail(
            $legacyCheckpoint['task_id'],
            $legacyCheckpoint['revision'],
            CommodityImportFailure::DETAIL_RESPONSE_INVALID,
        );
        jobExpect(!$legacyFailed['can_resume'] && !$legacyFailed['can_cancel'],
            'unverified historical response error received recovery authority');
        $legacyVersionFourRaw = (new JobStore())->get($legacyFailed['task_id']);
        writeJobHistoryState(
            $failurePath,
            [$legacyVersionFourRaw['task_id']=>$legacyVersionFourRaw],
            null,
            3,
        );
        $legacySchemaThreeBytes = (string)file_get_contents($failurePath);
        $legacyRaw = (new JobStore())->get($legacyFailed['task_id']);
        $legacyFailed = (new JobService())->get($legacyFailed['task_id']);
        jobExpect($legacyRaw['state'] === $legacyVersionFourRaw['state']
            && $legacyRaw['error_code'] === $legacyVersionFourRaw['error_code']
            && $legacyRaw['progress'] === $legacyVersionFourRaw['progress']
            && $legacyRaw['premium_percent'] === $legacyVersionFourRaw['premium_percent']
            && $legacyRaw['snapshot'] === $legacyVersionFourRaw['snapshot']
            && $legacyRaw['retry'] === null
            && $legacyRaw['last_detail_diagnostic'] === null
            && $legacyRaw['detail_compatibility_count'] === 0
            && $legacyRaw['detail_resume_authorization'] === null
            && !$legacyFailed['can_resume'] && !$legacyFailed['can_cancel']
            && file_get_contents($failurePath) === $legacySchemaThreeBytes,
            'schema 3 detail failure lost its checkpoint or fabricated recovery authority while reading');
        $legacyEvent = [
            'error_code' => CommodityImportFailure::DETAIL_RESPONSE_INVALID,
            'diagnostics' => [
                'category'=>'content_type', 'http_status'=>200, 'curl_code'=>0, 'elapsed_ms'=>31, 'attempts'=>1,
            ],
        ];
        $freshDiagnostic = [
            'category'=>'none', 'http_status'=>200, 'curl_code'=>0, 'elapsed_ms'=>19, 'attempts'=>1,
            'mime_category'=>'application_json', 'mime_count'=>1, 'json_valid'=>true, 'mime_compatibility'=>false,
        ];
        $legacyEvidence = [
            'task_id' => $legacyRaw['task_id'],
            'task_hash' => substr(hash('sha256', $legacyRaw['task_id']), 0, 16),
            'revision' => $legacyRaw['revision'],
            'index' => 1,
            'snapshot_sha256' => $legacyRaw['snapshot']['sha256'],
            'source_fingerprint' => $legacyRaw['source_fingerprint'],
            'legacy_event' => $legacyEvent,
            'diagnostic' => $freshDiagnostic,
        ];
        $legacyBytes = (string)file_get_contents($failurePath);
        $badEvidence = [];
        $badEvidence[] = array_replace($legacyEvidence, ['task_hash'=>str_repeat('0',16)]);
        $badEvidence[] = array_replace($legacyEvidence, ['index'=>0]);
        $badEvidence[] = array_replace($legacyEvidence, ['snapshot_sha256'=>str_repeat('0',64)]);
        $badLegacyEvent = $legacyEvidence;
        $badLegacyEvent['legacy_event']['diagnostics']['category'] = 'json';
        $badEvidence[] = $badLegacyEvent;
        $badFreshDiagnostic = $legacyEvidence;
        $badFreshDiagnostic['diagnostic']['mime_category'] = 'text_html';
        $badFreshDiagnostic['diagnostic']['mime_compatibility'] = true;
        $badEvidence[] = $badFreshDiagnostic;
        foreach ($badEvidence as $bad) {
            jobFails(static fn() => $failureService->authorizeLegacyDetailResume(
                $legacyFailed['task_id'], $legacyFailed['revision'], $bad,
            ), 'mismatched or conflated legacy recovery evidence was accepted');
            jobExpect(file_get_contents($failurePath) === $legacyBytes,
                'rejected legacy recovery evidence changed durable state');
        }
        $legacyAuthorized = $failureService->authorizeLegacyDetailResume(
            $legacyFailed['task_id'], $legacyFailed['revision'], $legacyEvidence,
        );
        $authorizedRaw = (new JobStore())->get($legacyAuthorized['task_id']);
        jobExpect($legacyAuthorized['error_code'] === CommodityImportFailure::DETAIL_RESPONSE_INVALID
            && $legacyAuthorized['can_resume'] && $legacyAuthorized['can_cancel']
            && $legacyAuthorized['detail_compatibility_count'] === 0
            && $authorizedRaw['detail_resume_authorization']['legacy_event'] === $legacyEvent
            && $authorizedRaw['detail_resume_authorization']['diagnostic'] === $freshDiagnostic
            && $authorizedRaw['detail_resume_authorization']['consumed'] === false,
            'legacy event and fresh diagnostic were conflated or did not grant one exact resume');
        jobFails(static fn() => $failureService->authorizeLegacyDetailResume(
            $legacyFailed['task_id'], $legacyFailed['revision'], $legacyEvidence,
        ), 'stale authorization created a second recovery grant');
        $legacyResumed = $failureService->control(
            $legacyAuthorized['task_id'], $legacyAuthorized['revision'], 'resume',
        );
        $legacyResumedAgain = $failureService->control(
            $legacyAuthorized['task_id'], $legacyAuthorized['revision'], 'resume',
        );
        $resumedRaw = (new JobStore())->get($legacyResumed['task_id']);
        jobExpect($legacyResumedAgain === $legacyResumed
            && $legacyResumed['state'] === JobStore::STATE_QUEUED_IMPORT
            && $resumedRaw['detail_resume_authorization']['consumed'] === true,
            'legacy recovery authorization was not atomically consumed exactly once');
        $legacyRunningAgain = $failureService->beginWork($legacyResumed['task_id'], $legacyResumed['revision']);
        $legacyFailedAgain = $failureService->fail(
            $legacyRunningAgain['task_id'],
            $legacyRunningAgain['revision'],
            CommodityImportFailure::DETAIL_RESPONSE_INVALID,
        );
        jobExpect(!$legacyFailedAgain['can_resume'], 'consumed legacy authorization silently authorized another failure');
        $legacyEvidence['revision'] = $legacyFailedAgain['revision'];
        $legacyReauthorized = $failureService->authorizeLegacyDetailResume(
            $legacyFailedAgain['task_id'], $legacyFailedAgain['revision'], $legacyEvidence,
        );
        $legacyCancelled = $failureService->control(
            $legacyReauthorized['task_id'], $legacyReauthorized['revision'], 'cancel',
        );
        $cancelledLegacyRaw = (new JobStore())->get($legacyCancelled['task_id']);
        jobExpect($legacyCancelled['state'] === JobStore::STATE_CANCELLED
            && $legacyCancelled['error_code'] === CommodityImportFailure::DETAIL_RESPONSE_INVALID
            && !$legacyCancelled['can_resume'] && !$legacyCancelled['can_retry_failed']
            && $cancelledLegacyRaw['detail_resume_authorization']['consumed'] === false,
            'cancel did not preserve legacy failure evidence or remained recoverable');

        // Schema 3 already carried exact item failures. Reading it must preserve
        // that ledger while supplying only the new recovery defaults in memory.
        writeJobHistoryState($failurePath, [$rawCheckpoint['task_id']=>$rawCheckpoint], null, 3);
        $schemaThreeBytes = (string)file_get_contents($failurePath);
        $schemaThreeStore = new JobStore();
        $schemaThreeRaw = $schemaThreeStore->get($rawCheckpoint['task_id']);
        $schemaThreeSummary = (new JobService())->get($rawCheckpoint['task_id']);
        jobExpect($schemaThreeRaw['item_failures'] === $rawCheckpoint['item_failures']
            && $schemaThreeRaw['retry'] === null
            && $schemaThreeRaw['last_detail_diagnostic'] === null
            && $schemaThreeRaw['detail_compatibility_count'] === 0
            && $schemaThreeRaw['detail_resume_authorization'] === null
            && $schemaThreeSummary['item_failures'] === $rawCheckpoint['item_failures']
            && !$schemaThreeSummary['can_retry_failed']
            && file_get_contents($failurePath) === $schemaThreeBytes,
            'schema 3 read lost its failure ledger, fabricated recovery state, or persisted a migration');

        // Legacy aggregates are readable without fabricated item indexes. Their
        // former failed-resume checkpoints retain an explicit cancel/GC exit.
        foreach ([1,2] as $legacySchema) {
            $legacyZero = array_replace($rawCheckpoint, ['state'=>JobStore::STATE_FAILED,
                'error_code'=>CommodityImportFailure::DETAIL_FETCH_FAILED,
                'progress'=>['total'=>3,'processed'=>1,'succeeded'=>1,'failed'=>0,'skipped'=>0]]);
            writeJobHistoryState($failurePath, [$legacyZero['task_id']=>$legacyZero], null, $legacySchema);
            $legacyZeroBytes = (string)file_get_contents($failurePath);
            $legacyZeroSummary = (new JobService())->get($legacyZero['task_id']);
            jobExpect($legacyZeroSummary['item_failures'] === [] && $legacyZeroSummary['can_resume']
                && $legacyZeroSummary['can_cancel'] && (new JobService())->recoverInterrupted() === []
                && file_get_contents($failurePath) === $legacyZeroBytes,
                'legacy zero-failure read changed its explicit-resume policy or persisted a migration');
            foreach ([CommodityImportFailure::DETAIL_FETCH_FAILED, CommodityImportFailure::DETAIL_TRANSPORT_FAILED,
                CommodityImportFailure::DETAIL_HTTP_RETRYABLE] as $legacyCode) {
                $legacyRecord = array_replace($rawCheckpoint, ['state'=>JobStore::STATE_FAILED, 'error_code'=>$legacyCode]);
                writeJobHistoryState($failurePath, [$legacyRecord['task_id']=>$legacyRecord], null, $legacySchema);
                $legacyBytes = (string)file_get_contents($failurePath);
                $legacyService = new JobService();
                $legacySummary = $legacyService->get($legacyRecord['task_id']);
                jobExpect($legacySummary['item_failures'] === null && !$legacySummary['can_resume'] && $legacySummary['can_cancel'],
                    'legacy failed aggregates were fabricated, resumed, or trapped without cancellation');
                jobExpect($legacyService->recoverInterrupted() === [] && file_get_contents($failurePath) === $legacyBytes,
                    'legacy failed task was automatically changed or resumed');
                jobFails(static fn() => $legacyService->control($legacyRecord['task_id'], $legacySummary['revision'], 'resume'),
                    'legacy failures without indexes became resumable');
                jobExpect(file_get_contents($failurePath) === $legacyBytes, 'denied legacy resume migrated state on disk');
                $cancelledLegacy = $legacyService->control($legacyRecord['task_id'], $legacySummary['revision'], 'cancel');
                jobExpect($cancelledLegacy['state'] === JobStore::STATE_CANCELLED && $cancelledLegacy['item_failures'] === null
                    && $cancelledLegacy['progress'] === $legacySummary['progress'] && !$cancelledLegacy['can_cancel'],
                    'legacy cancellation erased or fabricated historical failure detail');
                $retained = [$legacyRecord['task_id']=>array_replace($failureStore->get($legacyRecord['task_id']),
                    ['created_at'=>'2010-01-01T00:00:00+00:00'])];
                for ($index = 0; $index < 64; $index++) {
                    $candidateId = substr(hash('sha256', 'legacy-gc-' . $index), 0, 48);
                    $retained[$candidateId] = jobHistoryRecord($candidateId, 19000+$index, JobStore::STATE_COMPLETED,
                        '2020-01-01T00:00:00+00:00');
                }
                $candidate = (new ReflectionMethod(JobStore::class, 'retirementCandidate'))->invoke($failureStore,
                    ['schema'=>4,'jobs'=>$retained,'snapshot_gc'=>null], $candidateId);
                jobExpect($candidate['task_id'] === $legacyRecord['task_id'], 'explicitly cancelled legacy failure remained GC-protected');
            }
        }
        $missingList = $rawCheckpoint;
        unset(
            $missingList['item_failures'],
            $missingList['retry'],
            $missingList['last_detail_diagnostic'],
            $missingList['detail_compatibility_count'],
            $missingList['detail_resume_authorization'],
        );
        file_put_contents($failurePath, json_encode(['schema'=>3,'jobs'=>[$missingList['task_id']=>$missingList],'snapshot_gc'=>null], JSON_THROW_ON_ERROR));
        jobFails(static fn() => (new JobStore())->list(), 'schema 3 silently fabricated a missing failure list');
        $legacyWithNewFailureList = $rawCheckpoint;
        unset(
            $legacyWithNewFailureList['retry'],
            $legacyWithNewFailureList['last_detail_diagnostic'],
            $legacyWithNewFailureList['detail_compatibility_count'],
            $legacyWithNewFailureList['detail_resume_authorization'],
        );
        file_put_contents($failurePath, json_encode(['schema'=>2,'jobs'=>[$rawCheckpoint['task_id']=>$legacyWithNewFailureList],'snapshot_gc'=>null], JSON_THROW_ON_ERROR));
        jobFails(static fn() => (new JobStore())->list(), 'legacy schema accepted a new-format failure field');

        PathGuard::$root = $fixture . '/mirror-state';
        mkdir(PathGuard::$root, 0o700);
        mkdir(PathGuard::$root . '/site', 0o700);
        $mirrorService = new JobService();
        $mirrorStore = new JobStore();
        $mirrorFingerprint = hash('sha256', 'synthetic-mirror-source');
        $mirrorTarget = static fn(int $parent, int $leaf): array => ['mode'=>'mirror', 'path'=>[
            ['id'=>$parent, 'pid'=>0, 'name'=>'上游父级' . $parent, 'sort'=>0],
            ['id'=>$leaf, 'pid'=>$parent, 'name'=>'同名子分类', 'sort'=>1],
        ]];
        $mirrorItems = [
            ['code'=>'mirror-001', 'category'=>'同名子分类', 'stock'=>2, 'target'=>$mirrorTarget(11, 12)],
            ['code'=>'mirror-002', 'category'=>'同名子分类', 'stock'=>3, 'target'=>$mirrorTarget(21, 22)],
        ];
        $mirrorSuggestion = (new UpstreamCategoryTree())->suggest($mirrorItems);
        $mirrorCreated = $mirrorService->createAnalysis(23001, '只作后台识别', $mirrorFingerprint, 'mirror');
        jobExpect($mirrorCreated['category_mode'] === 'mirror', 'explicit mirror task lost its mode');
        jobFails(static fn() => $mirrorService->createAnalysis(23002, '非法模式', $mirrorFingerprint, 'flatten'),
            'unknown category mode was accepted');
        jobFails(static fn() => $mirrorStore->update($mirrorCreated['task_id'], $mirrorCreated['revision'],
            static fn(array $job): array => array_replace($job, ['category_mode'=>'smart', 'revision'=>$job['revision']+1])),
            'category mode changed after task creation');
        $mirrorActive = $mirrorService->beginWork($mirrorCreated['task_id'], $mirrorCreated['revision']);
        jobFails(static fn() => $mirrorService->storeAnalysisSnapshot($mirrorActive['task_id'], $mirrorActive['revision'],
            $mirrorSuggestion['plan_hash'], [['code'=>'wrong-mode', 'category'=>'智能分类', 'stock'=>1, 'target'=>jobTarget('其他')]]),
            'mirror task accepted a smart snapshot target');
        jobFails(static fn() => $mirrorService->storeAnalysisSnapshot($mirrorActive['task_id'], $mirrorActive['revision'],
            hash('sha256', 'not-the-frozen-mirror-plan'), $mirrorItems), 'mirror snapshot accepted an unrelated plan hash');
        $conflictingItems = $mirrorItems;
        $conflictingItems[1]['target']['path'][0]['id'] = 11;
        $conflictingItems[1]['target']['path'][1]['pid'] = 11;
        jobFails(static fn() => $mirrorService->storeAnalysisSnapshot($mirrorActive['task_id'], $mirrorActive['revision'],
            $mirrorSuggestion['plan_hash'], $conflictingItems), 'mirror snapshot accepted conflicting ancestor identities');
        $mirrorAnalyzed = $mirrorService->storeAnalysisData($mirrorActive['task_id'], $mirrorActive['revision'],
            $mirrorSuggestion['plan_hash'], $mirrorItems, $mirrorSuggestion['categories']);
        jobExpect(count($mirrorAnalyzed['categories']) === 2
            && array_column($mirrorAnalyzed['categories'], 'name') === ['同名子分类', '同名子分类'],
            'stable upstream IDs did not separate equal category names');
        $mirrorSnapshot = (new SnapshotStore())->read($mirrorActive['task_id'], $mirrorAnalyzed['snapshot']['sha256'],
            $mirrorFingerprint, $mirrorSuggestion['plan_hash']);
        jobExpect($mirrorSnapshot['category_mode'] === 'mirror' && $mirrorSnapshot['items'] === $mirrorItems,
            'snapshot did not freeze mirror mode and complete upstream paths');
        $mirrorMappings = array_map(static fn(array $row): array => ['source_category'=>$row['name'],
            'target'=>$row['target'], 'confidence'=>$row['confidence']], $mirrorAnalyzed['categories']);
        foreach (['parent_name', 'parent_id', 'confidence', 'leaf_name', 'mode', 'icons'] as $tamper) {
            $changed = $mirrorMappings;
            if ($tamper === 'parent_name') {
                $changed[0]['target']['path'][0]['name'] = '替换父级';
            } elseif ($tamper === 'parent_id') {
                $changed[0]['target']['path'][0]['id'] = 99;
                $changed[0]['target']['path'][1]['pid'] = 99;
            } elseif ($tamper === 'confidence') {
                $changed[0]['confidence'] = 'low';
            } elseif ($tamper === 'leaf_name') {
                $changed[0]['source_category'] = '替换名称';
                $changed[0]['target']['path'][1]['name'] = '替换名称';
            } elseif ($tamper === 'icons') {
                $changed[0]['target']['category_icons'] = true;
            } else {
                $changed[0]['target'] = jobTarget('改为智能分类');
            }
            jobFails(static fn() => $mirrorService->confirmImport($mirrorActive['task_id'], $mirrorAnalyzed['revision'],
                $mirrorSuggestion['plan_hash'], '10', $changed), 'mirror confirmation accepted ' . $tamper . ' tampering');
        }
        $mirrorConfirmed = $mirrorService->confirmImport($mirrorActive['task_id'], $mirrorAnalyzed['revision'],
            $mirrorSuggestion['plan_hash'], '10', $mirrorMappings);
        $mirrorRepeated = $mirrorService->confirmImport($mirrorActive['task_id'], $mirrorAnalyzed['revision'],
            $mirrorSuggestion['plan_hash'], '10', array_reverse($mirrorMappings));
        jobExpect($mirrorRepeated === $mirrorConfirmed, 'same mirror confirmation was not idempotent');
        $mirrorImport = $mirrorService->beginWork($mirrorActive['task_id'], $mirrorConfirmed['revision']);
        $mirrorPayload = $mirrorService->loadImportSnapshot($mirrorActive['task_id'], $mirrorImport['revision'], $mirrorFingerprint);
        jobExpect($mirrorPayload['items'] === $mirrorItems, 'mirror import overlaid or reclassified the frozen paths');
        jobFails(static fn() => $mirrorStore->update($mirrorImport['task_id'], $mirrorImport['revision'],
            static function (array $job): array {
                $job['mappings'][0]['target']['path'][0]['name'] = '后置替换';
                $job['revision']++;
                return $job;
            }), 'mirror import checkpoint accepted an alternate mapping');
        fwrite(STDOUT, "mirror job mode, same-name identity, frozen confirmation and import: PASS\n");

        $oldMirrorJobsPath = PathGuard::$root . '/extensions/PikaCatalogHub/jobs/jobs.json';
        $oldMirrorSnapshotPath = PathGuard::$root . '/extensions/PikaCatalogHub/snapshots/' . $mirrorActive['task_id'] . '.json';
        $oldMirrorJobs = file_get_contents($oldMirrorJobsPath);
        $oldMirrorSnapshot = file_get_contents($oldMirrorSnapshotPath);
        jobExpect(is_string($oldMirrorJobs) && is_string($oldMirrorSnapshot), 'legacy mirror evidence files were not read');
        $mirrorStore->get($mirrorActive['task_id']);
        $mirrorService->loadImportSnapshot($mirrorActive['task_id'], $mirrorImport['revision'], $mirrorFingerprint);
        jobExpect(file_get_contents($oldMirrorJobsPath) === $oldMirrorJobs
            && file_get_contents($oldMirrorSnapshotPath) === $oldMirrorSnapshot,
            'reading an old mirror task migrated its state or snapshot bytes');

        PathGuard::$root = $fixture . '/mirror-icons-state';
        mkdir(PathGuard::$root, 0o700);
        mkdir(PathGuard::$root . '/site', 0o700);
        $iconItems = $mirrorItems;
        foreach ($iconItems as &$iconItem) $iconItem['target']['category_icons'] = true;
        unset($iconItem);
        $iconSuggestion = (new UpstreamCategoryTree())->suggest($iconItems);
        jobExpect($iconSuggestion['plan_hash'] !== $mirrorSuggestion['plan_hash'],
            'new icon capability did not participate in the frozen plan hash');
        $iconService = new JobService();
        $iconCreated = $iconService->createAnalysis(23004, '图标能力冻结', $mirrorFingerprint, 'mirror');
        $iconActive = $iconService->beginWork($iconCreated['task_id'], $iconCreated['revision']);
        $iconAnalyzed = $iconService->storeAnalysisData($iconActive['task_id'], $iconActive['revision'],
            $iconSuggestion['plan_hash'], $iconItems, $iconSuggestion['categories']);
        $iconSnapshot = (new SnapshotStore())->read($iconActive['task_id'], $iconAnalyzed['snapshot']['sha256'],
            $mirrorFingerprint, $iconSuggestion['plan_hash']);
        jobExpect($iconSnapshot['items'] === $iconItems
            && (new JobStore())->get($iconActive['task_id'])['categories'] === $iconAnalyzed['categories'],
            'new icon capability was lost during durable job or snapshot reload');
        $iconMappings = array_map(static fn(array $row): array => ['source_category' => $row['name'],
            'target' => $row['target'], 'confidence' => $row['confidence']], $iconAnalyzed['categories']);
        foreach (['remove', 'false', 'integer'] as $tamper) {
            $changed = $iconMappings;
            if ($tamper === 'remove') unset($changed[0]['target']['category_icons']);
            else $changed[0]['target']['category_icons'] = $tamper === 'false' ? false : 1;
            jobFails(static fn() => $iconService->confirmImport($iconActive['task_id'], $iconAnalyzed['revision'],
                $iconSuggestion['plan_hash'], '20', $changed), 'icon confirmation accepted ' . $tamper . ' tampering');
        }
        $iconConfirmed = $iconService->confirmImport($iconActive['task_id'], $iconAnalyzed['revision'],
            $iconSuggestion['plan_hash'], '20', $iconMappings);
        $iconImport = $iconService->beginWork($iconActive['task_id'], $iconConfirmed['revision']);
        $iconPayload = (new JobService())->loadImportSnapshot($iconActive['task_id'], $iconImport['revision'], $mirrorFingerprint);
        jobExpect($iconPayload['items'] === $iconItems && $iconPayload['plan_hash'] === $iconSuggestion['plan_hash'],
            'import reload lost the confirmed icon capability or plan identity');
        fwrite(STDOUT, "mirror icon capability durable freeze, tamper rejection and old bytes: PASS\n");

        PathGuard::$root = $fixture . '/mirror-wide-state';
        mkdir(PathGuard::$root, 0o700);
        mkdir(PathGuard::$root . '/site', 0o700);
        $wideService = new JobService();
        $wideFingerprint = hash('sha256', 'synthetic-wide-mirror-source');
        $wideNodes = [1 => ['id'=>1, 'pid'=>0, 'name'=>'合成根', 'sort'=>0]];
        for ($index = 0; $index < 2; $index++) {
            $id = 2 + $index;
            $wideNodes[$id] = ['id'=>$id, 'pid'=>1, 'name'=>'合成分支' . $index, 'sort'=>$index];
        }
        for ($index = 0; $index < 56; $index++) {
            $id = 10 + $index;
            $wideNodes[$id] = ['id'=>$id, 'pid'=>2 + ($index % 2),
                'name'=>'合成祖先' . sprintf('%02d', $index), 'sort'=>$index];
        }
        $wideRemoteItems = [];
        $wideExpectedItems = [];
        $wideExpectedCategories = [];
        $wideLeafIds = [];
        for ($index = 0; $index < 155; $index++) {
            $id = 1000 + $index;
            $parent = 10 + ($index % 56);
            $name = '合成叶' . sprintf('%03d', $index);
            $code = 'mirror-wide-' . sprintf('%03d', $index);
            $stock = 1 + ($index % 5);
            $wideNodes[$id] = ['id'=>$id, 'pid'=>$parent, 'name'=>$name, 'sort'=>$index];
            $target = ['mode'=>'mirror', 'path'=>[
                $wideNodes[1], $wideNodes[$wideNodes[$parent]['pid']], $wideNodes[$parent], $wideNodes[$id],
            ]];
            $wideRemoteItems[] = ['code'=>$code, 'category_id'=>$id, 'name'=>'合成商品' . $index, 'stock'=>$stock];
            $wideExpectedItems[] = ['code'=>$code, 'category'=>$name, 'stock'=>$stock, 'target'=>$target];
            $wideExpectedCategories[] = ['name'=>$name, 'count'=>1, 'target'=>$target, 'confidence'=>'high'];
            $wideLeafIds[$id] = true;
        }
        jobExpect(UpstreamCategoryTree::MAX_TREE_NODES === 2048 && UpstreamCategoryTree::MAX_CATEGORIES === 200,
            'mirror tree-node capacity changed the existing item-category limit');
        jobExpect(count($wideNodes) === 214 && count($wideLeafIds) === 155
            && count(array_diff_key($wideNodes, $wideLeafIds)) === 59,
            'wide mirror fixture must contain 155 item categories and 59 pure ancestors');
        $wideCatalog = (new UpstreamCategoryTree())->flatten([
            'schema'=>1, 'capability'=>'pika_category_tree',
            'categories'=>array_values($wideNodes), 'items'=>$wideRemoteItems,
        ]);
        $wideItems = array_values(array_map(static fn(array $row): array => [
            'code'=>$row['code'], 'category'=>$row['category'], 'stock'=>$row['stock'], 'target'=>$row['target'],
        ], $wideCatalog));
        jobExpect($wideItems === $wideExpectedItems, '214-node mirror projection lost a product binding or complete path');
        $wideUsedNodes = [];
        foreach ($wideItems as $item) {
            jobExpect(count($item['target']['path']) === 4, 'wide mirror fixture lost its fourth category level');
            foreach ($item['target']['path'] as $node) {
                $wideUsedNodes[$node['id']] = $node;
            }
        }
        ksort($wideUsedNodes, SORT_NUMERIC);
        ksort($wideNodes, SORT_NUMERIC);
        jobExpect($wideUsedNodes === $wideNodes, 'wide mirror paths omitted or changed a necessary ancestor');
        $wideSuggestion = (new UpstreamCategoryTree())->suggest($wideItems);
        jobExpect($wideSuggestion['categories'] === $wideExpectedCategories
            && $wideSuggestion['counts'] === ['items'=>155, 'categories'=>155, 'high'=>155, 'low'=>0],
            'wide mirror suggestion counted pure ancestors as item categories');
        $wideCreated = $wideService->createAnalysis(23003, '合成宽树', $wideFingerprint, 'mirror');
        $wideActive = $wideService->beginWork($wideCreated['task_id'], $wideCreated['revision']);
        $wideAnalyzed = $wideService->storeAnalysisData($wideActive['task_id'], $wideActive['revision'],
            $wideSuggestion['plan_hash'], $wideItems, $wideSuggestion['categories']);
        jobExpect($wideAnalyzed['category_mode'] === 'mirror'
            && $wideAnalyzed['categories'] === $wideExpectedCategories
            && $wideAnalyzed['counts'] === $wideSuggestion['counts']
            && $wideAnalyzed['snapshot']['item_count'] === 155
            && $wideAnalyzed['snapshot']['plan_hash'] === $wideSuggestion['plan_hash'],
            'wide mirror analysis did not retain all 155 categories and its frozen plan');
        $wideSnapshot = (new SnapshotStore())->read($wideActive['task_id'], $wideAnalyzed['snapshot']['sha256'],
            $wideFingerprint, $wideSuggestion['plan_hash']);
        jobExpect($wideSnapshot['category_mode'] === 'mirror' && $wideSnapshot['items'] === $wideExpectedItems
            && $wideSnapshot['source_fingerprint'] === $wideFingerprint
            && $wideSnapshot['plan_hash'] === $wideSuggestion['plan_hash'],
            'wide mirror snapshot changed the source binding, plan hash or complete paths');
        $wideMappings = array_map(static fn(array $row): array => ['source_category'=>$row['name'],
            'target'=>$row['target'], 'confidence'=>$row['confidence']], $wideExpectedCategories);

        // Exercise the summary and confirmation gates directly, not an earlier snapshot limit.
        $wideOverLimitCategories = $wideExpectedCategories;
        for ($index = 155; $index < 201; $index++) {
            $parent = 10 + ($index % 56);
            $name = '合成叶' . sprintf('%03d', $index);
            $wideOverLimitCategories[] = ['name'=>$name, 'count'=>1, 'target'=>['mode'=>'mirror', 'path'=>[
                $wideNodes[1], $wideNodes[$wideNodes[$parent]['pid']], $wideNodes[$parent],
                ['id'=>1000 + $index, 'pid'=>$parent, 'name'=>$name, 'sort'=>$index],
            ]], 'confidence'=>'high'];
        }
        $wideOverLimitMappings = array_map(static fn(array $row): array => ['source_category'=>$row['name'],
            'target'=>$row['target'], 'confidence'=>$row['confidence']], $wideOverLimitCategories);
        $wideJobsPath = PathGuard::$root . '/extensions/PikaCatalogHub/jobs/jobs.json';
        $wideSnapshotPath = PathGuard::$root . '/extensions/PikaCatalogHub/snapshots/' . $wideActive['task_id'] . '.json';
        $wideJobsBefore = file_get_contents($wideJobsPath);
        $wideSnapshotBefore = file_get_contents($wideSnapshotPath);
        try {
            $wideService->storeAnalysis($wideActive['task_id'], $wideAnalyzed['revision'],
                $wideAnalyzed['snapshot']['sha256'], $wideSuggestion['plan_hash'], $wideOverLimitCategories, 201);
            throw new RuntimeException('mirror analysis unexpectedly accepted 201 item-category summaries');
        } catch (RuntimeException $failure) {
            jobExpect($failure->getMessage() === '后台任务分类摘要必须是 1-200 项列表。',
                'mirror L201 analysis did not fail at the existing category-summary limit');
        }
        try {
            $wideService->confirmImport($wideActive['task_id'], $wideAnalyzed['revision'],
                $wideSuggestion['plan_hash'], '10', $wideOverLimitMappings);
            throw new RuntimeException('mirror confirmation unexpectedly accepted 201 item-category mappings');
        } catch (RuntimeException $failure) {
            jobExpect($failure->getMessage() === '后台任务确认映射必须是 1-200 项列表。',
                'mirror L201 confirmation did not fail at the existing mapping limit');
        }
        jobExpect(file_get_contents($wideJobsPath) === $wideJobsBefore
            && file_get_contents($wideSnapshotPath) === $wideSnapshotBefore,
            'rejected mirror L201 summary or confirmation changed durable state');
        $wideConfirmed = $wideService->confirmImport($wideActive['task_id'], $wideAnalyzed['revision'],
            $wideSuggestion['plan_hash'], '10', $wideMappings);
        $wideConfirmedBytes = file_get_contents($wideJobsPath);
        $wideRepeated = $wideService->confirmImport($wideActive['task_id'], $wideAnalyzed['revision'],
            $wideSuggestion['plan_hash'], '10', array_reverse($wideMappings));
        jobExpect($wideRepeated === $wideConfirmed && file_get_contents($wideJobsPath) === $wideConfirmedBytes
            && file_get_contents($wideSnapshotPath) === $wideSnapshotBefore,
            'repeated wide mirror confirmation was not byte-preserving and idempotent');
        jobExpect($wideConfirmed['categories'] === $wideExpectedCategories && $wideConfirmed['mappings'] === $wideMappings
            && $wideConfirmed['snapshot']['plan_hash'] === $wideSuggestion['plan_hash'],
            'wide mirror confirmation changed its 155 category associations or plan hash');
        $wideImport = $wideService->beginWork($wideActive['task_id'], $wideConfirmed['revision']);
        $widePayload = $wideService->loadImportSnapshot($wideActive['task_id'], $wideImport['revision'], $wideFingerprint);
        jobExpect($widePayload['items'] === $wideExpectedItems && $widePayload['plan_hash'] === $wideSuggestion['plan_hash']
            && $wideImport['categories'] === $wideExpectedCategories && $wideImport['mappings'] === $wideMappings,
            'wide mirror import lost its frozen paths, category associations or plan hash');
        fwrite(STDOUT, "mirror 214-node/155-category frozen state chain and L200 boundaries: PASS\n");

        fwrite(STDOUT, "local catalog job state behavior: PASS\n");
    } finally {
        removeJobFixture($fixture);
    }
}
