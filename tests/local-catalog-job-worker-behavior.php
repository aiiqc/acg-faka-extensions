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
                throw new \RuntimeException('unexpected worker state directory request');
            }
            $path = self::$root . '/' . trim($relative, '/');
            if (!is_dir($path) && !mkdir($path, $mode, true) && !is_dir($path)) {
                throw new \RuntimeException('unable to create worker state directory');
            }
            if (!chmod($path, $mode)) {
                throw new \RuntimeException('unable to protect worker state directory');
            }
            return $path;
        }

        public static function runtimeOwner(): int
        {
            $owner = fileowner(self::$root);
            if (!is_int($owner)) {
                throw new \RuntimeException('unable to resolve worker fixture owner');
            }
            return $owner;
        }
    }
}

namespace Pika\LocalExtensions\PikaCatalogHub\Service {
    function error_log(string $message): bool
    {
        if ($GLOBALS['workerLoggerFails'] ?? false) {
            throw new \RuntimeException('secret=https://logger.invalid app_key=TOPSECRET');
        }
        $GLOBALS['workerSafeLogs'][] = $message;
        return true;
    }
}

namespace {
    use Pika\LocalExtensions\Manager\AtomicJson;
    use Pika\LocalExtensions\Manager\PathGuard;
    use Pika\LocalExtensions\PikaCatalogHub\Service\JobService;
    use Pika\LocalExtensions\PikaCatalogHub\Service\JobStore;
    use Pika\LocalExtensions\PikaCatalogHub\Service\JobWorker;
    use Pika\LocalExtensions\PikaCatalogHub\Service\JobWorkerFailure;
    use Pika\LocalExtensions\PikaSupplySync\Service\CommodityImportFailure;
    use Pika\LocalExtensions\PikaSupplySync\Service\CommodityImporter;
    use Pika\LocalExtensions\PikaSupplySync\Service\UpstreamFailure;
    use Pika\LocalExtensions\PikaSupplySync\Service\UpstreamCategoryTree;

    final class CatalogWorkerLease
    {
        public bool $released = false;

        public function release(): void
        {
            $this->released = true;
        }
    }

    function workerExpect(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function workerFails(callable $callback, string $message): void
    {
        try {
            $callback();
        } catch (Throwable) {
            return;
        }
        throw new RuntimeException($message);
    }

    function workerRemove(string $path): void
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
            throw new RuntimeException('unable to inspect worker fixture');
        }
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                workerRemove($path . '/' . $entry);
            }
        }
        rmdir($path);
    }

    function workerSource(int $sourceId): object
    {
        $source = new stdClass();
        $source->id = $sourceId;
        return $source;
    }

    /** @return array<string,array{code:string,name:string,category:string,stock:int,item:array}> */
    function workerCatalog(int $sourceId): array
    {
        return [
            '001' => [
                'code' => '001',
                'name' => 'private product name ' . $sourceId,
                'category' => 'Facebook',
                'stock' => 5,
                'item' => ['must_not_persist' => true],
            ],
            '002' => [
                'code' => '002',
                'name' => 'second private product ' . $sourceId,
                'category' => 'AI ChatGPT',
                'stock' => 7,
                'item' => ['must_not_persist' => true],
            ],
            '003' => [
                'code' => '003',
                'name' => 'third private product ' . $sourceId,
                'category' => '(X)Twitter 新增',
                'stock' => 3,
                'item' => ['must_not_persist' => true],
            ],
        ];
    }

    function workerMappings(array $categories): array
    {
        return array_map(static fn(array $entry): array => [
            'source_category' => $entry['name'],
            'target' => $entry['target'],
            'confidence' => $entry['confidence'],
        ], $categories);
    }

    $fixture = sys_get_temp_dir() . '/pika-catalog-worker-' . bin2hex(random_bytes(8));
    if (!mkdir($fixture, 0o700, true) || !mkdir($fixture . '/site', 0o700)) {
        throw new RuntimeException('unable to create worker fixture');
    }
    PathGuard::$root = $fixture;

    try {
        require_once dirname(__DIR__) . '/manager/site/local-extensions/src/AtomicJson.php';
        require_once dirname(__DIR__) . '/extensions/PikaSupplySync/bootstrap.php';
        require_once dirname(__DIR__) . '/extensions/PikaCatalogHub/bootstrap.php';

        $jobs = new JobService();
        $locksBusy = true;
        $leases = [];
        $imports = [];
        $resolved = [];
        $capacityChecks = [];
        $fetches = [];
        $budgetSource = null;
        $pauseTaskId = null;
        $pauseIssued = false;
        $cancelTaskId = null;
        $errorSourceId = null;
        $capacityFailureSourceId = null;
        $itemFailureSourceId = null;
        $detailFetchFailureSourceId = null;
        $detailFetchFailuresRemaining = 0;
        $detailFetchFailureCode = CommodityImportFailure::DETAIL_FETCH_FAILED;
        $detailFetchDiagnosticCategory = null;
        $workerFailureSourceId = null;
        $detailStocks = ['001' => 0, '002' => 7, '003' => 3];

        $runtime = [
            'lock_source' => static function (int $sourceId) use (&$locksBusy, &$leases): ?CatalogWorkerLease {
                if ($locksBusy) {
                    return null;
                }
                $lease = new CatalogWorkerLease();
                $leases[] = $lease;
                return $lease;
            },
            'load_source' => static fn(int $sourceId): object => workerSource($sourceId),
            'fingerprint' => static fn(object $source): string => hash('sha256', 'source-' . $source->id),
            'fetch_catalog' => static function (object $source) use (
                &$jobs,
                &$cancelTaskId,
                &$errorSourceId,
                &$fetches,
            ): array {
                $fetches[$source->id] = ($fetches[$source->id] ?? 0) + 1;
                if ($source->id === $errorSourceId) {
                    throw new RuntimeException('secret=https://upstream.invalid merchant=123 app_key=TOPSECRET');
                }
                if (is_string($cancelTaskId) && $source->id === 3) {
                    $current = $jobs->get($cancelTaskId);
                    $jobs->control($cancelTaskId, $current['revision'], 'cancel');
                }
                return workerCatalog($source->id);
            },
            'classify' => static fn(array $catalog): array => (new \Pika\LocalExtensions\PikaCatalogHub\Service\ClassificationSuggester())
                ->suggest($catalog),
            'assert_plan_capacity' => static function (
                object $source,
                string $alias,
                array $items,
            ) use (&$capacityChecks, &$capacityFailureSourceId): void {
                $capacityChecks[] = [$source->id, $alias, count($items)];
                if ($source->id === $capacityFailureSourceId) {
                    throw new RuntimeException('simulated projected category capacity failure');
                }
            },
            'import_planned_item' => static function (
                object $source,
                array $item,
                string $alias,
                array $target,
                string $planHash,
                object $options,
            ) use (
                &$imports,
                &$resolved,
                &$capacityChecks,
                &$jobs,
                &$pauseTaskId,
                &$pauseIssued,
                &$itemFailureSourceId,
                &$detailFetchFailureSourceId,
                &$detailFetchFailuresRemaining,
                &$detailFetchFailureCode,
                &$detailFetchDiagnosticCategory,
                &$workerFailureSourceId,
                $detailStocks,
            ): string {
                workerExpect($alias !== '' && strlen($planHash) === 64, 'planned importer lost immutable plan fields');
                workerExpect(isset($target['group'], $target['family']), 'planned importer lost the confirmed category target');
                workerExpect($options->premiumPercent === 10.0, 'confirmed premium did not reach importer');
                workerExpect($capacityChecks !== [], 'worker imported an item before the complete-plan capacity check');
                $resolved[] = [$source->id, $alias, $target];
                $imports[] = [$source->id, $item['code'], $item['stock'], $detailStocks[$item['code']]];
                if ($source->id === $workerFailureSourceId) {
                    throw new JobWorkerFailure('TASK_STATE_INVALID');
                }
                if ($source->id === $itemFailureSourceId) {
                    throw new CommodityImportFailure(
                        CommodityImportFailure::DETAIL_NORMALIZATION_FAILED,
                        new RuntimeException('secret=https://upstream.invalid product=private app_key=TOPSECRET'),
                    );
                }
                if ($source->id === $detailFetchFailureSourceId && $detailFetchFailuresRemaining > 0) {
                    $detailFetchFailuresRemaining--;
                    throw new CommodityImportFailure(
                        $detailFetchFailureCode,
                        $detailFetchDiagnosticCategory === null
                            ? new RuntimeException('secret=https://upstream.invalid product=private app_key=TOPSECRET')
                            : new UpstreamFailure($detailFetchDiagnosticCategory, [
                                'attempts' => 3, 'http_status' => 429, 'curl_code' => 0, 'elapsed_ms' => 1500,
                                'message' => 'TOPSECRET https://private.invalid',
                            ]),
                    );
                }
                if (is_string($pauseTaskId) && !$pauseIssued && $source->id === 2) {
                    $current = $jobs->get($pauseTaskId);
                    $jobs->control($pauseTaskId, $current['revision'], 'pause');
                    $pauseIssued = true;
                }
                return match ($item['code']) {
                    '001' => CommodityImporter::OUTCOME_CREATED,
                    '002' => CommodityImporter::OUTCOME_REATTACHED,
                    default => CommodityImporter::OUTCOME_ALREADY_MANAGED,
                };
            },
            'begin_source' => static function (int $sourceId) use (&$budgetSource): void {
                workerExpect($budgetSource === null, 'worker began two source budgets');
                $budgetSource = $sourceId;
            },
            'end_source' => static function () use (&$budgetSource): void {
                workerExpect(is_int($budgetSource), 'worker ended a missing source budget');
                $budgetSource = null;
            },
        ];

        $worker = new JobWorker($jobs, $runtime);
        $firstFingerprint = hash('sha256', 'source-1');
        $first = $jobs->createAnalysis(1, '货源A', $firstFingerprint);
        $busy = $worker->runOne();
        workerExpect($busy['status'] === 'busy', 'busy SourceLock did not defer the job');
        workerExpect($jobs->get($first['task_id'])['state'] === JobStore::STATE_QUEUED_ANALYSIS, 'busy lock claimed the job');

        $locksBusy = false;
        $analyzedResult = $worker->runOne();
        $analyzed = $jobs->get($first['task_id']);
        workerExpect($analyzedResult['status'] === JobStore::STATE_AWAITING_CONFIRMATION, 'analysis worker did not await confirmation');
        workerExpect($analyzed['counts']['items'] === 3 && $analyzed['counts']['categories'] === 3, 'analysis counts are wrong');
        $twitterCategories = array_values(array_filter(
            $analyzed['categories'],
            static fn(array $category): bool => $category['name'] === '(X)Twitter 新增',
        ));
        workerExpect(
            count($twitterCategories) === 1
                && $twitterCategories[0]['target'] === ['group' => 'Twitter X', 'family' => ''],
            'Twitter target category did not satisfy the safe segment contract',
        );
        workerExpect($analyzedResult['task_hash'] !== $first['task_id'], 'worker output exposed raw task ID');
        workerExpect(!str_contains(json_encode($analyzedResult, JSON_THROW_ON_ERROR), 'private product'), 'worker output exposed product name');
        workerExpect($leases[0]->released, 'analysis SourceLock was not released');

        $confirmed = $jobs->confirmImport(
            $first['task_id'],
            $analyzed['revision'],
            $analyzed['snapshot']['plan_hash'],
            '10',
            workerMappings($analyzed['categories']),
        );
        workerExpect($confirmed['state'] === JobStore::STATE_QUEUED_IMPORT, 'confirmed task did not queue');
        $yielded = $worker->runOne(1);
        workerExpect($yielded['status'] === JobStore::STATE_QUEUED_IMPORT, 'one-item batch did not yield');
        workerExpect($yielded['counts']['processed'] === 1 && $yielded['counts']['succeeded'] === 1, 'first import checkpoint is wrong');
        workerExpect(
            $imports[0] === [1, '001', 5, 0],
            'worker did not continue after the importer accepted a catalog-positive/detail-zero drift outcome',
        );
        $yieldedAgain = $worker->runOne(1);
        workerExpect($yieldedAgain['status'] === JobStore::STATE_QUEUED_IMPORT, 'second one-item batch did not yield');
        workerExpect(
            $yieldedAgain['counts']['processed'] === 2
                && $yieldedAgain['counts']['succeeded'] === 2
                && $yieldedAgain['counts']['skipped'] === 0,
            'reattached import checkpoint was not counted as success',
        );
        $completed = $worker->runOne(1);
        workerExpect($completed['status'] === JobStore::STATE_COMPLETED, 'third bounded import did not complete');
        workerExpect(
            $completed['counts']['processed'] === 3
                && $completed['counts']['succeeded'] === 2
                && $completed['counts']['skipped'] === 1,
            'created, reattached and idempotent import outcomes were counted incorrectly',
        );
        workerExpect($fetches[1] === 1, 'resumed import fetched the full upstream catalog again');
        workerExpect(count($resolved) === count($imports), 'category resolution escaped the atomic planned-import seam');

        $capacityFailure = $jobs->createAnalysis(9, '货源I', hash('sha256', 'source-9'));
        $worker->runOne();
        $capacityAnalyzed = $jobs->get($capacityFailure['task_id']);
        $jobs->confirmImport(
            $capacityFailure['task_id'],
            $capacityAnalyzed['revision'],
            $capacityAnalyzed['snapshot']['plan_hash'],
            10,
            workerMappings($capacityAnalyzed['categories']),
        );
        $capacityFailureSourceId = 9;
        $importsBeforeCapacityFailure = count($imports);
        $capacityFailed = $worker->runOne(20);
        workerExpect(
            $capacityFailed['status'] === JobStore::STATE_FAILED
                && $capacityFailed['error_code'] === 'IMPORT_CATEGORY_CAPACITY_FAILED'
                && count($imports) === $importsBeforeCapacityFailure,
            'complete-plan capacity failure did not stop before the first item import',
        );
        $capacityFailureSourceId = null;

        $itemFailure = $jobs->createAnalysis(10, '货源J', hash('sha256', 'source-10'));
        $worker->runOne();
        $itemFailureAnalyzed = $jobs->get($itemFailure['task_id']);
        $jobs->confirmImport(
            $itemFailure['task_id'],
            $itemFailureAnalyzed['revision'],
            $itemFailureAnalyzed['snapshot']['plan_hash'],
            10,
            workerMappings($itemFailureAnalyzed['categories']),
        );
        $itemFailureSourceId = 10;
        $itemFailed = $worker->runOne(20);
        $itemFailedJson = json_encode($itemFailed, JSON_THROW_ON_ERROR);
        workerExpect(
            $itemFailed['status'] === JobStore::STATE_FAILED
                && $itemFailed['error_code'] === CommodityImportFailure::DETAIL_NORMALIZATION_FAILED,
            'planned-item safe failure code was not preserved',
        );
        workerExpect(
            $jobs->get($itemFailure['task_id'])['error_code'] === CommodityImportFailure::DETAIL_NORMALIZATION_FAILED,
            'planned-item safe failure code was not persisted',
        );
        workerExpect(
            $jobs->get($itemFailure['task_id'])['can_resume'] === false,
            'a deterministic item failure was exposed as resumable',
        );
        workerExpect(
            !preg_match('/(?:TOPSECRET|upstream|product|app_key|https?:)/i', $itemFailedJson),
            'planned-item failure exposed upstream or product data',
        );
        workerExpect(
            !preg_match(
                '/(?:TOPSECRET|upstream|product|app_key|https?:)/i',
                json_encode($jobs->list(), JSON_THROW_ON_ERROR),
            ),
            'planned-item failure exposed original data through JobStore',
        );
        $itemFailureSourceId = null;

        workerFails(
            static fn() => $jobs->control(
                $itemFailure['task_id'],
                $jobs->get($itemFailure['task_id'])['revision'],
                'resume',
            ),
            'a deterministic detail-normalization failure was resumable',
        );

        $taxonomyCases = [
            ['transport', CommodityImportFailure::DETAIL_TRANSPORT_FAILED, true],
            ['http_retryable', CommodityImportFailure::DETAIL_HTTP_RETRYABLE, true],
            ['http_rejected', CommodityImportFailure::DETAIL_HTTP_REJECTED, false],
            ['credentials', CommodityImportFailure::DETAIL_CREDENTIALS_INVALID, false],
            ['business', CommodityImportFailure::DETAIL_BUSINESS_REJECTED, false],
            ['schema', CommodityImportFailure::DETAIL_RESPONSE_INVALID, false],
            ['budget', CommodityImportFailure::DETAIL_BUDGET_EXCEEDED, false],
            ['unknown', CommodityImportFailure::DETAIL_UNKNOWN_FAILED, false],
        ];
        foreach ($taxonomyCases as $caseIndex => [$category, $code, $canResume]) {
            $sourceId = 100 + $caseIndex;
            $taxonomyTask = $jobs->createAnalysis($sourceId, '分类测试' . $caseIndex, hash('sha256', 'source-' . $sourceId));
            $worker->runOne();
            $analyzed = $jobs->get($taxonomyTask['task_id']);
            $jobs->confirmImport($analyzed['task_id'], $analyzed['revision'], $analyzed['snapshot']['plan_hash'], 10,
                workerMappings($analyzed['categories']));
            $worker->runOne(1);
            $checkpoint = $jobs->get($analyzed['task_id']);
            $detailFetchFailureSourceId = $sourceId;
            $detailFetchFailuresRemaining = 1;
            $detailFetchFailureCode = $code;
            $detailFetchDiagnosticCategory = $category;
            $GLOBALS['workerSafeLogs'] = [];
            $GLOBALS['workerLoggerFails'] = $category === 'business';
            $failedResult = $worker->runOne(20);
            $GLOBALS['workerLoggerFails'] = false;
            $failedTask = $jobs->get($analyzed['task_id']);
            $isolatable = CommodityImportFailure::isIsolatableItemCode($code);
            workerExpect($failedResult['error_code'] === ($isolatable ? 'IMPORT_FINISHED_WITH_ISSUES' : $code)
                && $failedTask['can_resume'] === false,
                'taxonomy or logger failure changed safe code/resume policy');
            foreach (['snapshot', 'categories', 'counts', 'premium_percent', 'mappings'] as $preserved) {
                workerExpect($failedTask[$preserved] === $checkpoint[$preserved], 'detail failure changed ' . $preserved);
            }
            if (!$isolatable) {
                workerExpect($failedTask['progress'] === $checkpoint['progress'], 'fatal detail failure changed progress');
            }
            workerExpect($worker->runOne()['status'] === 'idle', 'failed detail job automatically retried');
            if ($category !== 'business') {
                workerExpect(count($GLOBALS['workerSafeLogs']) === 1, 'safe diagnostic was not emitted once');
                $logged = json_decode($GLOBALS['workerSafeLogs'][0], true, 8, JSON_THROW_ON_ERROR);
                workerExpect($logged === [
                    'event' => 'catalog_detail_failure', 'task_hash' => $failedResult['task_hash'], 'error_code' => $code,
                    'index' => 1,
                    'category' => $category, 'http_status' => 429, 'curl_code' => 0, 'elapsed_ms' => 1500, 'attempts' => 3,
                ], 'safe logger emitted extra or incorrect diagnostics');
            }
            workerExpect($failedTask['last_detail_diagnostic'] === [
                'index' => 1,
                'diagnostics' => [
                    'category' => $category, 'http_status' => 429, 'curl_code' => 0, 'elapsed_ms' => 1500, 'attempts' => 3,
                ],
            ] && $failedTask['detail_compatibility_count'] === 0,
                'job lost its exact safe failure diagnostic or counted a failed item as compatible');
            $serializedFailedTask = json_encode($failedTask, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            workerExpect(!str_contains($serializedFailedTask, 'TOPSECRET')
                && !str_contains($serializedFailedTask, 'https://'),
                'job failure diagnostics retained supplier secrets or URLs');
            if ($isolatable) {
                workerExpect($failedTask['progress'] === ['total'=>3,'processed'=>3,'succeeded'=>1,'failed'=>1,'skipped'=>1],
                    'isolated detail failure did not continue while preserving counter meanings');
                workerExpect($failedTask['item_failures'] === [['index'=>1,'code'=>$code,'attempts'=>3]],
                    'isolated detail failure did not persist its exact safe checkpoint');
                $sourceImports = array_values(array_filter($imports, static fn(array $entry): bool => $entry[0] === $sourceId));
                workerExpect(array_column($sourceImports, 1) === ['001', '002', '003'],
                    'isolated item failure was retried by the outer worker');
            }
            workerFails(static fn() => $jobs->control($analyzed['task_id'], $failedTask['revision'], 'resume'),
                'finished issues or deterministic fatal became resumable');
        }
        $detailFetchFailureSourceId = null;
        $detailFetchFailureCode = CommodityImportFailure::DETAIL_FETCH_FAILED;
        $detailFetchDiagnosticCategory = null;

        $retryableFailure = $jobs->createAnalysis(12, '货源L', hash('sha256', 'source-12'));
        $worker->runOne();
        $retryableAnalyzed = $jobs->get($retryableFailure['task_id']);
        $jobs->confirmImport(
            $retryableFailure['task_id'],
            $retryableAnalyzed['revision'],
            $retryableAnalyzed['snapshot']['plan_hash'],
            10,
            workerMappings($retryableAnalyzed['categories']),
        );
        $firstCheckpoint = $worker->runOne(1);
        workerExpect(
            $firstCheckpoint['status'] === JobStore::STATE_QUEUED_IMPORT
                && $firstCheckpoint['counts']['processed'] === 1,
            'retryable failure fixture did not establish a one-item checkpoint',
        );
        $detailFetchFailureSourceId = 12;
        $detailFetchFailuresRemaining = 1;
        $retryableFailed = $worker->runOne(20);
        workerExpect(
            $retryableFailed['status'] === JobStore::STATE_FAILED
                && $retryableFailed['error_code'] === CommodityImportFailure::DETAIL_FETCH_FAILED
                && $retryableFailed['counts']['processed'] === 1,
            'detail-fetch failure did not preserve the last completed checkpoint',
        );
        $failedCheckpoint = $jobs->get($retryableFailure['task_id']);
        workerExpect($failedCheckpoint['can_resume'] === true, 'detail-fetch checkpoint was not exposed as resumable');
        $resumed = $jobs->control(
            $retryableFailure['task_id'],
            $failedCheckpoint['revision'],
            'resume',
        );
        workerExpect(
            $resumed['state'] === JobStore::STATE_QUEUED_IMPORT
                && $resumed['phase'] === 'import'
                && $resumed['error_code'] === null
                && $resumed['revision'] === $failedCheckpoint['revision'] + 1,
            'detail-fetch failure did not resume as queued_import with a cleared safe code',
        );
        workerExpect($resumed['can_resume'] === false, 'queued import remained exposed as resumable');
        foreach (['progress', 'snapshot', 'categories', 'counts', 'premium_percent', 'mappings'] as $preserved) {
            workerExpect(
                $resumed[$preserved] === $failedCheckpoint[$preserved],
                "manual resume changed preserved {$preserved} state",
            );
        }
        $claimedAgain = $jobs->beginWork($retryableFailure['task_id'], $resumed['revision']);
        $failedAgain = $jobs->fail(
            $retryableFailure['task_id'],
            $claimedAgain['revision'],
            CommodityImportFailure::DETAIL_FETCH_FAILED,
        );
        workerFails(
            static fn() => $jobs->control(
                $retryableFailure['task_id'],
                $failedCheckpoint['revision'],
                'resume',
            ),
            'a stale resume request was reported as successful after the worker failed again',
        );
        $resumed = $jobs->control(
            $retryableFailure['task_id'],
            $failedAgain['revision'],
            'resume',
        );
        workerExpect(
            $resumed['state'] === JobStore::STATE_QUEUED_IMPORT
                && $resumed['error_code'] === null,
            'a fresh resume request did not requeue the second detail-fetch failure',
        );
        $detailFetchFailureSourceId = null;
        $retryableCompleted = $worker->runOne(20);
        workerExpect(
            $retryableCompleted['status'] === JobStore::STATE_COMPLETED
                && $retryableCompleted['counts']['processed'] === 3
                && $retryableCompleted['counts']['succeeded'] === 2
                && $retryableCompleted['counts']['skipped'] === 1,
            'resumed detail-fetch failure did not continue from its exact checkpoint',
        );
        workerExpect(
            $jobs->get($retryableFailure['task_id'])['can_resume'] === false,
            'completed import was exposed as resumable',
        );
        workerExpect($fetches[12] === 1, 'manual import resume fetched the full catalog again');

        // Abandoning a recoverable failure is explicit and preserves its evidence.
        $cancelFailure = $jobs->createAnalysis(12, '货源L', hash('sha256', 'source-12'));
        $worker->runOne();
        $cancelAnalyzed = $jobs->get($cancelFailure['task_id']);
        $jobs->confirmImport($cancelFailure['task_id'], $cancelAnalyzed['revision'],
            $cancelAnalyzed['snapshot']['plan_hash'], 10, workerMappings($cancelAnalyzed['categories']));
        $detailFetchFailureSourceId = 12;
        $detailFetchFailuresRemaining = 1;
        $worker->runOne(20);
        $beforeCancel = $jobs->get($cancelFailure['task_id']);
        workerExpect($beforeCancel['can_resume'] === true, 'cancel fixture was not resumable');
        $cancelledFailure = $jobs->control($cancelFailure['task_id'], $beforeCancel['revision'], 'cancel');
        workerExpect($cancelledFailure['state'] === JobStore::STATE_CANCELLED && $cancelledFailure['can_resume'] === false,
            'cancel did not retire the resumable failed task');
        foreach (['progress', 'snapshot', 'categories', 'counts', 'premium_percent', 'mappings', 'error_code'] as $preserved) {
            workerExpect($cancelledFailure[$preserved] === $beforeCancel[$preserved], 'cancel rewrote failed-task evidence: ' . $preserved);
        }
        workerFails(static fn() => $jobs->control($cancelFailure['task_id'], $cancelledFailure['revision'], 'resume'),
            'a cancelled failure was allowed to resume');
        $cancelAgain = $jobs->control($cancelFailure['task_id'], $beforeCancel['revision'], 'cancel');
        workerExpect($cancelAgain === $cancelledFailure, 'repeated cancel changed the retired task');
        $detailFetchFailureSourceId = null;

        $workerFailure = $jobs->createAnalysis(11, '货源K', hash('sha256', 'source-11'));
        $worker->runOne();
        $workerFailureAnalyzed = $jobs->get($workerFailure['task_id']);
        $jobs->confirmImport(
            $workerFailure['task_id'],
            $workerFailureAnalyzed['revision'],
            $workerFailureAnalyzed['snapshot']['plan_hash'],
            10,
            workerMappings($workerFailureAnalyzed['categories']),
        );
        $workerFailureSourceId = 11;
        $workerFailed = $worker->runOne(20);
        workerExpect(
            $workerFailed['status'] === JobStore::STATE_FAILED
                && $workerFailed['error_code'] === 'TASK_STATE_INVALID',
            'worker-native safe failure was reclassified as an item failure',
        );
        $workerFailureSourceId = null;

        $second = $jobs->createAnalysis(2, '货源B', hash('sha256', 'source-2'));
        $worker->runOne();
        $secondAnalyzed = $jobs->get($second['task_id']);
        $jobs->confirmImport(
            $second['task_id'],
            $secondAnalyzed['revision'],
            $secondAnalyzed['snapshot']['plan_hash'],
            10,
            workerMappings($secondAnalyzed['categories']),
        );
        $pauseTaskId = $second['task_id'];
        $paused = $worker->runOne(20);
        workerExpect($paused['status'] === JobStore::STATE_PAUSED, 'pause request was not acknowledged at item boundary');
        workerExpect($paused['counts']['processed'] === 1, 'pause acknowledged before completed item checkpoint');
        $pausedState = $jobs->get($second['task_id']);
        $jobs->control($second['task_id'], $pausedState['revision'], 'resume');
        $resumedComplete = $worker->runOne(20);
        workerExpect($resumedComplete['status'] === JobStore::STATE_COMPLETED, 'resumed task did not complete');

        $third = $jobs->createAnalysis(3, '货源C', hash('sha256', 'source-3'));
        $cancelTaskId = $third['task_id'];
        $cancelled = $worker->runOne();
        workerExpect($cancelled['status'] === JobStore::STATE_CANCELLED, 'cancel during catalog request was not acknowledged');
        workerExpect(!is_file($fixture . '/extensions/PikaCatalogHub/snapshots/' . $third['task_id'] . '.json'), 'cancelled analysis wrote a snapshot');

        $fourth = $jobs->createAnalysis(4, '货源D', hash('sha256', 'source-4'));
        $errorSourceId = 4;
        $failed = $worker->runOne();
        $failedJson = json_encode($failed, JSON_THROW_ON_ERROR);
        workerExpect($failed['status'] === JobStore::STATE_FAILED, 'upstream error did not fail safely');
        workerExpect($failed['error_code'] === 'ANALYSIS_FETCH_FAILED', 'upstream error code was not bounded');
        workerExpect(!preg_match('/(?:TOPSECRET|upstream|merchant|app_key|https?:)/i', $failedJson), 'worker result leaked upstream error data');
        workerExpect($jobs->get($fourth['task_id'])['error_code'] === 'ANALYSIS_FETCH_FAILED', 'safe error code was not persisted');
        workerExpect(
            $jobs->get($fourth['task_id'])['can_resume'] === false,
            'analysis failure was exposed as resumable',
        );
        workerFails(
            static fn() => $jobs->control(
                $fourth['task_id'],
                $jobs->get($fourth['task_id'])['revision'],
                'resume',
            ),
            'a failed analysis task was resumable',
        );

        // Simulate SIGKILL/OOM after an analysis claim and after the immutable
        // snapshot was published, but before JobStore received its metadata.
        $analysisCrash = $jobs->createAnalysis(5, '货源E', hash('sha256', 'source-5'));
        $analysisClaim = $jobs->beginWork($analysisCrash['task_id'], $analysisCrash['revision']);
        $analysisPlan = (new \Pika\LocalExtensions\PikaCatalogHub\Service\ClassificationSuggester())
            ->suggest(workerCatalog(5));
        $analysisTargets = [];
        foreach ($analysisPlan['categories'] as $category) {
            $analysisTargets[$category['name']] = $category['target'];
        }
        $analysisItems = array_map(static fn(array $row): array => [
            'code' => $row['code'],
            'category' => $row['category'],
            'stock' => $row['stock'],
            'target' => $analysisTargets[$row['category']],
        ], array_values(workerCatalog(5)));
        $jobs->storeAnalysisSnapshot(
            $analysisCrash['task_id'],
            $analysisClaim['revision'],
            $analysisPlan['plan_hash'],
            $analysisItems,
        );
        $analysisCrashPath = $fixture . '/extensions/PikaCatalogHub/snapshots/' . $analysisCrash['task_id'] . '.json';
        workerExpect(is_file($analysisCrashPath), 'analysis death fixture did not publish an orphan snapshot');
        $analysisRecovered = $jobs->recoverInterrupted();
        workerExpect(count($analysisRecovered) === 1, 'analysis death recovery did not report one task');
        workerExpect(
            $analysisRecovered[0]['state'] === JobStore::STATE_QUEUED_ANALYSIS
                && $analysisRecovered[0]['revision'] === $analysisClaim['revision'] + 1,
            'analysis death was not atomically requeued',
        );
        workerExpect(!file_exists($analysisCrashPath), 'unbound analysis snapshot survived recovery');
        $analysisRestarted = $worker->runOne();
        workerExpect($analysisRestarted['status'] === JobStore::STATE_AWAITING_CONFIRMATION, 'recovered analysis did not restart');
        $analysisRestartedState = $jobs->get($analysisCrash['task_id']);
        $jobs->control($analysisCrash['task_id'], $analysisRestartedState['revision'], 'cancel');

        // Simulate death immediately after an import claim. Recovery must prove
        // and preserve the bound snapshot before requeueing the unfinished batch.
        $importCrash = $jobs->createAnalysis(6, '货源F', hash('sha256', 'source-6'));
        $worker->runOne();
        $importAnalyzed = $jobs->get($importCrash['task_id']);
        $importQueued = $jobs->confirmImport(
            $importCrash['task_id'],
            $importAnalyzed['revision'],
            $importAnalyzed['snapshot']['plan_hash'],
            10,
            workerMappings($importAnalyzed['categories']),
        );
        $importClaim = $jobs->beginWork($importCrash['task_id'], $importQueued['revision']);
        $importCrashPath = $fixture . '/extensions/PikaCatalogHub/snapshots/' . $importCrash['task_id'] . '.json';
        $importSnapshotSha = hash_file('sha256', $importCrashPath);
        $importRecovered = $jobs->recoverInterrupted();
        workerExpect(
            count($importRecovered) === 1
                && $importRecovered[0]['state'] === JobStore::STATE_QUEUED_IMPORT
                && $importRecovered[0]['revision'] === $importClaim['revision'] + 1,
            'import death was not atomically requeued',
        );
        workerExpect(
            is_file($importCrashPath) && hash_file('sha256', $importCrashPath) === $importSnapshotSha,
            'recovery deleted or changed a bound import snapshot',
        );
        $importRestarted = $worker->runOne(20);
        workerExpect($importRestarted['status'] === JobStore::STATE_COMPLETED, 'recovered import did not complete');

        $pauseCrash = $jobs->createAnalysis(7, '货源G', hash('sha256', 'source-7'));
        $pauseClaim = $jobs->beginWork($pauseCrash['task_id'], $pauseCrash['revision']);
        $jobs->control($pauseCrash['task_id'], $pauseClaim['revision'], 'pause');
        $pauseRecovered = $jobs->recoverInterrupted();
        workerExpect(
            count($pauseRecovered) === 1 && $pauseRecovered[0]['state'] === JobStore::STATE_PAUSED,
            'interrupted pause request was not acknowledged',
        );
        $jobs->control($pauseCrash['task_id'], $pauseRecovered[0]['revision'], 'cancel');

        $cancelCrash = $jobs->createAnalysis(8, '货源H', hash('sha256', 'source-8'));
        $cancelClaim = $jobs->beginWork($cancelCrash['task_id'], $cancelCrash['revision']);
        $jobs->control($cancelCrash['task_id'], $cancelClaim['revision'], 'cancel');
        $cancelRecovered = $jobs->recoverInterrupted();
        workerExpect(
            count($cancelRecovered) === 1 && $cancelRecovered[0]['state'] === JobStore::STATE_CANCELLED,
            'interrupted cancel request was not acknowledged',
        );

        // A missing/unsafe bound snapshot must hold the running state rather
        // than silently requeueing an import that cannot prove its identity.
        $invalidCrash = $jobs->createAnalysis(9, '货源I', hash('sha256', 'source-9'));
        $worker->runOne();
        $invalidAnalyzed = $jobs->get($invalidCrash['task_id']);
        $invalidQueued = $jobs->confirmImport(
            $invalidCrash['task_id'],
            $invalidAnalyzed['revision'],
            $invalidAnalyzed['snapshot']['plan_hash'],
            10,
            workerMappings($invalidAnalyzed['categories']),
        );
        $invalidClaim = $jobs->beginWork($invalidCrash['task_id'], $invalidQueued['revision']);
        $invalidPath = $fixture . '/extensions/PikaCatalogHub/snapshots/' . $invalidCrash['task_id'] . '.json';
        chmod($invalidPath, 0o640);
        workerFails(static fn() => $jobs->recoverInterrupted(), 'unsafe bound snapshot was recovered');
        workerExpect(
            $jobs->get($invalidCrash['task_id'])['state'] === JobStore::STATE_IMPORTING,
            'failed recovery partially changed the interrupted import state',
        );
        chmod($invalidPath, 0o600);
        $invalidRecovered = $jobs->recoverInterrupted();
        workerExpect(
            count($invalidRecovered) === 1 && $invalidRecovered[0]['state'] === JobStore::STATE_QUEUED_IMPORT,
            'valid binding did not recover after the unsafe snapshot was repaired',
        );
        $jobs->control($invalidCrash['task_id'], $invalidRecovered[0]['revision'], 'cancel');

        workerFails(static fn() => $worker->runOne(0), 'zero import batch was accepted');
        workerFails(static fn() => $worker->runOne(21), 'more than twenty items per invocation was accepted');
        $idle = $worker->runOne();
        workerExpect($idle['status'] === 'idle' && $idle['task_hash'] === null, 'worker did not become idle');

        $snapshotRaw = implode("\n", array_map(
            static fn(string $path): string => (string)file_get_contents($path),
            glob($fixture . '/extensions/PikaCatalogHub/snapshots/*.json') ?: [],
        ));
        workerExpect(!str_contains($snapshotRaw, 'private product'), 'durable snapshot contains a product name');
        workerExpect(!preg_match('/(?:https?:\/\/|merchant|app[_-]?key|secret|token)/i', $snapshotRaw), 'durable snapshot contains a credential or URL');

        // A separate state root keeps bounded item-isolation cases independent
        // from the legacy worker/recovery fixtures above.
        PathGuard::$root = $fixture . '/item-failures';
        mkdir(PathGuard::$root, 0o700);
        mkdir(PathGuard::$root . '/site', 0o700);
        $issueJobs = new JobService();
        $nextSource = 20000;
        $makeIssueTask = static function (int $total) use ($issueJobs, &$nextSource): array {
            $sourceId = $nextSource++;
            $job = $issueJobs->createAnalysis($sourceId, '隔离测试' . $sourceId, hash('sha256', 'source-' . $sourceId));
            $running = $issueJobs->beginWork($job['task_id'], $job['revision']);
            $target = ['group'=>'其他', 'family'=>''];
            $items = [];
            for ($index = 0; $index < $total; $index++) {
                $items[] = ['code'=>sprintf('%04d', $index), 'category'=>'测试分类', 'stock'=>1, 'target'=>$target];
            }
            $analyzed = $issueJobs->storeAnalysisData($job['task_id'], $running['revision'],
                hash('sha256', 'issue-plan-' . $sourceId), $items,
                [['name'=>'测试分类', 'count'=>$total, 'target'=>$target, 'confidence'=>'high']]);
            return $issueJobs->confirmImport($job['task_id'], $analyzed['revision'], $analyzed['snapshot']['plan_hash'],
                10, workerMappings($analyzed['categories']));
        };
        $issueRuntime = [
            'lock_source'=>static fn(int $id): CatalogWorkerLease => new CatalogWorkerLease(),
            'load_source'=>static fn(int $id): object => workerSource($id),
            'fingerprint'=>static fn(object $source): string => hash('sha256', 'source-' . $source->id),
            'fetch_catalog'=>static function (): never { throw new RuntimeException('unexpected catalog request'); },
            'classify'=>static function (): never { throw new RuntimeException('unexpected classification'); },
            'assert_plan_capacity'=>static function (): void {},
            'begin_source'=>static function (): void {},
            'end_source'=>static function (): void {},
        ];
        $makeIssueWorker = static fn(callable $import): JobWorker => new JobWorker(new JobService(),
            $issueRuntime + ['import_planned_item'=>$import]);
        $isolated = static fn(string $code): CommodityImportFailure => new CommodityImportFailure($code,
            $code === CommodityImportFailure::ITEM_DATA_INVALID
                ? new RuntimeException('TOPSECRET https://supplier.invalid product=private')
                : ($code === CommodityImportFailure::DETAIL_JSON_INVALID
                    ? new UpstreamFailure('json', ['http_status'=>200,'curl_code'=>0,'elapsed_ms'=>10,'attempts'=>1,
                        'mime_category'=>'application_json','mime_count'=>1,'json_valid'=>false,'mime_compatibility'=>false,
                        'json_error_code'=>JSON_ERROR_SYNTAX,'json_error'=>'syntax'])
                    : new UpstreamFailure('transport', ['attempts'=>3, 'curl_code'=>28, 'http_status'=>0, 'elapsed_ms'=>100])));

        foreach ([CommodityImportFailure::DETAIL_TRANSPORT_FAILED, CommodityImportFailure::DETAIL_HTTP_RETRYABLE,
            CommodityImportFailure::DETAIL_ITEM_UNAVAILABLE, CommodityImportFailure::ITEM_DATA_INVALID,
            CommodityImportFailure::DETAIL_JSON_INVALID] as $safeCode) {
            $issueTask = $makeIssueTask(3);
            $calls = [];
            $issueWorker = $makeIssueWorker(static function (object $source, array $item) use (&$calls, $safeCode, $isolated): string {
                $index = (int)$item['code'];
                $calls[] = $index;
                if ($index === 0) { throw $isolated($safeCode); }
                return CommodityImporter::OUTCOME_CREATED;
            });
            $result = $issueWorker->runOne(20);
            $saved = $issueJobs->get($issueTask['task_id']);
            workerExpect($calls === [0,1,2], 'one isolated item stopped or retried the next item');
            workerExpect($result['status'] === JobStore::STATE_FAILED && $result['error_code'] === 'IMPORT_FINISHED_WITH_ISSUES',
                'issues were reported as successful completion');
            workerExpect($saved['progress'] === ['total'=>3,'processed'=>3,'succeeded'=>2,'failed'=>1,'skipped'=>0],
                'isolated item counters were not conserved');
            workerExpect($saved['item_failures'] === [['index'=>0,'code'=>$safeCode,
                'attempts'=>$safeCode === CommodityImportFailure::ITEM_DATA_INVALID ? 0
                    : ($safeCode === CommodityImportFailure::DETAIL_JSON_INVALID ? 1 : 3)]],
                'failure checkpoint has unsafe or inaccurate fields');
            workerExpect(!$saved['can_resume'], 'finished issues acquired an implicit retry entry');
            workerExpect(!preg_match('/TOPSECRET|https?:|supplier|product=private/', json_encode($saved)), 'item failure state leaked upstream data');
        }

        $consecutiveTask = $makeIssueTask(8);
        $calls = [];
        $alwaysFail = static function (object $source, array $item) use (&$calls, $isolated): never {
            $calls[] = (int)$item['code'];
            throw $isolated(CommodityImportFailure::DETAIL_ITEM_UNAVAILABLE);
        };
        $firstBatch = $makeIssueWorker($alwaysFail)->runOne(3);
        workerExpect($firstBatch['status'] === JobStore::STATE_QUEUED_IMPORT && $firstBatch['counts']['failed'] === 3,
            'isolated failures did not survive a bounded batch');
        $queued = $issueJobs->get($consecutiveTask['task_id']);
        $issueJobs->beginWork($queued['task_id'], $queued['revision']);
        $recoveredIssues = (new JobService())->recoverInterrupted();
        workerExpect(count($recoveredIssues) === 1 && count($recoveredIssues[0]['item_failures']) === 3,
            'restart recovery lost the item failure ledger');
        $limit = $makeIssueWorker($alwaysFail)->runOne(20);
        workerExpect($limit['error_code'] === 'IMPORT_ITEM_FAILURE_LIMIT' && $calls === [0,1,2,3,4]
            && $limit['counts']['processed'] === 5 && $limit['counts']['failed'] === 5,
            'five consecutive failures were not bounded across restart/batches');

        // Controls win the same atomic item checkpoint, including the item that
        // hits a stop threshold or completes a run with issues.
        foreach (['pause','cancel'] as $action) {
            foreach (['consecutive','cumulative','finished'] as $boundary) {
                $total = $boundary === 'cumulative' ? 205 : ($boundary === 'consecutive' ? 8 : 3);
                $trigger = $boundary === 'cumulative' ? 198 : ($boundary === 'consecutive' ? 4 : 2);
                $controlTask = $makeIssueTask($total);
                $calls = [];
                $callback = static function (object $source, array $item) use (&$calls, $issueJobs, $controlTask, $action, $boundary, $trigger, $isolated): string {
                    $index = (int)$item['code'];
                    $calls[] = $index;
                    if ($index === $trigger) {
                        $current = $issueJobs->get($controlTask['task_id']);
                        $issueJobs->control($current['task_id'], $current['revision'], $action);
                    }
                    if ($boundary === 'consecutive' || ($boundary === 'cumulative' && $index % 2 === 0)
                        || ($boundary === 'finished' && $index === 2)) {
                        throw $isolated(CommodityImportFailure::DETAIL_HTTP_RETRYABLE);
                    }
                    return CommodityImporter::OUTCOME_CREATED;
                };
                for ($batch = 0; $batch < 12; $batch++) {
                    $controlledResult = $makeIssueWorker($callback)->runOne(20);
                    if ($controlledResult['status'] !== JobStore::STATE_QUEUED_IMPORT) { break; }
                }
                $controlled = $issueJobs->get($controlTask['task_id']);
                workerExpect($controlled['state'] === ($action === 'pause' ? JobStore::STATE_PAUSED : JobStore::STATE_CANCELLED),
                    'threshold/finished status overrode a pending ' . $action);
                $expectedFailures = $boundary === 'cumulative' ? 100 : ($boundary === 'consecutive' ? 5 : 1);
                workerExpect($controlled['progress']['processed'] === $trigger + 1
                    && $controlled['progress']['failed'] === $expectedFailures
                    && count($controlled['item_failures']) === $expectedFailures
                    && $controlled['item_failures'][$expectedFailures - 1]['index'] === $trigger,
                    'control race dropped or truncated the trigger item failure');
                if ($action === 'pause') {
                    $issueJobs->control($controlled['task_id'], $controlled['revision'], 'resume');
                    $afterResume = $makeIssueWorker($callback)->runOne(20);
                    workerExpect(count($calls) === $trigger + 1, 'resuming a stopped boundary fetched another item');
                    workerExpect($afterResume['error_code'] === ($boundary === 'finished'
                        ? 'IMPORT_FINISHED_WITH_ISSUES' : 'IMPORT_ITEM_FAILURE_LIMIT'), 'resume bypassed an existing stop boundary');
                    workerExpect($issueJobs->get($controlled['task_id'])['item_failures'] === $controlled['item_failures'],
                        'resume rewrote the saved issue list');
                }
            }
        }

        $cumulativeTask = $makeIssueTask(205);
        $calls = [];
        $alternating = static function (object $source, array $item) use (&$calls, $isolated): string {
            $index = (int)$item['code']; $calls[] = $index;
            if ($index % 2 === 0) { throw $isolated(CommodityImportFailure::DETAIL_TRANSPORT_FAILED); }
            return CommodityImporter::OUTCOME_CREATED;
        };
        for ($batch = 0; $batch < 12; $batch++) {
            $cumulative = $makeIssueWorker($alternating)->runOne(20);
            if ($cumulative['status'] !== JobStore::STATE_QUEUED_IMPORT) { break; }
        }
        $saved = $issueJobs->get($cumulativeTask['task_id']);
        workerExpect($cumulative['error_code'] === 'IMPORT_ITEM_FAILURE_LIMIT' && count($calls) === 199
            && $saved['progress'] === ['total'=>205,'processed'=>199,'succeeded'=>99,'failed'=>100,'skipped'=>0]
            && count($saved['item_failures']) === 100 && $saved['item_failures'][99]['index'] === 198,
            'cumulative failures were truncated or the hundredth failure did not stop atomically');

        $ordinaryPauseTask = $makeIssueTask(3);
        $calls = [];
        $pauseAfterIssue = static function (object $source, array $item) use (&$calls, $issueJobs, $ordinaryPauseTask, $isolated): string {
            $index = (int)$item['code']; $calls[] = $index;
            if ($index === 0) {
                $current = $issueJobs->get($ordinaryPauseTask['task_id']);
                $issueJobs->control($current['task_id'], $current['revision'], 'pause');
                throw $isolated(CommodityImportFailure::ITEM_DATA_INVALID);
            }
            return CommodityImporter::OUTCOME_CREATED;
        };
        $ordinaryPause = $makeIssueWorker($pauseAfterIssue)->runOne(20);
        $pausedIssue = $issueJobs->get($ordinaryPauseTask['task_id']);
        workerExpect($ordinaryPause['status'] === JobStore::STATE_PAUSED && $calls === [0]
            && count($pausedIssue['item_failures']) === 1, 'ordinary pause did not preserve an isolated item');
        $issueJobs->control($pausedIssue['task_id'], $pausedIssue['revision'], 'resume');
        $ordinaryFinish = $makeIssueWorker($pauseAfterIssue)->runOne(20);
        workerExpect($calls === [0,1,2] && $ordinaryFinish['error_code'] === 'IMPORT_FINISHED_WITH_ISSUES'
            && $issueJobs->get($pausedIssue['task_id'])['item_failures'] === $pausedIssue['item_failures'],
            'ordinary pause/resume replayed or erased the isolated item');

        $fatalTask = $makeIssueTask(3);
        $calls = [];
        $fatalAfterIssue = $makeIssueWorker(static function (object $source, array $item) use (&$calls, $isolated): never {
            $index = (int)$item['code']; $calls[] = $index;
            if ($index === 0) { throw $isolated(CommodityImportFailure::ITEM_DATA_INVALID); }
            throw new RuntimeException('TOPSECRET unexpected database mutation failure');
        });
        $fatal = $fatalAfterIssue->runOne(20);
        $saved = $issueJobs->get($fatalTask['task_id']);
        workerExpect($fatal['error_code'] === 'ITEM_IMPORT_FAILED' && $calls === [0,1]
            && $saved['progress']['processed'] === 1 && count($saved['item_failures']) === 1,
            'fatal import failure was isolated or erased earlier issues');

        $checkpointTask = $makeIssueTask(3);
        $calls = [];
        $lockPath = PathGuard::$root . '/extensions/PikaCatalogHub/jobs/jobs.json.lock';
        $lockLink = $lockPath . '.checkpoint-test';
        $checkpointWorker = $makeIssueWorker(static function (object $source, array $item) use (&$calls, $isolated, $lockPath, $lockLink): string {
            $index = (int)$item['code']; $calls[] = $index;
            if ($index === 0) { throw $isolated(CommodityImportFailure::ITEM_DATA_INVALID); }
            workerExpect(link($lockPath, $lockLink), 'could not inject checkpoint lock rejection');
            return CommodityImporter::OUTCOME_CREATED;
        });
        $checkpointFailure = $checkpointWorker->runOne(20);
        $saved = $issueJobs->get($checkpointTask['task_id']);
        workerExpect($calls === [0,1] && $checkpointFailure['status'] === JobStore::STATE_FAILED
            && $saved['progress']['processed'] === 1 && $saved['progress']['failed'] === 1
            && count($saved['item_failures']) === 1, 'checkpoint failure lost the atomic prior ledger/cursor');
        unlink($lockLink);
        $cancelCheckpoint = $issueJobs->control($saved['task_id'], $saved['revision'], 'cancel');
        $issueJobs->checkpoint($saved['task_id'], $cancelCheckpoint['revision'], $saved['progress']);

        // Repair runs use the same immutable snapshot and only unresolved indexes.
        PathGuard::$root = $fixture . '/retry-failures';
        mkdir(PathGuard::$root, 0o700);
        mkdir(PathGuard::$root . '/site', 0o700);
        $repairTask = $makeIssueTask(5);
        $initialCalls = [];
        $makeIssueWorker(static function (object $source, array $item) use (&$initialCalls, $isolated): string {
            $index = (int)$item['code']; $initialCalls[] = $index;
            if (in_array($index, [1,3], true)) { throw $isolated(CommodityImportFailure::ITEM_DATA_INVALID); }
            return CommodityImporter::OUTCOME_CREATED;
        })->runOne(20);
        $beforeRepair = $issueJobs->get($repairTask['task_id']);
        $beforeRepairRaw = (new JobStore())->get($repairTask['task_id']);
        workerExpect($beforeRepair['can_retry_failed'] && $beforeRepair['progress']['failed'] === 2,
            'partial completion did not expose an explicit bounded repair action');
        $repairQueued = $issueJobs->control($repairTask['task_id'], $beforeRepair['revision'], 'retry_failed');
        $duplicateRepair = $issueJobs->control($repairTask['task_id'], $beforeRepair['revision'], 'retry_failed');
        workerExpect($duplicateRepair['revision'] === $repairQueued['revision'], 'duplicate repair click created another round');
        workerExpect($repairQueued['retry']['indices'] === [1,3] && $repairQueued['progress'] === $beforeRepair['progress'],
            'repair setup changed the scan ledger or selected a successful item');
        $repairCalls = [];
        $repairWorker = $makeIssueWorker(static function (object $source, array $item) use (&$repairCalls): string {
            $index = (int)$item['code']; $repairCalls[] = $index;
            return $index === 3 ? CommodityImporter::OUTCOME_ALREADY_MANAGED : CommodityImporter::OUTCOME_CREATED;
        });
        $repairWorker->runOne(1);
        $firstRepair = $issueJobs->get($repairTask['task_id']);
        workerExpect($repairCalls === [1] && $firstRepair['retry']['cursor'] === 1
            && $firstRepair['progress'] === ['total'=>5,'processed'=>5,'succeeded'=>4,'failed'=>1,'skipped'=>0],
            'first repair item did not atomically settle exactly one unresolved failure');
        $pausedRepair = $issueJobs->control($repairTask['task_id'], $firstRepair['revision'], 'pause');
        $refreshedRepair = (new JobService())->get($repairTask['task_id']);
        workerExpect($refreshedRepair['retry'] === $pausedRepair['retry'], 'refresh lost repair cursor or counters');
        workerFails(static fn() => $issueJobs->control($repairTask['task_id'], $beforeRepair['revision'], 'retry_failed'),
            'stale repair revision started another round after work had progressed');
        $issueJobs->control($repairTask['task_id'], $refreshedRepair['revision'], 'resume');
        $repairFinished = $repairWorker->runOne(20);
        $settled = $issueJobs->get($repairTask['task_id']);
        workerExpect($repairCalls === [1,3] && $repairFinished['status'] === JobStore::STATE_COMPLETED
            && $settled['progress'] === ['total'=>5,'processed'=>5,'succeeded'=>4,'failed'=>0,'skipped'=>1]
            && $settled['item_failures'] === [] && $settled['retry']['cursor'] === 2,
            'repair replayed successful items or double counted an idempotent existing result');
        $settledRaw = (new JobStore())->get($repairTask['task_id']);
        foreach (['snapshot','categories','premium_percent','mappings','source_fingerprint'] as $preserved) {
            workerExpect($settledRaw[$preserved] === $beforeRepairRaw[$preserved], 'repair changed a confirmed import binding');
        }

        $retryFailureTask = $makeIssueTask(2);
        $makeIssueWorker(static function (object $source, array $item) use ($isolated): string {
            if ((int)$item['code'] === 0) { throw $isolated(CommodityImportFailure::ITEM_DATA_INVALID); }
            return CommodityImporter::OUTCOME_CREATED;
        })->runOne(20);
        $retryFailureBefore = $issueJobs->get($retryFailureTask['task_id']);
        $issueJobs->control($retryFailureTask['task_id'], $retryFailureBefore['revision'], 'retry_failed');
        $retryFailureCalls = [];
        $makeIssueWorker(static function (object $source, array $item) use (&$retryFailureCalls, $isolated): never {
            $retryFailureCalls[] = (int)$item['code'];
            throw $isolated(CommodityImportFailure::DETAIL_ITEM_UNAVAILABLE);
        })->runOne(20);
        $retryStillFailed = $issueJobs->get($retryFailureTask['task_id']);
        workerExpect($retryFailureCalls === [0] && $retryStillFailed['progress'] === $retryFailureBefore['progress']
            && $retryStillFailed['item_failures'] === [['index'=>0,'code'=>CommodityImportFailure::DETAIL_ITEM_UNAVAILABLE,'attempts'=>3]]
            && $retryStillFailed['retry']['failed'] === 1 && $retryStillFailed['state'] === JobStore::STATE_FAILED,
            'failed repair lost its latest reason, doubled failures or reported full success');
        $issueJobs->control($retryFailureTask['task_id'], $retryStillFailed['revision'], 'retry_failed');
        $fatalRetry = $makeIssueWorker(static function () : never {
            throw new CommodityImportFailure(CommodityImportFailure::DETAIL_CREDENTIALS_INVALID,
                new UpstreamFailure('credentials', ['attempts'=>1]));
        })->runOne(20);
        $fatalRetryState = $issueJobs->get($retryFailureTask['task_id']);
        workerExpect($fatalRetry['error_code'] === CommodityImportFailure::DETAIL_CREDENTIALS_INVALID
            && $fatalRetryState['retry']['cursor'] === 0 && $fatalRetryState['retry']['failed'] === 0
            && $fatalRetryState['progress'] === $retryStillFailed['progress']
            && $fatalRetryState['item_failures'] === $retryStillFailed['item_failures'],
            'source-wide failure was isolated or advanced the repair cursor');

        $repairFuseTask = $makeIssueTask(8);
        $makeIssueWorker($alwaysFail)->runOne(20);
        $fusedScan = $issueJobs->get($repairFuseTask['task_id']);
        workerExpect($fusedScan['progress']['processed'] === 5 && $fusedScan['progress']['failed'] === 5,
            'repair fuse fixture did not stop at the existing five-item threshold');
        $issueJobs->control($repairFuseTask['task_id'], $fusedScan['revision'], 'retry_failed');
        $fuseCalls = [];
        $repairAlwaysFails = $makeIssueWorker(static function (object $source, array $item) use (&$fuseCalls, $isolated): never {
            $fuseCalls[] = (int)$item['code'];
            throw $isolated(CommodityImportFailure::ITEM_DATA_INVALID);
        });
        $repairAlwaysFails->runOne(3);
        $fusePartial = $issueJobs->get($repairFuseTask['task_id']);
        $fusePaused = $issueJobs->control($repairFuseTask['task_id'], $fusePartial['revision'], 'pause');
        $issueJobs->control($repairFuseTask['task_id'], $fusePaused['revision'], 'resume');
        $repairAlwaysFails->runOne(20);
        $fusedRepair = $issueJobs->get($repairFuseTask['task_id']);
        workerExpect($fuseCalls === [0,1,2,3,4] && !$fusedRepair['retry']['halted']
            && $fusedRepair['retry']['failed'] === 5 && $fusedRepair['retry']['consecutive_failed'] === 5
            && $fusedRepair['progress'] === $fusedScan['progress'] && $fusedRepair['can_resume']
            && $fusedRepair['state'] === JobStore::STATE_PAUSED && !$fusedRepair['can_retry_failed'],
            'bounded retry halted early, reset its counters, or silently resumed the original scan');
        $issueJobs->control($repairFuseTask['task_id'], $fusedRepair['revision'], 'resume');
        $originalScanGate = $repairAlwaysFails->runOne(20);
        $originalScanGateState = $issueJobs->get($repairFuseTask['task_id']);
        workerExpect($originalScanGate['error_code'] === 'IMPORT_ITEM_FAILURE_LIMIT'
            && $fuseCalls === [0,1,2,3,4] && $originalScanGateState['retry'] === $fusedRepair['retry'],
            'explicit original-scan resume bypassed its unchanged failure threshold');
        $issueJobs->control($repairFuseTask['task_id'], $originalScanGateState['revision'], 'retry_failed');
        $fixedCalls = [];
        $fixedWorker = $makeIssueWorker(static function (object $source, array $item) use (&$fixedCalls): string {
            $fixedCalls[] = (int)$item['code']; return CommodityImporter::OUTCOME_CREATED;
        });
        $fixedWorker->runOne(20);
        $fixedSubset = $issueJobs->get($repairFuseTask['task_id']);
        workerExpect($fixedCalls === [0,1,2,3,4] && $fixedSubset['state'] === JobStore::STATE_PAUSED
            && $fixedSubset['progress'] === ['total'=>8,'processed'=>5,'succeeded'=>5,'failed'=>0,'skipped'=>0],
            'repair silently scanned items outside its exact frozen subset');
        $issueJobs->control($repairFuseTask['task_id'], $fixedSubset['revision'], 'resume');
        $fixedWorker->runOne(20);
        workerExpect($fixedCalls === [0,1,2,3,4,5,6,7]
            && $issueJobs->get($repairFuseTask['task_id'])['state'] === JobStore::STATE_COMPLETED,
            'explicit continue did not resume the remaining original scan');

        foreach (['pause','cancel'] as $repairControl) {
            $controlledRepairTask = $makeIssueTask(2);
            $makeIssueWorker(static function (object $source, array $item) use ($isolated): string {
                if ((int)$item['code'] === 0) { throw $isolated(CommodityImportFailure::ITEM_DATA_INVALID); }
                return CommodityImporter::OUTCOME_CREATED;
            })->runOne(20);
            $controlledBefore = $issueJobs->get($controlledRepairTask['task_id']);
            $issueJobs->control($controlledRepairTask['task_id'], $controlledBefore['revision'], 'retry_failed');
            $controlledCalls = [];
            $makeIssueWorker(static function (object $source, array $item) use ($issueJobs, $controlledRepairTask, $repairControl, &$controlledCalls): string {
                $controlledCalls[] = (int)$item['code'];
                $current = $issueJobs->get($controlledRepairTask['task_id']);
                $issueJobs->control($current['task_id'], $current['revision'], $repairControl);
                return CommodityImporter::OUTCOME_CREATED;
            })->runOne(20);
            $controlledAfter = $issueJobs->get($controlledRepairTask['task_id']);
            workerExpect($controlledCalls === [0] && $controlledAfter['state'] === ($repairControl === 'pause' ? JobStore::STATE_PAUSED : JobStore::STATE_CANCELLED)
                && $controlledAfter['retry']['cursor'] === 1 && $controlledAfter['progress']['failed'] === 0,
                'control at the repair transaction boundary lost the settled item or was ignored');
            if ($repairControl === 'pause') {
                $issueJobs->control($controlledAfter['task_id'], $controlledAfter['revision'], 'resume');
                $repeatedRepairCalls = 0;
                $afterPause = $makeIssueWorker(static function () use (&$repeatedRepairCalls): never {
                    $repeatedRepairCalls++;
                    throw new RuntimeException('completed repair repeated HTTP');
                })->runOne(20);
                $afterPauseSaved = $issueJobs->get($controlledAfter['task_id']);
                workerExpect($repeatedRepairCalls === 0 && $afterPause['status'] === JobStore::STATE_COMPLETED
                    && $afterPauseSaved['state'] === JobStore::STATE_COMPLETED
                    && $afterPauseSaved['progress'] === $controlledAfter['progress'],
                    'resuming a fully settled paused repair replayed an item or failed completion');
            }
        }

        // The fifth isolated failure still settles an in-flight pause or cancel, without a retry fuse.
        foreach (['pause', 'cancel'] as $fuseControl) {
            $controlledFuseTask = $makeIssueTask(5);
            $makeIssueWorker($alwaysFail)->runOne(20);
            $fuseBefore = $issueJobs->get($controlledFuseTask['task_id']);
            $issueJobs->control($fuseBefore['task_id'], $fuseBefore['revision'], 'retry_failed');
            $makeIssueWorker($alwaysFail)->runOne(4);
            $makeIssueWorker(static function (object $source, array $item) use ($issueJobs, $controlledFuseTask, $fuseControl, $isolated): never {
                workerExpect((int)$item['code'] === 4, 'fuse/control race did not run the fifth exact repair index');
                $current = $issueJobs->get($controlledFuseTask['task_id']);
                $issueJobs->control($current['task_id'], $current['revision'], $fuseControl);
                throw $isolated(CommodityImportFailure::ITEM_DATA_INVALID);
            })->runOne(20);
            $fuseAfter = $issueJobs->get($controlledFuseTask['task_id']);
            workerExpect($fuseAfter['state'] === ($fuseControl === 'pause' ? JobStore::STATE_PAUSED : JobStore::STATE_CANCELLED)
                && $fuseAfter['retry']['halted'] === false && $fuseAfter['retry']['cursor'] === 5
                && $fuseAfter['retry']['consecutive_failed'] === 5
                && $fuseAfter['progress'] === $fuseBefore['progress']
                && $fuseAfter['can_resume'] === ($fuseControl === 'pause'),
                'the fifth retry failure overrode control or lost the settled failure counters');
            if ($fuseControl === 'pause') {
                workerExpect($fuseAfter['can_retry_failed'] === false,
                    'paused completed retry bypassed the original new-round eligibility gate');
                $issueJobs->control($fuseAfter['task_id'], $fuseAfter['revision'], 'cancel');
            } else {
                workerExpect($fuseAfter['can_retry_failed'] === false, 'cancelled repair still exposed another round');
            }
        }

        // A committed item followed by process death is settled as the native managed no-op.
        $repairCrashTask = $makeIssueTask(2);
        $makeIssueWorker(static function (object $source, array $item) use ($isolated): string {
            if ((int)$item['code'] === 0) { throw $isolated(CommodityImportFailure::ITEM_DATA_INVALID); }
            return CommodityImporter::OUTCOME_CREATED;
        })->runOne(20);
        $crashBefore = $issueJobs->get($repairCrashTask['task_id']);
        $crashQueued = $issueJobs->control($crashBefore['task_id'], $crashBefore['revision'], 'retry_failed');
        $crashClaim = $issueJobs->beginWork($crashQueued['task_id'], $crashQueued['revision']);
        // The importer transaction is represented by one existing managed product; no job checkpoint was written.
        $committedProducts = [0 => true];
        $repairRecovered = $issueJobs->recoverInterrupted();
        $recoveredRepair = $issueJobs->get($crashClaim['task_id']);
        workerExpect(count($repairRecovered) === 1 && $recoveredRepair['state'] === JobStore::STATE_QUEUED_IMPORT
            && $recoveredRepair['retry'] === $crashClaim['retry'] && $recoveredRepair['progress'] === $crashBefore['progress'],
            'interrupted repair lost the round or settled an uncheckpointed item twice');
        $restartCalls = [];
        $makeIssueWorker(static function (object $source, array $item) use (&$restartCalls, &$committedProducts): string {
            $index = (int)$item['code']; $restartCalls[] = $index;
            if (isset($committedProducts[$index])) { return CommodityImporter::OUTCOME_ALREADY_MANAGED; }
            $committedProducts[$index] = true;
            return CommodityImporter::OUTCOME_CREATED;
        })->runOne(20);
        $crashSettled = $issueJobs->get($crashClaim['task_id']);
        workerExpect($restartCalls === [0] && count($committedProducts) === 1
            && $crashSettled['state'] === JobStore::STATE_COMPLETED
            && $crashSettled['progress'] === ['total'=>2,'processed'=>2,'succeeded'=>1,'failed'=>0,'skipped'=>1],
            'repair recovery replayed a settled snapshot item or duplicated a committed product');

        // Production-sized counters with synthetic indexes only; no real supplier or product records.
        $starvationIndices = range(1800, 1826, 2);
        foreach (['new', JobStore::STATE_FAILED, JobStore::STATE_PAUSED] as $roundState) {
            $starvationTask = $makeIssueTask(5187);
            $starvationClaim = $issueJobs->beginWork($starvationTask['task_id'], $starvationTask['revision']);
            $starvationFailures = array_map(static fn(int $index): array =>
                ['index'=>$index,'code'=>CommodityImportFailure::DETAIL_ITEM_UNAVAILABLE,'attempts'=>1],
                $starvationIndices);
            $starvationBefore = $issueJobs->checkpoint(
                $starvationClaim['task_id'], $starvationClaim['revision'],
                ['total'=>5187,'processed'=>5187,'succeeded'=>1959,'failed'=>14,'skipped'=>3214],
                $starvationFailures,
            );
            if ($roundState !== 'new') {
                $legacyRetryRaw = (new JobStore())->get($starvationBefore['task_id']);
                $legacyRetryRaw['state'] = $roundState;
                $legacyRetryRaw['error_code'] = 'IMPORT_ITEM_FAILURE_LIMIT';
                $legacyRetryRaw['last_action'] = $roundState === JobStore::STATE_PAUSED ? 'pause' : 'retry_failed';
                $legacyRetryRaw['retry'] = [
                    'origin_error_code'=>'IMPORT_FINISHED_WITH_ISSUES', 'indices'=>$starvationIndices,
                    'cursor'=>5, 'succeeded'=>0, 'skipped'=>0, 'failed'=>5,
                    'consecutive_failed'=>5, 'halted'=>true,
                ];
                AtomicJson::update(PathGuard::$root . '/extensions/PikaCatalogHub/jobs/jobs.json', [],
                    static function (array $state) use ($legacyRetryRaw): array {
                        $state['jobs'][$legacyRetryRaw['task_id']] = $legacyRetryRaw;
                        return $state;
                    });
                $starvationBefore = $issueJobs->get($starvationBefore['task_id']);
            }
            $starvationQueued = $issueJobs->control(
                $starvationBefore['task_id'], $starvationBefore['revision'], 'retry_failed',
            );
            $starvationDuplicate = $issueJobs->control(
                $starvationBefore['task_id'], $starvationBefore['revision'], 'retry_failed',
            );
            workerExpect($starvationQueued === $starvationDuplicate
                && $starvationQueued['retry']['indices'] === $starvationIndices
                && $starvationQueued['retry']['cursor'] === ($roundState === 'new' ? 0 : 5)
                && $starvationQueued['retry']['failed'] === ($roundState === 'new' ? 0 : 5)
                && $starvationQueued['progress'] === $starvationBefore['progress'],
                'explicit retry replaced the frozen list, reset the legacy cursor or duplicated its control');
            $starvationCalls = [];
            $starvationWorker = $makeIssueWorker(static function (object $source, array $item) use (
                &$starvationCalls, $starvationIndices, $isolated,
            ): string {
                $index = (int)$item['code'];
                $starvationCalls[] = $index;
                $position = array_search($index, $starvationIndices, true);
                workerExpect(is_int($position), 'fixed retry called an index outside its frozen list');
                if ($position < 5) {
                    throw new CommodityImportFailure(CommodityImportFailure::DETAIL_ITEM_UNAVAILABLE,
                        new UpstreamFailure('item_unavailable', [
                            'http_status'=>200,'curl_code'=>0,'elapsed_ms'=>4,'attempts'=>1,
                            'mime_category'=>'application_json','mime_count'=>1,'json_valid'=>true,'mime_compatibility'=>false,
                        ]));
                }
                if ($position === 13) { throw $isolated(CommodityImportFailure::DETAIL_JSON_INVALID); }
                return CommodityImporter::OUTCOME_CREATED;
            });
            if ($roundState === 'new') {
                $starvationWorker->runOne(5);
                $afterFive = $issueJobs->get($starvationTask['task_id']);
                workerExpect($starvationCalls === array_slice($starvationIndices, 0, 5)
                    && $afterFive['state'] === JobStore::STATE_QUEUED_IMPORT
                    && $afterFive['retry']['cursor'] === 5 && $afterFive['retry']['failed'] === 5
                    && !$afterFive['retry']['halted'] && $afterFive['progress'] === $starvationBefore['progress']
                    && $afterFive['last_detail_diagnostic']['diagnostics']['json_valid'] === true,
                    'five unavailable JSON items starved the unvisited tail or altered the original ledger');
                $starvationPause = $issueJobs->control($afterFive['task_id'], $afterFive['revision'], 'pause');
                $starvationResume = $issueJobs->control($afterFive['task_id'], $starvationPause['revision'], 'resume');
                workerExpect($starvationResume['retry'] === $starvationPause['retry'],
                    'paused new round reset the visited head or its failure counters');
            }
            $starvationWorker->runOne(20);
            $starvationAfter = $issueJobs->get($starvationTask['task_id']);
            workerExpect($starvationCalls === ($roundState === 'new' ? $starvationIndices : array_slice($starvationIndices, 5))
                && count(array_unique($starvationCalls)) === count($starvationCalls)
                && $starvationAfter['state'] === JobStore::STATE_FAILED
                && $starvationAfter['error_code'] === 'IMPORT_FINISHED_WITH_ISSUES'
                && $starvationAfter['retry']['cursor'] === 14 && $starvationAfter['retry']['succeeded'] === 8
                && $starvationAfter['retry']['failed'] === 6 && !$starvationAfter['retry']['halted']
                && $starvationAfter['progress'] === ['total'=>5187,'processed'=>5187,'succeeded'=>1967,'failed'=>6,'skipped'=>3214]
                && array_slice($starvationAfter['item_failures'], 0, 5) === array_slice($starvationFailures, 0, 5)
                && $starvationAfter['snapshot'] === $starvationBefore['snapshot'],
                'mixed retry replayed its head, skipped healthy tail items or polluted historical counts');
            workerExpect($starvationWorker->runOne(20)['status'] === 'idle',
                'a completed mixed retry automatically started another round');
        }

        // A fixed hundred-item round visits every index once despite all outcomes remaining failed.
        $hundredTask = $makeIssueTask(205);
        $hundredClaim = $issueJobs->beginWork($hundredTask['task_id'], $hundredTask['revision']);
        $hundredIndices = range(0, 198, 2);
        $hundredFailures = array_map(static fn(int $index): array =>
            ['index'=>$index,'code'=>CommodityImportFailure::ITEM_DATA_INVALID,'attempts'=>0], $hundredIndices);
        $hundredBefore = $issueJobs->checkpoint($hundredClaim['task_id'], $hundredClaim['revision'],
            ['total'=>205,'processed'=>199,'succeeded'=>99,'failed'=>100,'skipped'=>0], $hundredFailures);
        $issueJobs->control($hundredBefore['task_id'], $hundredBefore['revision'], 'retry_failed');
        $hundredCalls = [];
        $hundredWorker = $makeIssueWorker(static function (object $source, array $item) use (&$hundredCalls, $isolated): never {
            $hundredCalls[] = (int)$item['code'];
            throw $isolated(CommodityImportFailure::ITEM_DATA_INVALID);
        });
        for ($batch = 0; $batch < 5; $batch++) {
            $hundredWorker->runOne(20);
            $hundredAfter = $issueJobs->get($hundredTask['task_id']);
            workerExpect($hundredAfter['retry']['cursor'] === ($batch + 1) * 20
                && $hundredAfter['retry']['failed'] === ($batch + 1) * 20
                && !$hundredAfter['retry']['halted'], 'fixed hundred-item retry stopped before its tail');
        }
        workerExpect($hundredCalls === $hundredIndices && $hundredAfter['state'] === JobStore::STATE_PAUSED
            && $hundredAfter['progress'] === $hundredBefore['progress']
            && $hundredAfter['retry']['consecutive_failed'] === 100,
            'hundred-item retry exceeded its list, lost failures or resumed the original scan');
        $issueJobs->control($hundredAfter['task_id'], $hundredAfter['revision'], 'cancel');

        // Source/security/budget/database and unknown outcomes never consume the current retry index.
        foreach ([
            ['credentials', CommodityImportFailure::DETAIL_CREDENTIALS_INVALID],
            ['business', CommodityImportFailure::DETAIL_BUSINESS_REJECTED],
            ['tls', CommodityImportFailure::DETAIL_RESPONSE_INVALID],
            ['ssrf', CommodityImportFailure::DETAIL_RESPONSE_INVALID],
            ['schema', CommodityImportFailure::DETAIL_RESPONSE_INVALID],
            ['budget', CommodityImportFailure::DETAIL_BUDGET_EXCEEDED],
            ['database', CommodityImportFailure::PERSISTENCE_FAILED],
            ['unknown', 'ITEM_IMPORT_OUTCOME_INVALID'],
        ] as [$fatalCategory, $fatalCode]) {
            $fatalTask = $makeIssueTask(4);
            $fatalClaim = $issueJobs->beginWork($fatalTask['task_id'], $fatalTask['revision']);
            $fatalFailures = array_map(static fn(int $index): array =>
                ['index'=>$index,'code'=>CommodityImportFailure::ITEM_DATA_INVALID,'attempts'=>0], [0,1,2]);
            $fatalBefore = $issueJobs->checkpoint($fatalClaim['task_id'], $fatalClaim['revision'],
                ['total'=>4,'processed'=>4,'succeeded'=>1,'failed'=>3,'skipped'=>0], $fatalFailures);
            $issueJobs->control($fatalBefore['task_id'], $fatalBefore['revision'], 'retry_failed');
            $fatalCalls = [];
            $fatalWorker = $makeIssueWorker(static function (object $source, array $item) use (
                &$fatalCalls, $fatalCategory, $fatalCode,
            ): string {
                $index = (int)$item['code']; $fatalCalls[] = $index;
                if ($index === 0) { return CommodityImporter::OUTCOME_CREATED; }
                if ($fatalCategory === 'unknown') { return 'unknown-write-outcome'; }
                throw new CommodityImportFailure($fatalCode, $fatalCategory === 'database'
                    ? new RuntimeException('synthetic database failure')
                    : new UpstreamFailure($fatalCategory, ['attempts'=>1]));
            });
            $fatalWorker->runOne(20);
            $fatalAfter = $issueJobs->get($fatalTask['task_id']);
            workerExpect($fatalCalls === [0,1] && $fatalAfter['error_code'] === $fatalCode
                && $fatalAfter['retry']['cursor'] === 1 && $fatalAfter['retry']['succeeded'] === 1
                && $fatalAfter['retry']['failed'] === 0
                && $fatalAfter['progress'] === ['total'=>4,'processed'=>4,'succeeded'=>2,'failed'=>2,'skipped'=>0]
                && $fatalAfter['item_failures'] === array_slice($fatalFailures, 1)
                && !$fatalAfter['can_retry_failed'] && !$fatalAfter['can_resume'],
                'fatal retry error consumed its current index, reset prior settlement or gained a retry entry');
            workerExpect($fatalWorker->runOne(20)['status'] === 'idle' && $fatalCalls === [0,1],
                'fatal retry error automatically repeated the failed or unknown write');
        }

        // Safe MIME observations are counted only when the corresponding item settles successfully.
        $diagnosticTask = $makeIssueTask(2);
        $lastObservation = null;
        $diagnosticRuntime = $issueRuntime + [
            'detail_diagnostics' => static function () use (&$lastObservation): ?array { return $lastObservation; },
            'import_planned_item' => static function (object $source, array $item) use (&$lastObservation): string {
                $index = (int)$item['code'];
                $lastObservation = ['category'=>'none', 'http_status'=>200,
                    'curl_code'=>0, 'elapsed_ms'=>4, 'attempts'=>1, 'mime_category'=>'text_html',
                    'mime_count'=>1, 'json_valid'=>true, 'mime_compatibility'=>true,
                    'body'=>'TOPSECRET', 'headers'=>['secret'=>'TOPSECRET']];
                if ($index === 1) {
                    throw new CommodityImportFailure(CommodityImportFailure::ITEM_DATA_INVALID,
                        new UpstreamFailure('schema', $lastObservation));
                }
                return CommodityImporter::OUTCOME_CREATED;
            },
        ];
        $observeThenReject = $diagnosticRuntime['import_planned_item'];
        (new JobWorker($issueJobs, $diagnosticRuntime))->runOne(20);
        $diagnosed = $issueJobs->get($diagnosticTask['task_id']);
        workerExpect($diagnosed['detail_compatibility_count'] === 1
            && $diagnosed['last_detail_diagnostic']['index'] === 1
            && $diagnosed['last_detail_diagnostic']['diagnostics']['category'] === 'none'
            && !str_contains(json_encode($diagnosed, JSON_THROW_ON_ERROR), 'TOPSECRET'),
            'worker diagnostics leaked supplier data, counted a rejected item or lost its latest safe failure');
        $issueJobs->control($diagnosed['task_id'], $diagnosed['revision'], 'retry_failed');
        $diagnosticRuntime['import_planned_item'] = static function (object $source, array $item): string {
            workerExpect((int)$item['code'] === 1, 'diagnostic repair repeated an already successful item');
            return CommodityImporter::OUTCOME_CREATED;
        };
        (new JobWorker($issueJobs, $diagnosticRuntime))->runOne(20);
        $diagnosticRepair = $issueJobs->get($diagnosed['task_id']);
        workerExpect($diagnosticRepair['state'] === JobStore::STATE_COMPLETED
            && $diagnosticRepair['detail_compatibility_count'] === 2
            && $diagnosticRepair['last_detail_diagnostic'] === $diagnosed['last_detail_diagnostic']
            && $diagnosticRepair['progress'] === ['total'=>2,'processed'=>2,'succeeded'=>2,'failed'=>0,'skipped'=>0],
            'identical safe summaries suppressed a distinct successful repair checkpoint');

        $noHttpTask = $makeIssueTask(2);
        $diagnosticRuntime['import_planned_item'] = $observeThenReject;
        (new JobWorker($issueJobs, $diagnosticRuntime))->runOne(20);
        $noHttpBefore = $issueJobs->get($noHttpTask['task_id']);
        $issueJobs->control($noHttpBefore['task_id'], $noHttpBefore['revision'], 'retry_failed');
        $lastObservation = null;
        $diagnosticRuntime['import_planned_item'] = static function (object $source, array $item): string {
            workerExpect((int)$item['code'] === 1, 'managed no-op repair repeated a successful item');
            return CommodityImporter::OUTCOME_ALREADY_MANAGED;
        };
        (new JobWorker($issueJobs, $diagnosticRuntime))->runOne(20);
        $noHttpAfter = $issueJobs->get($noHttpTask['task_id']);
        workerExpect($noHttpAfter['state'] === JobStore::STATE_COMPLETED
            && $noHttpAfter['detail_compatibility_count'] === $noHttpBefore['detail_compatibility_count']
            && $noHttpAfter['last_detail_diagnostic'] === $noHttpBefore['last_detail_diagnostic']
            && $noHttpAfter['progress'] === ['total'=>2,'processed'=>2,'succeeded'=>1,'failed'=>0,'skipped'=>1],
            'no-HTTP repair reused a stale diagnostic as a new compatibility event or failed to settle');

        // A production-sized snapshot is resumed at exactly index 3244 with its 10% decision.
        $largeTask = $makeIssueTask(5137);
        $largeRunning = $issueJobs->beginWork($largeTask['task_id'], $largeTask['revision']);
        $largeCheckpoint = $issueJobs->checkpoint($largeTask['task_id'], $largeRunning['revision'],
            ['total'=>5137,'processed'=>3244,'succeeded'=>1331,'failed'=>0,'skipped'=>1913]);
        $largeStopped = $issueJobs->fail($largeTask['task_id'], $largeCheckpoint['revision'], CommodityImportFailure::DETAIL_FETCH_FAILED);
        $issueJobs->control($largeTask['task_id'], $largeStopped['revision'], 'resume');
        $largeCalls = [];
        $largeWorker = $makeIssueWorker(static function (object $source, array $item, string $alias, array $target, string $planHash, object $options) use (&$largeCalls, $largeStopped): string {
            $largeCalls[] = (int)$item['code'];
            workerExpect($options->premiumPercent === 10.0 && $planHash === $largeStopped['snapshot']['plan_hash'],
                'large resumed import changed premium or its confirmed plan');
            return CommodityImporter::OUTCOME_CREATED;
        });
        $largeWorker->runOne(1);
        $largeAfter = $issueJobs->get($largeTask['task_id']);
        workerExpect($largeCalls === [3244] && $largeAfter['progress'] ===
            ['total'=>5137,'processed'=>3245,'succeeded'=>1332,'failed'=>0,'skipped'=>1913]
            && $largeAfter['snapshot'] === $largeStopped['snapshot'], 'large resume rescanned completed products or skipped the failed checkpoint');
        $issueJobs->control($largeTask['task_id'], $largeAfter['revision'], 'cancel');

        // Production-sized metadata only: no upstream requests or product database fixtures.
        $jsonTask = $makeIssueTask(5187);
        $jsonClaim = $issueJobs->beginWork($jsonTask['task_id'], $jsonTask['revision']);
        $priorFailures = [['index'=>1829,'code'=>CommodityImportFailure::DETAIL_ITEM_UNAVAILABLE,'attempts'=>1]];
        $jsonCheckpoint = $issueJobs->checkpoint($jsonTask['task_id'], $jsonClaim['revision'],
            ['total'=>5187,'processed'=>2566,'succeeded'=>46,'failed'=>1,'skipped'=>2519], $priorFailures);
        $oldJson = ['category'=>'json','http_status'=>200,'curl_code'=>0,'elapsed_ms'=>148,'attempts'=>1,
            'mime_category'=>'text_html','mime_count'=>1,'json_valid'=>false,'mime_compatibility'=>true];
        $jsonStopped = $issueJobs->fail($jsonTask['task_id'], $jsonCheckpoint['revision'],
            CommodityImportFailure::DETAIL_RESPONSE_INVALID, ['index'=>2566,'diagnostics'=>$oldJson]);
        workerExpect($jsonStopped['can_resume'] && !$jsonStopped['can_retry_failed'],
            'legacy JSON scan checkpoint did not expose only its explicit continuation');
        $jsonResumed = $issueJobs->control($jsonTask['task_id'], $jsonStopped['revision'], 'resume');
        workerExpect($jsonResumed['progress'] === $jsonStopped['progress']
            && $jsonResumed['item_failures'] === $priorFailures, 'resume erased the earlier isolated item');
        $jsonCalls = [];
        $jsonWorker = $makeIssueWorker(static function (object $source, array $item, string $alias, array $target,
            string $planHash, object $options) use (&$jsonCalls, $oldJson, $jsonStopped): string {
            $index = (int)$item['code']; $jsonCalls[] = $index;
            workerExpect($options->premiumPercent === 10.0 && $planHash === $jsonStopped['snapshot']['plan_hash'],
                'JSON continuation changed the confirmed price decision or plan');
            if ($index === 2566) {
                throw new CommodityImportFailure(CommodityImportFailure::DETAIL_JSON_INVALID,
                    new UpstreamFailure('json', $oldJson + ['json_error_code'=>JSON_ERROR_SYNTAX,'json_error'=>'syntax']));
            }
            return $index === 2567 ? CommodityImporter::OUTCOME_CREATED : CommodityImporter::OUTCOME_ALREADY_MANAGED;
        });
        $jsonWorker->runOne(1);
        $firstJson = $issueJobs->get($jsonTask['task_id']);
        workerExpect($jsonCalls === [2566] && $firstJson['progress'] ===
            ['total'=>5187,'processed'=>2567,'succeeded'=>46,'failed'=>2,'skipped'=>2519]
            && array_column($firstJson['item_failures'], 'index') === [1829,2566]
            && $firstJson['last_detail_diagnostic']['diagnostics']['json_error'] === 'syntax',
            'JSON isolation retried, lost a ledger entry, or failed to checkpoint the current item');
        $jsonPause = $issueJobs->control($firstJson['task_id'], $firstJson['revision'], 'pause');
        $jsonRefresh = (new JobService())->get($jsonTask['task_id']);
        workerExpect($jsonRefresh['item_failures'] === $firstJson['item_failures']
            && $jsonRefresh['progress'] === $firstJson['progress'], 'pause or refresh altered JSON progress');
        $issueJobs->control($jsonTask['task_id'], $jsonPause['revision'], 'resume');
        for ($batch = 0; $batch < 131; $batch++) {
            $jsonResult = $jsonWorker->runOne(20);
            if ($jsonResult['status'] !== JobStore::STATE_QUEUED_IMPORT) { break; }
        }
        $jsonScanned = $issueJobs->get($jsonTask['task_id']);
        workerExpect($jsonCalls === range(2566, 5186)
            && $jsonScanned['error_code'] === 'IMPORT_FINISHED_WITH_ISSUES'
            && $jsonScanned['progress'] === ['total'=>5187,'processed'=>5187,'succeeded'=>47,'failed'=>2,'skipped'=>5138]
            && $jsonScanned['can_retry_failed'] && !$jsonScanned['can_resume'],
            'bounded original scan repeated processed items or silently started a retry round');
        $jsonRetry = $issueJobs->control($jsonTask['task_id'], $jsonScanned['revision'], 'retry_failed');
        workerExpect($jsonRetry['retry']['indices'] === [1829,2566], 'retry did not freeze exactly unresolved indexes');
        $jsonRetryCalls = [];
        $makeIssueWorker(static function (object $source, array $item) use (&$jsonRetryCalls): string {
            $index = (int)$item['code']; $jsonRetryCalls[] = $index;
            return $index === 1829 ? CommodityImporter::OUTCOME_ALREADY_MANAGED : CommodityImporter::OUTCOME_CREATED;
        })->runOne(20);
        $jsonFinished = $issueJobs->get($jsonTask['task_id']);
        workerExpect($jsonRetryCalls === [1829,2566] && $jsonFinished['state'] === JobStore::STATE_COMPLETED
            && $jsonFinished['progress'] === ['total'=>5187,'processed'=>5187,'succeeded'=>48,'failed'=>0,'skipped'=>5139]
            && $jsonFinished['item_failures'] === [] && $jsonFinished['snapshot'] === $jsonStopped['snapshot']
            && count($jsonCalls) + count($jsonRetryCalls) === 2623
            && count(array_unique([...$jsonCalls, ...$jsonRetryCalls])) === 2622,
            'one explicit retry round rescanned settled items or duplicated outcome counters');

        PathGuard::$root = $fixture . '/mirror-worker';
        mkdir(PathGuard::$root, 0o700);
        mkdir(PathGuard::$root . '/site', 0o700);
        $mirrorJobs = new JobService();
        $mirrorEvents = [];
        $mirrorImports = [];
        $mirrorBroken = false;
        $mirrorUnsupported = false;
        $mirrorCapacityFailure = false;
        $mirrorCatalog = (new UpstreamCategoryTree())->flatten([
            'schema'=>1, 'capability'=>'pika_category_tree',
            'categories'=>[
                ['id'=>31, 'pid'=>0, 'name'=>'Telegram', 'sort'=>0],
                ['id'=>32, 'pid'=>31, 'name'=>'货源A', 'sort'=>0],
                ['id'=>33, 'pid'=>32, 'name'=>'同名子分类', 'sort'=>0],
                ['id'=>41, 'pid'=>0, 'name'=>'另一父级', 'sort'=>0],
                ['id'=>42, 'pid'=>41, 'name'=>'同名子分类', 'sort'=>0],
            ],
            'items'=>[
                ['code'=>'mirror-a', 'category_id'=>33, 'name'=>'仅内存商品甲', 'stock'=>1],
                ['code'=>'mirror-b', 'category_id'=>42, 'name'=>'仅内存商品乙', 'stock'=>2],
            ],
        ]);
        $mirrorRuntime = [
            'lock_source'=>static fn(int $id): CatalogWorkerLease => new CatalogWorkerLease(),
            'load_source'=>static fn(int $id): object => workerSource($id),
            'fingerprint'=>static fn(object $source): string => hash('sha256', 'mirror-' . $source->id),
            'fetch_catalog'=>static function (object $source, string $mode) use (&$mirrorEvents, &$mirrorBroken,
                &$mirrorUnsupported, $mirrorCatalog): array {
                workerExpect($mode === 'mirror', 'worker fetch did not carry the frozen category mode');
                $mirrorEvents[] = 'fetch';
                if ($mirrorUnsupported) { throw new RuntimeException('synthetic unsupported tree capability'); }
                if ($mirrorBroken) {
                    $invalid = $mirrorCatalog;
                    $firstKey = array_key_first($invalid);
                    $invalid[$firstKey]['target']['path'][1]['pid'] = 999;
                    return $invalid;
                }
                return $mirrorCatalog;
            },
            'classify'=>static function (array $catalog): array {
                throw new RuntimeException('mirror must never call the smart classifier');
            },
            'assert_plan_capacity'=>static function (object $source, string $alias, array $items) use (
                &$mirrorEvents, &$mirrorCapacityFailure): void {
                $mirrorEvents[] = 'capacity';
                workerExpect(count($items) === 2 && $items[0]['target']['mode'] === 'mirror',
                    'mirror capacity did not use complete frozen targets');
                if ($mirrorCapacityFailure) { throw new RuntimeException('synthetic category ownership conflict'); }
            },
            'import_planned_item'=>static function (object $source, array $item, string $alias, array $target,
                string $planHash, object $options) use (&$mirrorEvents, &$mirrorImports): string {
                $mirrorEvents[] = 'import';
                workerExpect($item['target'] === $target && $target['mode'] === 'mirror',
                    'mirror importer lost the frozen upstream path');
                $mirrorImports[] = $item;
                return CommodityImporter::OUTCOME_CREATED;
            },
            'begin_source'=>static function (int $id): void {},
            'end_source'=>static function (): void {},
        ];
        $mirrorWorker = new JobWorker($mirrorJobs, $mirrorRuntime);
        $mirrorTask = $mirrorJobs->createAnalysis(23001, '后台S0名称', hash('sha256', 'mirror-23001'), 'mirror');
        $mirrorResult = $mirrorWorker->runOne();
        $mirrorAnalysis = $mirrorJobs->get($mirrorTask['task_id']);
        workerExpect($mirrorResult['status'] === JobStore::STATE_AWAITING_CONFIRMATION
            && $mirrorEvents === ['fetch', 'capacity'] && count($mirrorAnalysis['categories']) === 2,
            'mirror analysis failed, merged names, skipped capacity, or imported early');
        $mirrorJobs->confirmImport($mirrorTask['task_id'], $mirrorAnalysis['revision'], $mirrorAnalysis['snapshot']['plan_hash'],
            '10', workerMappings($mirrorAnalysis['categories']));
        $mirrorApplied = $mirrorWorker->runOne();
        workerExpect($mirrorApplied['status'] === JobStore::STATE_COMPLETED
            && $mirrorEvents === ['fetch', 'capacity', 'capacity', 'import', 'import']
            && array_column($mirrorImports[0]['target']['path'], 'name') === ['Telegram', '货源A', '同名子分类']
            && array_column($mirrorImports[1]['target']['path'], 'name') === ['另一父级', '同名子分类'],
            'mirror apply fetched/reclassified again, injected alias, or changed frozen ancestry');
        foreach (['invalid'=>'MIRROR_TREE_INVALID', 'unsupported'=>'MIRROR_TREE_UNAVAILABLE',
            'capacity'=>'MIRROR_CATEGORY_CAPACITY_FAILED'] as $failureKind=>$expectedCode) {
            $mirrorBroken = $failureKind === 'invalid';
            $mirrorUnsupported = $failureKind === 'unsupported';
            $mirrorCapacityFailure = $failureKind === 'capacity';
            $beforeImports = count($mirrorImports);
            $failedTask = $mirrorJobs->createAnalysis(23001, '任意有效后台名称', hash('sha256', 'mirror-23001'), 'mirror');
            $failedResult = $mirrorWorker->runOne();
            $failedState = $mirrorJobs->get($failedTask['task_id']);
            workerExpect($failedResult['status'] === JobStore::STATE_FAILED && $failedResult['error_code'] === $expectedCode
                && $failedState['snapshot'] === null && $failedState['categories'] === []
                && count($mirrorImports) === $beforeImports,
                'mirror ' . $failureKind . ' did not block before snapshot confirmation and import');
        }
        fwrite(STDOUT, "mirror worker frozen analysis/apply and pre-import failures: PASS\n");

        fwrite(STDOUT, "local catalog job worker behavior: PASS\n");
    } finally {
        workerRemove($fixture);
    }
}
