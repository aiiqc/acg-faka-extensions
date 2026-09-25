<?php
declare(strict_types=1);

namespace {
    $fixtureSuffix = bin2hex(random_bytes(6));
    $fixtureRoot = sys_get_temp_dir() . '/pika-supply-sync-resume-' . $fixtureSuffix;
    $webUid = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $webGid = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $officialRoot = realpath((string)(getenv('ACG_FAKA_OFFICIAL_ROOT') ?: ''));
    if (
        !is_int($webUid)
        || !is_int($webGid)
        || !function_exists('posix_setuid')
        || posix_geteuid() !== 0
        || $officialRoot === false
        || !is_file($officialRoot . '/vendor/autoload.php')
    ) {
        throw new \RuntimeException('resume behavior fixture requires root, WEB_UID WEB_GID and ACG_FAKA_OFFICIAL_ROOT');
    }
    if (!mkdir($fixtureRoot, 0750, true) && !is_dir($fixtureRoot)) {
        throw new \RuntimeException('unable to create resume behavior fixture');
    }
    chown($fixtureRoot, $webUid);
    chgrp($fixtureRoot, $webGid);
    chmod($fixtureRoot, 0750);
    define('BASE_PATH', $fixtureRoot . '/');
}

namespace App\Util {
    // Keep only the site setting boundary synthetic. SharedGateway uses the
    // fixed official SharedCurrency implementation, including non-unit rates.
    final class Currency
    {
        public const DEFAULT_CODE = 'CNY';

        public static function code(): string
        {
            return 'CNY';
        }

        public static function rate(): string
        {
            return '1';
        }
    }

    final class Str
    {
        public static function generateSignature(array $data, mixed $appKey): string
        {
            return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR) . (string)$appKey);
        }
    }

    final class Date
    {
        public static function current(?string $format = null): string
        {
            return $format === null ? '2026-08-30 00:00:00' : '2026-08-30 00:00:00';
        }
    }
}

namespace {
    require $officialRoot . '/vendor/autoload.php';
    require dirname(__DIR__) . '/manager/site/local-extensions/src/PathGuard.php';
    require dirname(__DIR__) . '/manager/site/local-extensions/src/AtomicJson.php';
    require dirname(__DIR__) . '/extensions/PikaSupplySync/bootstrap.php';

    use App\Model\Commodity;
    use App\Util\Ini;
    use Illuminate\Database\Capsule\Manager as DB;
    use Illuminate\Database\Schema\Blueprint;
    use Pika\LocalExtensions\PikaSupplySync\Service\ExtensionLogger;
    use Pika\LocalExtensions\PikaSupplySync\Service\ImageCache;
    use Pika\LocalExtensions\PikaSupplySync\Service\Options;
    use Pika\LocalExtensions\PikaSupplySync\Service\PriceAdjuster;
    use Pika\LocalExtensions\PikaSupplySync\Service\RunBudget;
    use Pika\LocalExtensions\PikaSupplySync\Service\SafeHttpClient;
    use Pika\LocalExtensions\PikaSupplySync\Service\SharedGateway;
    use Pika\LocalExtensions\PikaSupplySync\Service\SourcePolicy;
    use Pika\LocalExtensions\PikaSupplySync\Service\StateStore;
    use Pika\LocalExtensions\PikaSupplySync\Service\SyncService;

    function resumeExpect(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    function resumeExpectThrows(callable $action, string $message): void
    {
        try {
            $action();
        } catch (\RuntimeException) {
            return;
        }
        throw new \RuntimeException($message);
    }

    resumeExpect(realpath((new \ReflectionClass(\App\Util\SharedCurrency::class))->getFileName())
        === $officialRoot . '/app/Util/SharedCurrency.php', 'currency coverage must load the fixed official implementation');

    $db = new DB();
    $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $db->setAsGlobal();
    $db->bootEloquent();
    $schema = DB::connection()->getSchemaBuilder();
    $schema->create('shared', static function (Blueprint $table): void {
        $table->increments('id');
        $table->unsignedInteger('type');
        $table->string('name');
        $table->string('domain');
        $table->string('app_id');
        $table->string('app_key');
        $table->string('currency')->default('CNY');
        $table->string('currency_rate')->default('1');
    });
    $schema->create('commodity', static function (Blueprint $table): void {
        $table->increments('id');
        $table->unsignedInteger('owner');
        $table->unsignedInteger('shared_id');
        $table->string('shared_code');
        $table->string('code');
        $table->unsignedInteger('status');
        $table->integer('stock');
        $table->unsignedInteger('shared_sync');
        $table->unsignedInteger('shared_premium_type');
        $table->unsignedInteger('inventory_sync');
        $table->text('shared_stock')->nullable();
        $table->unsignedInteger('shared_amount_sync')->default(1);
        $table->unsignedInteger('shared_config_sync')->default(1);
        $table->decimal('shared_premium', 12, 3)->default(0);
        $table->string('name')->default('Local fixture name');
        $table->string('cover')->default('/local-fixture.png');
        $table->text('description')->default('Local fixture description');
        $table->text('config')->default('');
        $table->decimal('price', 12, 2)->default(99);
        $table->decimal('user_price', 12, 2)->default(98);
        $table->decimal('draft_premium', 12, 2)->default(0);
        $table->unsignedInteger('draft_status')->default(0);
        $table->text('widget')->default('[]');
        $table->unsignedInteger('api_status')->default(0);
        $table->unsignedInteger('category_id')->default(17);
        $table->decimal('factory_price', 12, 2)->default(0);
        $table->string('create_time')->nullable();
        $table->string('seckill_start_time')->nullable();
        $table->string('seckill_end_time')->nullable();
        foreach (['delivery_way', 'contact_type', 'password_status', 'sort', 'coupon', 'shared_premium_template',
            'seckill_status', 'inventory_hidden', 'only_user', 'purchase_count', 'minimum', 'maximum', 'hide'] as $field) {
            $table->unsignedInteger($field)->default(0);
        }
    });
    $schema->create('category', static function (Blueprint $table): void {
        $table->increments('id');
        $table->unsignedInteger('owner');
    });
    DB::table('category')->insert(['id' => 17, 'owner' => 0]);
    DB::table('shared')->insert([
        'id' => 1,
        'type' => 0,
        'name' => 'resume-source',
        'domain' => 'https://example.com',
        'app_id' => 'resume-app',
        'app_key' => 'resume-key',
        'currency' => 'CNY',
        'currency_rate' => '1',
    ]);
    foreach (['A', 'B', 'C', 'D'] as $index => $code) {
        DB::table('commodity')->insert([
            'id' => $index + 1,
            'owner' => 0,
            'shared_id' => 1,
            'shared_code' => $code,
            'code' => 'PKS1' . str_repeat($code, 20),
            'status' => 1,
            'stock' => $code === 'A' ? 5 : 0,
            'shared_sync' => 0,
            'shared_premium_type' => 1,
            'inventory_sync' => 1,
            'shared_stock' => '["remote"]',
        ]);
    }

    $stateControl = '/var/lib/pika-local-extensions';
    $stateSites = $stateControl . '/sites';
    if (!is_dir($stateSites) && !mkdir($stateSites, 0755, true) && !is_dir($stateSites)) {
        throw new \RuntimeException('unable to create external state control directories');
    }
    chown($stateControl, 0);
    chgrp($stateControl, 0);
    chmod($stateControl, 0755);
    chown($stateSites, 0);
    chgrp($stateSites, 0);
    chmod($stateSites, 0755);
    $stateSite = $stateSites . '/' . hash('sha256', realpath($fixtureRoot));
    mkdir($stateSite . '/runtime', 0750, true);
    chown($stateSite, 0);
    chgrp($stateSite, 0);
    chmod($stateSite, 0755);
    chown($stateSite . '/runtime', $webUid);
    chgrp($stateSite . '/runtime', $webGid);
    chmod($stateSite . '/runtime', 0750);

    $cutAfterB = true;
    $catalogRequests = 0;

    if (!posix_setgid($webGid) || !posix_setuid($webUid)) {
        throw new \RuntimeException('unable to drop to isolated resume behavior identity');
    }

    $catalog = [[
        'name' => 'Root',
        'children' => array_map(static fn(string $code): array => [
            'id' => $code,
            'code' => $code,
            'name' => $code,
            'stock' => 0,
        ], ['A', 'B', 'C', 'D']),
    ]];
    $makeService = static function () use (&$cutAfterB, &$catalogRequests, $catalog): SyncService {
        $budget = new RunBudget(static function () use (&$cutAfterB): float {
            if (!$cutAfterB) {
                return 0.0;
            }
            $completedPrefix = DB::table('commodity')
                ->whereIn('shared_code', ['A', 'B'])
                ->where('shared_stock', '[]')
                ->count();
            return $completedPrefix === 2 ? 121.0 : 0.0;
        });
        $policy = new SourcePolicy(static fn(string $host): array => ['93.184.216.34']);
        $transport = static function (
            array $endpoint,
            string $address,
            string $method,
            array $headers,
            string $body,
            int $maxBytes,
            int $connectTimeoutMs,
            int $requestTimeoutMs,
        ) use (&$catalogRequests, $catalog): array {
            $catalogRequests++;
            return [
                'status' => 200,
                'content_type' => 'application/json',
                'body' => (string)json_encode(['code' => 200, 'data' => $catalog], JSON_THROW_ON_ERROR),
                'connected_ip' => $address,
            ];
        };
        $http = new SafeHttpClient($policy, $transport, $budget);
        $images = new ImageCache($http, $budget);
        return new SyncService(
            new SharedGateway($http, $policy),
            new PriceAdjuster(),
            $policy,
            $images,
            new ExtensionLogger(),
            $budget,
        );
    };
    $jobStateContainsActiveSource = static function (array $state, int $sourceId) use ($makeService): bool {
        $method = new \ReflectionMethod(SyncService::class, 'jobStateContainsActiveSource');
        $method->setAccessible(true);
        return (bool)$method->invoke($makeService(), $state, $sourceId);
    };
    $options = Options::fromArray([
        'mode' => 'basic',
        'source_ids' => '1',
        'batch_limit' => '4',
        'zero_fuse_percent' => '50',
        'zero_fuse_min' => '2',
    ]);

    $first = $makeService()->run($options)['sources'][0] ?? null;
    resumeExpect(is_array($first), 'first partial run result is missing');
    resumeExpect(
        ($first['status'] ?? null) === 'partial',
        'first run must stop as partial: ' . json_encode($first, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
    );
    resumeExpect(($first['budget_scope'] ?? null) === 'source', 'first run must report source budget exhaustion');
    resumeExpect(($first['phase'] ?? null) === 'actions' && ($first['remaining_budget']['source_ms'] ?? null) === 0
        && !isset($first['failure_diagnostic']), 'between-action exhaustion was mislabeled as a failed request');
    resumeExpect(($first['applied']['zero'] ?? null) === 2, 'first run must complete exactly A and B');
    $state = (new StateStore())->read(1);
    resumeExpect($state['priority_cursor'] === 'A', 'priority lane did not persist its last completed cursor');
    resumeExpect($state['cursor'] === 'B', 'normal lane did not persist its last completed cursor');

    $cutAfterB = false;
    $second = $makeService()->run($options)['sources'][0] ?? null;
    resumeExpect(is_array($second), 'second resume result is missing');
    resumeExpect(($second['status'] ?? null) === 'ok', 'second run must finish the remaining suffix');
    resumeExpect(($second['applied']['zero'] ?? null) === 2, 'second run must resume at C without replaying A or B');
    resumeExpect(!isset($second['budget_scope']) && !isset($second['failure_diagnostic'])
        && !isset($second['request_diagnostics']['catalog']['last_failure']),
        'successful continuation retained prior failure evidence');
    $state = (new StateStore())->read(1);
    resumeExpect($state['priority_cursor'] === 'A', 'empty priority lane must retain its completed cursor');
    resumeExpect($state['cursor'] === 'D', 'normal lane did not advance through the resumed suffix');
    foreach (['A', 'B', 'C', 'D'] as $code) {
        $row = Commodity::query()->where('shared_code', $code)->first();
        resumeExpect($row !== null && (int)$row->stock === 0, "commodity {$code} was not safely zeroed");
        resumeExpect($row->shared_stock === [], "commodity {$code} retained stale shared stock");
    }

    $catalogHubRoot = $stateSite . '/runtime/extensions/PikaCatalogHub';
    $jobsDirectory = $catalogHubRoot . '/jobs';
    if (!mkdir($jobsDirectory, 0750, true) && !is_dir($jobsDirectory)) {
        throw new \RuntimeException('unable to create CatalogHub job fixture');
    }
    chmod($catalogHubRoot, 0750);
    chmod($jobsDirectory, 0750);
    $taskId = str_repeat('a', 48);
    $jobsPath = $jobsDirectory . '/jobs.json';
    file_put_contents($jobsPath, json_encode([
        'schema' => 1,
        'jobs' => [
            $taskId => [
                'task_id' => $taskId,
                'source_id' => 1,
                'state' => 'awaiting_confirmation',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    chmod($jobsPath, 0600);

    $fullOptions = Options::fromArray([
        'mode' => 'full',
        'source_ids' => '1',
        'batch_limit' => '4',
        'zero_fuse_percent' => '50',
        'zero_fuse_min' => '2',
    ]);
    $requestsBeforeGuard = $catalogRequests;
    $activeTaskGuard = $makeService()->run($fullOptions)['sources'][0] ?? null;
    resumeExpect(
        is_array($activeTaskGuard)
            && ($activeTaskGuard['status'] ?? null) === 'error'
            && str_contains((string)($activeTaskGuard['message'] ?? ''), 'basic'),
        'full mode did not reject a CatalogHub active-task source with a basic-mode instruction',
    );
    resumeExpect($catalogRequests === $requestsBeforeGuard, 'active-task guard contacted the upstream catalog');

    $schemaTwoActiveTask = str_repeat('b', 48);
    $schemaTwoTerminalTask = str_repeat('c', 48);
    $schemaTwoActive = [
        'schema' => 2,
        'jobs' => [
            $schemaTwoActiveTask => [
                'task_id' => $schemaTwoActiveTask,
                'source_id' => 1,
                'state' => 'queued_import',
            ],
        ],
        'snapshot_gc' => null,
    ];
    file_put_contents(
        $jobsPath,
        json_encode(
            $schemaTwoActive,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n",
    );
    chmod($jobsPath, 0600);
    $requestsBeforeSchemaTwoGuard = $catalogRequests;
    $schemaTwoActiveGuard = $makeService()->run($fullOptions)['sources'][0] ?? null;
    resumeExpect(
        is_array($schemaTwoActiveGuard)
            && ($schemaTwoActiveGuard['status'] ?? null) === 'error'
            && str_contains((string)($schemaTwoActiveGuard['message'] ?? ''), 'basic'),
        'full mode did not reject a schema 2 CatalogHub active-task source',
    );
    resumeExpect(
        $catalogRequests === $requestsBeforeSchemaTwoGuard,
        'schema 2 active-task guard contacted the upstream catalog',
    );
    resumeExpect(
        $jobStateContainsActiveSource($schemaTwoActive, 1),
        'schema 2 active task with null GC was not recognized',
    );
    resumeExpect(
        !$jobStateContainsActiveSource($schemaTwoActive, 2),
        'schema 2 active task matched the wrong source',
    );

    $schemaTwoTerminal = [
        'schema' => 2,
        'jobs' => [
            $schemaTwoTerminalTask => [
                'task_id' => $schemaTwoTerminalTask,
                'source_id' => 1,
                'state' => 'completed',
            ],
        ],
        'snapshot_gc' => null,
    ];
    resumeExpect(
        !$jobStateContainsActiveSource($schemaTwoTerminal, 1),
        'schema 2 terminal task was incorrectly treated as active',
    );

    $pendingGcTask = str_repeat('d', 48);
    $schemaTwoPendingGc = $schemaTwoTerminal;
    $schemaTwoPendingGc['snapshot_gc'] = [
        'task_id' => $pendingGcTask,
        'source_fingerprint' => str_repeat('e', 64),
        'plan_hash' => str_repeat('f', 64),
        'sha256' => str_repeat('0', 64),
    ];
    resumeExpect(
        !$jobStateContainsActiveSource($schemaTwoPendingGc, 1),
        'valid schema 2 pending GC state was rejected',
    );
    $schemaTwoPendingGcWithoutSnapshot = $schemaTwoTerminal;
    $schemaTwoPendingGcWithoutSnapshot['snapshot_gc'] = [
        'task_id' => str_repeat('1', 48),
        'source_fingerprint' => str_repeat('2', 64),
        'plan_hash' => null,
        'sha256' => null,
    ];
    resumeExpect(
        !$jobStateContainsActiveSource($schemaTwoPendingGcWithoutSnapshot, 1),
        'valid schema 2 pending GC without a snapshot was rejected',
    );

    // The read-only full-mode guard consumes task identity, not the item ledger.
    foreach ([3, 4] as $jobSchema) {
        $currentSchemaActive = $schemaTwoActive;
        $currentSchemaActive['schema'] = $jobSchema;
        $currentSchemaActive['jobs'][$schemaTwoActiveTask]['item_failures'] = [];
        resumeExpect($jobStateContainsActiveSource($currentSchemaActive, 1), "schema {$jobSchema} active source was not recognized");
        resumeExpect(!$jobStateContainsActiveSource($currentSchemaActive, 2), "schema {$jobSchema} active task matched another source");
        file_put_contents($jobsPath, json_encode($currentSchemaActive, JSON_THROW_ON_ERROR));
        $requestsBeforeSchemaGuard = $catalogRequests;
        $currentSchemaGuard = $makeService()->run($fullOptions)['sources'][0] ?? null;
        resumeExpect(
            ($currentSchemaGuard['status'] ?? null) === 'error'
                && str_contains((string)($currentSchemaGuard['message'] ?? ''), 'basic'),
            "schema {$jobSchema} active-task guard did not retain the basic-mode guidance",
        );
        resumeExpect($catalogRequests === $requestsBeforeSchemaGuard, "schema {$jobSchema} guard contacted the upstream");
        foreach ([$schemaTwoTerminal, $schemaTwoPendingGc, $schemaTwoPendingGcWithoutSnapshot] as $previousSchema) {
            $previousSchema['schema'] = $jobSchema;
            resumeExpect(!$jobStateContainsActiveSource($previousSchema, 1), "schema {$jobSchema} terminal/GC metadata was rejected");
        }
    }

    $invalidStates = [];
    $invalidStates['schema 1 unknown field'] = [
        'schema' => 1,
        'jobs' => [],
        'snapshot_gc' => null,
    ];
    $invalidStates['schema 2 missing GC field'] = ['schema' => 2, 'jobs' => []];
    $invalidGcType = $schemaTwoTerminal;
    $invalidGcType['snapshot_gc'] = false;
    $invalidStates['schema 2 invalid GC type'] = $invalidGcType;
    $invalidTopLevel = $schemaTwoTerminal;
    $invalidTopLevel['unexpected'] = true;
    $invalidStates['schema 2 unknown field'] = $invalidTopLevel;
    $invalidGcField = $schemaTwoPendingGc;
    $invalidGcField['snapshot_gc']['unexpected'] = true;
    $invalidStates['GC unknown field'] = $invalidGcField;
    $invalidGcTask = $schemaTwoPendingGc;
    $invalidGcTask['snapshot_gc']['task_id'] = 'not-a-task-id';
    $invalidStates['GC task ID'] = $invalidGcTask;
    $invalidGcFingerprint = $schemaTwoPendingGc;
    $invalidGcFingerprint['snapshot_gc']['source_fingerprint'] = 'not-a-fingerprint';
    $invalidStates['GC source fingerprint'] = $invalidGcFingerprint;
    $invalidGcBinding = $schemaTwoPendingGc;
    $invalidGcBinding['snapshot_gc']['sha256'] = null;
    $invalidStates['GC partial binding'] = $invalidGcBinding;
    $invalidGcPlan = $schemaTwoPendingGc;
    $invalidGcPlan['snapshot_gc']['plan_hash'] = 'not-a-plan-hash';
    $invalidStates['GC plan hash'] = $invalidGcPlan;
    $invalidGcSha = $schemaTwoPendingGc;
    $invalidGcSha['snapshot_gc']['sha256'] = 'not-a-snapshot-hash';
    $invalidStates['GC snapshot hash'] = $invalidGcSha;
    $invalidGcReference = $schemaTwoTerminal;
    $invalidGcReference['snapshot_gc'] = [
        'task_id' => $schemaTwoTerminalTask,
        'source_fingerprint' => str_repeat('a', 64),
        'plan_hash' => null,
        'sha256' => null,
    ];
    $invalidStates['GC still references a job'] = $invalidGcReference;
    foreach ([3, 4] as $jobSchema) {
        $invalidStates["schema {$jobSchema} missing GC field"] = ['schema' => $jobSchema, 'jobs' => []];
        foreach ([$invalidGcType, $invalidTopLevel, $invalidGcBinding, $invalidGcReference] as $index => $invalidSchema) {
            $invalidSchema['schema'] = $jobSchema;
            $invalidStates["schema {$jobSchema} invalid metadata {$index}"] = $invalidSchema;
        }
    }
    foreach ($invalidStates as $label => $invalidState) {
        resumeExpectThrows(
            static fn() => $jobStateContainsActiveSource($invalidState, 1),
            "{$label} did not fail closed",
        );
    }
    unlink($jobsPath);

    $parentKey = hash('sha256', 'fixture-parent');
    $alias = '货源A';
    $sourceNodeKey = hash('sha256', 'source' . "\0" . 1 . "\0" . $parentKey . "\0" . $alias);
    $sourceCategory = 'Telegram | API/真机账号';
    $sourceCategoryKey = hash(
        'sha256',
        'source-category' . "\0" . 1 . "\0" . $sourceNodeKey . "\0" . $sourceCategory,
    );
    $categoryMapPath = $catalogHubRoot . '/category-map.json';
    file_put_contents($categoryMapPath, json_encode([
        'schema' => 2,
        'nodes' => [
            $sourceNodeKey => ['id' => 12, 'name' => $alias, 'parent_key' => $parentKey],
            $sourceCategoryKey => [
                'id' => 13,
                'name' => $sourceCategory,
                'parent_key' => $sourceNodeKey,
            ],
        ],
        'last_plan_hash' => hash('sha256', 'fixture-plan'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    chmod($categoryMapPath, 0600);

    $requestsBeforeGuard = $catalogRequests;
    $categoryMapGuard = $makeService()->run($fullOptions)['sources'][0] ?? null;
    resumeExpect(
        is_array($categoryMapGuard)
            && ($categoryMapGuard['status'] ?? null) === 'error'
            && str_contains((string)($categoryMapGuard['message'] ?? ''), 'basic'),
        'full mode did not reject a CatalogHub category-map source',
    );
    resumeExpect($catalogRequests === $requestsBeforeGuard, 'category-map guard contacted the upstream catalog');

    $basicWithCatalogHub = $makeService()->run($options)['sources'][0] ?? null;
    resumeExpect(
        is_array($basicWithCatalogHub) && ($basicWithCatalogHub['status'] ?? null) === 'ok',
        'basic mode was incorrectly blocked for a CatalogHub-managed source',
    );
    resumeExpect($catalogRequests === $requestsBeforeGuard + 1, 'basic mode did not continue steady-state synchronization');

    file_put_contents($categoryMapPath, "{\"schema\":1,\"nodes\":[],\"last_plan_hash\":false}\n");
    chmod($categoryMapPath, 0600);
    $requestsBeforeGuard = $catalogRequests;
    $corruptStateGuard = $makeService()->run($fullOptions)['sources'][0] ?? null;
    resumeExpect(
        is_array($corruptStateGuard) && ($corruptStateGuard['status'] ?? null) === 'error',
        'full mode did not fail closed on corrupt CatalogHub state',
    );
    resumeExpect($catalogRequests === $requestsBeforeGuard, 'corrupt-state guard contacted the upstream catalog');
    unlink($categoryMapPath); // End this deliberate corruption before unrelated selection scenarios.

    // Selection cases use the real INI parser, normalizer, price adjustment and
    // database save path. Only network transport/site settings/signatures are fixtures.
    $seedSelectionSource = static function (int $sourceId, array $rows, int $type = 0): void {
        DB::table('shared')->insert([
            'id' => $sourceId, 'type' => $type, 'name' => 'selection-source-' . $sourceId,
            'domain' => 'https://example.com', 'app_id' => 'fixture-app', 'app_key' => 'fixture-key',
            'currency' => 'CNY', 'currency_rate' => '1',
        ]);
        foreach ($rows as $code => $overrides) {
            DB::table('commodity')->insert($overrides + [
                'owner' => 0, 'shared_id' => $sourceId, 'shared_code' => $code,
                'code' => 'PKS1' . strtoupper(substr(hash('sha256', $sourceId . ':' . $code), 0, 20)),
                'status' => 1, 'stock' => 7, 'shared_sync' => 0, 'shared_premium_type' => 1,
                'inventory_sync' => 1, 'shared_stock' => '["keep-local"]',
            ]);
        }
    };
    $selectedOptions = static function (int $sourceId, array $selected, array $overrides = [], array $saved = []): Options {
        $config = ['mode' => 'basic', 'source_ids' => (string)$sourceId, 'batch_limit' => 4,
            'zero_fuse_percent' => 100, 'zero_fuse_min' => 1];
        foreach (Options::SYNC_FIELDS as $field) $config['sync_' . $field] = in_array($field, $selected, true);
        return Options::fromArray(array_replace($config, $saved), $overrides);
    };
    $selectionCatalog = static function (array $stocks): array {
        $children = [];
        foreach ($stocks as $code => $stock) $children[] = ['code' => $code, 'name' => $code, 'stock' => $stock];
        return [['name' => 'Selection fixture category', 'children' => $children]];
    };
    $selectionDetail = static fn(string $code, array $config = [], int $stock = 2): array => [
        'code' => $code, 'name' => 'Remote fixture ' . $code,
        'description' => 'Remote fixture description', 'cover' => '', 'config' => $config,
        'price' => '40.00', 'user_price' => '35.00', 'stock' => $stock, 'widget' => '[]',
    ];
    $makeSelectionService = static function (array $catalog, array $details, array &$requests,
        ?callable $imageResponse = null, ?callable $beforeDetailReply = null,
        ?callable $catalogReply = null, int $sourceType = 0, ?callable $clock = null): SyncService {
        $requests = ['catalog' => 0, 'detail' => 0, 'other' => 0];
        if ($imageResponse !== null) $requests['image'] = 0;
        $budget = new RunBudget($clock ?? static fn(): float => 0.0);
        $policy = new SourcePolicy(static fn(string $host): array => ['93.184.216.34']);
        $transport = static function (array $endpoint, string $address, string $method,
            array $headers, string $body, int $maxBytes, int $connectTimeoutMs, int $requestTimeoutMs
        ) use ($catalog, $details, &$requests, $imageResponse, $beforeDetailReply, $catalogReply, $sourceType): array {
            $path = parse_url($endpoint['url'], PHP_URL_PATH);
            $prefix = $sourceType === 2 ? '/plugin/SharedStock/api' : '/shared/commodity';
            if ($sourceType === 2) {
                parse_str($body, $form);
                resumeExpect(!isset($form['pika_category_tree']), 'SharedStock must not request the compact capability');
            }
            if ($path === $prefix . '/items') {
                $requests['catalog']++;
                if ($catalogReply !== null) {
                    parse_str($body, $form);
                    $reply = $catalogReply($form, $maxBytes);
                    if ($reply !== null) return $reply + ['connected_ip' => $address];
                }
                $data = $catalog;
            } elseif ($path === $prefix . '/item') {
                $requests['detail']++;
                parse_str($body, $form);
                if (!isset($details[$form['code'] ?? ''])) throw new \RuntimeException('unexpected fixture detail');
                $data = $details[$form['code']];
                if ($sourceType === 2) $data = [['name' => 'Selection fixture category', 'children' => [$data]]];
                if ($beforeDetailReply !== null) $beforeDetailReply();
            } elseif ($path === '/fixture-cover.png' && $imageResponse !== null) {
                $requests['image']++;
                resumeExpect($method === 'GET' && $body === '', 'cover fixture must use a body-free GET');
                return $imageResponse($endpoint['url']) + ['connected_ip' => $address];
            } else {
                $requests['other']++;
                throw new \RuntimeException('unexpected fixture transport path');
            }
            resumeExpect($method === 'POST', 'selection fixture received a non-POST request');
            return ['status' => 200, 'content_type' => 'application/json', 'connected_ip' => $address,
                'body' => json_encode(['code' => 200, 'data' => $data], JSON_THROW_ON_ERROR)];
        };
        $http = new SafeHttpClient($policy, $transport, $budget);
        return new SyncService(new SharedGateway($http, $policy), new PriceAdjuster(), $policy,
            new ImageCache($http, $budget), new ExtensionLogger(), $budget);
    };
    $sourceRows = static fn(int $id): array => DB::table('commodity')->where('shared_id', $id)
        ->orderBy('id')->get()->map(static fn(object $row): array => (array)$row)->all();
    $observeRun = static function (SyncService $service, Options $options, ?array $targetHashes = null,
        ?callable $targetOptionsReader = null): array {
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        $readTargetOptions = $targetOptionsReader ?? static fn(): Options => $options;
        $run = $targetHashes === null ? $service->run($options)
            : $service->run($options, $targetHashes,
                static fn(): Options => SyncService::targetedOptions($readTargetOptions(), $options));
        $result = $run['sources'][0] ?? null;
        $queries = DB::connection()->getQueryLog();
        DB::connection()->disableQueryLog();
        resumeExpect(is_array($result), 'selection run result missing');
        $writes = array_filter($queries, static fn(array $query): bool =>
            preg_match('/^\s*(insert|update|delete|replace)\b/i', $query['query']) === 1);
        return ['result' => $result, 'sources' => $run['sources'], 'writes' => count($writes), 'queries' => $queries, 'run' => $run];
    };
    $lastSelectionLog = static function () use ($stateSite): array {
        $lines = file($stateSite . '/runtime/extensions/PikaSupplySync/sync.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        resumeExpect(is_array($lines) && $lines !== [], 'selection log missing');
        $line = $lines[count($lines) - 1];
        return json_decode(substr($line, strpos($line, ' ') + 1), true, 16, JSON_THROW_ON_ERROR);
    };

    $pngChunk = static fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data
        . hash('crc32b', $type . $data, true);
    $pngPixel = static fn(string $rgb): string => "\x89PNG\r\n\x1a\n"
        . $pngChunk('IHDR', pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0))
        . $pngChunk('IDAT', gzcompress("\0" . $rgb)) . $pngChunk('IEND', '');
    $redImage = $pngPixel("\xff\0\0");
    $blueImage = $pngPixel("\0\0\xff");

    // A catalog budget failure must reach the actual log before error() writes it.
    $diagnosticSource = 6099;
    $seedSelectionSource($diagnosticSource, ['A' => []]);
    $diagnosticNow = 0.0;
    $diagnosticRequests = [];
    $diagnosticBefore = $sourceRows($diagnosticSource);
    $diagnosticStateBefore = (new StateStore())->read($diagnosticSource);
    $diagnosticRun = $observeRun($makeSelectionService($selectionCatalog(['A' => 2]), [],
        $diagnosticRequests, catalogReply: static function () use (&$diagnosticNow) {
            $diagnosticNow = 121.0;
            return null;
        }, clock: static function () use (&$diagnosticNow): float { return $diagnosticNow; }),
        $selectedOptions($diagnosticSource, ['inventory']));
    $diagnosticLog = $lastSelectionLog();
    resumeExpect($diagnosticRun['result']['status'] === 'error' && $diagnosticRun['writes'] === 0
        && $diagnosticRequests === ['catalog' => 1, 'detail' => 0, 'other' => 0]
        && $sourceRows($diagnosticSource) === $diagnosticBefore
        && (new StateStore())->read($diagnosticSource) === $diagnosticStateBefore,
        'catalog budget diagnostics changed business data or cursor behavior');
    resumeExpect(($diagnosticLog['budget_scope'] ?? null) === 'source'
        && ($diagnosticLog['request_diagnostics']['catalog']['last']['stage'] ?? null) === 'catalog'
        && ($diagnosticLog['request_diagnostics']['catalog']['last']['attempts'] ?? null) === 1,
        'catalog budget failure lost scope, stage or attempts in the actual log');
    resumeExpect($diagnosticLog['phase'] === 'catalog'
        && $diagnosticLog['request_diagnostics'] === $diagnosticRun['result']['request_diagnostics']
        && $diagnosticLog['failure_diagnostic']['category'] === 'budget'
        && $diagnosticLog['remaining_budget'] === ['round_ms' => 179000, 'source_ms' => 0],
        'catalog budget diagnostic return and persisted log diverged');

    foreach (['detail' => 6097, 'image' => 6098] as $failureStage => $sourceId) {
        $seedSelectionSource($sourceId, ['A' => []]);
        $now = 0.0;
        $cut = true;
        $requests = [];
        $before = $sourceRows($sourceId);
        $stateBefore = (new StateStore())->read($sourceId);
        $service = $makeSelectionService($selectionCatalog(['A' => 2]),
            ['A' => ['cover' => '/fixture-cover.png'] + $selectionDetail('A')], $requests,
            imageResponse: static function () use (&$now, &$cut, $failureStage, $redImage): array {
                if ($cut && $failureStage === 'image') $now = 121.0;
                return ['status' => 200, 'content_type' => 'image/png', 'body' => $redImage];
            }, beforeDetailReply: static function () use (&$now, &$cut, $failureStage): void {
                if ($cut && $failureStage === 'detail') $now = 121.0;
            }, clock: static function () use (&$now): float { return $now; });
        $options = $selectedOptions($sourceId, ['name', 'cover']);
        $observed = $observeRun($service, $options);
        $log = $lastSelectionLog();
        $stateAfter = (new StateStore())->read($sourceId);
        resumeExpect($observed['result']['status'] === 'partial' && $observed['writes'] === 0
            && $observed['result']['applied']['sync'] === 0 && $sourceRows($sourceId) === $before
            && $stateAfter['cursor'] === $stateBefore['cursor']
            && $stateAfter['priority_cursor'] === $stateBefore['priority_cursor']
            && $requests === ['catalog' => 1, 'detail' => 1, 'other' => 0, 'image' => $failureStage === 'image' ? 1 : 0],
            'request budget failure changed partial writes, calls or cursor behavior');
        resumeExpect($log['status'] === 'partial' && $log['budget_scope'] === 'source' && $log['phase'] === 'actions'
            && $log['failure_diagnostic']['stage'] === $failureStage
            && $log['request_diagnostics'][$failureStage]['last']['category'] === 'budget'
            && $log['request_diagnostics'][$failureStage]['last']['attempts'] === 1
            && $log['request_diagnostics']['catalog']['last']['category'] === 'none'
            && $log['request_diagnostics'] === $observed['result']['request_diagnostics'],
            'partial result/log lost detail or image budget evidence');
        $cut = false;
        $continued = $observeRun($service, $options);
        $log = $lastSelectionLog();
        resumeExpect($continued['result']['status'] === 'ok' && $continued['writes'] === 1
            && $continued['result']['applied']['sync'] === 1 && $log['status'] === 'ok'
            && !isset($log['budget_scope']) && !isset($log['failure_diagnostic'])
            && !isset($log['request_diagnostics'][$failureStage]['last_failure'])
            && $log['request_diagnostics']['catalog']['count'] === 1,
            'natural continuation failed or retained diagnostics from the previous source round');
    }

    // Malformed stock must fail before planning any writes, including earlier valid rows.
    require_once dirname(__DIR__) . '/extensions/PikaCatalogHub/bootstrap.php';
    $hubWorker = (new \ReflectionClass(\Pika\LocalExtensions\PikaCatalogHub\Service\JobWorker::class))
        ->newInstanceWithoutConstructor();
    $hubRuntime = (new \ReflectionMethod($hubWorker, 'productionRuntime'))->invoke($hubWorker);
    $hubFetch = $hubRuntime['fetch_catalog'];
    $hubGateway = (new \ReflectionFunction($hubFetch))->getStaticVariables()['gateway'];
    $invalidCatalogRuns = 0;
    foreach ([0, 2] as $sourceType) {
        foreach ([[], ['stock' => null], ['stock' => false], ['stock' => -1], ['stock' => 0.5],
            ['stock' => 'unknown'], ['stock' => []], ['stock' => 2147483648]] as $stockFields) {
            $sourceId = 6100 + $invalidCatalogRuns++;
            $seedSelectionSource($sourceId, ['A' => [], 'B' => []], $sourceType);
            $invalidCatalog = [['name' => 'C', 'children' => [
                ['code' => 'A', 'name' => 'Valid zero', 'stock' => 0],
                ['code' => 'B', 'name' => 'Invalid stock'] + $stockFields,
            ]]];
            $requests = [];
            $before = $sourceRows($sourceId);
            $service = $makeSelectionService($invalidCatalog, [], $requests, null, null, null, $sourceType);
            $observed = $observeRun($service, $selectedOptions($sourceId, ['inventory']));
            resumeExpect($observed['result']['status'] === 'error' && $observed['writes'] === 0
                && $sourceRows($sourceId) === $before
                && $requests === ['catalog' => 1, 'detail' => 0, 'other' => 0],
                'invalid catalog stock must reject the whole source before any business write');
            // Keep the real Hub smart-catalog closure; replace only its network boundary.
            $fixtureGateway = (new \ReflectionProperty(SyncService::class, 'gateway'))->getValue($service);
            foreach (['http', 'policy'] as $property) {
                $field = new \ReflectionProperty(SharedGateway::class, $property);
                $field->setValue($hubGateway, $field->getValue($fixtureGateway));
            }
            $hubRejected = false;
            try {
                $hubFetch(\App\Model\Shared::query()->find($sourceId), 'smart');
            } catch (\RuntimeException $exception) {
                $hubRejected = str_contains($exception->getMessage(), '库存');
            }
            resumeExpect($hubRejected && $sourceRows($sourceId) === $before
                && $requests === ['catalog' => 2, 'detail' => 0, 'other' => 0],
                'Hub smart analysis must use the real stock rejection before classification or import');
        }
    }

    // Preserve nineteen synthetic unknown identities while ordinary basic work continues.
    $unknownSource = 7000;
    $unknownRows = $unknownDetails = $unknownChildren = [];
    for ($index = 0; $index < 19; $index++) {
        $code = sprintf('U%02d', $index);
        $unknownChildren[] = ['code' => $code, 'name' => $code, 'stock' => null, 'delivery_way' => 1];
        if ($index < 18) $unknownRows[$code] = ['inventory_sync' => $index % 2];
    }
    for ($index = 0; $index < 8; $index++) {
        $code = sprintf('V%02d', $index);
        $unknownChildren[] = ['code' => $code, 'name' => $code, 'stock' => 7, 'delivery_way' => 1];
        $unknownRows[$code] = [];
        $unknownDetails[$code] = ['cover' => '/fixture-cover.png'] + $selectionDetail($code, [], 7);
    }
    $unknownTree = [['name' => 'Synthetic manual stock', 'children' => $unknownChildren]];
    $seedSelectionSource($unknownSource, $unknownRows);
    $unknownBefore = $sourceRows($unknownSource);
    $unknownCategories = DB::table('category')->orderBy('id')->get()->toJson();
    $unknownTotalRows = DB::table('commodity')->count();
    $unknownOptions = $selectedOptions($unknownSource, Options::SYNC_FIELDS);
    $unknownImage = static fn(): array => ['status' => 200, 'content_type' => 'image/png', 'body' => $redImage];
    $unknownRequests = [];
    $unknownPreview = clone $unknownOptions;
    $unknownPreview->dryRun = true;
    $unknownState = (new StateStore())->read($unknownSource);
    $preview = $observeRun($makeSelectionService($unknownTree, $unknownDetails, $unknownRequests, $unknownImage), $unknownPreview);
    resumeExpect($preview['result']['status'] === 'partial' && $preview['result']['catalog_unknown'] === 19
        && $preview['result']['planned']['held_unknown'] === 4 && $preview['result']['applied']['held_unknown'] === 0
        && $preview['writes'] === 0 && $sourceRows($unknownSource) === $unknownBefore
        && (new StateStore())->read($unknownSource) === $unknownState,
        'unknown preview must expose planned protection without advancing or writing');
    $unknownHeld = $unknownSynced = $unknownDetailCalls = 0;
    for ($round = 0; $round < 7; $round++) {
        $unknownOptions->batchLimit = $round === 6 ? 2 : 4;
        $observed = $observeRun($makeSelectionService($unknownTree, $unknownDetails, $unknownRequests, $unknownImage), $unknownOptions);
        $unknownHeld += $observed['result']['applied']['held_unknown'];
        $unknownSynced += $observed['result']['applied']['sync'];
        $unknownDetailCalls += $unknownRequests['detail'];
        $expectedStatus = $observed['result']['applied']['held_unknown'] > 0 ? 'partial' : 'ok';
        resumeExpect($observed['result']['status'] === $expectedStatus && $observed['result']['failed'] === 0
            && $observed['result']['catalog_total'] === 27 && $observed['result']['catalog_unknown'] === 19
            && $observed['result']['applied']['zero'] === 0 && $observed['result']['applied']['import'] === 0
            && $unknownRequests['catalog'] === 1 && $unknownRequests['other'] === 0
            && $unknownRequests['image'] === ($observed['result']['applied']['sync'] > 0 ? 1 : 0)
            && $observed['writes'] === $observed['result']['applied']['sync']
            && array_slice($sourceRows($unknownSource), 0, 18) === array_slice($unknownBefore, 0, 18)
            && DB::table('commodity')->count() === $unknownTotalRows
            && DB::table('category')->orderBy('id')->get()->toJson() === $unknownCategories,
            'unknown native basic batch lost identities, wrote held fields or blocked integer work');
        $last = (new StateStore())->read($unknownSource)['last_result'];
        $log = $lastSelectionLog();
        $loggedApplied = $log['applied'];
        $resultApplied = $observed['result']['applied'];
        ksort($loggedApplied);
        ksort($resultApplied);
        resumeExpect($last['catalog_unknown'] === 19
            && $last['applied']['held_unknown'] === $observed['result']['applied']['held_unknown']
            && $log['catalog_unknown'] === 19 && $loggedApplied === $resultApplied,
            'unknown counts diverged between actual result, saved state and safe log');
    }
    resumeExpect($unknownHeld === 18 && $unknownSynced === 8 && $unknownDetailCalls === 8
        && (new StateStore())->read($unknownSource)['cursor'] === 'V07',
        'ordinary cursor must pass held identities and reach all eight valid manual products');
    $recoveredTree = $unknownTree;
    $recoveredTree[0]['children'][1]['stock'] = 9;
    $unknownDetails['U01'] = ['cover' => '/fixture-cover.png'] + $selectionDetail('U01', [], 9);
    $unknownOptions->batchLimit = 100;
    $recovered = $observeRun($makeSelectionService($recoveredTree, $unknownDetails, $unknownRequests, $unknownImage), $unknownOptions);
    $recoveredRow = $sourceRows($unknownSource)[1];
    resumeExpect($recovered['result']['catalog_unknown'] === 18
        && $recovered['result']['applied']['held_unknown'] === 17 && $recoveredRow['stock'] === 9
        && $recoveredRow['name'] === 'Remote fixture U01', 'restored integer stock must naturally resume synchronization');

    // Catalog-to-detail null races must stop before normalizer/image work even when stock sync is off.
    foreach ([0, 1] as $inventorySync) {
        $raceSource = 7010 + $inventorySync;
        $seedSelectionSource($raceSource, ['A' => ['inventory_sync' => $inventorySync]]);
        $before = $sourceRows($raceSource);
        $requests = [];
        $raceDetail = array_replace($selectionDetail('A'), ['stock' => null, 'delivery_way' => 1,
            'cover' => '/fixture-cover.png']);
        $race = $observeRun($makeSelectionService($selectionCatalog(['A' => 7]), ['A' => $raceDetail], $requests,
            imageResponse: static fn(): array => throw new \RuntimeException('unknown detail fetched an image')),
            $selectedOptions($raceSource, Options::SYNC_FIELDS));
        resumeExpect($race['result']['status'] === 'partial' && $race['result']['catalog_unknown'] === 0
            && $race['result']['planned']['held_unknown'] === 0 && $race['result']['applied']['held_unknown'] === 1
            && $race['result']['applied']['held_race'] === 0 && $race['result']['failed'] === 0
            && $race['writes'] === 0 && $sourceRows($raceSource) === $before
            && $requests === ['catalog' => 1, 'detail' => 1, 'other' => 0, 'image' => 0]
            && (new StateStore())->read($raceSource)['cursor'] === 'A',
            'detail unknown must hold every field before normalization regardless of inventory selection');
    }

    // Shared Hub/full/legacy/targeted callers do not opt in to unknown stock.
    foreach (['full', 'legacy', 'targeted', 'hub'] as $index => $boundary) {
        $sourceId = 7020 + $index;
        $sourceType = $boundary === 'legacy' ? 2 : 0;
        $seedSelectionSource($sourceId, ['A' => []], $sourceType);
        $tree = [['name' => 'C', 'children' => [
            ['code' => 'A', 'name' => 'A', 'stock' => null, 'delivery_way' => 1],
        ]]];
        $requests = [];
        $before = $sourceRows($sourceId);
        $service = $makeSelectionService($tree, [], $requests, sourceType: $sourceType);
        if ($boundary === 'hub') {
            $gateway = (new \ReflectionProperty(SyncService::class, 'gateway'))->getValue($service);
            foreach (['http', 'policy'] as $property) {
                $field = new \ReflectionProperty(SharedGateway::class, $property);
                $field->setValue($hubGateway, $field->getValue($gateway));
            }
            resumeExpectThrows(static fn() => $hubFetch(\App\Model\Shared::query()->find($sourceId), 'smart'),
                'Hub must retain the strict native catalog contract');
        } else {
            $options = $selectedOptions($sourceId, Options::SYNC_FIELDS, [], [
                'mode' => $boundary === 'full' ? 'full' : 'basic',
                'follow_upstream_config' => true, 'follow_upstream_config_source_ids' => (string)$sourceId,
            ]);
            $observed = $observeRun($service, $options,
                $boundary === 'targeted' ? [substr(hash('sha256', 'A'), 0, 12)] : null);
            resumeExpect($observed['result']['status'] === 'error' && $observed['writes'] === 0,
                'unknown compatibility escaped its natural basic-sync boundary: ' . $boundary);
        }
        resumeExpect($sourceRows($sourceId) === $before
            && $requests === ['catalog' => 1, 'detail' => 0, 'other' => 0],
            'strict caller wrote data or fetched a detail for unknown stock');
    }
    fwrite(STDOUT, "native manual unknown resume PASS: 19 identities, 8 integer items, dry-run, zero-write holds, recovery, races and strict callers\n");

    // Exercise mixed gates with the real save path and a budget interruption in the priority lane.
    $mixedSource = 6200;
    $mixedRows = $mixedStocks = $mixedDetails = [];
    for ($index = 0; $index < 100; $index++) {
        $code = ($index < 6 ? 'Z' : ($index < 16 ? 'M' : 'P')) . sprintf('%03d', $index);
        $mixedRows[$code] = ['status' => $index % 2];
        if ($index < 6 || $index >= 16) $mixedStocks[$code] = $index < 6 ? 0 : 7;
        if ($index >= 16) $mixedDetails[$code] = $selectionDetail($code, [], 7);
    }
    $mixedRows['R'] = ['stock' => 0, 'status' => 0];
    $mixedStocks['R'] = 9;
    $mixedDetails['R'] = $selectionDetail('R', [], 9);
    $seedSelectionSource($mixedSource, $mixedRows);
    $mixedBefore = $sourceRows($mixedSource);
    $mixedOptions = $selectedOptions($mixedSource, ['inventory'], [],
        ['zero_fuse_min' => 5, 'zero_fuse_percent' => 10]);
    $cutMixed = true;
    $mixedRequests = [];
    $makeMixedService = static function () use ($makeSelectionService, $selectionCatalog, $mixedStocks,
        $mixedDetails, &$mixedRequests, &$cutMixed, $mixedSource): SyncService {
        return $makeSelectionService($selectionCatalog($mixedStocks), $mixedDetails, $mixedRequests,
            clock: static function () use (&$cutMixed, $mixedSource): float {
                return $cutMixed && DB::table('commodity')->where('shared_id', $mixedSource)
                    ->where('shared_code', 'Z000')->value('stock') === 0 ? 121.0 : 0.0;
            });
    };
    $mixedFirst = $observeRun($makeMixedService(), $mixedOptions);
    $mixedState = (new StateStore())->read($mixedSource);
    resumeExpect($mixedFirst['result']['status'] === 'partial'
        && $mixedFirst['result']['mass_zero_fuse'] === true
        && $mixedFirst['result']['mass_zero_ratio'] === 16.0
        && $mixedFirst['result']['applied']['sync'] === 1
        && $mixedFirst['result']['applied']['zero'] === 1 && $mixedFirst['writes'] === 2
        && $mixedState['priority_cursor'] === 'Z000' && $mixedState['cursor'] === '',
        'mixed split must restore stock, zero one explicit item and stop without advancing unattempted lanes');
    $cutMixed = false;
    $mixedSecond = $observeRun($makeMixedService(), $mixedOptions);
    $mixedState = (new StateStore())->read($mixedSource);
    resumeExpect($mixedSecond['result']['status'] === 'ok' && $mixedSecond['result']['applied']['zero'] === 3
        && $mixedSecond['writes'] === 3 && $mixedState['priority_cursor'] === 'Z003'
        && $mixedState['cursor'] === 'M006', 'mixed split must resume priority work and advance held normal work');
    $mixedThird = $observeRun($makeMixedService(), $mixedOptions);
    resumeExpect($mixedThird['result']['status'] === 'ok' && $mixedThird['result']['applied']['zero'] === 2
        && $mixedThird['writes'] === 2, 'mixed split must complete the remaining explicit zeroes without replay');
    foreach ($sourceRows($mixedSource) as $index => $row) {
        $expected = $mixedBefore[$index];
        if (str_starts_with($row['shared_code'], 'Z')) {
            $expected['stock'] = 0;
            $expected['shared_stock'] = '[]';
        } elseif ($row['shared_code'] === 'R') {
            $expected['stock'] = 9;
            $expected['shared_stock'] = '[]';
            $expected['api_status'] = 1;
        }
        resumeExpect($row === $expected, 'mixed split changed a held missing item, status or unselected field');
    }
    fwrite(STDOUT, "zero split SyncService/Hub fetch PASS: 16 malformed catalogs each, mixed save/restock/budget/cursors\n");

    $seedSelectionSource(101, ['A' => []]);
    $requests = [];
    $service = $makeSelectionService($selectionCatalog(['A' => 2]), [], $requests);
    $before = $sourceRows(101);
    $observed = $observeRun($service, $selectedOptions(101, []));
    resumeExpect(($observed['result']['selection_empty'] ?? false) && $observed['result']['status'] === 'ok'
        && array_sum($observed['result']['planned']) === 0 && array_sum($observed['result']['applied']) === 0,
        'all-disabled must report an explicit zero-action result');
    resumeExpect($requests === ['catalog' => 0, 'detail' => 0, 'other' => 0]
        && $observed['writes'] === 0 && $sourceRows(101) === $before,
        'all-disabled contacted upstream or changed a commodity column');
    resumeExpect(count(array_filter($observed['queries'], static fn(array $query): bool =>
        str_contains($query['query'], 'from "shared"'))) > 0, 'all-disabled skipped source existence verification');

    $seedSelectionSource(102, ['A' => [], 'B' => [], 'C' => [], 'D' => []]);
    $requests = [];
    $details = [];
    foreach (['A', 'B', 'C'] as $code) $details[$code] = $selectionDetail($code, [], 0);
    $service = $makeSelectionService($selectionCatalog(['A' => 0, 'B' => 0, 'C' => 0]), $details, $requests);
    $before = $sourceRows(102);
    $observed = $observeRun($service, $selectedOptions(102, ['name']));
    $result = $observed['result'];
    resumeExpect($result['status'] === 'ok' && $result['planned'] === ['sync' => 3, 'import' => 0, 'zero' => 0, 'hold_zero' => 0, 'held_unknown' => 0]
        && $result['applied']['sync'] === 3 && $result['applied']['zero'] === 0 && $result['applied']['held_race'] === 0
        && $result['mass_zero_fuse'] === false && $result['mass_zero_ratio'] == 0,
        'inventory-disabled shortage used zero/fuse/race handling');
    $state = (new StateStore())->read(102);
    resumeExpect($state['priority_cursor'] === '' && $state['cursor'] === 'D', 'inventory-disabled shortage entered the priority lane');
    resumeExpect($requests === ['catalog' => 1, 'detail' => 3, 'other' => 0] && $observed['writes'] === 3,
        'inventory-disabled name updates did not perform exactly three independent saves');
    foreach ($sourceRows(102) as $index => $row) {
        $expected = $before[$index];
        if ($row['shared_code'] !== 'D') {
            $expected['name'] = 'Remote fixture ' . $row['shared_code'];
            $expected['api_status'] = 1;
        }
        resumeExpect($row === $expected, 'inventory-disabled run changed stock/shared_stock or another unselected field');
    }

    $seedSelectionSource(103, ['A' => ['shared_config_sync' => 0]]);
    $requests = [];
    $service = $makeSelectionService($selectionCatalog(['A' => 2]), [], $requests);
    $before = $sourceRows(103);
    $observed = $observeRun($service, $selectedOptions(103, ['cover']));
    resumeExpect($observed['result']['planned']['sync'] === 1 && $observed['result']['applied']['sync'] === 0
        && $observed['result']['status'] === 'ok' && $requests === ['catalog' => 1, 'detail' => 0, 'other' => 0]
        && $observed['writes'] === 0 && $sourceRows(103) === $before,
        'cover selected behind disabled per-item config gate fetched detail or saved');

    $localConfig = ['category' => ['Basic' => '12.00', 'Missing' => '18.00'],
        'category_cost' => ['Basic' => '8.00', 'Missing' => '15.00']];
    $remoteConfig = ['category' => ['Basic' => '10.00', 'New' => '20.00']];
    foreach ([104 => ['name', 'options'], 105 => ['name', 'price']] as $sourceId => $fields) {
        $seedSelectionSource($sourceId, ['A' => ['config' => Ini::toConfig($localConfig)]]);
        $requests = [];
        $service = $makeSelectionService($selectionCatalog(['A' => 2]), ['A' => $selectionDetail('A', $remoteConfig)], $requests);
        $before = $sourceRows($sourceId)[0];
        $observed = $observeRun($service, $selectedOptions($sourceId, $fields));
        $result = $observed['result'];
        resumeExpect($result['status'] === 'partial' && $result['failed'] === 1 && ($result['selection_held'] ?? 0) === 1
            && $result['applied']['sync'] === 1 && $observed['writes'] === 1
            && $requests === ['catalog' => 1, 'detail' => 1, 'other' => 0],
            'selection mismatch must count a partial save, not full success or full-source failure');
        $after = $sourceRows($sourceId)[0];
        $expected = $before;
        $expected['name'] = 'Remote fixture A';
        $expected['api_status'] = 1;
        if ($sourceId === 105) {
            $expected['price'] = 40;
            $expected['user_price'] = 35;
            $matched = $localConfig;
            $matched['category']['Basic'] = '10.00';
            $matched['category_cost']['Basic'] = '10.00';
            $expected['config'] = Ini::toConfig($matched);
        }
        resumeExpect($after === $expected, 'partial selection changed protected prices, option keys, inventory or local content');
        $log = $lastSelectionLog();
        resumeExpect(($log['source_id'] ?? null) === $sourceId && ($log['selection_held'] ?? 0) === 1
            && $log['status'] === 'partial' && $log['failed'] === 1 && $log['applied']['sync'] === 1
            && $log['dry_run'] === false && !array_key_exists('errors', $log) && !array_key_exists('message', $log),
            'selection mismatch log lost the partial count or exposed error details');
    }

    $seedSelectionSource(106, ['A' => ['shared_amount_sync' => 0, 'shared_config_sync' => 1,
        'inventory_sync' => 0, 'shared_premium' => '0.5', 'config' => Ini::toConfig($localConfig)]]);
    $requests = [];
    $service = $makeSelectionService($selectionCatalog(['A' => 2]), [
        'A' => ['cover' => '/fixture-cover.png']
            + $selectionDetail('A', ['category' => ['Basic' => '10.00'], 'sku' => ['Region' => ['East' => '2.00']]]),
    ], $requests, static fn(string $url): array => ['status' => 200, 'content_type' => 'image/png', 'body' => $redImage]);
    $before = $sourceRows(106)[0];
    $legacy = Options::fromArray(['mode' => 'basic', 'source_ids' => '106', 'batch_limit' => 4]);
    $observed = $observeRun($service, $legacy);
    $after = $sourceRows(106)[0];
    $legacyConfig = Ini::toArray($after['config']);
    resumeExpect($legacy->syncFields === null && $observed['result']['status'] === 'ok'
        && $observed['result']['applied']['sync'] === 1 && $observed['writes'] === 1
        && $requests === ['catalog' => 1, 'detail' => 1, 'other' => 0, 'image' => 1]
        && $legacyConfig['category']['Basic'] === '15.00' && $legacyConfig['sku']['Region']['East'] === '3.00'
        && $legacyConfig['category_cost']['Basic'] === '10.00' && $legacyConfig['sku_cost']['Region']['East'] === '2.00',
        'legacy amount-off/config-on no longer refreshes adjusted config prices');
    resumeExpect(file_get_contents($fixtureRoot . $after['cover']) === $redImage,
        'legacy config sync must store the downloaded valid PNG cover');
    foreach (['price', 'user_price', 'stock', 'shared_stock', 'category_id', 'status', 'shared_amount_sync', 'shared_config_sync'] as $field) {
        resumeExpect($after[$field] === $before[$field], 'legacy compatibility changed independent field: ' . $field);
    }

    // The public default applies to imports, not to an existing item's margin.
    $publicDefaults = require dirname(__DIR__) . '/extensions/PikaSupplySync/Config/Config.php';
    $manifest = json_decode(file_get_contents(dirname(__DIR__) . '/extensions/PikaSupplySync/local-extension.json'),
        true, 16, JSON_THROW_ON_ERROR);
    $settingDefaults = array_column($manifest['settings'], 'default', 'key');
    resumeExpect(Options::fromArray([])->premiumPercent === 0.0
        && Options::fromArray($publicDefaults)->premiumFactor() === 0.0
        && $settingDefaults['premium_percent'] === 0, 'public import premium must still default to zero percent');
    $seedSelectionSource(107, ['A' => ['shared_premium' => '0.10']]);
    $priceOptions = $selectedOptions(107, ['price']);
    resumeExpect($priceOptions->premiumPercent === 0.0, 'price fixture must exercise the public zero-percent default');
    foreach ([['40.00', '35.00', '2.00', 44, 38.5, 2.2], ['50.00', '45.00', '3.00', 55, 49.5, 3.3]]
        as [$remotePrice, $remoteUserPrice, $remoteDraft, $sellPrice, $sellUserPrice, $sellDraft]) {
        $requests = [];
        $detail = ['price' => $remotePrice, 'user_price' => $remoteUserPrice, 'draft_premium' => $remoteDraft]
            + $selectionDetail('A');
        $service = $makeSelectionService($selectionCatalog(['A' => 2]), ['A' => $detail], $requests);
        $before = $sourceRows(107)[0];
        $observed = $observeRun($service, $priceOptions);
        $expected = array_replace($before, ['price' => $sellPrice, 'user_price' => $sellUserPrice,
            'draft_premium' => $sellDraft, 'api_status' => 1]);
        resumeExpect($observed['result']['status'] === 'ok' && $observed['result']['failed'] === 0
            && $observed['result']['applied']['sync'] === 1 && $observed['writes'] === 1
            && $requests === ['catalog' => 1, 'detail' => 1, 'other' => 0] && $sourceRows(107) === [$expected],
            'changed upstream prices must use the existing 0.10 margin without changing protected columns');
    }

    $seedSelectionSource(108, ['A' => []]);
    foreach ([[], ['premium_percent' => 10]] as $overrides) {
        $requests = [];
        $detail = $selectionDetail('A');
        $service = $makeSelectionService($selectionCatalog(['A' => 2]), ['A' => $detail], $requests);
        $before = $sourceRows(108)[0];
        $observed = $observeRun($service, $selectedOptions(108, ['price'], $overrides));
        $expected = array_replace($before, ['price' => 40, 'user_price' => 35, 'api_status' => 1]);
        resumeExpect($observed['result']['status'] === 'ok' && $observed['result']['failed'] === 0
            && $observed['result']['applied']['sync'] === 1
            && $requests === ['catalog' => 1, 'detail' => 1, 'other' => 0] && $sourceRows(108) === [$expected],
            'existing zero-percent item must stay at upstream prices even when import premium is ten percent');
    }

    // Exercise additions, removals and repricing through normalization, the
    // native INI parser, real PriceAdjuster and the database save, not a mock.
    $complexLocal = [
        'category' => ['Keep' => '11.00', 'Delete' => '22.00'],
        'wholesale' => [10 => '8.80', 20 => '7.70'],
        'sku' => ['Region' => ['East' => '1.10', 'West' => '2.20']],
        'category_wholesale' => ['Keep' => [10 => '9.90'], 'Delete' => [10 => '19.80']],
        'category_cost' => ['Keep' => '10.00', 'Delete' => '20.00'],
        'sku_cost' => ['Region' => ['East' => '1.00', 'West' => '2.00']],
        'shared_mapping' => ['Keep' => 'sku-keep', 'Delete' => 'sku-delete'],
    ];
    $complexAdded = [
        'category' => ['Keep' => '10.00', 'Delete' => '20.00', 'Added' => '30.00'],
        'wholesale' => [10 => '8.00', 20 => '7.00', 30 => '6.00'],
        'sku' => ['Region' => ['East' => '1.00', 'West' => '2.00', 'North' => '3.00'], 'Term' => ['Year' => '4.00']],
        'category_wholesale' => ['Keep' => [10 => '9.00'], 'Delete' => [10 => '18.00'], 'Added' => [10 => '27.00']],
        'shared_mapping' => ['Keep' => 'sku-keep', 'Delete' => 'sku-delete', 'Added' => 'sku-added'],
    ];
    $complexChanged = [
        'category' => ['Keep' => '12.00', 'Added' => '25.00'],
        'wholesale' => [10 => '10.00', 30 => '8.00'],
        'sku' => ['Region' => ['East' => '1.50', 'North' => '2.50']],
        'category_wholesale' => ['Keep' => [10 => '11.00'], 'Added' => [10 => '23.00']],
        'shared_mapping' => ['Keep' => 'sku-keep', 'Added' => 'sku-added'],
    ];
    $expectedAdded = [
        'category' => ['Keep' => '11.00', 'Delete' => '22.00', 'Added' => '33.00'],
        'wholesale' => [10 => '8.80', 20 => '7.70', 30 => '6.60'],
        'sku' => ['Region' => ['East' => '1.10', 'West' => '2.20', 'North' => '3.30'], 'Term' => ['Year' => '4.40']],
        'category_wholesale' => ['Keep' => [10 => '9.90'], 'Delete' => [10 => '19.80'], 'Added' => [10 => '29.70']],
        'category_cost' => ['Keep' => '10.00', 'Delete' => '20.00', 'Added' => '30.00'],
        'sku_cost' => ['Region' => ['East' => '1.00', 'West' => '2.00', 'North' => '3.00'], 'Term' => ['Year' => '4.00']],
        'shared_mapping' => ['Keep' => 'sku-keep', 'Delete' => 'sku-delete', 'Added' => 'sku-added'],
    ];
    $expectedChanged = [
        'category' => ['Keep' => '13.20', 'Added' => '27.50'],
        'wholesale' => [10 => '11.00', 30 => '8.80'],
        'sku' => ['Region' => ['East' => '1.65', 'North' => '2.75']],
        'category_wholesale' => ['Keep' => [10 => '12.10'], 'Added' => [10 => '25.30']],
        'category_cost' => ['Keep' => '12.00', 'Added' => '25.00'],
        'sku_cost' => ['Region' => ['East' => '1.50', 'North' => '2.50']],
        'shared_mapping' => ['Keep' => 'sku-keep', 'Added' => 'sku-added'],
    ];
    $seedSelectionSource(109, ['A' => ['shared_premium' => '0.10', 'config' => Ini::toConfig($complexLocal)]]);
    $totalRows = DB::table('commodity')->count();
    foreach ([[$complexAdded, $expectedAdded], [$complexChanged, $expectedChanged]] as [$remote, $expectedConfig]) {
        $requests = [];
        $service = $makeSelectionService($selectionCatalog(['A' => 2, 'NEW' => 2]),
            ['A' => $selectionDetail('A', $remote)], $requests);
        $before = $sourceRows(109)[0];
        $observed = $observeRun($service, $selectedOptions(109, ['price', 'options']));
        $expected = array_replace($before, ['price' => 44, 'user_price' => 38.5,
            'config' => Ini::toConfig($expectedConfig), 'api_status' => 1]);
        resumeExpect($observed['result']['status'] === 'ok' && $observed['result']['failed'] === 0
            && ($observed['result']['selection_held'] ?? 0) === 0 && $observed['result']['planned']['import'] === 0
            && $observed['result']['applied']['sync'] === 1 && $observed['result']['applied']['import'] === 0
            && $observed['writes'] === 1 && $requests === ['catalog' => 1, 'detail' => 1, 'other' => 0]
            && DB::table('commodity')->count() === $totalRows && $sourceRows(109) === [$expected],
            'complex option changes must track selling prices/costs at ten percent without importing or changing category/flags');
    }

    $seedSelectionSource(110, ['A' => ['shared_premium' => '0.10', 'shared_amount_sync' => 0]]);
    $requests = [];
    $service = $makeSelectionService($selectionCatalog(['A' => 2]), [], $requests);
    $before = $sourceRows(110);
    $observed = $observeRun($service, $selectedOptions(110, ['price']));
    resumeExpect($observed['result']['status'] === 'ok' && $observed['result']['applied']['sync'] === 0
        && $observed['writes'] === 0 && $requests === ['catalog' => 1, 'detail' => 0, 'other' => 0]
        && $sourceRows(110) === $before, 'selected price must respect the existing disabled per-item amount gate');

    // Exercise real PNG validation, atomic files and Commodity saves together.
    // The two source rows intentionally use equivalent relative/absolute URLs.
    file_put_contents($fixtureRoot . '/local-fixture.png', $redImage);
    $imageFile = static fn(string $path): string => $fixtureRoot . $path;
    $imagePath = static fn(string $bytes): string => '/assets/cache/pika-supply-sync/'
        . hash('sha256', 'https://example.com/fixture-cover.png') . '-' . hash('sha256', $bytes) . '.png';
    $imageFiles = static function () use ($fixtureRoot): array {
        $files = glob($fixtureRoot . '/assets/cache/pika-supply-sync/*');
        resumeExpect(is_array($files), 'image cache listing failed');
        $hashes = [];
        foreach ($files as $file) $hashes[basename($file)] = hash_file('sha256', $file);
        ksort($hashes);
        return $hashes;
    };
    $seedSelectionSource(111, ['A' => ['shared_premium' => '0.10'], 'B' => ['shared_premium' => '0.10']]);
    $seedSelectionSource(112, ['C' => ['shared_premium' => '0.10']]);
    $imageCatalog = $selectionCatalog(['A' => 2, 'B' => 2, 'C' => 2, 'NEW' => 2]);
    $imageDetails = [];
    foreach (['A', 'B', 'C'] as $code) {
        $imageDetails[$code] = ['cover' => $code === 'B'
            ? 'https://example.com/fixture-cover.png' : '/fixture-cover.png'] + $selectionDetail($code);
    }
    $coverBytes = $redImage;
    $coverStatus = 200;
    $serveCover = static function (string $url) use (&$coverBytes, &$coverStatus): array {
        resumeExpect($url === 'https://example.com/fixture-cover.png', 'cover URL normalization changed fixture identity');
        return ['status' => $coverStatus, 'content_type' => 'image/png', 'body' => $coverBytes];
    };
    $requests = [];
    $imageService = $makeSelectionService($imageCatalog, $imageDetails, $requests, $serveCover);
    $imageOptions = $selectedOptions(111, ['name', 'cover'], ['source_ids' => '111,112']);
    $totalRows = DB::table('commodity')->count();
    foreach ([$redImage, $blueImage] as $bytes) {
        $coverBytes = $bytes;
        $requests = array_fill_keys(array_keys($requests), 0);
        $before = array_merge($sourceRows(111), $sourceRows(112));
        $observed = $observeRun($imageService, $imageOptions);
        $expected = array_map(static fn(array $row): array => array_replace($row,
            ['name' => 'Remote fixture ' . $row['shared_code'], 'cover' => $imagePath($bytes), 'api_status' => 1]), $before);
        resumeExpect(count($observed['sources']) === 2 && $observed['writes'] === 3
            && $requests === ['catalog' => 2, 'detail' => 3, 'other' => 0, 'image' => 1]
            && array_merge($sourceRows(111), $sourceRows(112)) === $expected
            && DB::table('commodity')->count() === $totalRows,
            'one shared URL per run must download once across sources and save only the three existing covers/names');
        foreach ($observed['sources'] as $result) {
            resumeExpect($result['status'] === 'ok' && $result['failed'] === 0 && ($result['cover_failed'] ?? 0) === 0
                && $result['planned']['import'] === 0 && $result['applied']['import'] === 0,
                'successful image refresh must report no cover failure or basic-mode import');
        }
        $dimensions = getimagesize($imageFile($imagePath($bytes)));
        resumeExpect(file_get_contents($imageFile($imagePath($bytes))) === $bytes
            && is_array($dimensions) && $dimensions[0] === 1 && $dimensions[1] === 1,
            'saved database cover must point to the downloaded, decodable one-pixel PNG');
    }
    resumeExpect($imagePath($redImage) !== $imagePath($blueImage)
        && file_get_contents($imageFile($imagePath($redImage))) === $redImage,
        'next run with changed pixels must retain the prior content file unchanged');

    // Cached fatal cover failures may cross sources, but their request evidence must not.
    $coverStatus = 401;
    $requests = [];
    $cachedFailureService = $makeSelectionService($imageCatalog, $imageDetails, $requests, $serveCover);
    $before = array_merge($sourceRows(111), $sourceRows(112));
    $beforeFiles = $imageFiles();
    $cachedFailureRun = $observeRun($cachedFailureService, $imageOptions);
    $sourcesWithImageRequest = 0;
    foreach ($cachedFailureRun['sources'] as $result) {
        $sourcesWithImageRequest += isset($result['request_diagnostics']['image']) ? 1 : 0;
        resumeExpect($result['status'] === 'partial' && $result['applied']['sync'] === 0
            && $result['failure_diagnostic'] === ['category' => 'http_rejected', 'stage' => 'image'],
            'reused fatal image exception imported another source request history or hid failure');
    }
    resumeExpect($sourcesWithImageRequest === 1 && $cachedFailureRun['writes'] === 0
        && array_merge($sourceRows(111), $sourceRows(112)) === $before && $imageFiles() === $beforeFiles
        && $requests === ['catalog' => 2, 'detail' => 3, 'other' => 0, 'image' => 1],
        'cross-source diagnostics changed image deduplication, calls or fatal save behavior');

    $beforeImages = $imageFiles();
    $imageOptions = $selectedOptions(111, Options::SYNC_FIELDS, ['source_ids' => '111,112']);
    foreach ([
        [200, 'not an image', 1, '/fixture-cover.png', '40.00', '35.00', 5, '10.00', 44, 38.5, '11.00'],
        [404, $redImage, 1, '/fixture-cover.png', '50.00', '45.00', 6, '12.00', 55, 49.5, '13.20'],
        [503, $redImage, 3, '/fixture-cover.png', '60.00', '55.00', 7, '14.00', 66, 60.5, '15.40'],
        [200, '', 1, '/fixture-cover.png', '65.00', '60.00', 8, '15.00', 71.5, 66, '16.50'],
        [200, $redImage, 0, '', '70.00', '65.00', 9, '16.00', 77, 71.5, '17.60'],
    ] as [$coverStatus, $coverBytes, $attempts, $remoteCover, $remotePrice, $remoteUserPrice,
        $remoteStock, $remoteCost, $sellPrice, $sellUserPrice, $sellOption]) {
        foreach ($imageDetails as &$detail) {
            $detail = array_replace($detail, ['cover' => $remoteCover, 'name' => 'Changed name ' . $remotePrice,
                'description' => 'Changed description ' . $remotePrice, 'price' => $remotePrice,
                'user_price' => $remoteUserPrice, 'stock' => $remoteStock,
                'config' => ['category' => ['Live' => $remoteCost]]]);
        }
        unset($detail);
        $requests = [];
        $imageService = $makeSelectionService($imageCatalog, $imageDetails, $requests, $serveCover);
        $before = array_merge($sourceRows(111), $sourceRows(112));
        $observed = $observeRun($imageService, $imageOptions);
        $after = array_merge($sourceRows(111), $sourceRows(112));
        resumeExpect($after[0]['price'] === $sellPrice && $after[0]['stock'] === $remoteStock,
            'cover failure blocked valid price/inventory updates: price=' . $after[0]['price'] . ', stock=' . $after[0]['stock']);
        $expected = array_map(static fn(array $row): array => array_replace($row, [
            'name' => 'Changed name ' . $remotePrice, 'description' => 'Changed description ' . $remotePrice,
            'price' => $sellPrice, 'user_price' => $sellUserPrice, 'stock' => $remoteStock, 'shared_stock' => '[]',
            'config' => Ini::toConfig(['category' => ['Live' => $sellOption], 'category_cost' => ['Live' => $remoteCost]]),
        ]), $before);
        resumeExpect($observed['writes'] === 3 && $after === $expected && DB::table('commodity')->count() === $totalRows
            && $requests === ['catalog' => 2, 'detail' => 3, 'other' => 0, 'image' => $attempts]
            && $imageFiles() === $beforeImages,
            'bad image/HTTP/empty cover must retain only old covers/files while valid selected fields update at ten percent');
        $coverFailures = 0;
        foreach ($observed['sources'] as $result) {
            $expectedFailures = (int)$result['source_id'] === 111 ? 2 : 1;
            resumeExpect($result['status'] === 'partial' && $result['failed'] === $expectedFailures
                && ($result['cover_failed'] ?? 0) === $expectedFailures && $result['applied']['sync'] === $expectedFailures
                && $result['planned']['import'] === 0 && $result['applied']['import'] === 0,
                'failed cover refresh must count valid partial saves once and remain visible as partial/cover_failed');
            $coverFailures += $result['cover_failed'];
        }
        $log = $lastSelectionLog();
        resumeExpect($coverFailures === 3 && $log['status'] === 'partial'
            && ($log['cover_failed'] ?? 0) === $log['failed'] && $log['cover_failed'] > 0,
            'cover failures must remain visible in the safe per-source log');
    }

    $coverStatus = 404;
    foreach ($imageDetails as &$detail) $detail['cover'] = '/fixture-cover.png';
    unset($detail);
    $requests = [];
    $imageService = $makeSelectionService($imageCatalog, $imageDetails, $requests, $serveCover);
    $before = array_merge($sourceRows(111), $sourceRows(112));
    $observed = $observeRun($imageService, $selectedOptions(111, ['cover'], ['source_ids' => '111,112']));
    resumeExpect($observed['writes'] === 0 && array_merge($sourceRows(111), $sourceRows(112)) === $before
        && $requests === ['catalog' => 2, 'detail' => 3, 'other' => 0, 'image' => 1]
        && array_sum(array_column($observed['sources'], 'cover_failed')) === 3
        && array_sum(array_column($observed['sources'], 'failed')) === 3 && $imageFiles() === $beforeImages,
        'cover-only failure must not save housekeeping or change any commodity column');
    foreach ($observed['sources'] as $result) {
        resumeExpect($result['status'] === 'partial' && $result['applied']['sync'] === 0,
            'cover-only failure must not claim a successful save');
    }

    foreach ($imageDetails as &$detail) {
        $detail = array_replace($detail, ['name' => 'Legacy changed name', 'description' => 'Legacy changed description',
            'price' => '80.00', 'user_price' => '75.00', 'stock' => 9, 'config' => ['category' => ['Live' => '18.00']]]);
    }
    unset($detail);
    $requests = [];
    $imageService = $makeSelectionService($imageCatalog, $imageDetails, $requests, $serveCover);
    $before = array_merge($sourceRows(111), $sourceRows(112));
    $observed = $observeRun($imageService, Options::fromArray(['mode' => 'basic', 'source_ids' => '111,112', 'batch_limit' => 4]));
    $expected = array_map(static fn(array $row): array => array_replace($row, ['name' => 'Legacy changed name',
        'description' => 'Legacy changed description', 'price' => 88, 'user_price' => 82.5, 'stock' => 9,
        'config' => Ini::toConfig(['category' => ['Live' => '19.80'], 'category_cost' => ['Live' => '18.00']])]), $before);
    resumeExpect($observed['writes'] === 3 && array_merge($sourceRows(111), $sourceRows(112)) === $expected
        && $requests === ['catalog' => 2, 'detail' => 3, 'other' => 0, 'image' => 1]
        && array_sum(array_column($observed['sources'], 'cover_failed')) === 3
        && array_sum(array_column($observed['sources'], 'failed')) === 3 && $imageFiles() === $beforeImages,
        'legacy selection must retain old cover but still save valid content/prices/stock after a cover failure');

    foreach ($imageDetails as &$detail) $detail['stock'] = 0;
    unset($detail);
    $requests = [];
    $imageService = $makeSelectionService($imageCatalog, $imageDetails, $requests, $serveCover);
    $before = array_merge($sourceRows(111), $sourceRows(112));
    $observed = $observeRun($imageService, $imageOptions);
    resumeExpect($observed['writes'] === 0 && array_merge($sourceRows(111), $sourceRows(112)) === $before
        && $requests === ['catalog' => 2, 'detail' => 3, 'other' => 0, 'image' => 1]
        && array_sum(array_column($observed['sources'], 'cover_failed')) === 3
        && array_sum(array_column($observed['sources'], 'failed')) === 3 && $imageFiles() === $beforeImages,
        'cover failure must not bypass the catalog/detail zero-stock race guard');
    foreach ($observed['sources'] as $result) {
        $expectedHeld = (int)$result['source_id'] === 111 ? 2 : 1;
        resumeExpect($result['status'] === 'partial' && $result['applied']['held_race'] === $expectedHeld
            && $result['applied']['sync'] === 0, 'zero detail must still hold the whole item despite recoverable cover failure');
    }

    $seedSelectionSource(114, ['A' => ['cover' => $imagePath($blueImage), 'shared_premium' => '0.10',
        'config' => Ini::toConfig($localConfig)]]);
    $requests = [];
    $imageService = $makeSelectionService($selectionCatalog(['A' => 2]), [
        'A' => ['cover' => '/fixture-cover.png'] + $selectionDetail('A', $remoteConfig),
    ], $requests, $serveCover);
    $before = $sourceRows(114)[0];
    $observed = $observeRun($imageService, $selectedOptions(114, ['name', 'cover', 'price']));
    $matched = $localConfig;
    $matched['category']['Basic'] = '11.00';
    $matched['category_cost']['Basic'] = '10.00';
    $expected = array_replace($before, ['name' => 'Remote fixture A', 'price' => 44, 'user_price' => 38.5,
        'config' => Ini::toConfig($matched), 'api_status' => 1]);
    resumeExpect($observed['result']['status'] === 'partial' && $observed['result']['failed'] === 1
        && ($observed['result']['cover_failed'] ?? 0) === 1 && ($observed['result']['selection_held'] ?? 0) === 1
        && $observed['result']['applied']['sync'] === 1 && $observed['writes'] === 1
        && $sourceRows(114) === [$expected] && $imageFiles() === $beforeImages
        && $requests === ['catalog' => 1, 'detail' => 1, 'other' => 0, 'image' => 1],
        'cover failure plus held specifications must preserve exact projection and count the failed item only once');

    $coverStatus = 200;
    $coverBytes = $redImage;
    foreach ($imageDetails as &$detail) $detail['name'] = 'Selected name after cover disabled';
    unset($detail);
    $requests = [];
    $imageService = $makeSelectionService($imageCatalog, $imageDetails, $requests, $serveCover);
    $before = array_merge($sourceRows(111), $sourceRows(112));
    $observed = $observeRun($imageService, $selectedOptions(111, ['name'], ['source_ids' => '111,112']));
    $expected = array_map(static fn(array $row): array => array_replace($row,
        ['name' => 'Selected name after cover disabled']), $before);
    resumeExpect($observed['writes'] === 3 && array_merge($sourceRows(111), $sourceRows(112)) === $expected
        && $requests === ['catalog' => 2, 'detail' => 3, 'other' => 0, 'image' => 0]
        && $imageFiles() === $beforeImages, 'disabled cover must retain references/bytes while the selected name updates');

    $seedSelectionSource(113, ['A' => ['cover' => $imagePath($blueImage), 'shared_config_sync' => 0]]);
    $requests = [];
    $imageService = $makeSelectionService($selectionCatalog(['A' => 2]), [], $requests, $serveCover);
    $before = $sourceRows(113);
    $observed = $observeRun($imageService, $selectedOptions(113, ['cover']));
    resumeExpect($observed['writes'] === 0 && $sourceRows(113) === $before
        && $observed['result']['status'] === 'ok' && $observed['result']['applied']['sync'] === 0
        && $requests === ['catalog' => 1, 'detail' => 0, 'other' => 0, 'image' => 0]
        && $imageFiles() === $beforeImages, 'disabled per-item config gate must not fetch or replace a valid cached cover');

    // Unknown sections are synthetic metadata, not alternative price fields.
    // These cases exercise effective gates and the normalized widget candidate
    // through the real save path; equality of raw detail bytes is insufficient.
    $unknownMetadata = ['fixture_notes' => ['caption' => 'stable note', 'tag' => 'synthetic marker'],
        'fixture_metadata' => ['group' => ['label' => 'unchanged nested value']]];
    $changedMetadata = $unknownMetadata;
    $changedMetadata['fixture_notes']['caption'] = 'changed note';
    $manyMetadata = $unknownMetadata;
    foreach (range(3, 36) as $index) {
        $manyMetadata['fixture_notes'][sprintf('entry_%02d', $index)] = 'synthetic marker ' . $index;
    }
    $costTen = $saleEleven = $costTwenty = $saleTwentyTwo = [];
    foreach (range(1, 36) as $index) {
        $key = sprintf('Choice_%02d', $index);
        $costTen[$key] = '10.00';
        $saleEleven[$key] = '11.00';
        $costTwenty[$key] = '20.00';
        $saleTwentyTwo[$key] = '22.00';
    }
    $stableKnown = ['category' => $saleEleven, 'category_cost' => $costTen];
    $remoteKnown = ['category' => $costTen, 'category_cost' => $costTen];
    $changedKnown = ['category' => $saleTwentyTwo, 'category_cost' => $costTwenty];
    $changedRemoteKnown = ['category' => $costTwenty, 'category_cost' => $costTwenty];
    $canonicalWidget = json_encode([['cn' => 'Prompt', 'name' => 'field_alpha', 'placeholder' => '',
        'type' => 'text', 'regex' => '', 'error' => '', 'dict' => '']], JSON_THROW_ON_ERROR);
    $rawWidget = '[{"type":"text","name":"field_alpha","cn":"Prompt"}]';
    $changedWidget = json_encode([['cn' => 'Select plan', 'name' => 'plan_choice', 'placeholder' => 'Select one',
        'type' => 'select', 'regex' => '', 'error' => '', 'dict' => 'Starter,Plus']], JSON_THROW_ON_ERROR);
    resumeExpect($rawWidget !== $canonicalWidget, 'widget fixture must distinguish raw equality from normalized equality');
    $changedDetail = ['name' => 'Changed selected name', 'description' => 'Changed selected description',
        'price' => '50.00', 'user_price' => '45.00', 'stock' => 5, 'widget' => $changedWidget, 'draft_status' => 1];
    $changedColumns = ['name' => 'Changed selected name', 'description' => 'Changed selected description',
        'price' => 55, 'user_price' => 49.5, 'stock' => 5, 'widget' => $changedWidget, 'draft_status' => 1];
    $unknownCases = [];
    foreach (['no_price_tree' => [[], [], [], []],
        'thirty_six_prices' => [$stableKnown, $remoteKnown, $changedKnown, $changedRemoteKnown]]
        as $shape => [$localKnown, $upstreamKnown, $expectedKnown, $upstreamChanged]) {
        $caseMetadata = $shape === 'thirty_six_prices' ? $manyMetadata : $unknownMetadata;
        $changedCaseMetadata = $caseMetadata;
        $changedCaseMetadata['fixture_notes']['caption'] = 'changed note';
        $local = $caseMetadata + $localKnown;
        $upstream = $caseMetadata + $upstreamKnown;
        $unknownCases[] = ['label' => $shape . ':equal', 'local' => $local, 'remote' => $upstream, 'writes' => 0];
        $unknownCases[] = ['label' => $shape . ':raw_widget_equal', 'local' => $local, 'remote' => $upstream,
            'row' => ['widget' => $rawWidget], 'detail' => ['widget' => $rawWidget],
            'changed' => ['widget' => $canonicalWidget], 'writes' => 1];
        $unknownCases[] = ['label' => $shape . ':valid_widget_and_price_change', 'local' => $local,
            'remote' => $caseMetadata + $upstreamChanged, 'detail' => $changedDetail,
            'changed' => $changedColumns + ['config' => Ini::toConfig($caseMetadata + $expectedKnown)], 'writes' => 1];
        $heldColumns = $changedColumns;
        unset($heldColumns['widget'], $heldColumns['draft_status']);
        $unknownCases[] = ['label' => $shape . ':unknown_change', 'local' => $local,
            'remote' => $changedCaseMetadata + $upstreamChanged, 'detail' => $changedDetail,
            'changed' => $heldColumns + ['config' => Ini::toConfig($caseMetadata + $expectedKnown)],
            'held' => 1, 'failed' => 1, 'writes' => 1];
    }
    $local = $unknownMetadata + $stableKnown;
    $upstream = $unknownMetadata + $changedRemoteKnown;
    $unknownCases[] = ['label' => 'amount_gate_off', 'local' => $local, 'remote' => $upstream,
        'row' => ['shared_amount_sync' => 0], 'detail' => $changedDetail,
        'changed' => ['name' => 'Changed selected name', 'description' => 'Changed selected description', 'stock' => 5],
        'held' => 1, 'failed' => 1, 'writes' => 1];
    $unknownCases[] = ['label' => 'config_gate_off', 'local' => $local, 'remote' => $upstream,
        'row' => ['shared_config_sync' => 0], 'detail' => $changedDetail,
        'changed' => ['price' => 55, 'user_price' => 49.5, 'stock' => 5,
            'config' => Ini::toConfig($unknownMetadata + $changedKnown)],
        'held' => 1, 'failed' => 1, 'writes' => 1, 'images' => 0];
    $unknownCases[] = ['label' => 'both_effective_gates_off', 'local' => $local, 'remote' => $upstream,
        'row' => ['shared_amount_sync' => 0, 'shared_config_sync' => 0], 'detail' => $changedDetail,
        'changed' => ['stock' => 5], 'writes' => 1, 'images' => 0];
    $unknownCases[] = ['label' => 'legacy_unknown_change', 'local' => $local,
        'remote' => $changedMetadata + $changedRemoteKnown, 'detail' => $changedDetail, 'legacy' => true,
        'changed' => $changedColumns + ['config' => Ini::toConfig($changedMetadata + $changedKnown)], 'writes' => 1];
    $unknownCases[] = ['label' => 'ordinary_cover_failure', 'local' => $local, 'remote' => $upstream,
        'detail' => $changedDetail, 'http_status' => 404,
        'changed' => $changedColumns + ['config' => Ini::toConfig($unknownMetadata + $changedKnown)],
        'failed' => 1, 'cover_failed' => 1, 'writes' => 1];
    $unknownCases[] = ['label' => 'invalid_widget', 'local' => $local, 'remote' => $upstream,
        'detail' => ['widget' => '[{"name":"invalid-name","type":"text"}]'] + $changedDetail,
        'failed' => 1, 'applied' => 0, 'writes' => 0, 'images' => 0];
    $unknownCases[] = ['label' => 'invalid_draft_status', 'local' => $local, 'remote' => $upstream,
        'detail' => ['draft_status' => 2] + $changedDetail, 'failed' => 1, 'applied' => 0, 'writes' => 0];
    foreach ($unknownCases as $index => $case) {
        $sourceId = 201 + $index;
        $seedSelectionSource($sourceId, ['A' => array_replace([
            'shared_premium' => '0.10', 'name' => 'Remote fixture A', 'description' => 'Remote fixture description',
            'cover' => $imagePath($redImage), 'config' => Ini::toConfig($case['local']), 'widget' => $canonicalWidget,
            'price' => 44, 'user_price' => 38.5, 'stock' => 2, 'shared_stock' => '[]', 'api_status' => 1,
        ], $case['row'] ?? [])]);
        $detail = array_replace(['cover' => '/fixture-cover.png', 'widget' => $canonicalWidget, 'draft_status' => 0]
            + $selectionDetail('A', $case['remote']), $case['detail'] ?? []);
        $before = $sourceRows($sourceId)[0];
        if (str_ends_with($case['label'], ':raw_widget_equal')) {
            resumeExpect($detail['widget'] === $before['widget'] && $detail['widget'] !== $canonicalWidget,
                'raw widget equality setup is not testing a different normalized candidate');
        }
        $coverStatus = $case['http_status'] ?? 200;
        $coverBytes = $redImage;
        $requests = [];
        $service = $makeSelectionService($selectionCatalog(['A' => 2]), ['A' => $detail], $requests, $serveCover);
        $options = !empty($case['legacy'])
            ? Options::fromArray(['mode' => 'basic', 'source_ids' => (string)$sourceId, 'batch_limit' => 4])
            : $selectedOptions($sourceId, Options::SYNC_FIELDS);
        $beforeCount = DB::table('commodity')->count();
        $beforeImages = $imageFiles();
        $observed = $observeRun($service, $options);
        $result = $observed['result'];
        $failed = $case['failed'] ?? 0;
        resumeExpect($result['status'] === ($failed > 0 ? 'partial' : 'ok') && $result['failed'] === $failed
            && ($result['selection_held'] ?? 0) === ($case['held'] ?? 0)
            && ($result['cover_failed'] ?? 0) === ($case['cover_failed'] ?? 0)
            && $result['applied']['sync'] === ($case['applied'] ?? 1),
            'unknown metadata integration outcome mismatch: ' . $case['label'] . '; status=' . $result['status']
                . '; failed=' . $result['failed'] . '; selection_held=' . ($result['selection_held'] ?? 0));
        resumeExpect($observed['writes'] === $case['writes']
            && $sourceRows($sourceId) === [array_replace($before, $case['changed'] ?? [])]
            && $requests === ['catalog' => 1, 'detail' => 1, 'other' => 0, 'image' => ($case['images'] ?? 1)]
            && DB::table('commodity')->count() === $beforeCount && $imageFiles() === $beforeImages
            && $result['planned']['import'] === 0 && $result['applied']['import'] === 0
            && $result['applied']['zero'] === 0 && $result['applied']['held_race'] === 0,
            'unknown metadata integration changed protected columns, normalized widget, requests or stock/category safety: ' . $case['label']);
    }

    // Explicit ownership uses the same normalized, currency-converted and
    // price-adjusted candidate as the ordinary selected-field save path.
    $followLocal = $complexLocal + ['fixture_notes' => ['caption' => 'local manual edit', 'local_only' => '17.75'],
        'fixture_local_only' => ['remove_me' => 'local'], 'category_factory' => ['Keep' => '99.00']];
    $followFirst = $complexAdded + ['fixture_notes' => ['caption' => 'upstream first', 'limit' => '7', 'ratio' => '0.25'],
        'fixture_metadata' => ['group' => ['label' => 'unchanged literal']],
        'category_factory' => ['Keep' => '3.00', 'Added' => '4.00']];
    $followFirstExpected = [
        'category' => ['Keep' => '22.00', 'Delete' => '44.00', 'Added' => '66.00'],
        'wholesale' => [10 => '17.60', 20 => '15.40', 30 => '13.20'],
        'sku' => ['Region' => ['East' => '2.20', 'West' => '4.40', 'North' => '6.60'], 'Term' => ['Year' => '8.80']],
        'category_wholesale' => ['Keep' => [10 => '19.80'], 'Delete' => [10 => '39.60'], 'Added' => [10 => '59.40']],
        'shared_mapping' => $complexAdded['shared_mapping'],
        'fixture_notes' => $followFirst['fixture_notes'], 'fixture_metadata' => $followFirst['fixture_metadata'],
        // Unknown to ConfigSelection is not the same as unknown to SharedCurrency:
        // category_factory is converted once by core, never marked up here.
        'category_factory' => ['Keep' => '6.00', 'Added' => '8.00'],
        'sku_cost' => ['Region' => ['East' => '2.00', 'West' => '4.00', 'North' => '6.00'], 'Term' => ['Year' => '8.00']],
        'category_cost' => ['Keep' => '20.00', 'Delete' => '40.00', 'Added' => '60.00'],
    ];
    $followSecond = $complexChanged + ['fixture_notes' => ['caption' => 'upstream second', 'limit' => '9'],
        'fixture_added' => ['numeric_parameter' => '12.50'], 'category_factory' => ['Keep' => '4.00']];
    $followSecondExpected = [
        'category' => ['Keep' => '26.40', 'Added' => '55.00'],
        'wholesale' => [10 => '22.00', 30 => '17.60'],
        'sku' => ['Region' => ['East' => '3.30', 'North' => '5.50']],
        'category_wholesale' => ['Keep' => [10 => '24.20'], 'Added' => [10 => '50.60']],
        'shared_mapping' => $complexChanged['shared_mapping'],
        'fixture_notes' => $followSecond['fixture_notes'], 'fixture_added' => $followSecond['fixture_added'],
        'category_factory' => ['Keep' => '8.00'],
        'sku_cost' => ['Region' => ['East' => '3.00', 'North' => '5.00']],
        'category_cost' => ['Keep' => '24.00', 'Added' => '50.00'],
    ];
    $followDetail = static fn(array $config): array => ['widget' => $changedWidget, 'draft_status' => 1,
        'draft_premium' => '2.00'] + $selectionDetail('A', $config, 19);
    $followOptions = static fn(int $sourceId, array $selected = ['price', 'options']): Options =>
        $selectedOptions($sourceId, $selected, [], ['follow_upstream_config' => true,
            'follow_upstream_config_source_ids' => (string)$sourceId]);
    $seedSelectionSource(301, ['A' => ['config' => Ini::toConfig($followLocal), 'shared_premium' => '0.10']]);
    DB::table('shared')->where('id', 301)->update(['currency_rate' => '2']);
    $followRuns = 0;
    foreach ([[$followFirst, $followFirstExpected], [$followSecond, $followSecondExpected],
        [$followSecond, $followSecondExpected]] as $phase => [$remote, $expectedConfig]) {
        if ($phase === 1) {
            $manualConfig = $followFirstExpected;
            $manualConfig['fixture_notes']['caption'] = 'manual edit after first follow';
            $manualConfig['fixture_manual_added'] = ['discard_me' => 'local'];
            DB::table('commodity')->where('shared_id', 301)->update(['config' => Ini::toConfig($manualConfig)]);
        }
        $before = $sourceRows(301)[0];
        $countBefore = DB::table('commodity')->count();
        $requests = [];
        $observed = $observeRun($makeSelectionService($selectionCatalog(['A' => 19, 'NEW' => 19]),
            ['A' => $followDetail($remote)], $requests), $followOptions(301));
        $expected = array_replace($before, ['price' => 88, 'user_price' => 77, 'draft_premium' => 4.4,
            'config' => Ini::toConfig($expectedConfig), 'widget' => $changedWidget, 'draft_status' => 1, 'api_status' => 1]);
        resumeExpect($observed['result']['status'] === 'ok' && $observed['result']['failed'] === 0
            && ($observed['result']['selection_held'] ?? 0) === 0 && $observed['result']['applied']['sync'] === 1
            && $observed['result']['planned']['import'] === 0 && $observed['result']['applied']['import'] === 0
            && $observed['writes'] === ($phase === 2 ? 0 : 1) && $sourceRows(301) === [$expected]
            && DB::table('commodity')->count() === $countBefore
            && $requests === ['catalog' => 1, 'detail' => 1, 'other' => 0],
            'full ownership must track additions/changes/deletions once without changing unselected fields: phase ' . $phase
                . '; result=' . json_encode($observed['result'], JSON_THROW_ON_ERROR)
                . '; writes=' . $observed['writes'] . '; changed=' . json_encode(array_diff_assoc($sourceRows(301)[0], $expected), JSON_THROW_ON_ERROR));
        $followRuns++;
    }

    // The independent stored ownership list is separate from execution sources;
    // a CLI source override cannot authorize a different source's config.
    $scopeLocal = ['fixture_notes' => ['caption' => 'local']];
    $scopeRemote = ['fixture_notes' => ['caption' => 'remote']];
    foreach ([302, 303] as $sourceId) {
        $seedSelectionSource($sourceId, ['A' => ['config' => Ini::toConfig($scopeLocal), 'api_status' => 1,
            'price' => 40, 'user_price' => 35]]);
    }
    $scopeConfig = ['mode' => 'basic', 'source_ids' => '302', 'follow_upstream_config' => true,
        'follow_upstream_config_source_ids' => '302', 'batch_limit' => 4];
    foreach (Options::SYNC_FIELDS as $field) $scopeConfig['sync_' . $field] = in_array($field, ['price', 'options'], true);
    foreach ([302, 303] as $sourceId) {
        $otherSource = $sourceRows($sourceId === 302 ? 303 : 302);
        $before = $sourceRows($sourceId)[0];
        $requests = [];
        $observed = $observeRun($makeSelectionService($selectionCatalog(['A' => 2]),
            ['A' => $selectionDetail('A', $scopeRemote)], $requests),
            Options::fromArray($scopeConfig, ['source_ids' => (string)$sourceId]));
        resumeExpect($sourceRows($sourceId) === [array_replace($before,
            $sourceId === 302 ? ['config' => Ini::toConfig($scopeRemote)] : [])]
            && $observed['writes'] === ($sourceId === 302 ? 1 : 0)
            && ($observed['result']['selection_held'] ?? 0) === ($sourceId === 302 ? 0 : 1)
            && $sourceRows($sourceId === 302 ? 303 : 302) === $otherSource,
            'execution source override expanded ownership or modified a non-target source');
        $followRuns++;
    }

    $guardCases = [
        ['label' => 'default_missing', 'follow' => null, 'selected' => ['price', 'options']],
        ['label' => 'explicit_off', 'follow' => false, 'selected' => ['price', 'options']],
        ['label' => 'six_checked_not_consent', 'follow' => null, 'selected' => Options::SYNC_FIELDS],
        ['label' => 'price_unselected', 'selected' => ['options']],
        ['label' => 'options_unselected', 'selected' => ['price']],
        ['label' => 'amount_gate_off', 'selected' => ['price', 'options'], 'row' => ['shared_amount_sync' => 0]],
        ['label' => 'config_gate_off', 'selected' => ['price', 'options'], 'row' => ['shared_config_sync' => 0]],
    ];
    foreach ($guardCases as $index => $case) {
        $sourceId = 310 + $index;
        $seedSelectionSource($sourceId, ['A' => ($case['row'] ?? []) + ['config' => Ini::toConfig($scopeLocal),
            'api_status' => 1, 'price' => 40, 'user_price' => 35, 'stock' => 2, 'shared_stock' => '[]',
            'name' => 'Remote fixture A', 'description' => 'Remote fixture description', 'cover' => $imagePath($redImage)]]);
        $before = $sourceRows($sourceId);
        $requests = [];
        $detail = ['widget' => $changedWidget, 'draft_status' => 1, 'cover' => '/fixture-cover.png']
            + $selectionDetail('A', $scopeRemote);
        $coverStatus = 200;
        $coverBytes = $redImage;
        $observed = $observeRun($makeSelectionService($selectionCatalog(['A' => 2]), ['A' => $detail], $requests, $serveCover),
            $selectedOptions($sourceId, $case['selected'], [],
                isset($case['follow']) || !array_key_exists('follow', $case)
                    ? ['follow_upstream_config' => $case['follow'] ?? true,
                        'follow_upstream_config_source_ids' => (string)$sourceId] : []));
        resumeExpect($observed['result']['status'] === 'partial' && ($observed['result']['selection_held'] ?? 0) === 1
            && $observed['writes'] === 0 && $sourceRows($sourceId) === $before,
            'ownership must preserve existing protection without both effective selections: ' . $case['label']);
        $followRuns++;
    }

    // Config, controls and draft mode form one candidate. Invalid candidates
    // must not leak otherwise-valid prices or housekeeping into the database.
    $invalidFollowCases = [
        'invalid_category_amount' => ['config' => ['category' => ['Keep' => 'not-money']]],
        'invalid_category_tree' => ['config' => ['category' => 'bad-tree']],
        'invalid_mapping' => ['config' => ['category' => ['Keep' => '10.00'], 'shared_mapping' => ['Other' => 'sku-other']]],
        'invalid_mapping_tree' => ['config' => ['category' => ['Keep' => '10.00'], 'shared_mapping' => 'bad-tree']],
        'invalid_config_key' => ['config' => ['fixture_notes' => ["bad\nkey" => 'literal']]],
        'invalid_widget' => ['widget' => '[{"name":"invalid-name","type":"text"}]'],
        'invalid_draft' => ['draft_status' => 2],
        'detail_identity_changed' => ['code' => 'DIFFERENT'],
    ];
    foreach ($invalidFollowCases as $index => $changes) {
        $sourceId = 330 + $followRuns;
        $seedSelectionSource($sourceId, ['A' => ['config' => Ini::toConfig($scopeLocal)]]);
        $before = $sourceRows($sourceId);
        $requests = [];
        $observed = $observeRun($makeSelectionService($selectionCatalog(['A' => 19]),
            ['A' => array_replace($followDetail($scopeRemote), $changes)], $requests), $followOptions($sourceId));
        resumeExpect($observed['result']['failed'] === 1 && $observed['result']['applied']['sync'] === 0
            && $observed['writes'] === 0 && $sourceRows($sourceId) === $before && DB::connection()->transactionLevel() === 0,
            'invalid full-ownership candidate escaped zero-write validation: ' . $index
                . '; result=' . json_encode($observed['result'], JSON_THROW_ON_ERROR) . '; writes=' . $observed['writes']);
        $followRuns++;
    }

    foreach (['source_identity', 'managed_code', 'core_owner', 'commodity_owner'] as $index => $kind) {
        $sourceId = 360 + $index;
        $seedSelectionSource($sourceId, ['A' => ['config' => Ini::toConfig($scopeLocal)]]);
        $injectedRows = [];
        $requests = [];
        $inject = static function () use ($sourceId, $kind, &$injectedRows, $sourceRows): void {
            if ($kind === 'source_identity') DB::table('shared')->where('id', $sourceId)->update(['name' => 'changed during request']);
            else DB::table('commodity')->where('shared_id', $sourceId)->update(match ($kind) {
                'managed_code' => ['code' => 'UNMANAGED'], 'core_owner' => ['shared_sync' => 1], 'commodity_owner' => ['owner' => 9],
            });
            $injectedRows = $sourceRows($sourceId);
        };
        $observed = $observeRun($makeSelectionService($selectionCatalog(['A' => 19]),
            ['A' => $followDetail($scopeRemote)], $requests, null, $inject), $followOptions($sourceId));
        resumeExpect($observed['result']['failed'] === 1 && $observed['result']['applied']['sync'] === 0
            && $observed['writes'] === 1 && $sourceRows($sourceId) === $injectedRows
            && DB::connection()->transactionLevel() === 0,
            'full ownership wrote after identity/management drift (one write belongs only to fault injection): ' . $kind);
        $followRuns++;
    }

    // Native SQLite triggers inject a failure before/after the UPDATE without
    // adding an event-dispatch dependency absent from the fixed core vendor set.
    $afterSaveSeen = 0;
    DB::connection()->getPdo()->sqliteCreateFunction('fixture_after_save', static function () use (&$afterSaveSeen): int {
        return ++$afterSaveSeen;
    }, 0);
    foreach (['BEFORE', 'AFTER'] as $timing) {
        $sourceId = $timing === 'BEFORE' ? 370 : 371;
        $seedSelectionSource($sourceId, ['A' => ['config' => Ini::toConfig($scopeLocal)]]);
        $afterSaveSeen = 0;
        $afterStatement = $timing === 'AFTER' ? 'SELECT fixture_after_save();' : '';
        DB::unprepared("CREATE TRIGGER fixture_save_failure {$timing} UPDATE ON commodity "
            . "WHEN NEW.shared_id = {$sourceId} BEGIN {$afterStatement} SELECT RAISE(FAIL, 'synthetic save failure'); END");
        $before = $sourceRows($sourceId);
        $requests = [];
        $observed = $observeRun($makeSelectionService($selectionCatalog(['A' => 19]),
            ['A' => $followDetail($scopeRemote)], $requests), $followOptions($sourceId));
        DB::unprepared('DROP TRIGGER fixture_save_failure');
        // Eloquent does not log the failed UPDATE. AFTER + RAISE(FAIL) proves
        // the row was reached; only the surrounding transaction undoes it.
        resumeExpect($observed['result']['failed'] === 1 && $observed['result']['applied']['sync'] === 0
            && $observed['writes'] === 0 && $sourceRows($sourceId) === $before
            && $afterSaveSeen === ($timing === 'AFTER' ? 1 : 0)
            && DB::connection()->transactionLevel() === 0,
            'full config/widget/draft/price candidate did not rollback a save failure: ' . $timing);
        $followRuns++;
    }

    // First import and later synchronization share the existing importer and
    // normalized pricing path; no new category planning or import queue runs.
    $seedSelectionSource(380, []);
    DB::table('shared')->where('id', 380)->update(['currency_rate' => '2']);
    $requests = [];
    $importService = $makeSelectionService($selectionCatalog(['A' => 19]), ['A' => $followDetail($followFirst)], $requests);
    $dependency = static fn(string $name): mixed => (new \ReflectionProperty($importService, $name))->getValue($importService);
    $importer = new \Pika\LocalExtensions\PikaSupplySync\Service\CommodityImporter(
        $dependency('gateway'), $dependency('prices'),
        new \Pika\LocalExtensions\PikaSupplySync\Service\RemoteItem($dependency('images')), $dependency('sourcePolicy'));
    $importSource = \App\Model\Shared::query()->find(380);
    $importOptions = $selectedOptions(380, ['price', 'options'], [], ['follow_upstream_config' => true,
        'follow_upstream_config_source_ids' => '380', 'premium_percent' => 10]);
    $beforeImportCount = DB::table('commodity')->count();
    $outcome = $importer->import($importSource, ['code' => 'A', 'category' => 'Synthetic category', 'stock' => 19], 17, $importOptions);
    $imported = $sourceRows(380)[0];
    resumeExpect($outcome === 'created' && DB::table('commodity')->count() === $beforeImportCount + 1
        && $imported['config'] === Ini::toConfig($followFirstExpected) && $imported['price'] === 88
        && $imported['user_price'] === 77 && $imported['draft_premium'] === 4.4
        && $imported['shared_sync'] === 0 && $imported['category_id'] === 17
        && $requests === ['catalog' => 0, 'detail' => 1, 'other' => 0],
        'real importer did not establish the normalized full config and managed identity');
    $requests = [];
    $observed = $observeRun($makeSelectionService($selectionCatalog(['A' => 19, 'NEW' => 19]),
        ['A' => $followDetail($followSecond)], $requests), $importOptions);
    resumeExpect($observed['result']['status'] === 'ok' && $observed['result']['applied']['sync'] === 1
        && $observed['result']['applied']['import'] === 0 && $observed['writes'] === 1
        && $sourceRows(380) === [array_replace($imported, ['config' => Ini::toConfig($followSecondExpected)])]
        && DB::table('commodity')->count() === $beforeImportCount + 1,
        'first import to subsequent ownership sync changed category/identity, duplicated a row or lost normalized config');
    $followRuns++;

    // Consumer checks use the actual core parser/valuation and order entry's
    // early validation branches. No order, payment, account or upstream call.
    $order = new \App\Service\Bind\Order();
    $persisted = Commodity::query()->where('shared_id', 301)->first();
    resumeExpect($persisted !== null, 'consumer fixture disappeared');
    $parsedCommodity = clone $persisted;
    $order->parseConfig($parsedCommodity, null);
    resumeExpect($parsedCommodity->config === $followSecondExpected
        && json_decode($persisted->widget, true, 16, JSON_THROW_ON_ERROR)[0]['name'] === 'plan_choice',
        'saved config or dynamic widget cannot be consumed by core');
    resumeExpect($order->valuation($persisted, 1, 'Keep', ['Region' => 'North']) === '31.90'
        && $order->valuation($persisted, 10, 'Keep', ['Region' => 'East']) === '275.00'
        && $order->getCost($persisted, 1, 'Keep', ['Region' => 'North']) === '29.00',
        'core quote/cost consumers did not use the synchronized specification, tier and cost once');
    $consumerRejected = 0;
    $invalidSubmission = ['item_id' => (int)$persisted->id, 'contact' => 'synthetic@example.invalid', 'num' => 0,
        'card_id' => 0, 'pay_id' => 0, 'device' => 0, 'password' => '', 'coupon' => '', 'race' => 'Keep',
        'request_no' => 'synthetic-only', 'sku' => ['Region' => 'North']];
    foreach ([static fn() => $order->valuation($persisted, 1, 'Delete', ['Region' => 'North']),
        static fn() => $order->valuation($persisted, 1, 'Keep', ['Region' => 'West']),
        static fn() => $order->getTradeAmount(null, null, 0, 0, '', $persisted, 'Keep', ['Region' => 'North']),
        static fn() => $order->getTradeAmount(null, null, 0, 1, '', $persisted, 'Delete', ['Region' => 'North']),
        static fn() => $order->getTradeAmount(null, null, 0, 1, '', $persisted, 'Keep', []),
        static fn() => $order->getTradeAmount(null, null, 0, 1, '', $persisted, 'Keep', ['Region' => 'West']),
        static fn() => $order->trade(null, null, $invalidSubmission),
        static fn() => $order->trade(null, null, array_replace($invalidSubmission, ['item_id' => 0, 'num' => 1]))] as $invalidConsumer) {
        try {
            $invalidConsumer();
        } catch (\Kernel\Exception\JSONException) {
            $consumerRejected++;
        }
    }
    resumeExpect($consumerRejected === 8, 'core quote/submit prevalidation accepted a stale/missing selection or invalid count');

    // The approved existing-item policy is six fields, CNY, and twenty percent.
    // Seed exactly two synthetic targets; never replay private production data.
    $twentyFirstExpected = [
        'category' => ['Keep' => '12.00', 'Delete' => '24.00', 'Added' => '36.00'],
        'wholesale' => [10 => '9.60', 20 => '8.40', 30 => '7.20'],
        'sku' => ['Region' => ['East' => '1.20', 'West' => '2.40', 'North' => '3.60'], 'Term' => ['Year' => '4.80']],
        'category_wholesale' => ['Keep' => [10 => '10.80'], 'Delete' => [10 => '21.60'], 'Added' => [10 => '32.40']],
        'shared_mapping' => $complexAdded['shared_mapping'],
        'fixture_notes' => $followFirst['fixture_notes'], 'fixture_metadata' => $followFirst['fixture_metadata'],
        'category_factory' => $followFirst['category_factory'],
        'sku_cost' => $complexAdded['sku'], 'category_cost' => $complexAdded['category'],
    ];
    $twentySecondExpected = [
        'category' => ['Keep' => '14.40', 'Added' => '30.00'],
        'wholesale' => [10 => '12.00', 30 => '9.60'],
        'sku' => ['Region' => ['East' => '1.80', 'North' => '3.00']],
        'category_wholesale' => ['Keep' => [10 => '13.20'], 'Added' => [10 => '27.60']],
        'shared_mapping' => $complexChanged['shared_mapping'],
        'fixture_notes' => $followSecond['fixture_notes'], 'fixture_added' => $followSecond['fixture_added'],
        'category_factory' => $followSecond['category_factory'],
        'sku_cost' => $complexChanged['sku'], 'category_cost' => $complexChanged['category'],
    ];
    $seedSelectionSource(390, [
        'A' => ['config' => Ini::toConfig($scopeLocal), 'shared_premium' => '0.20'],
        'B' => ['config' => Ini::toConfig($followLocal), 'shared_premium' => '0.20'],
    ]);
    $twentyOptions = $selectedOptions(390, Options::SYNC_FIELDS, [], [
        'batch_limit' => 20, 'follow_upstream_config' => true, 'follow_upstream_config_source_ids' => '390',
        'premium_percent' => 99, // Import defaults must not change existing-item margins.
    ]);
    $twentyOtherSources = DB::table('commodity')->where('shared_id', '!=', 390)->orderBy('id')->get()->toJson();
    $twentyCount = DB::table('commodity')->count();
    $twentyRuns = 0;
    $twentyDetails = static function (array $config, bool $empty) use ($selectionDetail, $changedWidget): array {
        $details = [];
        foreach (['A', 'B'] as $code) {
            $details[$code] = ['cover' => '/fixture-cover.png', 'widget' => $empty ? '[]' : $changedWidget,
                'draft_status' => $empty ? 0 : 1, 'draft_premium' => $empty ? '0.00' : '2.00']
                + $selectionDetail($code, $config, 19);
        }
        return $details;
    };
    foreach ([[$followFirst, $twentyFirstExpected], [$followSecond, $twentySecondExpected],
        [$followSecond, $twentySecondExpected], [[], []], [[], []]] as $phase => [$remote, $expectedConfig]) {
        $before = $sourceRows(390);
        $requests = [];
        $coverStatus = 200;
        $coverBytes = $redImage;
        $observed = $observeRun($makeSelectionService($selectionCatalog(['A' => 19, 'B' => 19, 'NEW' => 19]),
            $twentyDetails($remote, $phase >= 3), $requests, $serveCover), $twentyOptions);
        $expected = array_map(static fn(array $row): array => array_replace($row, [
            'name' => 'Remote fixture ' . $row['shared_code'], 'description' => 'Remote fixture description',
            'cover' => $imagePath($redImage), 'price' => 48, 'user_price' => 42,
            'draft_premium' => $phase >= 3 ? 0 : 2.4, 'config' => Ini::toConfig($expectedConfig),
            'widget' => $phase >= 3 ? '[]' : $changedWidget, 'draft_status' => $phase >= 3 ? 0 : 1,
            'stock' => 19, 'shared_stock' => '[]', 'api_status' => 1,
        ]), $before);
        resumeExpect($observed['result']['status'] === 'ok' && $observed['result']['failed'] === 0
            && ($observed['result']['selection_held'] ?? 0) === 0 && ($observed['result']['cover_failed'] ?? 0) === 0
            && $observed['result']['applied']['sync'] === 2 && $observed['result']['applied']['import'] === 0
            && $observed['writes'] === (in_array($phase, [2, 4], true) ? 0 : 2)
            && $sourceRows(390) === $expected && DB::table('commodity')->count() === $twentyCount
            && $requests === ['catalog' => 1, 'detail' => 2, 'other' => 0, 'image' => 1]
            && DB::table('commodity')->where('shared_id', '!=', 390)->orderBy('id')->get()->toJson() === $twentyOtherSources,
            'twenty-percent two-target full follow changed scope, costs, identity, paired controls or repeat pricing: phase ' . $phase);
        foreach (Commodity::query()->where('shared_id', 390)->get() as $saved) {
            $parsed = clone $saved;
            $order->parseConfig($parsed, null);
            resumeExpect($parsed->config === $expectedConfig, 'twenty-percent saved tree failed native parser readback');
            if ($phase === 1 || $phase === 2) {
                resumeExpect($order->valuation($saved, 1, 'Keep', ['Region' => 'North']) === '17.40'
                    && $order->valuation($saved, 10, 'Keep', ['Region' => 'East']) === '150.00'
                    && $order->getCost($saved, 1, 'Keep', ['Region' => 'North']) === '14.50',
                    'twenty-percent native quote/cost differs from one upstream markup and unmarked cost');
            }
        }
        $twentyRuns++;
    }
    foreach ($invalidFollowCases + ['negative_price' => ['price' => '-1.00'],
        'scalar_unknown_section' => ['config' => ['fixture_notes' => 'not-a-section']]] as $label => $changes) {
        $before = $sourceRows(390);
        $requests = [];
        $details = array_map(static fn(array $detail): array => array_replace($detail, $changes),
            $twentyDetails($followSecond, false));
        $observed = $observeRun($makeSelectionService($selectionCatalog(['A' => 19, 'B' => 19]),
            $details, $requests, $serveCover), $twentyOptions);
        resumeExpect($observed['result']['failed'] === 2 && $observed['result']['applied']['sync'] === 0
            && $observed['writes'] === 0 && $sourceRows(390) === $before && DB::connection()->transactionLevel() === 0,
            'twenty-percent invalid two-target candidate escaped zero-write validation: ' . $label);
        $twentyRuns++;
    }

    // Targeted acceptance must not consume ordinary rotation, even when the
    // requested pair sits outside the next batch of a larger source.
    $targetHash = static fn(string $code): string => substr(hash('sha256', $code), 0, 12);
    $targetHashes = array_map($targetHash, ['Y', 'Z']);
    $seedSelectionSource(400, array_fill_keys(['A', 'B', 'Y', 'Z'],
        ['config' => Ini::toConfig($scopeLocal), 'shared_premium' => '0.20']));
    $targetOptions = $selectedOptions(400, Options::SYNC_FIELDS, [], ['batch_limit' => 2,
        'follow_upstream_config' => true, 'follow_upstream_config_source_ids' => '400']);
    $targetDetails = [];
    foreach (['A', 'B', 'Y', 'Z'] as $code) {
        $targetDetails[$code] = ['cover' => '/fixture-cover.png', 'widget' => $changedWidget,
            'draft_status' => 1, 'draft_premium' => '2.00'] + $selectionDetail($code, $followSecond, 19);
    }
    $targetCatalog = $selectionCatalog(['A' => 19, 'B' => 19, 'Y' => 19, 'Z' => 19, 'NEW' => 19]);
    $targetStore = new StateStore();
    $targetStore->write(400, array_replace($targetStore->read(400), ['cursor' => 'Z', 'priority_cursor' => 'Y']));
    $targetRuntime = $stateSite . '/runtime/extensions/PikaSupplySync';
    $targetStateBytes = file_get_contents($targetRuntime . '/source-400.json');
    $targetRotationBytes = file_get_contents($targetRuntime . '/rotation.json');
    $targetOrdinaryLog = file_get_contents($targetRuntime . '/sync.log');
    $targetedLogBefore = is_file($targetRuntime . '/targeted-sync.log')
        ? file_get_contents($targetRuntime . '/targeted-sync.log') : '';
    resumeExpect(is_string($targetedLogBefore), 'unable to read preceding targeted history');
    $targetRuns = 0;
    foreach ([false, false, true] as $iteration => $preview) {
        $before = $sourceRows(400);
        $requests = [];
        $targetOptions->dryRun = $preview;
        $observed = $observeRun($makeSelectionService($targetCatalog, $targetDetails, $requests, $serveCover),
            $targetOptions, $targetHashes);
        $expected = array_map(static fn(array $row): array => $preview || !in_array($row['shared_code'], ['Y', 'Z'], true)
            ? $row : array_replace($row, ['name' => 'Remote fixture ' . $row['shared_code'],
                'description' => 'Remote fixture description', 'cover' => $imagePath($redImage),
                'price' => 48, 'user_price' => 42, 'draft_premium' => 2.4, 'config' => Ini::toConfig($twentySecondExpected),
                'widget' => $changedWidget, 'draft_status' => 1, 'stock' => 19, 'shared_stock' => '[]', 'api_status' => 1]), $before);
        resumeExpect($observed['result']['status'] === 'ok' && ($observed['run']['targeted'] ?? false) === true
            && $observed['result']['verified_code_hashes'] === ($preview ? [] : $targetHashes)
            && $observed['result']['planned'] === ['sync' => 2, 'import' => 0, 'zero' => 0, 'hold_zero' => 0, 'held_unknown' => 0]
            && $observed['result']['applied']['sync'] === ($preview ? 0 : 2)
            && $observed['writes'] === ($iteration === 0 ? 2 : 0) && $sourceRows(400) === $expected
            && $requests === ['catalog' => 1, 'detail' => $preview ? 0 : 2, 'other' => 0, 'image' => $preview ? 0 : 1]
            && file_get_contents($targetRuntime . '/source-400.json') === $targetStateBytes
            && file_get_contents($targetRuntime . '/rotation.json') === $targetRotationBytes
            && file_get_contents($targetRuntime . '/sync.log') === $targetOrdinaryLog,
            'targeted full follow did not restrict the exact pair or preserve ordinary state/logs');
        $targetRuns++;
    }
    $targetOptions->dryRun = false;
    $targetedLogAfter = file_get_contents($targetRuntime . '/targeted-sync.log');
    resumeExpect(is_string($targetedLogAfter) && str_starts_with($targetedLogAfter, $targetedLogBefore)
        && str_ends_with($targetedLogAfter, "\n"), 'targeted acceptance changed preceding history or left a partial record');
    $targetLog = explode("\n", substr($targetedLogAfter, strlen($targetedLogBefore)));
    array_pop($targetLog); // The complete final newline was checked above.
    resumeExpect(count($targetLog) === 3
        && (fileperms($targetRuntime . '/targeted-sync.log') & 0777) === 0600,
        'targeted acceptance must use the existing private bounded logger independently of ordinary history');
    foreach ($targetLog as $line) {
        $record = json_decode(substr($line, strpos($line, ' ') + 1), true, 32, JSON_THROW_ON_ERROR);
        resumeExpect(($record['targeted'] ?? false) === true && $record['target_code_hashes'] === $targetHashes
            && !isset($record['errors'], $record['message']), 'targeted log lost scope or exposed item details');
    }
    foreach ([[], [$targetHashes[0], $targetHashes[0]], [...$targetHashes, $targetHash('A')],
        ['not-a-hash'], [7], ['key' => $targetHashes[0]]] as $invalidTargets) {
        $requests = [];
        $service = $makeSelectionService($targetCatalog, $targetDetails, $requests, $serveCover);
        resumeExpectThrows(static fn() => $service->run($targetOptions, $invalidTargets, static fn() => $targetOptions),
            'invalid target filter was accepted or expanded to an ordinary run');
        resumeExpect($requests === ['catalog' => 0, 'detail' => 0, 'other' => 0, 'image' => 0],
            'invalid target filter contacted the catalog');
        $targetRuns++;
    }
    foreach ([['batchLimit', 1], ['sourceIds', []], ['sourceIds', [400, 390]],
        ['mode', Options::MODE_FULL], ['syncFields', null]] as [$field, $value]) {
        $invalidOptions = clone $targetOptions;
        $invalidOptions->{$field} = $value;
        $requests = [];
        $service = $makeSelectionService($targetCatalog, $targetDetails, $requests, $serveCover);
        resumeExpectThrows(static fn() => $service->run($invalidOptions, $targetHashes, static fn() => $invalidOptions),
            'targeted execution broadened its basic/source/selection/batch boundary');
        resumeExpect($requests['catalog'] === 0 && $requests['detail'] === 0, 'invalid target options performed HTTP');
        $targetRuns++;
    }
    $missingAuthority = $selectedOptions(400, Options::SYNC_FIELDS);
    $requests = [];
    $service = $makeSelectionService($targetCatalog, $targetDetails, $requests, $serveCover);
    resumeExpectThrows(static fn() => $service->run($missingAuthority, $targetHashes, static fn() => $missingAuthority),
        'targeted execution inferred ownership from all six selections');
    resumeExpectThrows(static fn() => $service->run($targetOptions, $targetHashes),
        'targeted execution accepted a missing fresh-authorization reader');
    $targetRuns += 2;

    foreach (['missing_remote', 'missing_local', 'zero_remote', 'empty_catalog', 'unmanaged',
        'amount_gate', 'config_gate', 'inventory_gate', 'full_source_fuse', 'source_locked'] as $case) {
        DB::beginTransaction();
        $lock = null;
        try {
            $catalog = $targetCatalog;
            $options = clone $targetOptions;
            if ($case === 'missing_remote') $catalog = $selectionCatalog(['A' => 19, 'B' => 19, 'Z' => 19]);
            if ($case === 'missing_local') DB::table('commodity')->where('shared_id', 400)->where('shared_code', 'Z')->delete();
            if ($case === 'zero_remote') $catalog = $selectionCatalog(['A' => 19, 'B' => 19, 'Y' => 19, 'Z' => 0]);
            if ($case === 'empty_catalog') $catalog = [];
            foreach (['unmanaged' => 'shared_sync', 'amount_gate' => 'shared_amount_sync',
                'config_gate' => 'shared_config_sync', 'inventory_gate' => 'inventory_sync'] as $label => $column) {
                if ($case === $label) DB::table('commodity')->where('shared_id', 400)->where('shared_code', 'Z')
                    ->update([$column => $case === 'unmanaged' ? 1 : 0]);
            }
            if ($case === 'full_source_fuse') {
                $catalog = $selectionCatalog(['Y' => 19, 'Z' => 19]);
                $options->zeroFusePercent = 10;
                $options->zeroFuseMin = 1;
            }
            if ($case === 'source_locked') {
                $lock = new \Pika\LocalExtensions\PikaSupplySync\Service\SourceLock();
                resumeExpect($lock->acquire(400), 'unable to hold synthetic target source lock');
            }
            $before = $sourceRows(400);
            $requests = [];
            $observed = $observeRun($makeSelectionService($catalog, $targetDetails, $requests, $serveCover), $options, $targetHashes);
            resumeExpect($observed['result']['status'] === 'error' && $observed['writes'] === 0
                && $sourceRows(400) === $before && $requests['detail'] === 0 && $requests['image'] === 0,
                'target preflight/fuse/lock failed to reject the entire pair before details: ' . $case);
            $targetRuns++;
        } finally {
            if ($lock !== null) $lock->release();
            DB::rollBack();
        }
    }

    foreach (['shared_amount_sync', 'shared_config_sync', 'inventory_sync', 'shared_sync', 'managed_code',
        'premium', 'replace_id', 'source_identity', 'follow_authority', 'six_field_authority', 'batch_authority'] as $case) {
        DB::beginTransaction();
        try {
            $injectedRows = [];
            $readerOptions = $targetOptions;
            $inject = static function () use ($case, &$injectedRows, &$readerOptions, $targetOptions, $sourceRows, $selectedOptions): void {
                $query = DB::table('commodity')->where('shared_id', 400)->where('shared_code', 'Y');
                if (in_array($case, ['shared_amount_sync', 'shared_config_sync', 'inventory_sync', 'shared_sync'], true)) {
                    $query->update([$case => $case === 'shared_sync' ? 1 : 0]);
                } elseif ($case === 'managed_code') $query->update(['code' => 'PKS1' . str_repeat('F', 20)]);
                elseif ($case === 'premium') $query->update(['shared_premium' => '0.30']);
                elseif ($case === 'replace_id') {
                    $replacement = (array)$query->first();
                    unset($replacement['id']);
                    $query->delete();
                    DB::table('commodity')->insert($replacement);
                } elseif ($case === 'source_identity') DB::table('shared')->where('id', 400)->update(['name' => 'changed during request']);
                elseif ($case === 'follow_authority') $readerOptions = $selectedOptions(400, Options::SYNC_FIELDS);
                else {
                    $readerOptions = clone $targetOptions;
                    if ($case === 'batch_authority') $readerOptions->batchLimit = 1;
                    else $readerOptions->syncFields['options'] = false;
                }
                $injectedRows = $sourceRows(400);
            };
            $requests = [];
            $observed = $observeRun($makeSelectionService($targetCatalog, $targetDetails, $requests, $serveCover, $inject),
                $targetOptions, $targetHashes, static function () use (&$readerOptions): Options { return $readerOptions; });
            resumeExpect($observed['result']['status'] === 'partial' && $observed['result']['failed'] === 1
                && $observed['result']['applied']['sync'] === 0 && $sourceRows(400) === $injectedRows
                && $requests['detail'] === 1, 'target race saved stale authority/identity or continued to the second item: ' . $case);
            $targetRuns++;
        } finally {
            DB::rollBack();
        }
    }
    foreach (['after_catalog', 'after_first_save'] as $phase) {
        DB::beginTransaction();
        try {
            DB::table('commodity')->where('shared_id', 400)->whereIn('shared_code', ['Y', 'Z'])
                ->update(['config' => Ini::toConfig($scopeLocal)]);
            $readerOptions = $targetOptions;
            $revoke = static function () use (&$readerOptions, $missingAuthority): int {
                $readerOptions = $missingAuthority;
                return 1;
            };
            if ($phase === 'after_first_save') {
                DB::connection()->getPdo()->sqliteCreateFunction('fixture_target_revoke', $revoke, 0);
                DB::unprepared("CREATE TRIGGER fixture_target_revoke AFTER UPDATE ON commodity "
                    . "WHEN NEW.shared_id = 400 AND NEW.shared_code = 'Y' BEGIN SELECT fixture_target_revoke(); END");
            }
            $catalogReply = $phase === 'after_catalog' ? static function () use ($revoke): ?array { $revoke(); return null; } : null;
            $before = $sourceRows(400);
            $requests = [];
            $observed = $observeRun($makeSelectionService($targetCatalog, $targetDetails, $requests, $serveCover, null, $catalogReply),
                $targetOptions, $targetHashes, static function () use (&$readerOptions): Options { return $readerOptions; });
            $firstSaved = $phase === 'after_first_save';
            resumeExpect($observed['result']['status'] === 'partial' && $observed['result']['failed'] === 1
                && $observed['writes'] === ($firstSaved ? 1 : 0)
                && $requests === ['catalog' => 1, 'detail' => $firstSaved ? 1 : 0, 'other' => 0, 'image' => $firstSaved ? 1 : 0]
                && $observed['result']['verified_code_hashes'] === ($firstSaved ? [$targetHash('Y')] : [])
                && ($firstSaved || $sourceRows(400) === $before),
                'revoked full-follow authority still allowed the next detail/image request: ' . $phase);
            $targetRuns++;
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS fixture_target_revoke');
            DB::rollBack();
        }
    }
    foreach (['first_invalid', 'second_invalid', 'cover_failed', 'held_race'] as $case) {
        DB::beginTransaction();
        try {
            DB::table('commodity')->where('shared_id', 400)->whereIn('shared_code', ['Y', 'Z'])
                ->update(['config' => Ini::toConfig($scopeLocal)]);
            $before = $sourceRows(400);
            $details = $targetDetails;
            if ($case === 'first_invalid' || $case === 'second_invalid') {
                $details[$case === 'first_invalid' ? 'Y' : 'Z']['config'] = ['category' => ['Keep' => 'invalid']];
            }
            if ($case === 'held_race') $details['Y']['stock'] = 0;
            $coverStatus = $case === 'cover_failed' ? 404 : 200;
            $requests = [];
            $observed = $observeRun($makeSelectionService($targetCatalog, $details, $requests, $serveCover), $targetOptions, $targetHashes);
            $after = $sourceRows(400);
            resumeExpect($observed['result']['status'] === 'partial' && $observed['result']['failed'] === 1
                && $observed['writes'] === ($case === 'second_invalid' ? 1 : 0)
                && $observed['result']['applied']['sync'] === ($case === 'second_invalid' ? 1 : 0)
                && $requests['detail'] === ($case === 'second_invalid' ? 2 : 1)
                && $observed['result']['verified_code_hashes'] === ($case === 'second_invalid' ? [$targetHash('Y')] : [])
                && $observed['result']['applied']['held_race'] === ($case === 'held_race' ? 1 : 0)
                && ($case === 'second_invalid' ? ($after[0] === $before[0] && $after[1] === $before[1]
                    && $after[2]['config'] === Ini::toConfig($twentySecondExpected) && $after[3] === $before[3]) : $after === $before),
                'target failure did not stop promptly or misreported per-item partial application: ' . $case);
            $targetRuns++;
        } finally {
            DB::rollBack();
        }
    }
    $coverStatus = 200;
    foreach (['price' => 'price + 0.01', 'config' => "'[altered]' || char(10) || 'key=value'",
        'widget' => "'[]'", 'stock' => 'stock + 1', 'shared_stock' => "'[\"altered\"]'"] as $field => $sqlValue) {
        DB::beginTransaction();
        try {
            DB::table('commodity')->where('shared_id', 400)->where('shared_code', 'Y')
                ->update(['config' => Ini::toConfig($scopeLocal)]);
            DB::unprepared("CREATE TRIGGER fixture_target_readback AFTER UPDATE ON commodity "
                . "WHEN NEW.shared_id = 400 AND NEW.shared_code = 'Y' "
                . "BEGIN UPDATE commodity SET {$field} = {$sqlValue} WHERE id = NEW.id; END");
            $before = $sourceRows(400);
            $requests = [];
            $observed = $observeRun($makeSelectionService($targetCatalog, $targetDetails, $requests, $serveCover),
                $targetOptions, $targetHashes);
            resumeExpect($observed['result']['status'] === 'partial' && $observed['result']['failed'] === 1
                && $observed['result']['applied']['sync'] === 0 && $observed['result']['verified_code_hashes'] === []
                && $sourceRows(400) === $before && $requests['detail'] === 1,
                'target save success concealed a persisted readback mismatch: ' . $field);
            $targetRuns++;
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS fixture_target_readback');
            DB::rollBack();
        }
    }
    resumeExpect(file_get_contents($targetRuntime . '/source-400.json') === $targetStateBytes
        && file_get_contents($targetRuntime . '/rotation.json') === $targetRotationBytes
        && file_get_contents($targetRuntime . '/sync.log') === $targetOrdinaryLog,
        'targeted failure paths mutated ordinary source/rotation/history state');

    // Isolate two synthetic sources in a rollback-only fixture transaction so
    // source_ids="" exercises the real all-sources branch, not a simulated list.
    $independentScopeRuns = 0;
    $dualScopeCases = [
        ['label' => 'both_explicit', 'source_ids' => '1,2', 'targets' => [1, 2]],
        ['label' => 'all_sources', 'source_ids' => '', 'targets' => [1, 2]],
        ['label' => 'second_only', 'source_ids' => '2', 'targets' => [2]],
        ['label' => 'saved_sync_list_expanded', 'source_ids' => '1,2', 'targets' => [1, 2]],
        ['label' => 'cli_list_expanded', 'source_ids' => '2', 'targets' => [1, 2],
            'overrides' => ['source_ids' => '1,2', 'follow_upstream_config_source_ids' => '1,2']],
        ['label' => 'follow_list_is_not_execution_list', 'source_ids' => '1', 'targets' => [1]],
    ];
    foreach ($dualScopeCases as $case) {
        DB::beginTransaction();
        try {
            // These are only rows in the disposable SQLite :memory: database.
            DB::table('commodity')->delete();
            DB::table('shared')->delete();
            $dualLocal = ['category' => ['Basic' => '10.00'], 'category_cost' => ['Basic' => '10.00'],
                'fixture_notes' => ['caption' => 'local manual edit'], 'fixture_local_only' => ['tag' => 'local']];
            $dualRemote = ['category' => ['Basic' => '12.00'], 'fixture_notes' => ['caption' => 'upstream changed'],
                'fixture_remote_only' => ['limit' => '9']];
            foreach ([1, 2] as $sourceId) $seedSelectionSource($sourceId, ['A' => ['config' => Ini::toConfig($dualLocal)]]);
            $beforeDual = [1 => $sourceRows(1)[0], 2 => $sourceRows(2)[0]];
            $config = ['mode' => 'basic', 'source_ids' => $case['source_ids'], 'batch_limit' => 4,
                'follow_upstream_config' => true, 'follow_upstream_config_source_ids' => '2'];
            foreach (Options::SYNC_FIELDS as $field) $config['sync_' . $field] = in_array($field, ['name', 'price', 'options'], true);
            $requests = [];
            $detail = ['name' => 'Selected new name', 'price' => '50.00', 'user_price' => '45.00',
                'widget' => $changedWidget, 'draft_status' => 1] + $selectionDetail('A', $dualRemote, 19);
            $observed = $observeRun($makeSelectionService($selectionCatalog(['A' => 19, 'NEW' => 19]),
                ['A' => $detail], $requests), Options::fromArray($config, $case['overrides'] ?? []));
            $resultsBySource = array_column($observed['sources'], null, 'source_id');
            $actualTargets = array_map('intval', array_keys($resultsBySource));
            sort($actualTargets);
            resumeExpect($actualTargets === $case['targets'] && $observed['writes'] === count($case['targets'])
                && $requests === ['catalog' => count($case['targets']), 'detail' => count($case['targets']), 'other' => 0]
                && DB::table('commodity')->count() === 2,
                'independent ownership changed the execution set or imported a duplicate: ' . $case['label']);
            foreach ([1, 2] as $sourceId) {
                $expected = $beforeDual[$sourceId];
                if (in_array($sourceId, $case['targets'], true)) {
                    $projected = $sourceId === 2 ? $dualRemote + ['category_cost' => ['Basic' => '12.00']]
                        : array_replace($dualLocal, ['category' => ['Basic' => '12.00'], 'category_cost' => ['Basic' => '12.00']]);
                    $expected = array_replace($expected, ['name' => 'Selected new name', 'price' => 50, 'user_price' => 45,
                        'config' => Ini::toConfig($projected), 'api_status' => 1]);
                    if ($sourceId === 2) $expected = array_replace($expected, ['widget' => $changedWidget, 'draft_status' => 1]);
                    $result = $resultsBySource[$sourceId];
                    resumeExpect($result['status'] === ($sourceId === 2 ? 'ok' : 'partial')
                        && $result['failed'] === ($sourceId === 2 ? 0 : 1)
                        && ($result['selection_held'] ?? 0) === ($sourceId === 2 ? 0 : 1)
                        && $result['applied']['sync'] === 1 && $result['applied']['import'] === 0,
                        'only source 2 may follow full config; source 1 must retain unknown protection while selected fields update: '
                            . $case['label'] . '; source=' . $sourceId . '; result=' . json_encode($result, JSON_THROW_ON_ERROR));
                }
                resumeExpect($sourceRows($sourceId) === [$expected],
                    'independent ownership overwrote protected config/widget/draft or an unselected field/source: '
                        . $case['label'] . '; source=' . $sourceId);
            }
            $independentScopeRuns++;
        } finally {
            DB::rollBack();
        }
    }

    $missingScopeConfig = ['mode' => 'basic', 'source_ids' => '1,2', 'follow_upstream_config' => true];
    foreach (Options::SYNC_FIELDS as $field) $missingScopeConfig['sync_' . $field] = true;
    $rejectedScopes = 0;
    foreach ([[], ['follow_upstream_config_source_ids' => '']] as $missingScope) {
        foreach ([[], ['follow_upstream_config_source_ids' => '2']] as $cliOverride) {
            try {
                Options::fromArray(array_replace($missingScopeConfig, $missingScope), $cliOverride);
            } catch (\InvalidArgumentException) {
                $rejectedScopes++;
            }
        }
    }
    resumeExpect($rejectedScopes === 4,
        'missing/empty persisted ownership list was inferred from execution source_ids or supplied by a CLI override');
    $legacyWithoutOwnership = Options::fromArray(['mode' => 'basic', 'source_ids' => '1,2']);
    $allCheckedWithoutOwnership = $missingScopeConfig;
    unset($allCheckedWithoutOwnership['follow_upstream_config']);
    $allCheckedWithoutOwnership = Options::fromArray($allCheckedWithoutOwnership);
    foreach ([$legacyWithoutOwnership, $allCheckedWithoutOwnership] as $withoutOwnership) {
        resumeExpect(!$withoutOwnership->followsUpstreamConfig(1) && !$withoutOwnership->followsUpstreamConfig(2),
            'old execution-only config automatically acquired independent config ownership');
    }

    // S0-style type 2/basic sources keep SharedStock and their legacy schema-2
    // mapping. Repeated detail reads must not compound the saved ten-percent margin.
    $legacyTypeTwoRuns = 0;
    $savedMapBytes = file_exists($categoryMapPath) ? file_get_contents($categoryMapPath) : null;
    $savedJobsBytes = file_exists($jobsPath) ? file_get_contents($jobsPath) : null;
    DB::beginTransaction();
    try {
        $legacySource = 503;
        DB::table('category')->insert([['id' => 50301, 'owner' => 0], ['id' => 50302, 'owner' => 0]]);
        $legacyLocal = ['category' => ['Basic' => '12.00'], 'category_cost' => ['Basic' => '8.00']];
        $seedSelectionSource($legacySource, ['A' => ['shared_premium' => '0.10', 'category_id' => 50302,
            'config' => Ini::toConfig($legacyLocal)]], 2);
        $legacyGroupKey = hash('sha256', "group\0" . "0\0\0Legacy group");
        $legacySourceKey = hash('sha256', 'source' . "\0" . $legacySource . "\0" . $legacyGroupKey . "\0Legacy source");
        $legacyCategoryKey = hash('sha256', 'source-category' . "\0" . $legacySource . "\0" . $legacySourceKey . "\0Legacy category");
        $legacyMapBytes = json_encode(['schema' => 2, 'nodes' => [
            $legacyGroupKey => ['id' => 17, 'name' => 'Legacy group', 'parent_key' => null],
            $legacySourceKey => ['id' => 50301, 'name' => 'Legacy source', 'parent_key' => $legacyGroupKey],
            $legacyCategoryKey => ['id' => 50302, 'name' => 'Legacy category', 'parent_key' => $legacySourceKey],
        ], 'last_plan_hash' => hash('sha256', 'legacy type-two fixture')], JSON_THROW_ON_ERROR) . "\n";
        $legacyJobsBytes = json_encode($schemaTwoTerminal, JSON_THROW_ON_ERROR) . "\n";
        file_put_contents($categoryMapPath, $legacyMapBytes); chmod($categoryMapPath, 0600);
        file_put_contents($jobsPath, $legacyJobsBytes); chmod($jobsPath, 0600);
        $legacyCategories = DB::table('category')->orderBy('id')->get()->toJson();
        $legacyCount = DB::table('commodity')->count();
        $legacyBefore = $sourceRows($legacySource)[0];
        $legacyOptions = $selectedOptions($legacySource, ['name', 'price']);
        $legacyDetail = ['cover' => '/fixture-cover.png', 'widget' => $changedWidget, 'draft_status' => 1,
            'draft_premium' => '2.00'] + $selectionDetail('A', ['category' => ['Basic' => '10.00']], 19);
        $legacyExpected = array_replace($legacyBefore, ['name' => 'Remote fixture A', 'price' => 44,
            'user_price' => 38.5, 'draft_premium' => 2.2, 'api_status' => 1,
            'config' => Ini::toConfig(['category' => ['Basic' => '11.00'], 'category_cost' => ['Basic' => '10.00']])]);
        foreach ([1, 0] as $expectedWrites) {
            $requests = [];
            $observed = $observeRun($makeSelectionService($selectionCatalog(['A' => 19, 'NEW' => 19]),
                ['A' => $legacyDetail], $requests, null, null, null, 2), $legacyOptions);
            resumeExpect($observed['result']['status'] === 'ok' && $observed['result']['failed'] === 0
                && $observed['result']['applied']['sync'] === 1 && $observed['result']['applied']['import'] === 0
                && $observed['writes'] === $expectedWrites && $sourceRows($legacySource) === [$legacyExpected]
                && $requests === ['catalog' => 1, 'detail' => 1, 'other' => 0]
                && DB::table('commodity')->count() === $legacyCount,
                'type 2/basic route changed margin, identity, unselected fields or imported a new item');
            resumeExpect(file_get_contents($categoryMapPath) === $legacyMapBytes
                && file_get_contents($jobsPath) === $legacyJobsBytes
                && DB::table('category')->orderBy('id')->get()->toJson() === $legacyCategories,
                'type 2/basic sync converted legacy mapping, changed jobs or wrote categories');
            $legacyTypeTwoRuns++;
        }
    } finally {
        DB::rollBack();
        if ($savedMapBytes === null) unlink($categoryMapPath);
        else file_put_contents($categoryMapPath, $savedMapBytes);
        if ($savedJobsBytes === null) unlink($jobsPath);
        else file_put_contents($jobsPath, $savedJobsBytes);
    }

    // Reuse the same real SyncService/detail/SQLite path for compact routing.
    $compactSource = 501;
    $stateStore = new StateStore();
    $compactRows = $compactStocks = $compactDetails = [];
    foreach (range(1, 20) as $number) {
        $code = sprintf('C%02d', $number);
        $compactRows[$code] = ['shared_premium' => '0.20', 'config' => Ini::toConfig(['category' => ['Basic' => '11.00']])];
        $compactStocks[$code] = 9;
        $compactDetails[$code] = ['cover' => '/fixture-cover.png'] + $selectionDetail($code, ['category' => ['Basic' => '10.00']], 9);
    }
    $seedSelectionSource($compactSource, $compactRows);
    $mirrorKey = hash('sha256', 'mirror' . "\0" . $compactSource . "\0" . 901);
    $mirrorRegistry = ['schema' => 2, 'nodes' => [$mirrorKey => ['id' => 17, 'name' => 'Compact category',
        'parent_key' => null, 'mode' => 'mirror', 'source_id' => $compactSource, 'upstream_id' => 901]],
        'last_plan_hash' => hash('sha256', 'compact fixture plan')];
    $mirrorBytes = json_encode($mirrorRegistry, JSON_THROW_ON_ERROR) . "\n";
    file_put_contents($categoryMapPath, $mirrorBytes); chmod($categoryMapPath, 0600);
    $compactSnapshot = static function (array $stocks): array {
        $items = [];
        foreach ($stocks as $code => $stock) $items[] = ['code' => (string)$code, 'category_id' => 901, 'stock' => $stock];
        return ['schema' => 2, 'capability' => 'pika_category_tree',
            'categories' => [['id' => 901, 'pid' => 0, 'name' => 'Compact category', 'sort' => 0]], 'items' => $items];
    };
    $snapshot = $compactSnapshot($compactStocks + ['NEW' => 9]);
    $expectCompact = static function (array $form, int $limit): void {
        resumeExpect(($form['pika_category_tree'] ?? null) === '2' && !isset($form['app_key'])
            && $limit === 16777216, 'compact route did not preserve opt-in signature/capacity contract');
    };
    $expectLegacy = static function (array $form, int $limit): void {
        resumeExpect(!isset($form['pika_category_tree']) && $limit === 16777216,
            'ordinary source was probed for compact support');
    };
    $compactOptions = static fn(array $overrides = []): Options =>
        $selectedOptions($compactSource, Options::SYNC_FIELDS, $overrides, ['batch_limit' => 20]);
    $before = $sourceRows($compactSource);
    $categoriesBefore = DB::table('category')->orderBy('id')->get()->toJson();
    $stateBefore = $stateStore->read($compactSource);
    $jobsBefore = file_exists($jobsPath) ? file_get_contents($jobsPath) : null;
    $requests = [];
    $preview = $observeRun($makeSelectionService($snapshot, $compactDetails, $requests, null, null, $expectCompact),
        $compactOptions(['dry_run' => true]));
    resumeExpect($preview['result']['status'] === 'ok' && $preview['result']['planned']['sync'] === 15
        && $preview['result']['planned']['import'] === 0 && $preview['writes'] === 0
        && $requests === ['catalog' => 1, 'detail' => 0, 'other' => 0]
        && $sourceRows($compactSource) === $before && $stateStore->read($compactSource) === $stateBefore,
        'compact stock-priority dry-run did not preserve 15 priority slots: ' . json_encode([
            'status' => $preview['result']['status'], 'planned' => $preview['result']['planned'] ?? null,
            'writes' => $preview['writes'], 'requests' => $requests,
            'rows_unchanged' => $sourceRows($compactSource) === $before,
            'cursor_unchanged' => $stateStore->read($compactSource) === $stateBefore], JSON_THROW_ON_ERROR));
    // Keep the original 75% priority ceiling: fifteen mismatches plus five normal items fill twenty slots.
    DB::table('commodity')->where('shared_id', $compactSource)
        ->whereIn('shared_code', array_slice(array_keys($compactStocks), 15))->update(['stock' => 9]);
    $before = $sourceRows($compactSource);
    $requests = [];
    $preview = $observeRun($makeSelectionService($snapshot, $compactDetails, $requests, null, null, $expectCompact),
        $compactOptions(['dry_run' => true]));
    resumeExpect($preview['result']['status'] === 'ok' && $preview['result']['planned']['sync'] === 20
        && $preview['result']['planned']['import'] === 0 && $preview['writes'] === 0
        && $requests === ['catalog' => 1, 'detail' => 0, 'other' => 0]
        && $sourceRows($compactSource) === $before && $stateStore->read($compactSource) === $stateBefore,
        'compact dry-run did not plan 20 existing items without writes/cursor movement: ' . json_encode([
            'status' => $preview['result']['status'], 'planned' => $preview['result']['planned'] ?? null,
            'writes' => $preview['writes'], 'requests' => $requests,
            'rows_unchanged' => $sourceRows($compactSource) === $before,
            'cursor_unchanged' => $stateStore->read($compactSource) === $stateBefore], JSON_THROW_ON_ERROR));
    $coverStatus = 200; $coverBytes = $redImage;
    $requests = [];
    $run = $observeRun($makeSelectionService($snapshot, $compactDetails, $requests, $serveCover, null, $expectCompact), $compactOptions());
    resumeExpect($run['result']['status'] === 'ok' && $run['result']['applied']['sync'] === 20
        && $run['result']['applied']['import'] === 0 && $run['writes'] === 20
        && $requests === ['catalog' => 1, 'detail' => 20, 'other' => 0, 'image' => 1],
        'compact batch bypassed the original bounded per-item sync path');
    foreach ($sourceRows($compactSource) as $index => $row) {
        $code = $row['shared_code'];
        $config = Ini::toArray($row['config']);
        $expected = array_replace($before[$index], ['name' => 'Remote fixture ' . $code,
            'description' => 'Remote fixture description', 'cover' => $imagePath($redImage),
            'price' => 48, 'user_price' => 42, 'stock' => 9, 'shared_stock' => '[]', 'api_status' => 1,
            'config' => Ini::toConfig(['category' => ['Basic' => '12.00'], 'category_cost' => ['Basic' => '10.00']])]);
        resumeExpect($row === $expected && $config['category']['Basic'] === '12.00',
            'compact sync changed identity/category/markup/unselected columns or lost one of the six selected fields');
    }

    // The opt-in capability and tree arrive in one catalog response, not a
    // separate probe. Targeting must preserve that route and its full snapshot.
    $compactTargetOptions = $selectedOptions($compactSource, Options::SYNC_FIELDS, [], ['batch_limit' => 2,
        'follow_upstream_config' => true, 'follow_upstream_config_source_ids' => (string)$compactSource]);
    $compactTargetState = file_get_contents($targetRuntime . '/source-' . $compactSource . '.json');
    $compactTargetRotation = file_get_contents($targetRuntime . '/rotation.json');
    $compactTargetBefore = $sourceRows($compactSource);
    $requests = [];
    $compactTarget = $observeRun($makeSelectionService($snapshot, $compactDetails, $requests, $serveCover, null, $expectCompact),
        $compactTargetOptions, array_map($targetHash, ['C19', 'C20']));
    resumeExpect($compactTarget['result']['status'] === 'ok' && $compactTarget['result']['applied']['sync'] === 2
        && $compactTarget['result']['verified_code_hashes'] === array_map($targetHash, ['C19', 'C20'])
        && $requests === ['catalog' => 1, 'detail' => 2, 'other' => 0, 'image' => 1]
        && $compactTarget['writes'] === 0 && $sourceRows($compactSource) === $compactTargetBefore
        && file_get_contents($targetRuntime . '/source-' . $compactSource . '.json') === $compactTargetState
        && file_get_contents($targetRuntime . '/rotation.json') === $compactTargetRotation,
        'targeted compact route added a capability probe, extra targets or changed ordinary rotation');
    $targetRuns++;

    // A legal nickname/key/rate update is not a loss of persisted mirror ownership.
    DB::table('shared')->where('id', $compactSource)->update(['name' => 'renamed compact source',
        'app_key' => 'rotated-fixture-key', 'currency_rate' => '2']);
    $requests = [];
    $renamed = $observeRun($makeSelectionService($snapshot, $compactDetails, $requests, $serveCover, null, $expectCompact), $compactOptions());
    resumeExpect($renamed['result']['status'] === 'ok' && $renamed['result']['applied']['sync'] === 20
        && $sourceRows($compactSource)[0]['price'] === 96 && $sourceRows($compactSource)[0]['user_price'] === 84
        && Ini::toArray($sourceRows($compactSource)[0]['config'])['category']['Basic'] === '24.00',
        'compact ownership froze a mutable source fingerprint or bypassed official currency conversion');

    // Legitimate deletion/stock-zero uses the exact existing plan, lanes and fuse.
    $localMethod = new \ReflectionMethod(SyncService::class, 'localMap');
    foreach ([['C01' => 0] + array_slice($compactStocks, 2, null, true), ['C01' => 9]] as $stocks) {
        $requests = [];
        $service = $makeSelectionService($compactSnapshot($stocks), [], $requests, null, null, $expectCompact);
        $local = $localMethod->invoke($service, $compactSource);
        $state = $stateStore->read($compactSource);
        $planOptions = $selectedOptions($compactSource, ['inventory'], ['dry_run' => true],
            ['batch_limit' => 20, 'zero_fuse_percent' => 10, 'zero_fuse_min' => 5]);
        $planner = new \Pika\LocalExtensions\PikaSupplySync\Service\CatalogPlanner();
        $legacyCatalog = $planner->flatten($selectionCatalog($stocks));
        $treeCatalog = (new \Pika\LocalExtensions\PikaSupplySync\Service\UpstreamCategoryTree())->flatten($compactSnapshot($stocks));
        $expectedPlan = $planner->plan($legacyCatalog, $local, $state['cursor'], $planOptions, $state['priority_cursor']);
        resumeExpect($planner->plan($treeCatalog, $local, $state['cursor'], $planOptions, $state['priority_cursor']) === $expectedPlan,
            'compact changed legitimate removal, stock-zero, fuse or cursor semantics');
        $observed = $observeRun($service, $planOptions);
        resumeExpect($observed['result']['planned'] === $expectedPlan['counts']
            && $observed['result']['mass_zero_fuse'] === $expectedPlan['fuse'] && $observed['writes'] === 0,
            'compact rejected a valid current catalog or ignored its existing fuse');
    }

    $before = $sourceRows($compactSource); $stateBefore = $stateStore->read($compactSource);
    $badSnapshots = ['empty' => ['schema' => 2, 'capability' => 'pika_category_tree', 'categories' => [], 'items' => []],
        'capability-lost' => $selectionCatalog($compactStocks)];
    foreach (['schema', 'missing-parent', 'duplicate', 'limit', 'negative-stock', 'extra-field', 'partial-node', 'extra-branch'] as $kind) {
        $bad = $snapshot;
        if ($kind === 'schema') $bad['schema'] = 1;
        if ($kind === 'missing-parent') $bad['categories'][0]['pid'] = 902;
        if ($kind === 'duplicate') $bad['items'][] = $bad['items'][0];
        if ($kind === 'limit') $bad['items'] = array_fill(0, 10001, $bad['items'][0]);
        if ($kind === 'negative-stock') $bad['items'][0]['stock'] = -1;
        if ($kind === 'extra-field') $bad['items'][0]['name'] = 'must not fabricate names';
        if ($kind === 'partial-node') unset($bad['categories'][0]['pid']);
        if ($kind === 'extra-branch') $bad['categories'][] = ['id' => 902, 'pid' => 0, 'name' => 'Unused', 'sort' => 0];
        $badSnapshots[$kind] = $bad;
    }
    foreach ($badSnapshots as $kind => $bad) {
        $requests = [];
        $observed = $observeRun($makeSelectionService($bad, [], $requests, null, null, $expectCompact), $compactOptions());
        resumeExpect($observed['result']['status'] === 'error' && $observed['writes'] === 0
            && $requests === ['catalog' => 1, 'detail' => 0, 'other' => 0]
            && $sourceRows($compactSource) === $before && $stateStore->read($compactSource) === $stateBefore,
            'invalid compact response retried/fell back/wrote/moved cursor: ' . $kind);
    }
    foreach (['credentials', 'response_size', 'json'] as $kind) {
        $reply = static function (array $form, int $limit) use ($kind, $expectCompact): array {
            $expectCompact($form, $limit);
            return ['status' => $kind === 'credentials' ? 403 : 200, 'content_type' => 'application/json',
                'body' => $kind === 'response_size' ? str_repeat('x', $limit + 1) : '{'];
        };
        $requests = [];
        $observed = $observeRun($makeSelectionService([], [], $requests, null, null, $reply), $compactOptions());
        $diagnostic = $observed['result']['catalog_diagnostic'] ?? [];
        resumeExpect($observed['result']['status'] === 'error' && $observed['writes'] === 0
            && $requests === ['catalog' => 1, 'detail' => 0, 'other' => 0]
            && $sourceRows($compactSource) === $before && $stateStore->read($compactSource) === $stateBefore
            && array_keys($diagnostic) === ['category', 'http_status', 'curl_code', 'elapsed_ms', 'attempts']
            && $lastSelectionLog()['catalog_diagnostic'] === $diagnostic,
            'catalog failure lost bounded diagnostics, retried or changed products');
        if ($kind === 'response_size') resumeExpect($diagnostic['category'] === 'response_size'
            && str_contains($observed['result']['message'], '16 MiB'), 'size error remained a generic HTTPS message');
    }
    foreach ([502, 2147483648] as $ordinarySource) {
        $seedSelectionSource($ordinarySource, ['A' => []]);
        $requests = [];
        $ordinary = $observeRun($makeSelectionService($selectionCatalog(['A' => 9]), ['A' => $selectionDetail('A', [], 9)],
            $requests, null, null, $expectLegacy), $selectedOptions($ordinarySource, ['name']));
        resumeExpect($ordinary['result']['status'] === 'ok' && $requests === ['catalog' => 1, 'detail' => 1, 'other' => 0],
            'ordinary or unsigned-ID source inherited compact routing/restrictions');
    }
    file_put_contents($categoryMapPath, "{\"schema\":2,\"nodes\":[],\"last_plan_hash\":false}\n");
    $requests = [];
    $corrupt = $observeRun($makeSelectionService($snapshot, [], $requests, null, null, $expectCompact), $compactOptions());
    resumeExpect($corrupt['result']['status'] === 'error' && $corrupt['writes'] === 0
        && $requests === ['catalog' => 0, 'detail' => 0, 'other' => 0]
        && $sourceRows($compactSource) === $before && $stateStore->read($compactSource) === $stateBefore,
        'basic routing accepted a corrupt mirror registry');
    file_put_contents($categoryMapPath, $mirrorBytes);
    resumeExpect(file_get_contents($categoryMapPath) === $mirrorBytes
        && (file_exists($jobsPath) ? file_get_contents($jobsPath) : null) === $jobsBefore
        && DB::table('category')->orderBy('id')->get()->toJson() === $categoriesBefore,
        'basic compact routing mutated category map, jobs or category rows');

    fwrite(STDOUT, "local supply SyncService resume PASS; compact stock-priority dry-run=15; compact dry-run=20; compact detail batch=20; compact failures=13; selection cases=6; price/specification runs=7; image runs=13; unknown metadata runs="
        . count($unknownCases) . '; config ownership runs=' . $followRuns
        . '; twenty-percent two-target runs=' . $twentyRuns
        . '; targeted runs=' . $targetRuns
        . '; independent scope runs=' . $independentScopeRuns
        . '; legacy type-two ten-percent runs=' . $legacyTypeTwoRuns
        . "; currency=official; quote/cost=official; submit=prevalidation-only; network=injected; database=sqlite-memory; uid="
        . posix_geteuid() . "\n");
}
