<?php
declare(strict_types=1);

namespace {
    $fixtureRoot = sys_get_temp_dir() . '/pika-planned-category-' . bin2hex(random_bytes(6));
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
        throw new RuntimeException('planned category fixture requires root, WEB_UID WEB_GID and ACG_FAKA_OFFICIAL_ROOT');
    }
    if (!mkdir($fixtureRoot, 0750, true) && !is_dir($fixtureRoot)) {
        throw new RuntimeException('unable to create planned category fixture');
    }
    chown($fixtureRoot, $webUid);
    chgrp($fixtureRoot, $webGid);
    chmod($fixtureRoot, 0750);
    define('BASE_PATH', $fixtureRoot . '/');
}

namespace App\Util {
    final class Date
    {
        public static function current(?string $format = null): string
        {
            return '2026-09-01 00:00:00';
        }
    }

    final class SharedCurrency
    {
        public static function factor(\App\Model\Shared $shared): string
        {
            return '1';
        }

        public static function item(array $item, string $factor): array
        {
            return $item;
        }
    }
}

namespace Pika\LocalExtensions\PikaSupplySync\Service {
    final class PlannedCategoryMapperFaultInjector
    {
        public static ?string $failNextReadPath = null;
    }

    /** @return resource|false */
    function fopen(string $filename, string $mode)
    {
        if (
            $mode === 'rb'
            && PlannedCategoryMapperFaultInjector::$failNextReadPath !== null
            && hash_equals(PlannedCategoryMapperFaultInjector::$failNextReadPath, $filename)
        ) {
            PlannedCategoryMapperFaultInjector::$failNextReadPath = null;
            return false;
        }
        return \fopen($filename, $mode);
    }
}

namespace {
    require $officialRoot . '/vendor/autoload.php';
    require dirname(__DIR__) . '/manager/site/local-extensions/src/PathGuard.php';
    require dirname(__DIR__) . '/manager/site/local-extensions/src/AtomicJson.php';
    require dirname(__DIR__) . '/extensions/PikaSupplySync/bootstrap.php';
    require dirname(__DIR__) . '/extensions/PikaCatalogHub/bootstrap.php';

    use App\Model\Category;
    use App\Model\Commodity;
    use App\Model\PriceTemplate;
    use App\Model\Shared;
    use Illuminate\Database\Capsule\Manager as DB;
    use Illuminate\Database\Schema\Blueprint;
    use Pika\LocalExtensions\PikaCatalogHub\Service\AdminService;
    use Pika\LocalExtensions\PikaCatalogHub\Service\ConfigRepository;
    use Pika\LocalExtensions\PikaCatalogHub\Service\JobStore;
    use Pika\LocalExtensions\PikaCatalogHub\Service\SourceAliasService;
    use Pika\LocalExtensions\PikaSupplySync\Service\CommodityImportFailure;
    use Pika\LocalExtensions\PikaSupplySync\Service\CategoryIcons;
    use Pika\LocalExtensions\PikaSupplySync\Service\CommodityImporter;
    use Pika\LocalExtensions\PikaSupplySync\Service\ImageCache;
    use Pika\LocalExtensions\PikaSupplySync\Service\Options;
    use Pika\LocalExtensions\PikaSupplySync\Service\PlannedCategoryMapper;
    use Pika\LocalExtensions\PikaSupplySync\Service\PlannedCategoryMapperFaultInjector;
    use Pika\LocalExtensions\PikaSupplySync\Service\PriceAdjuster;
    use Pika\LocalExtensions\PikaSupplySync\Service\RemoteItem;
    use Pika\LocalExtensions\PikaSupplySync\Service\RunBudget;
    use Pika\LocalExtensions\PikaSupplySync\Service\SafeHttpClient;
    use Pika\LocalExtensions\PikaSupplySync\Service\SharedGateway;
    use Pika\LocalExtensions\PikaSupplySync\Service\SourcePolicy;

    function mapperExpect(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function mapperFails(callable $callback, string $message): void
    {
        try {
            $callback();
        } catch (Throwable) {
            return;
        }
        throw new RuntimeException($message);
    }

    function mapperFailsWithMessage(callable $callback, string $expected, string $message): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            mapperExpect($exception->getMessage() === $expected, $message);
            return;
        }
        throw new RuntimeException($message);
    }

    function mapperFailsWithSafeCode(callable $callback, string $expected, string $message): void
    {
        try {
            $callback();
        } catch (CommodityImportFailure $exception) {
            mapperExpect($exception->safeCode === $expected, $message);
            mapperExpect($exception->getMessage() === $expected, $message . ' exposed an original message');
            return;
        }
        throw new RuntimeException($message);
    }

    function mapperNodeKey(string $kind, int $sourceId, string $parentKey, string $name): string
    {
        return hash('sha256', $kind . "\0" . $sourceId . "\0" . $parentKey . "\0" . $name);
    }

    /** @param array<string,mixed> $registry */
    function mapperWriteRegistry(string $path, array $registry): string
    {
        ksort($registry['nodes'], SORT_STRING);
        $bytes = json_encode(
            $registry,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
        mapperExpect(file_put_contents($path, $bytes) !== false, 'unable to write category registry fixture');
        mapperExpect(chmod($path, 0600), 'unable to protect category registry fixture');
        return $bytes;
    }

    /** @param array{total:int,processed:int,succeeded:int,failed:int,skipped:int} $progress */
    function mapperJobRecord(
        string $seed,
        int $sourceId,
        string $state,
        string $phase,
        array $progress,
        ?string $errorCode = null,
    ): array {
        return [
            'task_id' => substr(hash('sha256', $seed), 0, 48),
            'source_id' => $sourceId,
            'source_alias' => '货源A',
            'source_fingerprint' => hash('sha256', 'source-' . $sourceId),
            'state' => $state,
            'phase' => $phase,
            'revision' => 1,
            'created_at' => '2026-09-01T00:00:00+00:00',
            'updated_at' => '2026-09-01T00:00:00+00:00',
            'snapshot' => null,
            'categories' => [],
            'counts' => ['items' => 0, 'categories' => 0, 'high' => 0, 'low' => 0],
            'progress' => $progress,
            'item_failures' => [],
            'retry' => null,
            'last_detail_diagnostic' => null,
            'detail_compatibility_count' => 0,
            'detail_resume_authorization' => null,
            'premium_percent' => null,
            'mappings' => [],
            'error_code' => $errorCode,
            'last_action' => 'create',
        ];
    }

    function mapperRejectsIncompleteAnalysis(
        int $sourceId,
        string $displayAlias,
        string $mapPath,
        ConfigRepository $config,
        string $message,
    ): void {
        $configBefore = $config->get();
        $mapBefore = (string)file_get_contents($mapPath);
        $jobsBefore = (new JobStore())->list();
        mapperFailsWithMessage(
            static fn() => (new AdminService())->analyze($sourceId, $displayAlias),
            '该货源的分类映射不完整，已停止分析。',
            $message,
        );
        mapperExpect(
            $config->get() === $configBefore
                && (string)file_get_contents($mapPath) === $mapBefore
                && (new JobStore())->list() === $jobsBefore,
            $message . ' or changed config, mapping, or task state',
        );
    }

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
    $schema->create('category', static function (Blueprint $table): void {
        $table->increments('id');
        $table->string('name');
        $table->integer('sort');
        $table->string('create_time');
        $table->unsignedInteger('owner');
        $table->string('icon');
        $table->unsignedInteger('status');
        $table->unsignedInteger('hide');
        $table->unsignedInteger('pid')->nullable();
    });
    $schema->create('commodity', static function (Blueprint $table): void {
        $table->increments('id');
        $table->unsignedInteger('category_id');
        $table->unsignedInteger('owner');
        $table->unsignedInteger('shared_id');
        $table->string('shared_code');
        $table->string('code');
        $table->unsignedInteger('shared_sync');
        $table->unsignedInteger('shared_premium_type');
        $table->decimal('factory_price', 10, 2)->default(0);
        $table->decimal('price', 10, 2)->default(0);
        $table->decimal('user_price', 10, 2)->default(0);
        $table->integer('stock')->nullable();
        $table->text('shared_stock')->nullable();
        $table->unsignedInteger('inventory_sync')->default(0);
        $table->string('sentinel')->default('');
        foreach (['name', 'description', 'cover', 'create_time', 'widget', 'config'] as $column) {
            $table->text($column)->default('');
        }
        foreach (['status', 'api_status', 'delivery_way', 'contact_type', 'password_status',
            'sort', 'coupon', 'shared_premium_template', 'shared_amount_sync', 'shared_config_sync',
            'seckill_status', 'draft_status', 'inventory_hidden', 'only_user', 'purchase_count',
            'minimum', 'maximum', 'hide'] as $column) {
            $table->unsignedInteger($column)->default(0);
        }
        $table->decimal('shared_premium', 10, 3)->default(0);
        $table->decimal('draft_premium', 10, 2)->default(0);
        $table->string('seckill_start_time')->nullable();
        $table->string('seckill_end_time')->nullable();
    });
    $schema->create('order', static function (Blueprint $table): void {
        $table->increments('id');
        $table->unsignedInteger('commodity_id');
        $table->decimal('amount', 10, 2);
        $table->unsignedInteger('status');
        $table->string('sentinel');
    });
    $schema->create('planned_effect', static function (Blueprint $table): void {
        $table->increments('id');
        $table->unsignedInteger('category_id');
    });
    foreach ([1 => 'source-a', 2 => 'source-b'] as $id => $name) {
        DB::table('shared')->insert([
            'id' => $id,
            'type' => 0,
            'name' => $name,
            'domain' => 'https://source-' . $id . '.example',
            'app_id' => 'merchant-' . $id,
            'app_key' => 'secret-' . $id,
            'currency' => 'CNY',
            'currency_rate' => '1',
        ]);
    }
    DB::table('category')->insert([
        'id' => 1,
        'name' => 'AI工具',
        'sort' => 0,
        'create_time' => '2026-09-01 00:00:00',
        'owner' => 0,
        'icon' => '/favicon.ico',
        'status' => 1,
        'hide' => 0,
        'pid' => null,
    ]);

    $stateControl = '/var/lib/pika-local-extensions';
    $stateSites = $stateControl . '/sites';
    if (!is_dir($stateSites) && !mkdir($stateSites, 0755, true) && !is_dir($stateSites)) {
        throw new RuntimeException('unable to create planned category state root');
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

    if (!posix_setgid($webGid) || !posix_setuid($webUid)) {
        throw new RuntimeException('unable to drop planned category fixture identity');
    }

    $sourceA = Shared::query()->findOrFail(1);
    $sourceB = Shared::query()->findOrFail(2);
    $mapper = new PlannedCategoryMapper();
    $aliasConfig = new ConfigRepository();
    $aliasConfig->upsertAlias(1, '货源A');
    $aliasConfig->upsertAlias(2, '货源B');
    $planA = hash('sha256', 'plan-a');
    $target = ['group' => 'AI工具', 'family' => 'GPT'];

    // Remote details must be obtained before the mapper is entered. A failed
    // upstream request therefore cannot leave an empty category hierarchy or
    // a category-map entry behind.
    $policy = new SourcePolicy(static fn(string $host): array => ['93.184.216.34']);
    $detailRequests = 0;
    $failedDetailTransactionLevels = [];
    $transport = static function (
        array $endpoint,
        string $address,
        string $method,
        array $headers,
        string $body,
        int $maxBytes,
        int $connectTimeoutMs,
        int $requestTimeoutMs,
    ) use (&$detailRequests, &$failedDetailTransactionLevels): array {
        $detailRequests++;
        $failedDetailTransactionLevels[] = DB::connection()->transactionLevel();
        throw new RuntimeException('simulated remote detail failure');
    };
    $budget = new RunBudget();
    $http = new SafeHttpClient($policy, $transport, $budget);
    $importer = new CommodityImporter(
        new SharedGateway($http, $policy),
        new PriceAdjuster(),
        new RemoteItem(new ImageCache($http, $budget), $budget),
        $policy,
    );
    $importStage = new ReflectionMethod($importer, 'stage');
    $persistenceFailure = new CommodityImportFailure(
        CommodityImportFailure::PERSISTENCE_FAILED,
        new RuntimeException('private persistence detail'),
    );
    mapperFailsWithSafeCode(
        static fn() => $importStage->invoke(
            $importer,
            CommodityImportFailure::CATEGORY_TRANSACTION_FAILED,
            true,
            static fn() => throw $persistenceFailure,
        ),
        CommodityImportFailure::PERSISTENCE_FAILED,
        'persistence failure was overwritten by the outer category stage',
    );
    $ordinaryFailure = new RuntimeException('ordinary import detail');
    try {
        $importStage->invoke(
            $importer,
            CommodityImportFailure::DETAIL_FETCH_FAILED,
            false,
            static fn() => throw $ordinaryFailure,
        );
        throw new RuntimeException('ordinary import failure was accepted');
    } catch (Throwable $exception) {
        mapperExpect($exception === $ordinaryFailure, 'ordinary import failure behavior changed');
    }
    try {
        $importer->import(
            $sourceA,
            ['code' => 'ordinary-remote-failure', 'category' => 'AI Claude', 'stock' => 1],
            1,
            Options::fromArray(['mode' => 'full', 'source_ids' => '1', 'premium_percent' => 10]),
        );
        throw new RuntimeException('ordinary import remote failure was accepted');
    } catch (CommodityImportFailure) {
        throw new RuntimeException('ordinary import unexpectedly classified its original exception');
    } catch (RuntimeException $exception) {
        mapperExpect(
            $exception->getMessage() === '远端 HTTPS 请求失败',
            'ordinary import did not preserve its original exception behavior',
        );
    }
    $mapPath = $stateSite . '/runtime/extensions/PikaCatalogHub/category-map.json';
    mapperExpect(!$mapper->hasSourceMapping(1), 'empty category registry reported a source mapping');
    mapperFailsWithSafeCode(
        static fn() => $importer->importPlanned(
            $sourceA,
            ['code' => 'remote-failure', 'category' => 'AI Claude', 'stock' => 1],
            $mapper,
            '货源A',
            ['group' => 'AI工具', 'family' => 'Claude'],
            hash('sha256', 'remote-failure-plan'),
            Options::fromArray(['mode' => 'full', 'source_ids' => '1', 'premium_percent' => 10]),
        ),
        CommodityImportFailure::DETAIL_UNKNOWN_FAILED,
        'remote detail failure was accepted',
    );
    mapperExpect(Category::query()->count() === 1, 'remote detail failure left an empty category');
    mapperExpect(!file_exists($mapPath), 'remote detail failure left a category registry');

    $failingTransactionMapper = new PlannedCategoryMapper(
        static fn(callable $callback): never => throw new RuntimeException(
            'private mapper transaction detail https://upstream.invalid app_key=TOPSECRET',
        ),
    );
    mapperFailsWithSafeCode(
        static fn() => $importStage->invoke(
            $importer,
            CommodityImportFailure::CATEGORY_TRANSACTION_FAILED,
            true,
            static fn() => $failingTransactionMapper->withResolvedCategory(
                $sourceA,
                '货源A',
                ['group' => 'AI工具', 'family' => 'Claude'],
                'Claude 企业账号',
                hash('sha256', 'mapping-failure-plan'),
                static fn(Category $category): Category => $category,
            ),
        ),
        CommodityImportFailure::CATEGORY_TRANSACTION_FAILED,
        'planned category transaction failure was not safely classified',
    );

    $leafA = $mapper->resolve($sourceA, '货源A', $target, 'GPT / API 账号', $planA);
    $sourceNodeA = Category::query()->findOrFail((int)$leafA->pid);
    $family = Category::query()->findOrFail((int)$sourceNodeA->pid);
    $group = Category::query()->findOrFail((int)$family->pid);

    mapperExpect((int)$group->id !== 1, 'mapper adopted an administrator-created same-name root');
    mapperExpect($group->name === 'AI工具' && $group->pid === null, 'managed group is wrong');
    mapperExpect($family->name === 'GPT' && (int)$family->pid === (int)$group->id, 'managed family is wrong');
    mapperExpect($sourceNodeA->name === '货源A' && (int)$sourceNodeA->pid === (int)$family->id, 'source node is wrong');
    mapperExpect($leafA->name === 'GPT / API 账号' && (int)$leafA->pid === (int)$sourceNodeA->id, 'upstream category leaf is wrong');
    mapperExpect(
        $mapper->hasSourceMapping(1) && !$mapper->hasSourceMapping(2),
        'source mapping lookup did not bind the exact source id',
    );
    $mapBeforeDisplayRename = (string)file_get_contents($mapPath);
    $displayRename = (new SourceAliasService($aliasConfig))->rename(1, '货源A显示名');
    mapperExpect(
        array_column($displayRename['aliases'], 'alias', 'source_id')[1] === '货源A显示名'
            && $sourceNodeA->fresh()->name === '货源A显示名'
            && (int)$leafA->fresh()->pid === (int)$sourceNodeA->id
            && $leafA->fresh()->name === 'GPT / API 账号'
            && (string)file_get_contents($mapPath) !== $mapBeforeDisplayRename,
        'display-name edit did not rename its source node while preserving leaf identity',
    );
    mapperExpect(
        $mapper->classificationAlias(1, '货源A显示名') === '货源A显示名'
            && $mapper->classificationAlias(2, '货源B') === '货源B',
        'classification alias did not follow the saved display name',
    );
    (new SourceAliasService($aliasConfig))->rename(1, '货源A');

    $cleanMappingBytes = (string)file_get_contents($mapPath);
    $multiAliasRegistry = json_decode($cleanMappingBytes, true, 32, JSON_THROW_ON_ERROR);
    $sourceKey = null;
    $sourceParentKey = null;
    foreach ($multiAliasRegistry['nodes'] as $key => $entry) {
        if ((int)$entry['id'] === (int)$sourceNodeA->id) {
            $sourceKey = $key;
            $sourceParentKey = $entry['parent_key'];
            break;
        }
    }
    mapperExpect(
        is_string($sourceKey) && is_string($sourceParentKey),
        'unable to locate source key for alias-integrity fixture',
    );
    $historicalSourceKey = mapperNodeKey('source', 1, $sourceParentKey, '货源A历史名');
    $multiAliasRegistry['nodes'][$historicalSourceKey] = [
        'id' => 2000000001,
        'name' => '货源A历史名',
        'parent_key' => $sourceParentKey,
    ];
    $multiAliasBytes = mapperWriteRegistry($mapPath, $multiAliasRegistry);
    mapperFails(
        static fn() => (new SourceAliasService($aliasConfig))->rename(1, '货源A多映射显示名'),
        'rename accepted an invalid extra source mapping',
    );
    $multiAliasDisplay = $aliasConfig->upsertAlias(1, '货源A多映射显示名');
    $jobsBeforeMultiAliasAnalysis = (new JobStore())->list();
    mapperFailsWithMessage(
        static fn() => (new AdminService())->analyze(1, '货源A多映射显示名'),
        '该货源的分类映射包含不一致的历史名称，已停止分析。',
        'analysis accepted multiple frozen classification aliases for one source id',
    );
    mapperExpect(
        array_column($multiAliasDisplay['aliases'], 'alias', 'source_id')[1] === '货源A多映射显示名'
            && (string)file_get_contents($mapPath) === $multiAliasBytes
            && (new JobStore())->list() === $jobsBeforeMultiAliasAnalysis,
        'display rename touched a multi-alias mapping or failed analysis created a job',
    );
    mapperExpect(file_put_contents($mapPath, $cleanMappingBytes) !== false, 'unable to restore clean mapping fixture');
    mapperExpect(chmod($mapPath, 0600), 'unable to protect restored mapping fixture');

    $orphanRegistry = json_decode($cleanMappingBytes, true, 32, JSON_THROW_ON_ERROR);
    $missingSourceKey = hash('sha256', 'missing-source-node');
    $orphanSourceCategoryKey = mapperNodeKey('source-category', 1, $missingSourceKey, '孤立上游分类');
    $orphanRegistry['nodes'][$orphanSourceCategoryKey] = [
        'id' => 2000000002,
        'name' => '孤立上游分类',
        'parent_key' => $missingSourceKey,
    ];
    $orphanMappingBytes = mapperWriteRegistry($mapPath, $orphanRegistry);
    mapperFails(
        static fn() => (new SourceAliasService($aliasConfig))->rename(1, '货源A孤立映射显示名'),
        'rename accepted an orphan source-category mapping',
    );
    $orphanDisplay = $aliasConfig->upsertAlias(1, '货源A孤立映射显示名');
    $jobsBeforeOrphanAnalysis = (new JobStore())->list();
    mapperFailsWithMessage(
        static fn() => (new AdminService())->analyze(1, '货源A孤立映射显示名'),
        '该货源的分类映射不完整，已停止分析。',
        'analysis accepted a source-category mapping without its frozen source node',
    );
    mapperExpect(
        array_column($orphanDisplay['aliases'], 'alias', 'source_id')[1] === '货源A孤立映射显示名'
            && (string)file_get_contents($mapPath) === $orphanMappingBytes
            && (new JobStore())->list() === $jobsBeforeOrphanAnalysis,
        'display rename touched an incomplete mapping or failed analysis created a job',
    );
    mapperExpect(file_put_contents($mapPath, $cleanMappingBytes) !== false, 'unable to restore mapping after orphan fixture');
    mapperExpect(chmod($mapPath, 0600), 'unable to protect mapping restored after orphan fixture');
    mapperExpect(
        (new SourceAliasService($aliasConfig))->classificationAlias(1, '货源A孤立映射显示名') === '货源A',
        'restored mapping did not retain its original frozen classification alias',
    );

    $missingParentRegistry = json_decode($cleanMappingBytes, true, 32, JSON_THROW_ON_ERROR);
    $missingParentKey = hash('sha256', 'missing-source-parent');
    $missingParentSourceKey = mapperNodeKey('source', 1, $missingParentKey, '货源A');
    $missingParentRegistry['nodes'][$missingParentSourceKey] = [
        'id' => 2000000003,
        'name' => '货源A',
        'parent_key' => $missingParentKey,
    ];
    mapperWriteRegistry($mapPath, $missingParentRegistry);
    mapperRejectsIncompleteAnalysis(
        1,
        '货源A孤立映射显示名',
        $mapPath,
        $aliasConfig,
        'analysis accepted a frozen source node with a missing parent',
    );
    mapperExpect(file_put_contents($mapPath, $cleanMappingBytes) !== false, 'unable to restore mapping after missing-parent fixture');
    mapperExpect(chmod($mapPath, 0600), 'unable to protect mapping restored after missing-parent fixture');

    $zeroLeafRegistry = json_decode($cleanMappingBytes, true, 32, JSON_THROW_ON_ERROR);
    foreach ($zeroLeafRegistry['nodes'] as $key => $entry) {
        if ($entry['parent_key'] === $sourceKey) {
            unset($zeroLeafRegistry['nodes'][$key]);
        }
    }
    mapperWriteRegistry($mapPath, $zeroLeafRegistry);
    mapperRejectsIncompleteAnalysis(
        1,
        '货源A孤立映射显示名',
        $mapPath,
        $aliasConfig,
        'analysis accepted a frozen source node without a source-category leaf',
    );
    mapperExpect(file_put_contents($mapPath, $cleanMappingBytes) !== false, 'unable to restore mapping after zero-leaf fixture');
    mapperExpect(chmod($mapPath, 0600), 'unable to protect mapping restored after zero-leaf fixture');

    $unknownChildRegistry = json_decode($cleanMappingBytes, true, 32, JSON_THROW_ON_ERROR);
    $unknownChildRegistry['nodes'][hash('sha256', 'unknown-source-child')] = [
        'id' => 2000000004,
        'name' => '未知子节点',
        'parent_key' => $sourceKey,
    ];
    mapperWriteRegistry($mapPath, $unknownChildRegistry);
    mapperRejectsIncompleteAnalysis(
        1,
        '货源A孤立映射显示名',
        $mapPath,
        $aliasConfig,
        'analysis accepted an unknown child under a frozen source node',
    );
    mapperExpect(file_put_contents($mapPath, $cleanMappingBytes) !== false, 'unable to restore mapping after unknown-child fixture');
    mapperExpect(chmod($mapPath, 0600), 'unable to protect mapping restored after unknown-child fixture');

    DB::table('shared')->insert([
        'id' => 3,
        'type' => 0,
        'name' => 'source-c',
        'domain' => 'https://source-3.example',
        'app_id' => 'merchant-3',
        'app_key' => 'secret-3',
        'currency' => 'CNY',
        'currency_rate' => '1',
    ]);
    $missingMappingJobs = new JobStore();
    $missingMappingJobs->create(mapperJobRecord(
        'completed-import-without-mapping',
        3,
        JobStore::STATE_COMPLETED,
        'import',
        ['total' => 1, 'processed' => 1, 'succeeded' => 1, 'failed' => 0, 'skipped' => 0],
    ));
    $configBeforeMissingMappingAnalysis = $aliasConfig->get();
    mapperExpect(
        !array_key_exists(3, array_column($configBeforeMissingMappingAnalysis['aliases'], 'alias', 'source_id')),
        'missing-mapping fixture unexpectedly started with a display alias',
    );
    mapperRejectsIncompleteAnalysis(
        3,
        '未初始化显示名',
        $mapPath,
        $aliasConfig,
        'analysis accepted completed import history without a source mapping',
    );
    mapperExpect(
        $aliasConfig->get() === $configBeforeMissingMappingAnalysis
            && !array_key_exists(3, array_column($aliasConfig->get()['aliases'], 'alias', 'source_id')),
        'missing source mapping failure initialized a display alias before validation',
    );

    DB::table('shared')->insert([
        'id' => 4,
        'type' => 0,
        'name' => 'source-d',
        'domain' => 'https://source-4.example',
        'app_id' => 'merchant-4',
        'app_key' => 'secret-4',
        'currency' => 'CNY',
        'currency_rate' => '1',
    ]);
    DB::table('commodity')->insert([
        'category_id' => 1,
        'owner' => 0,
        'shared_id' => 4,
        'shared_code' => 'rotated-history-item',
        'code' => 'PKS1' . str_repeat('C', 20),
        'shared_sync' => 0,
        'shared_premium_type' => PriceTemplate::TYPE_PERCENT,
        'sentinel' => 'managed-history-without-job',
    ]);
    $configBeforeRotatedHistory = $aliasConfig->get();
    mapperExpect(
        array_values(array_filter(
            (new JobStore())->list(),
            static fn(array $job): bool => (int)$job['source_id'] === 4,
        )) === [],
        'rotated-history fixture unexpectedly retained a source job',
    );
    mapperRejectsIncompleteAnalysis(
        4,
        '历史已轮转显示名',
        $mapPath,
        $aliasConfig,
        'analysis accepted a managed commodity after its import history was rotated out',
    );
    mapperExpect(
        $aliasConfig->get() === $configBeforeRotatedHistory
            && !array_key_exists(4, array_column($aliasConfig->get()['aliases'], 'alias', 'source_id')),
        'managed commodity fallback initialized a display alias before validation',
    );
    mapperExpect(
        (int)$group->owner === 0
            && (int)$family->owner === 0
            && (int)$sourceNodeA->owner === 0
            && (int)$leafA->owner === 0,
        'managed category owner is wrong',
    );

    $repeatA = $mapper->resolve($sourceA, '货源A', $target, 'GPT / API 账号', $planA);
    mapperExpect((int)$repeatA->id === (int)$leafA->id, 'same source plan did not reuse its managed leaf');
    mapperExpect(Category::query()->count() === 5, 'idempotent resolve created duplicate categories');

    $leafB = $mapper->resolve($sourceB, '货源B', $target, 'GPT / API 账号', hash('sha256', 'plan-b'));
    $sourceNodeB = Category::query()->findOrFail((int)$leafB->pid);
    mapperExpect((int)$sourceNodeB->pid === (int)$family->id, 'second source did not share the confirmed group/family');
    mapperExpect($sourceNodeB->name === '货源B', 'second source node name is wrong');
    mapperExpect((int)$leafB->id !== (int)$leafA->id, 'two sources shared a leaf category');
    mapperExpect(Category::query()->count() === 7, 'second source created unexpected hierarchy nodes');

    $telegramLeaf = $mapper->resolve(
        $sourceA,
        '货源A',
        ['group' => 'Telegram', 'family' => ''],
        'Telegram | API/真机账号',
        hash('sha256', 'telegram-plan'),
    );
    $telegramSource = Category::query()->findOrFail((int)$telegramLeaf->pid);
    $telegramGroup = Category::query()->findOrFail((int)$telegramSource->pid);
    mapperExpect(
        $telegramGroup->name === 'Telegram'
            && $telegramGroup->pid === null
            && $telegramSource->name === '货源A'
            && $telegramLeaf->name === 'Telegram | API/真机账号',
        'group/source/upstream-category hierarchy is wrong when family is empty',
    );

    $invalidCategoryCount = Category::query()->count();
    foreach (['', "bad\ncategory", "\xC3\x28", "\u{00A0}bad", str_repeat('分', 129)] as $invalidCategory) {
        mapperFails(
            static fn() => $mapper->resolve(
                $sourceA,
                '货源A',
                ['group' => 'Telegram', 'family' => ''],
                $invalidCategory,
                hash('sha256', 'invalid-category'),
            ),
            'unsafe upstream category was accepted',
        );
    }
    mapperExpect(
        Category::query()->count() === $invalidCategoryCount,
        'unsafe upstream category changed the category table',
    );

    mapperExpect(is_file($mapPath) && !is_link($mapPath), 'category registry is missing or unsafe');
    $mapMetadata = lstat($mapPath);
    mapperExpect(is_array($mapMetadata) && ($mapMetadata['mode'] & 0777) === 0600 && $mapMetadata['nlink'] === 1, 'category registry mode is unsafe');
    $mapRaw = (string)file_get_contents($mapPath);
    mapperExpect(!str_contains($mapRaw, 'secret-') && !str_contains($mapRaw, 'merchant-'), 'category registry leaked source credentials');

    $persist = (new ReflectionClass(CommodityImporter::class))->getMethod('persist');
    $managedId = DB::table('commodity')->insertGetId([
        'category_id' => 1,
        'owner' => 0,
        'shared_id' => 1,
        'shared_code' => 'managed-remote',
        'code' => 'PKS1' . str_repeat('A', 20),
        'shared_sync' => 0,
        'shared_premium_type' => PriceTemplate::TYPE_PERCENT,
        'factory_price' => '12.34',
        'price' => '23.45',
        'user_price' => '21.00',
        'stock' => 37,
        'shared_stock' => '{"sku-a":37}',
        'inventory_sync' => 1,
        'sentinel' => 'must-stay-identical',
    ]);
    $managedBefore = (array)DB::table('commodity')->where('id', $managedId)->first();
    $managedOutcome = $persist->invoke(
        $importer,
        $sourceA,
        (int)$telegramLeaf->id,
        [
            'item' => ['code' => 'managed-remote'],
            'factor' => 1.1,
            'prices' => ['config' => [], 'price' => '0', 'user_price' => '0'],
        ],
        true,
    );
    $managedAfter = (array)DB::table('commodity')->where('id', $managedId)->first();
    mapperExpect(
        $managedOutcome === CommodityImporter::OUTCOME_REATTACHED
            && (int)$managedAfter['category_id'] === (int)$telegramLeaf->id,
        'existing exact Pika commodity was not reattached to the confirmed upstream category',
    );
    $managedBefore['category_id'] = $managedAfter['category_id'];
    mapperExpect(
        $managedBefore === $managedAfter,
        'reattaching an existing Pika commodity changed fields other than category_id',
    );

    $orderId = DB::table('order')->insertGetId([
        'commodity_id' => $managedId,
        'amount' => '23.45',
        'status' => 1,
        'sentinel' => 'order-must-stay-identical',
    ]);
    $terminalJobs = new JobStore();
    $terminalJobs->create(mapperJobRecord(
        'completed-import-history',
        1,
        JobStore::STATE_COMPLETED,
        'import',
        ['total' => 1, 'processed' => 1, 'succeeded' => 1, 'failed' => 0, 'skipped' => 0],
    ));
    $terminalJobs->create(mapperJobRecord(
        'failed-history',
        1,
        JobStore::STATE_FAILED,
        'analysis',
        ['total' => 0, 'processed' => 0, 'succeeded' => 0, 'failed' => 0, 'skipped' => 0],
        'TEST_FAILURE',
    ));
    $terminalJobs->create(mapperJobRecord(
        'cancelled-history',
        1,
        JobStore::STATE_CANCELLED,
        'analysis',
        ['total' => 0, 'processed' => 0, 'succeeded' => 0, 'failed' => 0, 'skipped' => 0],
    ));

    foreach ([CommodityImportFailure::DETAIL_FETCH_FAILED, CommodityImportFailure::DETAIL_TRANSPORT_FAILED,
        CommodityImportFailure::DETAIL_HTTP_RETRYABLE] as $resumableCode) {
        $resumableRecord = mapperJobRecord('rename-protection-' . $resumableCode, 1, JobStore::STATE_FAILED, 'import',
            ['total' => 2, 'processed' => 1, 'succeeded' => 1, 'failed' => 0, 'skipped' => 0], $resumableCode);
        $resumableRecord['premium_percent'] = '10';
        $resumableRecord['snapshot'] = ['sha256' => str_repeat('a', 64), 'plan_hash' => str_repeat('b', 64),
            'source_fingerprint' => $resumableRecord['source_fingerprint'], 'item_count' => 2];
        $terminalJobs->create($resumableRecord);
        $beforeResumableRename = $aliasConfig->get();
        $beforeResumableMap = file_get_contents($mapPath);
        $beforeResumableJobs = $terminalJobs->list();
        mapperFailsWithMessage(static fn() => (new SourceAliasService($aliasConfig))->rename(1, '不可修改'),
            '该货源还有可继续的导入任务，请先继续或取消任务，再修改名称。',
            'resumable detail checkpoint did not protect the confirmed source alias');
        mapperExpect($aliasConfig->get() === $beforeResumableRename && file_get_contents($mapPath) === $beforeResumableMap
            && $terminalJobs->list() === $beforeResumableJobs, 'blocked rename changed confirmed state');
        $terminalJobs->update($resumableRecord['task_id'], 1, static fn(array $job): array => array_replace($job, [
            'state' => JobStore::STATE_CANCELLED, 'last_action' => 'cancel', 'revision' => 2,
        ]));
    }

    $configBeforeImportedDisplayRename = $aliasConfig->get();
    $expectedConfigAfterImportedDisplayRename = $configBeforeImportedDisplayRename;
    foreach ($expectedConfigAfterImportedDisplayRename['aliases'] as &$entry) {
        if ($entry['source_id'] === 1) {
            $entry['alias'] = '货源A已入库显示名';
        }
    }
    unset($entry);
    $sharedBeforeImportedDisplayRename = (array)DB::table('shared')->where('id', 1)->first();
    $categoriesBeforeImportedDisplayRename = array_map(
        static fn(object $row): array => (array)$row,
        DB::table('category')->orderBy('id')->get()->all(),
    );
    $expectedCategoriesAfterRename = array_map(static function (array $row) use ($mapper): array {
        if ($mapper->managedSourceCategory((int)$row['id'], [1]) !== null) {
            $row['name'] = '货源A已入库显示名';
        }
        return $row;
    }, $categoriesBeforeImportedDisplayRename);
    $commodityBeforeImportedDisplayRename = (array)DB::table('commodity')->where('id', $managedId)->first();
    $orderBeforeImportedDisplayRename = (array)DB::table('order')->where('id', $orderId)->first();
    $jobsBeforeImportedDisplayRename = $terminalJobs->list();
    $mapBeforeImportedDisplayRename = (string)file_get_contents($mapPath);

    $importedDisplayRename = (new SourceAliasService($aliasConfig))->rename(1, '货源A已入库显示名');
    mapperExpect(
        $importedDisplayRename === $expectedConfigAfterImportedDisplayRename
            && $importedDisplayRename !== $configBeforeImportedDisplayRename,
        'completed import history, mappings, or commodities blocked a display-only rename',
    );
    mapperExpect(
        (array)DB::table('shared')->where('id', 1)->first() === $sharedBeforeImportedDisplayRename
            && array_map(
                static fn(object $row): array => (array)$row,
                DB::table('category')->orderBy('id')->get()->all(),
            ) === $expectedCategoriesAfterRename
            && (array)DB::table('commodity')->where('id', $managedId)->first() === $commodityBeforeImportedDisplayRename
            && (array)DB::table('order')->where('id', $orderId)->first() === $orderBeforeImportedDisplayRename
            && $terminalJobs->list() === $jobsBeforeImportedDisplayRename
            && (string)file_get_contents($mapPath) !== $mapBeforeImportedDisplayRename,
        'linked rename changed fields beyond the source Category names and mapping keys',
    );
    mapperExpect(
        (new SourceAliasService($aliasConfig))->classificationAlias(1, '货源A已入库显示名') === '货源A已入库显示名',
        'linked rename did not update the classification alias after import',
    );
    (new SourceAliasService($aliasConfig))->rename(1, '货源A');

    $ordinaryManagedId = DB::table('commodity')->insertGetId([
        'category_id' => 1,
        'owner' => 0,
        'shared_id' => 1,
        'shared_code' => 'ordinary-managed-remote',
        'code' => 'PKS1' . str_repeat('B', 20),
        'shared_sync' => 0,
        'shared_premium_type' => PriceTemplate::TYPE_PERCENT,
        'sentinel' => 'ordinary-import-must-not-move',
    ]);
    $ordinaryManagedBefore = (array)DB::table('commodity')->where('id', $ordinaryManagedId)->first();
    $ordinaryManagedOutcome = $persist->invoke(
        $importer,
        $sourceA,
        (int)$telegramLeaf->id,
        [
            'item' => ['code' => 'ordinary-managed-remote'],
            'factor' => 1.1,
            'prices' => ['config' => [], 'price' => '0', 'user_price' => '0'],
        ],
    );
    $ordinaryManagedAfter = (array)DB::table('commodity')->where('id', $ordinaryManagedId)->first();
    mapperExpect(
        $ordinaryManagedOutcome === CommodityImporter::OUTCOME_ALREADY_MANAGED
            && $ordinaryManagedBefore === $ordinaryManagedAfter,
        'ordinary SupplySync import reattached an existing Pika commodity',
    );

    $unmanagedId = DB::table('commodity')->insertGetId([
        'category_id' => 1,
        'owner' => 0,
        'shared_id' => 1,
        'shared_code' => 'unmanaged-remote',
        'code' => 'MANUAL-CODE',
        'shared_sync' => 0,
        'shared_premium_type' => PriceTemplate::TYPE_PERCENT,
        'sentinel' => 'manual-must-not-move',
    ]);
    $unmanagedBefore = (array)DB::table('commodity')->where('id', $unmanagedId)->first();
    $unmanagedOutcome = $persist->invoke(
        $importer,
        $sourceA,
        (int)$telegramLeaf->id,
        [
            'item' => ['code' => 'unmanaged-remote'],
            'factor' => 1.1,
            'prices' => ['config' => [], 'price' => '0', 'user_price' => '0'],
        ],
    );
    $unmanagedAfter = (array)DB::table('commodity')->where('id', $unmanagedId)->first();
    mapperExpect(
        $unmanagedOutcome === CommodityImporter::OUTCOME_HELD_EXISTING_UNMANAGED
            && $unmanagedBefore === $unmanagedAfter,
        'existing unmanaged commodity was changed during category reconciliation',
    );

    $capacityCheck = (new ReflectionClass(PlannedCategoryMapper::class))->getMethod('assertProjectedCapacity');
    $nearCapacityNodes = [];
    for ($index = 0; $index < 2047; $index++) {
        $nearCapacityNodes[hash('sha256', 'capacity-' . $index)] = true;
    }
    $capacityCheck->invoke($mapper, ['nodes' => $nearCapacityNodes], [hash('sha256', 'one-new-node')]);
    $beforeCapacityFailure = Category::query()->count();
    mapperFails(
        static fn() => $capacityCheck->invoke($mapper, ['nodes' => $nearCapacityNodes], [
            hash('sha256', 'one-new-node'),
            hash('sha256', 'two-new-nodes'),
        ]),
        'projected category node count above 2048 was accepted',
    );
    mapperExpect(
        Category::query()->count() === $beforeCapacityFailure,
        'projected category capacity failure changed the category table',
    );

    $mapLockPath = $mapPath . '.lock';
    clearstatcache(true, $mapLockPath);
    $mapLockMetadata = lstat($mapLockPath);
    mapperExpect(
        is_array($mapLockMetadata)
            && ($mapLockMetadata['mode'] & 0170000) === 0100000
            && ($mapLockMetadata['mode'] & 0777) === 0600
            && $mapLockMetadata['nlink'] === 1,
        'category registry lock was not securely created',
    );
    $mapBeforeLockTests = (string)file_get_contents($mapPath);

    $mapLockHardlink = $mapLockPath . '.hardlink-test';
    mapperExpect(link($mapLockPath, $mapLockHardlink), 'unable to create category lock hardlink fixture');
    $mapLockModeBeforeHardlinkRejection = fileperms($mapLockPath) & 0777;
    mapperFails(
        static fn() => $mapper->resolve($sourceA, '货源A', $target, 'GPT / API 账号', $planA),
        'hard-linked category registry lock was accepted',
    );
    mapperExpect(
        (fileperms($mapLockPath) & 0777) === $mapLockModeBeforeHardlinkRejection,
        'category lock hardlink rejection changed the target mode',
    );
    mapperExpect((string)file_get_contents($mapPath) === $mapBeforeLockTests, 'category lock hardlink rejection changed registry');
    unlink($mapLockHardlink);
    clearstatcache(true, $mapLockPath);

    chmod($mapLockPath, 0644);
    mapperFails(
        static fn() => $mapper->resolve($sourceA, '货源A', $target, 'GPT / API 账号', $planA),
        'mode 0644 category registry lock was accepted',
    );
    clearstatcache(true, $mapLockPath);
    mapperExpect(
        (fileperms($mapLockPath) & 0777) === 0644,
        'mode 0644 category lock rejection changed the target mode',
    );
    mapperExpect((string)file_get_contents($mapPath) === $mapBeforeLockTests, 'unsafe category lock mode changed registry');
    chmod($mapLockPath, 0600);

    $mapLockBackup = $mapLockPath . '.safe-backup';
    mapperExpect(rename($mapLockPath, $mapLockBackup), 'unable to isolate category lock for symlink test');
    $mapLockBackupHash = hash_file('sha256', $mapLockBackup);
    mapperExpect(symlink($mapLockBackup, $mapLockPath), 'unable to create category lock symlink fixture');
    mapperFails(
        static fn() => $mapper->resolve($sourceA, '货源A', $target, 'GPT / API 账号', $planA),
        'symbolic-link category registry lock was accepted',
    );
    mapperExpect(
        hash_equals($mapLockBackupHash, hash_file('sha256', $mapLockBackup))
            && (fileperms($mapLockBackup) & 0777) === 0600,
        'category lock symlink rejection changed its target',
    );
    unlink($mapLockPath);
    mapperExpect(rename($mapLockBackup, $mapLockPath), 'unable to restore category lock after symlink test');

    $mapLockBackup = $mapLockPath . '.safe-backup';
    mapperExpect(rename($mapLockPath, $mapLockBackup), 'unable to isolate category lock for type test');
    mapperExpect(mkdir($mapLockPath, 0700), 'unable to create non-regular category lock fixture');
    mapperFails(
        static fn() => $mapper->resolve($sourceA, '货源A', $target, 'GPT / API 账号', $planA),
        'non-regular category registry lock was accepted',
    );
    clearstatcache(true, $mapLockPath);
    mapperExpect(
        is_dir($mapLockPath) && (fileperms($mapLockPath) & 0777) === 0700,
        'non-regular category lock rejection changed the path mode or type',
    );
    rmdir($mapLockPath);
    mapperExpect(rename($mapLockBackup, $mapLockPath), 'unable to restore category lock after type test');

    $mapLockHandle = fopen($mapLockPath, 'r+b');
    mapperExpect(is_resource($mapLockHandle), 'unable to open category lock for post-flock identity test');
    $mapLockMoved = $mapLockPath . '.post-flock-original';
    $assertHandle = (new ReflectionClass(PlannedCategoryMapper::class))->getMethod('assertHandle');
    try {
        mapperExpect(flock($mapLockHandle, LOCK_EX), 'unable to lock category fixture for post-flock identity test');
        mapperExpect(rename($mapLockPath, $mapLockMoved), 'unable to replace category lock after flock');
        mapperExpect(file_put_contents($mapLockPath, 'replacement') !== false, 'unable to create replacement category lock');
        chmod($mapLockPath, 0600);
        mapperFails(
            static fn() => $assertHandle->invoke($mapper, $mapLockHandle, $mapLockPath, '映射锁'),
            'post-flock category lock path replacement was accepted',
        );
    } finally {
        flock($mapLockHandle, LOCK_UN);
        fclose($mapLockHandle);
        if (is_file($mapLockPath) || is_link($mapLockPath)) {
            unlink($mapLockPath);
        }
        if (file_exists($mapLockMoved)) {
            mapperExpect(rename($mapLockMoved, $mapLockPath), 'unable to restore category lock after post-flock test');
        }
    }
    mapperExpect((string)file_get_contents($mapPath) === $mapBeforeLockTests, 'post-flock category lock rejection changed registry');

    // A commodity failure inside the coordinated transaction rolls back all
    // newly created hierarchy rows and leaves the exact prior registry intact.
    $beforeRollbackCount = Category::query()->count();
    $beforeRollbackMap = (string)file_get_contents($mapPath);
    mapperFails(
        static fn() => $mapper->withResolvedCategory(
            $sourceA,
            '货源A',
            ['group' => 'AI工具', 'family' => 'Claude'],
            'Claude 企业账号',
            hash('sha256', 'rollback-plan'),
            static function (Category $category): never {
                throw new RuntimeException('simulated commodity write failure');
            },
        ),
        'commodity failure inside planned transaction was accepted',
    );
    mapperExpect(
        Category::query()->count() === $beforeRollbackCount,
        'commodity failure left an empty planned category',
    );
    mapperExpect(
        (string)file_get_contents($mapPath) === $beforeRollbackMap,
        'commodity failure changed the category registry',
    );

    // Simulate the PDO failure mode where the server applied COMMIT but the
    // client lost the acknowledgement. The mapper must propagate the error
    // without restoring the old registry, so retry reuses the committed tree.
    $commitUnknownMapper = new PlannedCategoryMapper(static function (callable $callback): never {
        DB::connection()->beginTransaction();
        try {
            $callback();
        } catch (Throwable $failure) {
            DB::connection()->rollBack();
            throw $failure;
        }
        DB::connection()->commit();
        throw new RuntimeException('simulated lost commit acknowledgement');
    });
    $commitUnknownTarget = ['group' => 'AI工具', 'family' => 'Gemini'];
    $commitUnknownPlan = hash('sha256', 'commit-unknown-plan');
    $beforeUnknownCount = Category::query()->count();
    mapperFails(
        static fn() => $commitUnknownMapper->withResolvedCategory(
            $sourceA,
            '货源A',
            $commitUnknownTarget,
            'Gemini Advanced',
            $commitUnknownPlan,
            static function (Category $category): bool {
                DB::table('planned_effect')->insert(['category_id' => (int)$category->id]);
                return true;
            },
        ),
        'commit-after-apply exception was reported as success',
    );
    mapperExpect(DB::table('planned_effect')->count() === 1, 'commit-after-apply fixture did not commit');
    $afterUnknownCount = Category::query()->count();
    mapperExpect($afterUnknownCount > $beforeUnknownCount, 'commit-after-apply hierarchy was not committed');
    $commitUnknownMap = (string)file_get_contents($mapPath);
    mapperExpect(
        $commitUnknownMap !== $beforeRollbackMap && str_contains($commitUnknownMap, 'Gemini'),
        'commit-after-apply incorrectly restored the prior registry',
    );
    $retryLeaf = $mapper->resolve($sourceA, '货源A', $commitUnknownTarget, 'Gemini Advanced', $commitUnknownPlan);
    mapperExpect((int)$retryLeaf->id > 0, 'commit-after-apply retry did not resolve its leaf');
    mapperExpect(
        Category::query()->count() === $afterUnknownCount,
        'commit-after-apply retry created a duplicate hierarchy',
    );

    $group->name = 'tampered';
    $group->save();
    mapperFails(
        static fn() => $mapper->resolve($sourceA, '货源A', $target, 'GPT / API 账号', $planA),
        'managed category drift was accepted',
    );
    mapperFails(
        static fn() => $mapper->resolve($sourceA, 'bad/alias', $target, 'GPT / API 账号', $planA),
        'unsafe source alias was accepted',
    );

    $readRegistry = (new ReflectionClass(PlannedCategoryMapper::class))->getMethod('read');
    $schemaOneSlash = [
        'schema' => 1,
        'nodes' => [
            hash('sha256', 'legacy-slash') => [
                'id' => 1,
                'name' => 'Legacy/A',
                'parent_key' => null,
            ],
        ],
        'last_plan_hash' => '',
    ];
    mapperExpect(
        file_put_contents(
            $mapPath,
            json_encode(
                $schemaOneSlash,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . "\n",
        ) !== false,
        'unable to create schema-1 slash fixture',
    );
    chmod($mapPath, 0600);
    mapperFails(
        static fn() => $readRegistry->invoke($mapper, $mapPath),
        'schema-1 registry accepted a slash-delimited node name',
    );

    $schemaOneFormat = $schemaOneSlash;
    $schemaOneFormat['nodes'][hash('sha256', 'legacy-slash')]['name'] = "Legacy\u{202E}Node";
    mapperExpect(
        file_put_contents(
            $mapPath,
            json_encode(
                $schemaOneFormat,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . "\n",
        ) !== false,
        'unable to create schema-1 format-control fixture',
    );
    chmod($mapPath, 0600);
    mapperFails(
        static fn() => $readRegistry->invoke($mapper, $mapPath),
        'schema-1 registry accepted a bidi format character',
    );

    $schemaOne = [
        'schema' => 1,
        'nodes' => [
            hash('sha256', 'legacy-node') => [
                'id' => 1,
                'name' => 'LegacyNode',
                'parent_key' => null,
            ],
        ],
        'last_plan_hash' => '',
    ];
    $schemaOneRaw = '{"nodes":{"' . hash('sha256', 'legacy-node')
        . '":{"name":"LegacyNode","parent_key":null,"id":1}},'
        . '"last_plan_hash":"","schema":1}' . "\n";
    mapperExpect(file_put_contents($mapPath, $schemaOneRaw) !== false, 'unable to create schema-1 upgrade fixture');
    chmod($mapPath, 0600);
    $legacyUpgradeLeaf = $mapper->resolve(
        $sourceA,
        '货源A',
        ['group' => 'Legacy', 'family' => ''],
        'Legacy/Category',
        hash('sha256', 'legacy-upgrade-plan'),
    );
    $upgradedRegistry = json_decode((string)file_get_contents($mapPath), true, 32, JSON_THROW_ON_ERROR);
    mapperExpect(
        (int)$legacyUpgradeLeaf->id > 0 && ($upgradedRegistry['schema'] ?? null) === 2,
        'a successful schema-1 registry update was not published as schema 2',
    );

    mapperExpect(file_put_contents($mapPath, $schemaOneRaw) !== false, 'unable to reset schema-1 rollback fixture');
    chmod($mapPath, 0600);
    $beforeLegacyRollbackCount = Category::query()->count();
    mapperFails(
        static fn() => $mapper->withResolvedCategory(
            $sourceA,
            '货源A',
            ['group' => 'LegacyRollback', 'family' => ''],
            'Legacy rollback category',
            hash('sha256', 'legacy-rollback-plan'),
            static function (Category $category) use ($mapPath): bool {
                PlannedCategoryMapperFaultInjector::$failNextReadPath = $mapPath;
                return (int)$category->id > 0;
            },
        ),
        'post-publish verification fault was reported as success',
    );
    mapperExpect(
        Category::query()->count() === $beforeLegacyRollbackCount,
        'schema-1 rollback fault left created category rows',
    );
    mapperExpect(
        (string)file_get_contents($mapPath) === $schemaOneRaw,
        'schema-1 registry was not restored exactly after a publish failure',
    );

    $nearByteLimitNodes = [];
    for ($index = 1; ; $index++) {
        $nearByteLimitNodes[hash('sha256', 'byte-capacity-' . $index)] = [
            'id' => $index,
            'name' => str_repeat('界', 120) . sprintf('%07d', $index),
            'parent_key' => null,
        ];
        $nearByteLimitRegistry = [
            'schema' => 2,
            'nodes' => $nearByteLimitNodes,
            'last_plan_hash' => '',
        ];
        $nearByteLimitRaw = json_encode(
            $nearByteLimitRegistry,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
        if (strlen($nearByteLimitRaw) >= 930000) {
            break;
        }
    }
    mapperExpect(
        count($nearByteLimitNodes) < 1846 && strlen($nearByteLimitRaw) <= 1048576,
        'unable to build a valid near-byte-limit registry fixture',
    );
    mapperExpect(file_put_contents($mapPath, $nearByteLimitRaw) !== false, 'unable to publish byte-capacity fixture');
    chmod($mapPath, 0600);
    $byteCapacityPlan = [];
    for ($index = 1; $index <= 200; $index++) {
        $byteCapacityPlan[] = [
            'category' => str_repeat('类', 120) . sprintf('%08d', $index),
            'target' => ['group' => 'ByteCapacity', 'family' => ''],
        ];
    }
    $beforeByteCapacityCount = Category::query()->count();
    mapperFails(
        static fn() => $mapper->assertPlanCapacity($sourceA, '货源A', $byteCapacityPlan),
        'complete plan byte-capacity overflow was accepted',
    );
    mapperExpect(
        Category::query()->count() === $beforeByteCapacityCount,
        'byte-capacity preflight changed the category table',
    );
    mapperExpect(
        (string)file_get_contents($mapPath) === $nearByteLimitRaw,
        'byte-capacity preflight changed the category registry',
    );

    $restoreRegistry = (new ReflectionClass(PlannedCategoryMapper::class))->getMethod('restoreRegistry');
    $restoreRegistry->invoke(
        $mapper,
        $mapPath,
        ['schema' => 1, 'nodes' => [], 'last_plan_hash' => ''],
        false,
        null,
    );
    mapperExpect(!file_exists($mapPath), 'absent category registry was restored as an empty file');

    // Exercise the linked-rename recovery boundary on a fresh mapping. Existing
    // business rows remain present so equality checks include unrelated state.
    $aliasConfig->upsertAlias(1, 'rename-old');
    $renameLeafOne = $mapper->resolve($sourceA, 'rename-old', ['group' => 'Rename one', 'family' => ''], 'original/one', $planA);
    $renameLeafTwo = $mapper->resolve($sourceA, 'rename-old', ['group' => 'Rename two', 'family' => ''], 'original/two', $planA);
    $renameSourceIds = [(int)$renameLeafOne->pid, (int)$renameLeafTwo->pid];
    $renameOtherLeaf = $mapper->resolve($sourceB, '货源B', ['group' => 'Rename other', 'family' => ''], 'other/original', $planA);
    $businessBeforeRename = [];
    foreach (['commodity', 'order', 'shared'] as $table) {
        $businessBeforeRename[$table] = DB::table($table)->orderBy('id')->get()->toJson();
    }
    $renameJobsBefore = $terminalJobs->list();
    $readRenameAlias = static fn(): ?string => array_column($aliasConfig->get()['aliases'], 'alias', 'source_id')[1] ?? null;
    $writeRenameAlias = static function (string $alias) use ($aliasConfig): void { $aliasConfig->upsertAlias(1, $alias); };
    $renameReceiptPath = dirname($mapPath) . '/source-rename-receipt.json';
    $aliasService = new SourceAliasService($aliasConfig);
    $aliasService->rename(1, 'rename-new');
    mapperExpect(Category::query()->whereIn('id', $renameSourceIds)->pluck('name')->all() === ['rename-new', 'rename-new'], 'all source nodes were not renamed');
    mapperExpect((int)$renameLeafOne->fresh()->pid === $renameSourceIds[0] && $renameLeafOne->fresh()->name === 'original/one', 'rename moved or renamed an original child');
    mapperExpect($mapper->resolve($sourceA, 'rename-new', ['group' => 'Rename one', 'family' => ''], 'original/one', $planA)->id === $renameLeafOne->id, 'renamed mapping created a second category path');
    mapperExpect($aliasService->managedCategory((int)$renameLeafOne->id) === null, 'original child was exposed as a linked-rename source node');
    mapperExpect($aliasService->managedCategory($renameSourceIds[0])['source_node_count'] === 2, 'native category lookup lost source identity');
    // A saved legacy display name with frozen mappings is repairable by saving
    // that same value; isChange must not discard this operation as a no-op.
    $aliasConfig->upsertAlias(1, 'same-value-repair');
    mapperExpect($aliasService->isChange(1, 'same-value-repair'), 'same-value save ignored stale mapping names');
    $aliasService->rename(1, 'same-value-repair');
    mapperExpect(!$aliasService->isChange(1, 'same-value-repair'), 'a reconciled same-value save still reports a change');
    $sameKeyMap = (string)file_get_contents($mapPath);
    Category::query()->whereIn('id', $renameSourceIds)->update(['name' => 'same-key-db-drift']);
    mapperExpect($aliasService->isChange(1, 'same-value-repair'), 'same-key DB name drift was ignored');
    $aliasService->rename(1, 'same-value-repair');
    mapperExpect((string)file_get_contents($mapPath) === $sameKeyMap
        && Category::query()->whereIn('id', $renameSourceIds)->pluck('name')->all() === ['same-value-repair', 'same-value-repair'],
        'same-key repair rewrote mapping keys or did not repair DB names');
    Category::query()->whereIn('id', $renameSourceIds)->update(['name' => 'same-key-recovery-drift']);
    mapperFails(static fn() => $mapper->renameSource(1, 'same-value-repair', $readRenameAlias,
        static function (string $alias): never { throw new RuntimeException('injected same-key publication failure'); }), 'same-key failure reported success');
    $aliasService->rename(1, 'same-value-repair');
    mapperExpect((string)file_get_contents($mapPath) === $sameKeyMap && !$aliasService->isChange(1, 'same-value-repair'), 'same-key recovery failed');

    $rollbackMapBefore = (string)file_get_contents($mapPath);
    $rollbackConfigBefore = $aliasConfig->get();
    $rollbackRename = new PlannedCategoryMapper(static function (callable $callback): never {
        DB::transaction(static function () use ($callback): never { $callback(); throw new RuntimeException('injected before commit'); });
        throw new RuntimeException('unreachable');
    });
    mapperFails(static fn() => $rollbackRename->renameSource(1, 'must-rollback', $readRenameAlias, $writeRenameAlias), 'pre-commit failure reported success');
    mapperExpect((string)file_get_contents($mapPath) === $rollbackMapBefore && $aliasConfig->get() === $rollbackConfigBefore, 'pre-commit failure changed JSON projections');
    mapperExpect(Category::query()->whereIn('id', $renameSourceIds)->pluck('name')->all() === ['same-value-repair', 'same-value-repair'], 'pre-commit failure left renamed Category rows');
    $mapper->assertNoPendingRename();

    $unknownRename = new PlannedCategoryMapper(static function (callable $callback): never {
        DB::transaction($callback);
        throw new RuntimeException('injected commit acknowledgement loss');
    });
    mapperFails(static fn() => $unknownRename->renameSource(1, 'committed-unknown', $readRenameAlias, $writeRenameAlias), 'commit-unknown result reported success');
    mapperExpect(Category::query()->whereIn('id', $renameSourceIds)->pluck('name')->all() === ['committed-unknown', 'committed-unknown'], 'commit-unknown fixture did not commit');
    mapperFails(static fn() => $mapper->assertNoPendingRename(), 'unknown commit did not block old-name imports');
    mapperFails(static fn() => $mapper->resolve($sourceA, 'same-value-repair', ['group' => 'Rename one', 'family' => ''], 'original/one', $planA), 'pending rename allowed an old-name path');
    $aliasConfig->upsertAlias(2, 'other-source-during-recovery');
    $rulesDuringRecovery = $aliasConfig->get();
    $rulesDuringRecovery['rules'] = [['priority' => 99, 'mode' => 'contains', 'keywords' => ['unchanged-rule'], 'target' => ['group' => 'Other rules', 'family' => '']]];
    $aliasConfig->saveRulesWithUnchangedAliases($rulesDuringRecovery);
    $aliasService->rename(1, 'committed-unknown');
    mapperExpect($readRenameAlias() === 'committed-unknown' && $aliasConfig->get()['rules'] === $rulesDuringRecovery['rules']
        && array_column($aliasConfig->get()['aliases'], 'alias', 'source_id')[2] === 'other-source-during-recovery', 'recovery overwrote unrelated aliases or rules');
    mapperExpect($renameOtherLeaf->fresh()->name === 'other/original', 'recovery changed another source leaf');
    $mapper->assertNoPendingRename();

    mapperFails(static fn() => $mapper->renameSource(1, 'projection-pending', $readRenameAlias,
        static function (string $alias): never { throw new RuntimeException('injected config publication failure'); }), 'config publication failure reported success');
    mapperExpect($readRenameAlias() === 'committed-unknown', 'failed config publication changed the alias');
    $pendingBytes = (string)file_get_contents($renameReceiptPath);
    $pendingMap = (string)file_get_contents($mapPath);
    Category::query()->where('id', $renameSourceIds[0])->update(['name' => 'foreign-name']);
    mapperFails(static fn() => $aliasService->rename(1, 'projection-pending'), 'mixed/external DB state was automatically overwritten');
    mapperExpect((string)file_get_contents($renameReceiptPath) === $pendingBytes && (string)file_get_contents($mapPath) === $pendingMap, 'conflicted recovery rewrote its receipt or mapping');
    Category::query()->where('id', $renameSourceIds[0])->update(['name' => 'projection-pending']);
    Category::query()->where('id', $renameLeafOne->id)->update(['pid' => $renameSourceIds[1]]);
    mapperFails(static fn() => $aliasService->rename(1, 'projection-pending'), 'recovery ignored changed original-child parent');
    mapperExpect((string)file_get_contents($renameReceiptPath) === $pendingBytes, 'child structure conflict overwrote recovery receipt');
    Category::query()->where('id', $renameLeafOne->id)->update(['pid' => $renameSourceIds[0], 'name' => 'administrator-original-name']);
    $aliasService->rename(1, 'projection-pending');
    mapperExpect($readRenameAlias() === 'projection-pending', 'partially published rename did not recover');
    mapperExpect($renameLeafOne->fresh()->name === 'administrator-original-name', 'nickname recovery overwrote an original-child name');
    $aliasService->rename(1, 'leaf-name-scope');
    mapperExpect($renameLeafOne->fresh()->name === 'administrator-original-name', 'nickname save overwrote an original-child name');
    foreach (['commodity', 'order', 'shared'] as $table) {
        mapperExpect(DB::table($table)->orderBy('id')->get()->toJson() === $businessBeforeRename[$table], 'rename or recovery changed business table ' . $table);
    }
    mapperExpect($terminalJobs->list() === $renameJobsBefore, 'rename rewrote historical jobs');

    // A 2048-node map can be valid while the operation's old/new receipt cannot
    // fit its 1 MiB bound. Capacity rejection must precede every Category write.
    $capacityKey = static fn(string $kind, int $source, string $parent, string $name): string => hash('sha256', $kind . "\0" . $source . "\0" . $parent . "\0" . $name);
    $capacityNodes = [];
    $capacityGroup = $capacityKey('group', 0, '', 'receipt-capacity');
    $capacitySource = $capacityKey('source', 1, $capacityGroup, 'leaf-name-scope');
    $capacityPrototype = ['sort' => 0, 'create_time' => '2026-09-01 00:00:00', 'owner' => 0, 'icon' => '/favicon.ico', 'status' => 1, 'hide' => 0];
    $capacityGroupId = DB::table('category')->insertGetId($capacityPrototype + ['name' => 'receipt-capacity', 'pid' => null]);
    $capacitySourceId = DB::table('category')->insertGetId($capacityPrototype + ['name' => 'leaf-name-scope', 'pid' => $capacityGroupId]);
    $capacityNodes[$capacityGroup] = ['id' => $capacityGroupId, 'name' => 'receipt-capacity', 'parent_key' => null];
    $capacityNodes[$capacitySource] = ['id' => $capacitySourceId, 'name' => 'leaf-name-scope', 'parent_key' => $capacityGroup];
    for ($index = 0; $index < 2046; $index++) {
        $name = str_repeat('界', 60) . sprintf('%07d', $index);
        $id = DB::table('category')->insertGetId($capacityPrototype + ['name' => $name, 'pid' => $capacitySourceId]);
        $capacityNodes[$capacityKey('source-category', 1, $capacitySource, $name)] = ['id' => $id, 'name' => $name, 'parent_key' => $capacitySource];
    }
    $capacityRaw = json_encode(['schema' => 2, 'nodes' => $capacityNodes, 'last_plan_hash' => ''], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    mapperExpect(strlen($capacityRaw) < 1048576 && count($capacityNodes) === 2048, 'receipt-capacity map fixture is not valid');
    $savedMapBeforeCapacity = (string)file_get_contents($mapPath);
    file_put_contents($mapPath, $capacityRaw);
    $capacityRowsBefore = Category::query()->orderBy('id')->get()->toJson();
    $capacityAliasBefore = $aliasConfig->get();
    $capacityReceiptBefore = (string)file_get_contents($renameReceiptPath);
    $capacityChangesBefore = DB::connection()->getPdo()->query('SELECT total_changes()')->fetchColumn();
    mapperFailsWithMessage(static fn() => $aliasService->rename(1, 'receipt-capacity-new'),
        '智能分类映射超过安全上限', 'oversize rename receipt was accepted or failed for an unrelated reason');
    mapperExpect(Category::query()->orderBy('id')->get()->toJson() === $capacityRowsBefore
        && $aliasConfig->get() === $capacityAliasBefore && (string)file_get_contents($mapPath) === $capacityRaw
        && (string)file_get_contents($renameReceiptPath) === $capacityReceiptBefore
        && DB::connection()->getPdo()->query('SELECT total_changes()')->fetchColumn() === $capacityChangesBefore,
        'receipt-capacity rejection wrote Category rows or projections');
    file_put_contents($mapPath, $savedMapBeforeCapacity);

    // The real planned entrypoint must resolve existing rows without entering
    // the detail/image transport, even when that transport always fails.
    $localExistingId = DB::table('commodity')->insertGetId([
        'category_id' => 1, 'owner' => 0, 'shared_id' => 1,
        'shared_code' => 'local-existing', 'code' => 'PKS1' . str_repeat('C', 20),
        'shared_sync' => 0, 'shared_premium_type' => PriceTemplate::TYPE_PERCENT,
        'factory_price' => '12.34', 'price' => '23.45', 'user_price' => '21.00',
        'stock' => 37, 'shared_stock' => '{"sku-a":37}', 'inventory_sync' => 1,
        'sentinel' => 'local-existing-unchanged',
    ]);
    $localExistingBefore = (array)DB::table('commodity')->where('id', $localExistingId)->first();
    $localTarget = ['group' => 'Local existing path', 'family' => ''];
    $localPlan = hash('sha256', 'local-existing-plan');
    $localOptions = Options::fromArray(['mode' => 'full', 'source_ids' => '1', 'premium_percent' => 10]);
    $localItem = ['code' => 'local-existing', 'category' => 'local-original', 'stock' => 1];
    $requestsBeforeLocal = $detailRequests;
    try {
        $localOutcome = $importer->importPlanned($sourceA, $localItem, $mapper,
            'leaf-name-scope', $localTarget, $localPlan, $localOptions);
    } finally {
        mapperExpect($detailRequests === $requestsBeforeLocal,
            'existing planned commodity performed a remote detail request');
    }
    $localExistingAfter = (array)DB::table('commodity')->where('id', $localExistingId)->first();
    mapperExpect($localOutcome === CommodityImporter::OUTCOME_REATTACHED
        && $localExistingAfter['category_id'] !== $localExistingBefore['category_id'],
        'existing planned commodity was not reattached');
    $localExistingBefore['category_id'] = $localExistingAfter['category_id'];
    mapperExpect($localExistingAfter === $localExistingBefore,
        'local planned reattachment changed fields other than category_id');

    mapperExpect($importer->importPlanned($sourceA, $localItem, $mapper,
        'leaf-name-scope', $localTarget, $localPlan, $localOptions) === CommodityImporter::OUTCOME_ALREADY_MANAGED
        && $detailRequests === $requestsBeforeLocal
        && (array)DB::table('commodity')->where('id', $localExistingId)->first() === $localExistingAfter,
        'same-category managed commodity requested detail or changed local data');

    // Keep ordinary SupplySync import behavior unchanged: it still prepares
    // detail first, even for an existing managed commodity.
    mapperFails(static fn() => $importer->import($sourceA, $localItem, 1, $localOptions),
        'ordinary existing import unexpectedly bypassed detail');
    mapperExpect($detailRequests === $requestsBeforeLocal + 1
        && (array)DB::table('commodity')->where('id', $localExistingId)->first() === $localExistingAfter,
        'ordinary import changed its existing-product behavior');

    $insertLocalCommodity = static function (string $code, array $overrides = []): int {
        return DB::table('commodity')->insertGetId(array_replace([
            'category_id' => 1, 'owner' => 0, 'shared_id' => 1, 'shared_code' => $code,
            'code' => 'PKS1' . strtoupper(substr(hash('sha256', $code), 0, 20)),
            'shared_sync' => 0, 'shared_premium_type' => PriceTemplate::TYPE_PERCENT,
            'price' => '98.76', 'stock' => 19, 'sentinel' => 'unchanged-' . $code,
        ], $overrides));
    };
    $localSnapshot = static fn(): array => [
        Category::query()->orderBy('id')->get()->toJson(),
        (string)file_get_contents($mapPath),
    ];
    $importLocalCode = static fn(CommodityImporter $selectedImporter, PlannedCategoryMapper $selectedMapper,
        string $code, array $selectedTarget = ['group' => 'Local existing path', 'family' => '']): string =>
        $selectedImporter->importPlanned($sourceA, ['code' => $code, 'category' => 'local-original', 'stock' => 1],
            $selectedMapper, 'leaf-name-scope', $selectedTarget, $localPlan, $localOptions);
    $requestsBeforeChecks = $detailRequests;

    $unmanagedLocalId = $insertLocalCommodity('local-unmanaged', ['code' => 'administrator-code']);
    $unmanagedLocalBefore = (array)DB::table('commodity')->where('id', $unmanagedLocalId)->first();
    mapperExpect($importLocalCode($importer, $mapper, 'local-unmanaged') === CommodityImporter::OUTCOME_HELD_EXISTING_UNMANAGED
        && (array)DB::table('commodity')->where('id', $unmanagedLocalId)->first() === $unmanagedLocalBefore,
        'existing unmanaged commodity was adopted or mutated');

    // The transaction sees fresh management markers, never the precheck row.
    foreach (['shared_sync' => 1, 'code' => 'new-administrator-code', 'shared_premium_type' => 999] as $field => $value) {
        $changedCode = 'local-marker-' . $field;
        $changedId = $insertLocalCommodity($changedCode);
        $changedBefore = null;
        $changedMapper = new PlannedCategoryMapper(static function (callable $callback) use ($changedId, $field, $value, &$changedBefore): mixed {
            DB::table('commodity')->where('id', $changedId)->update([$field => $value]);
            $changedBefore = (array)DB::table('commodity')->where('id', $changedId)->first();
            return DB::transaction($callback);
        });
        mapperExpect($importLocalCode($importer, $changedMapper, $changedCode) === CommodityImporter::OUTCOME_HELD_EXISTING_UNMANAGED
            && (array)DB::table('commodity')->where('id', $changedId)->first() === $changedBefore,
            'transaction did not recheck management marker ' . $field);
    }

    $snapshotBeforeRejected = $localSnapshot();
    $unsafeSource = clone $sourceA;
    $unsafeSource->domain = 'http://source-1.example';
    mapperFailsWithSafeCode(static fn() => $importer->importPlanned($unsafeSource, $localItem, $mapper,
        'leaf-name-scope', $localTarget, $localPlan, $localOptions),
        CommodityImportFailure::SOURCE_POLICY_FAILED, 'local existing path bypassed source policy');
    foreach (['', "bad code", "bad\x7fcode", str_repeat('x', 65)] as $invalidLocalCode) {
        $insertLocalCommodity($invalidLocalCode);
        mapperFailsWithSafeCode(static fn() => $importLocalCode($importer, $mapper, $invalidLocalCode),
            CommodityImportFailure::DETAIL_UNKNOWN_FAILED, 'local existing path accepted invalid commodity code');
    }
    mapperFailsWithSafeCode(static fn() => $importLocalCode($importer, $failingTransactionMapper, 'local-existing'),
        CommodityImportFailure::CATEGORY_TRANSACTION_FAILED, 'local transaction error was treated as a missing commodity');
    $driftMapper = new PlannedCategoryMapper(static function (callable $callback): mixed {
        DB::table('shared')->where('id', 1)->update(['name' => 'changed-before-local-lock']);
        return DB::transaction($callback);
    });
    mapperFailsWithSafeCode(static fn() => $importLocalCode($importer, $driftMapper, 'local-existing'),
        CommodityImportFailure::CATEGORY_TRANSACTION_FAILED, 'local existing path ignored source identity drift');
    DB::table('shared')->where('id', 1)->update(['name' => $sourceA->name]);
    mapperExpect($detailRequests === $requestsBeforeChecks && $localSnapshot() === $snapshotBeforeRejected,
        'local ownership/rejection checks requested detail or left category/map writes');

    // Successful detail and image preparation use the real normalizer, price
    // adjuster and database persistence, with no live network in this fixture.
    $newDetailRequests = 0;
    $newImageRequests = 0;
    $atDetail = null;
    $newTransport = static function (array $endpoint, string $address, string $method, array $headers, string $body)
        use (&$newDetailRequests, &$newImageRequests, &$atDetail, $mapPath): array {
        mapperExpect(DB::connection()->transactionLevel() === 0, 'remote request ran inside a database transaction');
        $mapLock = fopen($mapPath . '.lock', 'c');
        mapperExpect(is_resource($mapLock), 'unable to inspect map lock');
        try {
            mapperExpect(flock($mapLock, LOCK_EX | LOCK_NB), 'remote request ran while the category-map lock was held');
        } finally {
            flock($mapLock, LOCK_UN);
            fclose($mapLock);
        }
        if ($method === 'GET') {
            $newImageRequests++;
            return ['status' => 200, 'content_type' => 'image/png', 'connected_ip' => $address,
                'body' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jAusAAAAASUVORK5CYII=', true)];
        }
        $newDetailRequests++;
        parse_str($body, $fields);
        $code = (string)$fields['code'];
        if ($atDetail !== null) {
            $atDetail($code);
        }
        return ['status' => 200, 'content_type' => 'application/json', 'connected_ip' => $address,
            'body' => json_encode(['code' => 200, 'data' => [
                'code' => $code, 'name' => 'New local fixture', 'description' => '<p>Safe fixture description</p>',
                'cover' => '/fixture-' . $code . '.png', 'stock' => 7, 'price' => '10.00', 'user_price' => '8.00',
            ]], JSON_THROW_ON_ERROR)];
    };
    $newBudget = new RunBudget();
    $newHttp = new SafeHttpClient($policy, $newTransport, $newBudget);
    $newImporter = new CommodityImporter(new SharedGateway($newHttp, $policy), new PriceAdjuster(),
        new RemoteItem(new ImageCache($newHttp, $newBudget), $newBudget), $policy);
    mapperExpect($importLocalCode($newImporter, $mapper, 'local-new') === CommodityImporter::OUTCOME_CREATED,
        'new planned commodity did not use the full import path');
    $newCommodity = Commodity::query()->where('shared_code', 'local-new')->firstOrFail();
    mapperExpect($newDetailRequests === 1 && $newImageRequests === 1 && $newCommodity->stock === 7
        && $newCommodity->price === 11.0 && $newCommodity->user_price === 8.8
        && $newCommodity->shared_sync === 0 && str_starts_with($newCommodity->code, 'PKS1')
        && $newCommodity->description === '<p>Safe fixture description</p>'
        && str_starts_with($newCommodity->cover, '/assets/cache/pika-supply-sync/'),
        'new planned commodity skipped detail, image, normalization, pricing or persistence');

    // A missing precheck row can appear during preparation. The final locked
    // lookup must prevent a duplicate and preserve every non-category field.
    $appearedId = null;
    $appearedBefore = null;
    $atDetail = static function (string $code) use ($insertLocalCommodity, &$appearedId, &$appearedBefore): void {
        $appearedId = $insertLocalCommodity($code);
        $appearedBefore = (array)DB::table('commodity')->where('id', $appearedId)->first();
    };
    mapperExpect($importLocalCode($newImporter, $mapper, 'local-appeared') === CommodityImporter::OUTCOME_REATTACHED,
        'commodity appearing after precheck was not resolved under the final lock');
    $appearedAfter = (array)DB::table('commodity')->where('id', $appearedId)->first();
    $appearedBefore['category_id'] = $appearedAfter['category_id'];
    mapperExpect(DB::table('commodity')->where('shared_code', 'local-appeared')->count() === 1
        && $appearedAfter === $appearedBefore, 'appearance race duplicated or overwrote an existing commodity');

    // Disappearance is injected before the mapper transaction, as a committed
    // concurrent change. Its temporary category hierarchy must be gone before
    // the fallback request; the second mapper transaction then inserts safely.
    $vanishedId = $insertLocalCommodity('local-vanished');
    $beforeVanished = $localSnapshot();
    $vanishedMapperCalls = 0;
    $vanishedMapper = new PlannedCategoryMapper(static function (callable $callback) use ($vanishedId, &$vanishedMapperCalls): mixed {
        if (++$vanishedMapperCalls === 1) {
            DB::table('commodity')->where('id', $vanishedId)->delete();
        }
        return DB::transaction($callback);
    });
    $atDetail = static function (string $code) use ($beforeVanished, $localSnapshot): void {
        mapperExpect($code === 'local-vanished' && $localSnapshot() === $beforeVanished,
            'disappearance fallback retained category rows or a published mapping');
    };
    mapperExpect($importLocalCode($newImporter, $vanishedMapper, 'local-vanished',
        ['group' => 'Vanished fallback', 'family' => '']) === CommodityImporter::OUTCOME_CREATED
        && $vanishedMapperCalls === 2 && DB::table('commodity')->where('shared_code', 'local-vanished')->count() === 1,
        'disappearing commodity did not restart the full new-item path after rollback');

    // Owner/source/code no longer matching the precheck must not authorize any
    // mutation of that row. A failed new-item request also leaves no hierarchy.
    foreach (['owner' => 9, 'shared_id' => 2, 'shared_code' => 'moved-code'] as $field => $value) {
        $movedCode = 'local-moved-' . $field;
        $movedId = $insertLocalCommodity($movedCode);
        $movedBefore = null;
        $beforeMoved = $localSnapshot();
        $movedMapper = new PlannedCategoryMapper(static function (callable $callback) use ($movedId, $field, $value, &$movedBefore): mixed {
            DB::table('commodity')->where('id', $movedId)->update([$field => $value]);
            $movedBefore = (array)DB::table('commodity')->where('id', $movedId)->first();
            return DB::transaction($callback);
        });
        mapperFailsWithSafeCode(static fn() => $importLocalCode($importer, $movedMapper, $movedCode,
            ['group' => 'Moved fallback ' . $field, 'family' => '']),
            CommodityImportFailure::DETAIL_UNKNOWN_FAILED, 'identity change did not return to the new-item request path');
        mapperExpect((array)DB::table('commodity')->where('id', $movedId)->first() === $movedBefore
            && $localSnapshot() === $beforeMoved, 'identity change mutated the wrong commodity or left category/map writes');
    }
    mapperExpect(array_values(array_unique($failedDetailTransactionLevels)) === [0],
        'a failing detail request ran inside a database transaction');

    // Initial mirror import uses upstream IDs, never names or the backend alias.
    // Sources 1-4 retain the preceding smart/history fixtures.
    $mirrorSourceId = 5;
    $mirrorOtherSourceId = 6;
    foreach ([$mirrorSourceId, $mirrorOtherSourceId] as $fixtureSourceId) {
        DB::table('shared')->insert(['id' => $fixtureSourceId, 'type' => 0,
            'name' => 'mirror-fixture-' . $fixtureSourceId, 'domain' => 'https://mirror.example',
            'app_id' => 'fixture-merchant', 'app_key' => 'fixture-secret',
            'currency' => 'CNY', 'currency_rate' => '1']);
    }
    $mirrorSource = Shared::query()->findOrFail($mirrorSourceId);
    $mirrorOtherSource = Shared::query()->findOrFail($mirrorOtherSourceId);
    $mirrorSettings = $aliasConfig->get();
    $mirrorSettings['aliases'][] = ['source_id' => $mirrorSourceId, 'alias' => 'S0后台名称', 'category_mode' => 'mirror'];
    $mirrorSettings['aliases'][] = ['source_id' => $mirrorOtherSourceId, 'alias' => '其他后台名称', 'category_mode' => 'mirror'];
    $aliasConfig->save($mirrorSettings);
    $mirrorTarget = ['mode' => 'mirror', 'path' => [
        ['id' => 10, 'pid' => 0, 'name' => 'Telegram', 'sort' => 7],
        ['id' => 20, 'pid' => 10, 'name' => '货源A', 'sort' => 8],
        ['id' => 30, 'pid' => 20, 'name' => '账号 / 服务', 'sort' => 9],
    ]];
    $mirrorPlan = hash('sha256', 'mirror-frozen-plan');
    $smartConflictLeaf = $mapper->resolve($sourceA, 'leaf-name-scope', ['group' => 'Telegram', 'family' => ''],
        'mirror-name-conflict', $planA);
    $smartConflictRootId = (int)Category::query()->findOrFail($smartConflictLeaf->pid)->pid;
    $beforeMirror = $localSnapshot();
    $mapper->assertPlanCapacity($mirrorSource, 'S0后台名称', [['category' => '账号 / 服务', 'target' => $mirrorTarget]]);
    mapperExpect($localSnapshot() === $beforeMirror, 'mirror preflight changed category rows or registry');
    $mirrorLeaf = $mapper->resolve($mirrorSource, 'S0后台名称', $mirrorTarget, '账号 / 服务', $mirrorPlan);
    $mirrorParent = Category::query()->findOrFail($mirrorLeaf->pid);
    $mirrorRoot = Category::query()->findOrFail($mirrorParent->pid);
    mapperExpect($mirrorRoot->name === 'Telegram' && $mirrorRoot->pid === null
        && $mirrorParent->name === '货源A' && $mirrorLeaf->name === '账号 / 服务'
        && (int)$mirrorRoot->id !== $smartConflictRootId
        && (int)$mirrorLeaf->sort === 9 && (int)$mirrorParent->sort === 8 && (int)$mirrorRoot->sort === 7
        && !Category::query()->where('name', 'S0后台名称')->exists(),
        'mirror tree inserted an alias/classification layer or removed a real upstream node');
    $mirrorBytes = (string)file_get_contents($mapPath);
    $mirrorRegistry = json_decode($mirrorBytes, true, 32, JSON_THROW_ON_ERROR);
    $mirrorKey = static fn(int $sourceId, int $categoryId): string => hash('sha256', 'mirror' . "\0" . $sourceId . "\0" . $categoryId);
    mapperExpect($mirrorRegistry['nodes'][$mirrorKey($mirrorSourceId, 30)]['upstream_id'] === 30
        && $mirrorRegistry['nodes'][$mirrorKey($mirrorSourceId, 30)]['source_id'] === $mirrorSourceId
        && $mapper->hasSourceMapping($mirrorSourceId)
        && $mapper->classificationAlias($mirrorSourceId, 'S0后台名称') === 'S0后台名称',
        'mirror mapping lost stable ID/source metadata or required an alias source-node');
    $mirrorCount = Category::query()->count();
    mapperExpect($mapper->resolve($mirrorSource, 'S0后台名称', $mirrorTarget, '账号 / 服务', $mirrorPlan)->id === $mirrorLeaf->id
        && Category::query()->count() === $mirrorCount && (string)file_get_contents($mapPath) === $mirrorBytes,
        'repeat mirror confirmation duplicated or rewrote its mapping');
    Category::query()->where('id', $mirrorParent->id)->update(['sort' => 42]);
    $reorderedTarget = $mirrorTarget;
    $reorderedTarget['path'][0]['sort'] = 70;
    $reorderedTarget['path'][1]['sort'] = 80;
    $reorderedTarget['path'][2]['sort'] = 90;
    $reorderedPlan = hash('sha256', 'mirror-reordered-plan');
    $mapper->assertPlanCapacity($mirrorSource, 'S0后台名称', [['category' => '账号 / 服务', 'target' => $reorderedTarget]]);
    $reorderedLeaf = $mapper->resolve($mirrorSource, 'S0后台名称', $reorderedTarget, '账号 / 服务', $reorderedPlan);
    mapperExpect($reorderedLeaf->id === $mirrorLeaf->id, 'pure sort drift changed the reused mirror leaf ID');
    mapperExpect(Category::query()->count() === $mirrorCount, 'pure sort drift duplicated mirror nodes');
    mapperExpect((int)$mirrorRoot->fresh()->sort === 7 && (int)$mirrorParent->fresh()->sort === 42
        && (int)$mirrorLeaf->fresh()->sort === 9,
        'pure sort drift overwrote existing local sorting');
    // A new plan updates its receipt without changing any mapped node bytes.
    $expectedReorderedRegistry = array_replace($mirrorRegistry, ['last_plan_hash' => $reorderedPlan]);
    $mirrorBytes = (string)file_get_contents($mapPath);
    mapperExpect($mirrorBytes === (json_encode($expectedReorderedRegistry,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"),
        'pure sort drift changed mapping bytes beyond the latest plan receipt');
    $aliasService->rename($mirrorSourceId, '仅供后台的新名称');
    mapperExpect((string)file_get_contents($mapPath) === $mirrorBytes
        && Category::query()->count() === $mirrorCount && $mirrorParent->fresh()->name === '货源A'
        && $mapper->classificationAlias($mirrorSourceId, '仅供后台的新名称') === '仅供后台的新名称'
        && $aliasService->managedCategory((int)$mirrorRoot->id) === null,
        'backend alias rename rewrote or exposed a mirror category as an alias node');
    $mirrorAliasEntry = array_values(array_filter($aliasConfig->get()['aliases'], static fn(array $entry): bool => $entry['source_id'] === $mirrorSourceId))[0];
    mapperExpect($mirrorAliasEntry['category_mode'] === 'mirror', 'alias rename reset the saved mirror mode');
    mapperExpect($mapper->resolve($mirrorSource, '仅供后台的新名称', $mirrorTarget, '账号 / 服务', $mirrorPlan)->id === $mirrorLeaf->id,
        'a different valid backend alias changed mirror identity');

    $sameNameTarget = $mirrorTarget;
    $sameNameTarget['path'][1]['id'] = 21;
    $sameNameTarget['path'][2]['id'] = 31;
    $sameNameTarget['path'][2]['pid'] = 21;
    $sameNameLeaf = $mapper->resolve($mirrorSource, '仅供后台的新名称', $sameNameTarget, '账号 / 服务', $mirrorPlan);
    $otherSourceLeaf = $mapper->resolve($mirrorOtherSource, '其他后台名称', $mirrorTarget, '账号 / 服务', $mirrorPlan);
    mapperExpect($sameNameLeaf->id !== $mirrorLeaf->id && $sameNameLeaf->pid !== $mirrorLeaf->pid
        && $otherSourceLeaf->id !== $mirrorLeaf->id && $otherSourceLeaf->pid !== $mirrorLeaf->pid,
        'mirror merged same-name distinct category IDs or different sources');
    $beforeMirrorFailure = $localSnapshot();
    foreach (['name', 'parent'] as $drift) {
        $driftedTarget = $mirrorTarget;
        if ($drift === 'name') {
            $driftedTarget['path'][1]['name'] = 'upstream renamed';
        } else {
            $driftedTarget['path'][1]['id'] = 21;
            $driftedTarget['path'][2]['pid'] = 21;
        }
        mapperFails(static fn() => $mapper->assertPlanCapacity($mirrorSource, '仅供后台的新名称', [
            ['category' => '账号 / 服务', 'target' => $driftedTarget],
        ]), 'mirror whole-plan preflight allowed an established ID rename/reparent');
        mapperFails(static fn() => $mapper->resolve($mirrorSource, '仅供后台的新名称', $driftedTarget, '账号 / 服务', $mirrorPlan),
            'mirror resolve silently renamed or moved an established ID');
    }
    $conflictingTarget = $mirrorTarget;
    $conflictingTarget['path'][0]['name'] = 'conflicting root';
    mapperFails(static fn() => $mapper->assertPlanCapacity($mirrorSource, '仅供后台的新名称', [
        ['category' => '账号 / 服务', 'target' => $mirrorTarget],
        ['category' => '账号 / 服务', 'target' => $conflictingTarget],
    ]), 'mirror preflight accepted conflicting definitions of an upstream ID');
    mapperFails(static fn() => $mapper->resolve($sourceA, 'leaf-name-scope', $mirrorTarget, '账号 / 服务', $mirrorPlan),
        'existing smart source was converted to mirror');
    mapperFails(static fn() => $mapper->resolve($mirrorSource, '仅供后台的新名称', $target, '账号 / 服务', $mirrorPlan),
        'existing mirror source was converted to smart');
    foreach (['missing', 'cycle'] as $invalidTree) {
        $invalidTarget = $mirrorTarget;
        if ($invalidTree === 'missing') {
            $invalidTarget['path'][1]['pid'] = 999;
        } else {
            $invalidTarget['path'][2]['id'] = 10;
        }
        mapperFails(static fn() => $mapper->resolve($mirrorSource, '仅供后台的新名称', $invalidTarget, '账号 / 服务', $mirrorPlan),
            'mirror accepted a missing ancestor or cyclic path before writes');
    }
    mapperExpect($localSnapshot() === $beforeMirrorFailure, 'mirror structural rejection wrote categories or map');
    $aliasService->assertCategoryMode($mirrorSourceId, 'mirror');
    mapperFails(static fn() => $aliasService->assertCategoryMode($mirrorSourceId, 'smart'), 'mode save gate allowed mirror-to-smart');
    mapperFails(static fn() => $aliasService->assertCategoryMode(1, 'mirror'), 'mode save gate allowed smart-to-mirror');

    $mirrorCapacityRegistry = json_decode($beforeMirrorFailure[1], true, 32, JSON_THROW_ON_ERROR);
    for ($index = count($mirrorCapacityRegistry['nodes']); $index < 2048; $index++) {
        $mirrorCapacityRegistry['nodes'][hash('sha256', 'mirror-capacity-' . $index)] = [
            'id' => 2147480000 + $index, 'name' => 'capacity-fixture', 'parent_key' => null,
        ];
    }
    $mirrorCapacityBytes = mapperWriteRegistry($mapPath, $mirrorCapacityRegistry);
    $mirrorExtraRoot = ['mode' => 'mirror', 'path' => [['id' => 99, 'pid' => 0, 'name' => 'Capacity', 'sort' => 0]]];
    mapperFailsWithMessage(static fn() => $mapper->assertPlanCapacity($mirrorSource, '仅供后台的新名称', [
        ['category' => 'Capacity', 'target' => $mirrorExtraRoot],
    ]), '智能分类映射节点预计超过安全上限', 'mirror plan ignored node capacity');
    mapperExpect((string)file_get_contents($mapPath) === $mirrorCapacityBytes
        && Category::query()->orderBy('id')->get()->toJson() === $beforeMirrorFailure[0],
        'mirror capacity rejection wrote category rows or mapping');
    file_put_contents($mapPath, $beforeMirrorFailure[1]);
    chmod($mapPath, 0600);

    $rollbackTarget = ['mode' => 'mirror', 'path' => [['id' => 90, 'pid' => 0, 'name' => 'Rollback mirror', 'sort' => 0]]];
    mapperFails(static fn() => $mapper->withResolvedCategory($mirrorSource, '仅供后台的新名称', $rollbackTarget,
        'Rollback mirror', $mirrorPlan, static function (Category $category): never {
            DB::table('planned_effect')->insert(['category_id' => (int)$category->id]);
            throw new RuntimeException('injected mirror callback rollback');
        }), 'mirror callback rollback was reported as successful');
    mapperExpect($localSnapshot() === $beforeMirrorFailure, 'mirror callback rollback left categories or map entries');
    foreach (['missing', 'cycle', 'other_source', 'duplicate_local'] as $corruption) {
        $badRegistry = json_decode($beforeMirrorFailure[1], true, 32, JSON_THROW_ON_ERROR);
        if ($corruption === 'duplicate_local') {
            $badRegistry['nodes'][$mirrorKey($mirrorSourceId, 30)]['id'] = (int)$otherSourceLeaf->id;
        } else {
            $badRegistry['nodes'][$mirrorKey($mirrorSourceId, 30)]['parent_key'] = match ($corruption) {
                'missing' => $mirrorKey($mirrorSourceId, 999),
                'cycle' => $mirrorKey($mirrorSourceId, 30),
                'other_source' => $mirrorKey($mirrorOtherSourceId, 20),
            };
        }
        mapperWriteRegistry($mapPath, $badRegistry);
        mapperFails(static fn() => $mapper->classificationAlias($mirrorSourceId, '仅供后台的新名称'),
            'mirror registry accepted missing/cyclic/cross-source ancestry');
    }
    file_put_contents($mapPath, $beforeMirrorFailure[1]);
    chmod($mapPath, 0600);

    $mirrorCountBeforeUnknown = Category::query()->count();
    mapperFails(static fn() => $commitUnknownMapper->withResolvedCategory($mirrorSource, '仅供后台的新名称', $rollbackTarget,
        'Rollback mirror', $mirrorPlan, static fn(Category $category): Category => $category),
        'mirror commit-result unknown was reported as successful');
    mapperExpect(Category::query()->count() === $mirrorCountBeforeUnknown + 1,
        'mirror commit-unknown fixture did not commit its category');
    $mirrorUnknownBytes = (string)file_get_contents($mapPath);
    $mapper->resolve($mirrorSource, '仅供后台的新名称', $rollbackTarget, 'Rollback mirror', $mirrorPlan);
    mapperExpect(Category::query()->count() === $mirrorCountBeforeUnknown + 1
        && (string)file_get_contents($mapPath) === $mirrorUnknownBytes,
        'mirror unknown-commit retry restored stale mapping or duplicated its category');

    // Both commodity branches consume the same target without hidden alias nodes.
    $mirrorExistingId = $insertLocalCommodity('mirror-existing', ['shared_id' => $mirrorSourceId]);
    $mirrorExistingBefore = (array)DB::table('commodity')->where('id', $mirrorExistingId)->first();
    $mirrorRequestsBefore = $detailRequests;
    $mirrorItem = ['code' => 'mirror-existing', 'category' => '账号 / 服务', 'stock' => 1];
    mapperExpect($importer->importPlanned($mirrorSource, $mirrorItem, $mapper, '仅供后台的新名称', $mirrorTarget,
        $mirrorPlan, $localOptions) === CommodityImporter::OUTCOME_REATTACHED
        && $detailRequests === $mirrorRequestsBefore, 'existing mirror import requested remote details');
    $mirrorExistingBefore['category_id'] = (int)$mirrorLeaf->id;
    mapperExpect((array)DB::table('commodity')->where('id', $mirrorExistingId)->first() === $mirrorExistingBefore,
        'existing mirror import changed fields other than its category');
    mapperExpect($importer->importPlanned($mirrorSource, $mirrorItem, $mapper, '仅供后台的新名称', $mirrorTarget,
        $mirrorPlan, $localOptions) === CommodityImporter::OUTCOME_ALREADY_MANAGED,
        'repeat mirror commodity import was not idempotent');
    foreach (['mirror-existing', 'mirror-rejected-new'] as $rejectedCode) {
        mapperFailsWithSafeCode(static fn() => $importer->importPlanned($mirrorSource,
            ['code' => $rejectedCode, 'category' => 'wrong leaf', 'stock' => 1], $mapper, '仅供后台的新名称',
            $mirrorTarget, $mirrorPlan, $localOptions), CommodityImportFailure::CATEGORY_TRANSACTION_FAILED,
            'mirror importer accepted a leaf-name/target mismatch');
    }
    mapperExpect($detailRequests === $mirrorRequestsBefore, 'invalid mirror target reached remote detail');
    $atDetail = null;
    $mirrorNewRequests = $newDetailRequests;
    mapperExpect($newImporter->importPlanned($mirrorSource,
        ['code' => 'mirror-new', 'category' => '账号 / 服务', 'stock' => 1], $mapper, '仅供后台的新名称',
        $mirrorTarget, $mirrorPlan, $localOptions) === CommodityImporter::OUTCOME_CREATED
        && $newDetailRequests === $mirrorNewRequests + 1
        && (int)Commodity::query()->where('shared_id', $mirrorSourceId)->where('shared_code', 'mirror-new')->firstOrFail()->category_id === (int)$mirrorLeaf->id,
        'new mirror import did not use the frozen category through the existing prepare/persist path');

    // Product-bearing category count (155) is distinct from its complete
    // ancestry closure (214): 1 root + 2 branches + 56 parents + 155 leaves.
    // Sources 1-6 belong to earlier fixtures; this source is independently synthetic.
    $wideSourceId = 7;
    mapperExpect(!Shared::query()->whereKey($wideSourceId)->exists(), 'wide-tree fixture source ID is already occupied');
    DB::table('shared')->insert(['id' => $wideSourceId, 'type' => 0,
        'name' => 'wide-tree-fixture', 'domain' => 'https://wide-tree.example',
        'app_id' => 'wide-tree-fixture-merchant', 'app_key' => 'wide-tree-fixture-secret',
        'currency' => 'CNY', 'currency_rate' => '1']);
    $wideSource = Shared::query()->findOrFail($wideSourceId);
    $wideAlias = 'wide-tree-backend-alias';
    $wideOptions = Options::fromArray(['mode' => 'full', 'source_ids' => (string)$wideSourceId, 'premium_percent' => 10]);
    $wideRoot = ['id' => 10001, 'pid' => 0, 'name' => 'wide-tree-root', 'sort' => 0];
    $wideBranches = [
        ['id' => 10002, 'pid' => 10001, 'name' => 'wide-tree-branch-0', 'sort' => 0],
        ['id' => 10003, 'pid' => 10001, 'name' => 'wide-tree-branch-1', 'sort' => 1],
    ];
    $wideNodes = [$wideRoot['id'] => $wideRoot];
    foreach ($wideBranches as $wideBranch) {
        $wideNodes[$wideBranch['id']] = $wideBranch;
    }
    $wideParents = [];
    for ($index = 0; $index < 56; $index++) {
        $wideParent = ['id' => 10100 + $index, 'pid' => $wideBranches[$index % 2]['id'],
            'name' => 'wide-tree-parent-' . $index, 'sort' => $index];
        $wideParents[] = $wideParent;
        $wideNodes[$wideParent['id']] = $wideParent;
    }
    $wideItems = [];
    $wideCommodityBefore = [];
    $wideParentUsage = [];
    for ($index = 0; $index < 155; $index++) {
        $parentIndex = $index % count($wideParents);
        $wideParent = $wideParents[$parentIndex];
        $wideLeafNode = ['id' => 11000 + $index, 'pid' => $wideParent['id'],
            'name' => 'wide-tree-leaf-' . $index, 'sort' => $index];
        $wideNodes[$wideLeafNode['id']] = $wideLeafNode;
        $wideParentUsage[$wideParent['id']] = true;
        $wideCode = 'wide-tree-item-' . $index;
        $wideItems[] = ['code' => $wideCode, 'category' => $wideLeafNode['name'], 'stock' => 1,
            'target' => ['mode' => 'mirror', 'path' => [
                $wideRoot, $wideBranches[$parentIndex % 2], $wideParent, $wideLeafNode,
            ]]];
        $wideCommodityId = $insertLocalCommodity($wideCode, ['shared_id' => $wideSourceId]);
        $wideCommodityBefore[$wideCode] = (array)DB::table('commodity')->where('id', $wideCommodityId)->first();
    }
    mapperExpect(count($wideNodes) === 214 && count($wideItems) === 155
        && count($wideNodes) - count($wideItems) === 59 && count($wideParentUsage) === 56,
        'wide-tree fixture does not contain exactly 155 product leaves and 59 necessary ancestors');
    $widePlan = hash('sha256', json_encode($wideItems, JSON_THROW_ON_ERROR));
    $wideCategoriesBefore = Category::query()->count();
    $wideSnapshotBefore = $localSnapshot();
    $wideCommoditiesBefore = DB::table('commodity')->orderBy('id')->get()->toJson();
    $wideChangesBefore = DB::connection()->getPdo()->query('SELECT total_changes()')->fetchColumn();
    $mapper->assertPlanCapacity($wideSource, $wideAlias, $wideItems);
    mapperExpect($localSnapshot() === $wideSnapshotBefore
        && DB::table('commodity')->orderBy('id')->get()->toJson() === $wideCommoditiesBefore
        && DB::connection()->getPdo()->query('SELECT total_changes()')->fetchColumn() === $wideChangesBefore,
        '214-node/155-leaf mirror preflight wrote rows or mapping state');

    $wideResolvedLeaves = [];
    foreach ($wideItems as $wideItem) {
        mapperExpect(count($wideItem['target']['path']) === 4, 'wide-tree fixture path is not four levels deep');
        $wideResolved = $mapper->resolve($wideSource, $wideAlias, $wideItem['target'], $wideItem['category'], $widePlan);
        $wideResolvedLeaves[$wideItem['code']] = (int)$wideResolved->id;
    }
    $wideRegistry = json_decode((string)file_get_contents($mapPath), true, 32, JSON_THROW_ON_ERROR);
    $wideMappedNodes = array_filter($wideRegistry['nodes'], static fn(array $entry): bool =>
        ($entry['mode'] ?? null) === 'mirror' && ($entry['source_id'] ?? null) === $wideSourceId);
    mapperExpect(Category::query()->count() === $wideCategoriesBefore + 214
        && count($wideMappedNodes) === 214
        && count(array_unique(array_column($wideMappedNodes, 'id'))) === 214
        && count(array_unique($wideResolvedLeaves)) === 155,
        '214-node mirror resolution duplicated shared ancestors or merged distinct product leaves');
    foreach ($wideNodes as $wideNode) {
        $wideMapping = $wideMappedNodes[$mirrorKey($wideSourceId, $wideNode['id'])] ?? null;
        mapperExpect(is_array($wideMapping), 'wide-tree mapping omitted an upstream category ID');
        $wideRow = Category::query()->findOrFail($wideMapping['id']);
        $wideExpectedPid = $wideNode['pid'] === 0 ? null
            : $wideMappedNodes[$mirrorKey($wideSourceId, $wideNode['pid'])]['id'];
        mapperExpect((string)$wideRow->name === $wideNode['name'] && (int)$wideRow->owner === 0
            && ($wideRow->pid === null ? null : (int)$wideRow->pid) === $wideExpectedPid
            && (int)$wideRow->sort === $wideNode['sort'],
            'wide-tree category changed its confirmed name, owner, parent or initial sort');
    }
    $wideRequestsBefore = $detailRequests;
    foreach ($wideItems as $wideItem) {
        mapperExpect($importer->importPlanned($wideSource, $wideItem, $mapper, $wideAlias,
            $wideItem['target'], $widePlan, $wideOptions) === CommodityImporter::OUTCOME_REATTACHED,
            'wide-tree managed commodity did not attach through the existing importer');
        $wideExpectedCommodity = $wideCommodityBefore[$wideItem['code']];
        $wideExpectedCommodity['category_id'] = $wideResolvedLeaves[$wideItem['code']];
        mapperExpect((array)DB::table('commodity')->where('id', $wideExpectedCommodity['id'])->first() === $wideExpectedCommodity,
            'wide-tree commodity did not retain all fields except the confirmed leaf assignment');
    }
    mapperExpect(Commodity::query()->where('shared_id', $wideSourceId)->count() === 155
        && Commodity::query()->where('shared_id', $wideSourceId)->distinct()->count('category_id') === 155
        && $detailRequests === $wideRequestsBefore && Category::query()->count() === $wideCategoriesBefore + 214,
        'wide-tree import duplicated commodities/categories, lost leaf assignments or requested remote details');

    $wideRepeatedSnapshot = $localSnapshot();
    $wideRepeatedCommodities = DB::table('commodity')->orderBy('id')->get()->toJson();
    $wideRepeatedChanges = DB::connection()->getPdo()->query('SELECT total_changes()')->fetchColumn();
    $mapper->assertPlanCapacity($wideSource, $wideAlias, $wideItems);
    foreach ($wideItems as $wideItem) {
        mapperExpect((int)$mapper->resolve($wideSource, $wideAlias, $wideItem['target'], $wideItem['category'], $widePlan)->id
            === $wideResolvedLeaves[$wideItem['code']], 'repeated wide-tree plan changed a mapped leaf ID');
        mapperExpect($importer->importPlanned($wideSource, $wideItem, $mapper, $wideAlias,
            $wideItem['target'], $widePlan, $wideOptions) === CommodityImporter::OUTCOME_ALREADY_MANAGED,
            'repeated wide-tree import did not reuse the managed commodity assignment');
    }
    mapperExpect($localSnapshot() === $wideRepeatedSnapshot
        && DB::table('commodity')->orderBy('id')->get()->toJson() === $wideRepeatedCommodities
        && DB::connection()->getPdo()->query('SELECT total_changes()')->fetchColumn() === $wideRepeatedChanges
        && $detailRequests === $wideRequestsBefore,
        'repeated 214-node/155-leaf plan wrote rows, changed mapping bytes or requested remote details');
    // Icon preparation is opt-in and runs before both the map write lock and
    // the database transaction. The frozen target and mapping schema stay old.
    $iconA = '/assets/cache/pika-supply-sync/' . str_repeat('a', 64) . '.png';
    $iconB = '/assets/cache/pika-supply-sync/' . str_repeat('b', 64) . '-' . str_repeat('c', 64) . '.webp';
    $iconTarget = ['mode' => 'mirror', 'category_icons' => true, 'path' => [
        ['id' => 12001, 'pid' => 0, 'name' => 'icon-root', 'sort' => 3],
        ['id' => 12002, 'pid' => 12001, 'name' => 'icon-leaf', 'sort' => 4],
    ]];
    $iconProviderCalls = [];
    $iconMapper = new PlannedCategoryMapper(null,
        static function (Shared $selectedSource, array $nodes) use ($wideSourceId, $mapPath, $iconA, &$iconProviderCalls): array {
            mapperExpect((int)$selectedSource->id === $wideSourceId && DB::connection()->transactionLevel() === 0,
                'icon provider ran for a different source or inside a database transaction');
            $probe = fopen($mapPath . '.lock', 'r+b');
            mapperExpect(is_resource($probe) && flock($probe, LOCK_EX | LOCK_NB),
                'icon provider ran while the publishing map lock was held');
            flock($probe, LOCK_UN);
            fclose($probe);
            foreach ($nodes as $node) {
                mapperExpect(array_keys($node) === ['id', 'pid', 'name', 'sort'],
                    'icon provider changed the frozen target node contract');
            }
            $iconProviderCalls[] = $nodes;
            return [12001 => $iconA, 12002 => '/favicon.ico'];
        });
    $iconLeaf = $iconMapper->resolve($wideSource, $wideAlias, $iconTarget, 'icon-leaf', $widePlan);
    $iconRoot = Category::query()->findOrFail($iconLeaf->pid);
    mapperExpect($iconProviderCalls === [$iconTarget['path']] && (string)$iconRoot->icon === $iconA
        && (string)$iconLeaf->icon === '/favicon.ico',
        'creation did not use the prepared cache icon or preserve the missing-image default');
    $iconRegistry = json_decode((string)file_get_contents($mapPath), true, 32, JSON_THROW_ON_ERROR);
    mapperExpect($iconRegistry['schema'] === 2
        && array_keys($iconRegistry['nodes'][$mirrorKey($wideSourceId, 12001)])
            === ['id', 'name', 'parent_key', 'mode', 'source_id', 'upstream_id'],
        'icon creation changed the existing mapping schema');
    Category::query()->where('id', $iconLeaf->id)->update(['icon' => '/manual-icon.png']);
    $iconExistingSnapshot = $localSnapshot();
    $iconMapper->resolve($wideSource, $wideAlias, $iconTarget, 'icon-leaf', $widePlan);
    mapperExpect(count($iconProviderCalls) === 1 && $localSnapshot() === $iconExistingSnapshot,
        'existing mirror categories triggered image work or lost a manual icon');
    $smartIconMapper = new PlannedCategoryMapper(null, static function (): never {
        throw new RuntimeException('smart mode must never request category icons');
    });
    $smartIconMapper->resolve($sourceA, 'leaf-name-scope', ['group' => 'smart-icons', 'family' => ''], 'smart-icon-leaf', $planA);
    $oldIconTarget = ['mode' => 'mirror', 'path' => [
        ['id' => 12005, 'pid' => 0, 'name' => 'old-snapshot-icon', 'sort' => 0],
    ]];
    $oldSnapshotLeaf = $smartIconMapper->resolve($wideSource, $wideAlias, $oldIconTarget, 'old-snapshot-icon', $widePlan);
    mapperExpect((string)$oldSnapshotLeaf->icon === '/favicon.ico',
        'old snapshot without the explicit icon capability requested images or changed its default');

    $concurrentTarget = $iconTarget;
    $concurrentTarget['path'][1] = ['id' => 12003, 'pid' => 12001, 'name' => 'concurrent-icon-leaf', 'sort' => 0];
    $concurrentIconMapper = new PlannedCategoryMapper(null,
        static function (Shared $selectedSource, array $nodes) use ($mapper, $wideAlias, $widePlan, $concurrentTarget, $iconB): array {
            mapperExpect($nodes === [$concurrentTarget['path'][1]], 'provider included an already mapped ancestor');
            $created = $mapper->resolve($selectedSource, $wideAlias, $concurrentTarget, 'concurrent-icon-leaf', $widePlan);
            Category::query()->where('id', $created->id)->update(['icon' => '/concurrent-manual.png']);
            return [12003 => $iconB];
        });
    $concurrentIconLeaf = $concurrentIconMapper->resolve($wideSource, $wideAlias, $concurrentTarget, 'concurrent-icon-leaf', $widePlan);
    mapperExpect((string)$concurrentIconLeaf->icon === '/concurrent-manual.png',
        'preparation overwrote an icon on a concurrently created mapped category');

    $rejectedIconTarget = ['mode' => 'mirror', 'category_icons' => true, 'path' => [
        ['id' => 12004, 'pid' => 0, 'name' => 'rejected-icon', 'sort' => 0],
    ]];
    foreach ([[], [12004 => 'https://images.example/icon.png'], [12004 => '/assets/cache/pika-supply-sync/../bad.png'],
        [12004 => 3], [999 => $iconA]] as $badIcons) {
        $beforeRejectedIcons = $localSnapshot();
        $badIconMapper = new PlannedCategoryMapper(null, static fn(): array => $badIcons);
        mapperFails(static fn() => $badIconMapper->resolve($wideSource, $wideAlias, $rejectedIconTarget, 'rejected-icon', $widePlan),
            'unsafe or unrequested provider icon was accepted');
        mapperExpect($localSnapshot() === $beforeRejectedIcons, 'rejected icon preparation left database or map writes');
    }
    $failedIconMapper = new PlannedCategoryMapper(null, static function (): never {
        throw new RuntimeException('synthetic image download failure');
    });
    $beforeRejectedIcons = $localSnapshot();
    mapperFails(static fn() => $failedIconMapper->resolve($wideSource, $wideAlias, $rejectedIconTarget, 'rejected-icon', $widePlan),
        'image preparation failure was silently accepted');
    mapperFails(static fn() => DB::transaction(static fn() => $iconMapper->resolve(
        $wideSource, $wideAlias, $rejectedIconTarget, 'rejected-icon', $widePlan)),
        'image preparation was allowed inside an outer transaction');
    mapperExpect(count($iconProviderCalls) === 1 && $localSnapshot() === $beforeRejectedIcons,
        'failed or nested-transaction image preparation changed state');
    $sourceDriftIconMapper = new PlannedCategoryMapper(null,
        static function () use ($wideSourceId, $iconA): array {
            DB::table('shared')->where('id', $wideSourceId)->update(['domain' => 'https://changed-during-icons.example']);
            return [12004 => $iconA];
        });
    mapperFails(static fn() => $sourceDriftIconMapper->resolve($wideSource, $wideAlias, $rejectedIconTarget, 'rejected-icon', $widePlan),
        'icon preparation source drift was not rechecked inside the category transaction');
    DB::table('shared')->where('id', $wideSourceId)->update(['domain' => $wideSource->domain]);
    mapperExpect($localSnapshot() === $beforeRejectedIcons, 'post-download source drift left categories or map writes');

    // The one-off path acts on at most 16 existing exact bindings. It does not
    // create categories, re-import products, rewrite jobs, or publish the map.
    $iconIds = range(11000, 11015);
    $iconFiles = static function () use ($mapPath): array {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname($mapPath), FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $files[$file->getPathname()] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($files);
        return $files;
    };
    $iconFilesBefore = $iconFiles();
    $iconProductsBefore = DB::table('commodity')->orderBy('id')->get()->toJson();
    $iconOrdersBefore = DB::table('order')->orderBy('id')->get()->toJson();
    $iconCategoriesBefore = Category::query()->orderBy('id')->get()->toArray();
    $iconTotalBefore = DB::connection()->getPdo()->query('SELECT total_changes()')->fetchColumn();
    $iconTargets = $mapper->mirrorIconTargets($wideSource, $iconIds);
    mapperExpect(count($iconTargets) === 16
        && array_keys($iconTargets[0]) === ['upstream_id', 'upstream_pid', 'local_id', 'pid', 'name', 'icon']
        && $iconTargets[0]['upstream_pid'] === 10100
        && DB::connection()->getPdo()->query('SELECT total_changes()')->fetchColumn() === $iconTotalBefore,
        'read-only icon targets changed rows or lost exact upstream ancestry');
    $rootIconTarget = $mapper->mirrorIconTargets($wideSource, [10001])[0];
    mapperExpect($rootIconTarget['upstream_pid'] === 0 && $rootIconTarget['pid'] === null,
        'root icon target changed null local parent or zero upstream parent');
    $iconChanges = array_map(static function (array $row) use ($iconA): array {
        unset($row['icon']);
        return $row + ['old_icon' => '/favicon.ico', 'new_icon' => $iconA];
    }, $iconTargets);
    $iconApplied = $mapper->compareAndSetMirrorIcons($wideSource, $iconChanges);
    mapperExpect($iconApplied === ['status' => 'applied', 'changed' => 16, 'total' => 16]
        && $mapper->compareAndSetMirrorIcons($wideSource, $iconChanges)
            === ['status' => 'already_applied', 'changed' => 0, 'total' => 16],
        'bounded icon apply or replay was not idempotent');
    $iconExpectedCategories = $iconCategoriesBefore;
    foreach ($iconExpectedCategories as &$row) {
        if (in_array((int)$row['id'], array_column($iconTargets, 'local_id'), true)) {
            $row['icon'] = $iconA;
        }
    }
    unset($row);
    mapperExpect(Category::query()->orderBy('id')->get()->toArray() === $iconExpectedCategories
        && $iconFiles() === $iconFilesBefore
        && DB::table('commodity')->orderBy('id')->get()->toJson() === $iconProductsBefore
        && DB::table('order')->orderBy('id')->get()->toJson() === $iconOrdersBefore,
        'icon apply changed hierarchy, non-icon fields, products, orders, map or existing job/snapshot bytes');
    mapperExpect($mapper->compareAndSetMirrorIcons($wideSource, $iconChanges, true)
        === ['status' => 'rolled_back', 'changed' => 16, 'total' => 16]
        && $mapper->compareAndSetMirrorIcons($wideSource, $iconChanges, true)
            === ['status' => 'already_rolled_back', 'changed' => 0, 'total' => 16]
        && Category::query()->orderBy('id')->get()->toArray() === $iconCategoriesBefore,
        'conditional icon rollback changed rows outside the original icon delta');

    foreach ([[], range(11000, 11016), [11000, 11000], ['11000'], [0], [2147483648], [999999]] as $invalidIds) {
        mapperFails(static fn() => $mapper->mirrorIconTargets($wideSource, $invalidIds), 'invalid or unmapped icon target list was accepted');
    }
    mapperFails(static fn() => $mapper->mirrorIconTargets($sourceA, [11000]), 'smart mapping accepted mirror icon operations');
    mapperFails(static fn() => $mapper->mirrorIconTargets($mirrorSource, [11000]), 'another source adopted mirror icon bindings');
    $staleIconSource = clone $wideSource;
    $staleIconSource->domain = 'https://changed-source.example';
    mapperFails(static fn() => $mapper->mirrorIconTargets($staleIconSource, $iconIds), 'icon preview accepted stale source identity');
    mapperFails(static fn() => $mapper->compareAndSetMirrorIcons($staleIconSource, $iconChanges), 'icon apply accepted stale source identity');
    foreach (['upstream_pid' => 10101, 'local_id' => (int)$iconRoot->id, 'pid' => null,
        'name' => 'changed-name', 'old_icon' => '/manual-icon.png', 'new_icon' => 'https://images.example/icon.png'] as $field => $value) {
        $invalidChanges = $iconChanges;
        $invalidChanges[0][$field] = $value;
        mapperFails(static fn() => $mapper->compareAndSetMirrorIcons($wideSource, $invalidChanges), 'changed identity or unsafe icon passed CAS');
    }
    $duplicateIconChanges = [$iconChanges[0], $iconChanges[0]];
    mapperFails(static fn() => $mapper->compareAndSetMirrorIcons($wideSource, $duplicateIconChanges), 'duplicate icon target passed CAS');

    foreach (['/manual-after-preview.png', $iconA] as $changedIcon) {
        Category::query()->where('id', $iconTargets[0]['local_id'])->update(['icon' => $changedIcon]);
        $beforeIconConflict = $localSnapshot();
        foreach ([false, true] as $rollbackIcons) {
            mapperFails(static fn() => $mapper->compareAndSetMirrorIcons($wideSource, $iconChanges, $rollbackIcons),
                'manual or mixed icon states were not rejected as a complete batch');
            mapperExpect($localSnapshot() === $beforeIconConflict, 'icon conflict partially changed the batch');
        }
        Category::query()->where('id', $iconTargets[0]['local_id'])->update(['icon' => '/favicon.ico']);
    }
    foreach (['owner' => 9, 'name' => 'changed-ancestor', 'pid' => 1] as $field => $value) {
        $ancestorId = $rootIconTarget['local_id'];
        $oldValue = Category::query()->findOrFail($ancestorId)->{$field};
        Category::query()->where('id', $ancestorId)->update([$field => $value]);
        $beforeAncestorConflict = $localSnapshot();
        mapperFails(static fn() => $mapper->mirrorIconTargets($wideSource, $iconIds), 'preview ignored full ancestor identity drift');
        mapperFails(static fn() => $mapper->compareAndSetMirrorIcons($wideSource, $iconChanges), 'CAS ignored full ancestor identity drift');
        mapperExpect($localSnapshot() === $beforeAncestorConflict, 'ancestor rejection wrote state');
        Category::query()->where('id', $ancestorId)->update([$field => $oldValue]);
    }
    $missingIconRow = Category::query()->findOrFail($iconTargets[0]['local_id'])->getAttributes();
    Category::query()->where('id', $missingIconRow['id'])->delete();
    $beforeMissingIcon = $localSnapshot();
    mapperFails(static fn() => $mapper->compareAndSetMirrorIcons($wideSource, $iconChanges), 'CAS recreated a missing category');
    mapperExpect($localSnapshot() === $beforeMissingIcon, 'missing target failure created a row or changed the map');
    DB::table('category')->insert($missingIconRow);

    // The second SQL write fails after the first one ran: neither may commit.
    DB::unprepared('CREATE TEMP TRIGGER reject_icon_batch BEFORE UPDATE OF icon ON category WHEN NEW.id = '
        . $iconTargets[1]['local_id'] . " BEGIN SELECT RAISE(ABORT, 'synthetic icon write failure'); END");
    $beforeIconWriteFailure = $localSnapshot();
    mapperFails(static fn() => $mapper->compareAndSetMirrorIcons($wideSource, $iconChanges), 'failed batch SQL was reported as applied');
    DB::unprepared('DROP TRIGGER reject_icon_batch');
    mapperExpect($localSnapshot() === $beforeIconWriteFailure && $iconFiles() === $iconFilesBefore
        && DB::table('commodity')->orderBy('id')->get()->toJson() === $iconProductsBefore
        && DB::table('order')->orderBy('id')->get()->toJson() === $iconOrdersBefore,
        'failed SQL did not roll back all icons or altered unrelated state');

    // Exercise the real metadata gateway, safe transport and bitmap cache, not
    // a CategoryIcons test double. All transport responses remain synthetic.
    $serviceNodes = [
        13001 => ['id' => 13001, 'pid' => 0, 'name' => 'service-icon-root', 'sort' => 5, 'icon' => '/category-fixtures/create.png'],
        13002 => ['id' => 13002, 'pid' => 13001, 'name' => 'service-icon-default', 'sort' => 6, 'icon' => '/favicon.ico'],
        13003 => ['id' => 13003, 'pid' => 0, 'name' => 'service-icon-empty', 'sort' => 0, 'icon' => ''],
        13004 => ['id' => 13004, 'pid' => 0, 'name' => 'service-icon-failure', 'sort' => 0, 'icon' => '/category-fixtures/failure.png'],
    ];
    foreach ([11000 => '/category-fixtures/repair-a.png', 11001 => '', 11002 => '/favicon.ico',
        11003 => '/category-fixtures/manual-no-download.png', 11004 => '/category-fixtures/repair-b.png'] as $id => $sourceIcon) {
        $serviceNodes[$id] = $wideNodes[$id] + ['icon' => $sourceIcon];
    }
    $servicePostCount = 0;
    $serviceGetPaths = [];
    $serviceMetadataMode = 'normal';
    $serviceImageMode = 'normal';
    $servicePolicy = new SourcePolicy(static fn(string $host): array => $host === 'private-icons.example'
        ? ['127.0.0.1'] : ['93.184.216.34']);
    $serviceTransport = static function (array $endpoint, string $address, string $method, array $headers, string $body)
        use (&$serviceNodes, &$servicePostCount, &$serviceGetPaths, &$serviceMetadataMode, &$serviceImageMode, $mapPath): array {
        mapperExpect(DB::connection()->transactionLevel() === 0, 'category service network ran inside a transaction');
        $probe = fopen($mapPath . '.lock', 'r+b');
        mapperExpect(is_resource($probe) && flock($probe, LOCK_EX | LOCK_NB), 'category service network ran under the map lock');
        flock($probe, LOCK_UN);
        fclose($probe);
        $path = parse_url($endpoint['url'], PHP_URL_PATH);
        if ($method === 'GET') {
            $serviceGetPaths[] = $path;
            return ['status' => $serviceImageMode === 'http' ? 403 : 200,
                'content_type' => $serviceImageMode === 'svg' ? 'image/svg+xml' : 'image/png', 'connected_ip' => $address,
                'body' => match ($serviceImageMode) {
                    'svg' => '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>',
                    'malformed' => 'not a bitmap',
                    'oversized' => str_repeat('x', 5242881),
                    default => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jAusAAAAASUVORK5CYII=', true),
                }];
        }
        $servicePostCount++;
        parse_str($body, $fields);
        mapperExpect($method === 'POST' && $path === '/shared/commodity/items'
            && ($fields['pika_category_tree'] ?? null) === '3' && isset($fields['sign'])
            && !array_key_exists('app_key', $fields)
            && array_diff(array_keys($fields), ['app_id', 'pika_category_tree', 'pika_category_ids', 'sign']) === [],
            'category metadata used an unexpected route, product action or unsigned version');
        $ids = array_map('intval', explode(',', $fields['pika_category_ids']));
        $selected = array_map(static fn(int $id): array => $serviceNodes[$id], $ids);
        $data = ['schema' => 3, 'capability' => 'pika_category_icons', 'categories' => $selected];
        if ($serviceMetadataMode === 'missing') array_pop($data['categories']);
        if ($serviceMetadataMode === 'extra') $data['extra'] = true;
        if ($serviceMetadataMode === 'string_schema') $data['schema'] = '3';
        if ($serviceMetadataMode === 'bad_icon') $data['categories'][0]['icon'] = 'javascript:alert(1)';
        return ['status' => $serviceMetadataMode === 'http' ? 403 : 200,
            'content_type' => 'application/json', 'connected_ip' => $address,
            'body' => json_encode(['code' => 200, 'data' => $data], JSON_THROW_ON_ERROR)];
    };
    $serviceBudget = new RunBudget();
    $serviceHttp = new SafeHttpClient($servicePolicy, $serviceTransport, $serviceBudget);
    $serviceGateway = new SharedGateway($serviceHttp, $servicePolicy);
    $serviceImages = new ImageCache($serviceHttp, $serviceBudget);
    $categoryIcons = new CategoryIcons($serviceGateway, $serviceImages, $mapper);
    $creationNodes = array_map(static function (array $node): array { unset($node['icon']); return $node; },
        [$serviceNodes[13001], $serviceNodes[13002]]);
    $beforeServiceCreation = $localSnapshot();
    $createdIcons = $categoryIcons->forCreation($wideSource, $creationNodes);
    mapperExpect($servicePostCount === 1 && $serviceGetPaths === ['/category-fixtures/create.png']
        && $createdIcons[13002] === '/favicon.ico'
        && is_file(BASE_PATH . ltrim($createdIcons[13001], '/'))
        && $localSnapshot() === $beforeServiceCreation,
        'creation preparation did not return exact cached/default icons without database/map writes');
    mapperExpect($categoryIcons->forCreation($wideSource, $creationNodes) === $createdIcons
        && $servicePostCount === 1 && count($serviceGetPaths) === 1,
        'creation memoization requested the same metadata or bitmap again');
    $emptyCreation = $serviceNodes[13003];
    unset($emptyCreation['icon']);
    mapperExpect($categoryIcons->forCreation($wideSource, [$emptyCreation]) === [13003 => '/favicon.ico']
        && count($serviceGetPaths) === 1, 'empty upstream icon downloaded a default image');
    foreach (['name' => 'remote-renamed', 'pid' => 999, 'sort' => 99] as $field => $changedValue) {
        $beforeDriftGets = count($serviceGetPaths);
        $originalValue = $serviceNodes[13001][$field];
        $serviceNodes[13001][$field] = $changedValue;
        $freshIcons = new CategoryIcons($serviceGateway, $serviceImages, $mapper);
        mapperFails(static fn() => $freshIcons->forCreation($wideSource, $creationNodes), 'remote frozen-node drift was accepted');
        mapperExpect(count($serviceGetPaths) === $beforeDriftGets && $localSnapshot() === $beforeServiceCreation,
            'frozen-node drift downloaded an image or wrote a category');
        $serviceNodes[13001][$field] = $originalValue;
    }
    $memoDrift = $creationNodes;
    $memoDrift[0]['sort'] = 88;
    $beforeMemoPost = $servicePostCount;
    mapperFails(static fn() => $categoryIcons->forCreation($wideSource, $memoDrift), 'memo accepted different frozen node semantics');
    mapperExpect($servicePostCount === $beforeMemoPost, 'memo drift made an unnecessary metadata request');
    $serviceCreationMapper = new PlannedCategoryMapper(null, static fn(Shared $selected, array $nodes): array =>
        $categoryIcons->forCreation($selected, $nodes));
    $serviceCreationTarget = ['mode' => 'mirror', 'path' => $creationNodes, 'category_icons' => true];
    $serviceCreationLeaf = $serviceCreationMapper->resolve($wideSource, $wideAlias, $serviceCreationTarget,
        'service-icon-default', $widePlan);
    mapperExpect((string)Category::query()->findOrFail($serviceCreationLeaf->pid)->icon === $createdIcons[13001]
        && (string)$serviceCreationLeaf->icon === '/favicon.ico', 'prepared bitmap was not used by real category creation');
    Category::query()->where('id', $serviceCreationLeaf->id)->update(['icon' => '/manual-service.png']);
    $serviceManualBefore = $localSnapshot();
    $beforeMemoPost = $servicePostCount;
    $serviceCreationMapper->resolve($wideSource, $wideAlias, $serviceCreationTarget, 'service-icon-default', $widePlan);
    mapperExpect($localSnapshot() === $serviceManualBefore && $servicePostCount === $beforeMemoPost,
        'existing service-backed creation overwrote a manual image or requested metadata');

    $failureCreation = $serviceNodes[13004];
    unset($failureCreation['icon']);
    foreach (['missing', 'extra', 'string_schema', 'bad_icon', 'http'] as $metadataFailure) {
        $serviceMetadataMode = $metadataFailure;
        $beforeFailureGets = count($serviceGetPaths);
        $beforeFailurePost = $servicePostCount;
        mapperFails(static fn() => (new CategoryIcons($serviceGateway, $serviceImages, $mapper))
            ->forCreation($wideSource, [$failureCreation]), 'unsafe or failed metadata was silently accepted');
        mapperExpect(count($serviceGetPaths) === $beforeFailureGets && $servicePostCount === $beforeFailurePost + 1
            && $localSnapshot() === $serviceManualBefore, 'metadata failure retried, downloaded or changed categories');
    }
    $serviceMetadataMode = 'normal';
    foreach (['http', 'svg', 'malformed', 'oversized'] as $imageFailure) {
        $serviceImageMode = $imageFailure;
        $serviceNodes[13004]['icon'] = '/category-fixtures/failure-' . $imageFailure . '.png';
        $beforeFailureGets = count($serviceGetPaths);
        mapperFails(static fn() => (new CategoryIcons($serviceGateway, $serviceImages, $mapper))
            ->forCreation($wideSource, [$failureCreation]), 'failed or unsafe bitmap was reported as localized');
        mapperExpect(count($serviceGetPaths) === $beforeFailureGets + 1 && $localSnapshot() === $serviceManualBefore,
            'bitmap failure retried or changed category state');
    }
    $serviceImageMode = 'normal';
    $serviceNodes[13004]['icon'] = 'https://private-icons.example/image.png';
    $beforeFailureGets = count($serviceGetPaths);
    mapperFails(static fn() => (new CategoryIcons($serviceGateway, $serviceImages, $mapper))
        ->forCreation($wideSource, [$failureCreation]), 'private-address bitmap escaped the existing source policy');
    mapperExpect(count($serviceGetPaths) === $beforeFailureGets, 'SSRF rejection reached the injected HTTP transport');

    $serviceManualId = $iconTargets[3]['local_id'];
    Category::query()->where('id', $serviceManualId)->update(['icon' => '/manual-maintenance.png']);
    $beforeServiceMaintenance = $localSnapshot();
    $serviceFilesBefore = $iconFiles();
    $beforePreviewGets = count($serviceGetPaths);
    $beforePreviewChanges = DB::connection()->getPdo()->query('SELECT total_changes()')->fetchColumn();
    $iconPlan = $categoryIcons->preview($wideSource, [11004, 11003, 11002, 11001, 11000]);
    mapperExpect($iconPlan['ids'] === [11000, 11001, 11002, 11003, 11004]
        && $iconPlan['skipped'] === ['manual' => 1, 'upstream_default' => 2]
        && count($serviceGetPaths) === $beforePreviewGets && $localSnapshot() === $beforeServiceMaintenance
        && DB::connection()->getPdo()->query('SELECT total_changes()')->fetchColumn() === $beforePreviewChanges,
        'icon preview downloaded images, wrote rows or omitted manual/default skips');
    $beforePreparePosts = $servicePostCount;
    $iconReceipt = $categoryIcons->prepare($wideSource, $iconPlan);
    mapperExpect(count($iconReceipt['changes']) === 2 && count($iconReceipt['cache']) === 2
        && count($serviceGetPaths) === $beforePreviewGets + 2 && $servicePostCount === $beforePreparePosts
        && $localSnapshot() === $beforeServiceMaintenance && $iconFiles() === $serviceFilesBefore
        && !in_array('/category-fixtures/manual-no-download.png', $serviceGetPaths, true),
        'prepare did not download only eligible images or changed DB/map/job state');
    mapperExpect($categoryIcons->prepare($wideSource, $iconPlan) === $iconReceipt
        && count($serviceGetPaths) === $beforePreviewGets + 2 && $servicePostCount === $beforePreparePosts,
        'repeated prepare did not reuse the existing validated bitmap cache');
    $beforeServiceRequests = [$servicePostCount, count($serviceGetPaths)];
    $serviceApplied = $categoryIcons->apply($wideSource, $iconReceipt);
    mapperExpect($serviceApplied === ['status' => 'applied', 'changed' => 2, 'total' => 2,
        'skipped' => ['manual' => 1, 'upstream_default' => 2]]
        && $categoryIcons->apply($wideSource, $iconReceipt)['status'] === 'already_applied'
        && (string)Category::query()->findOrFail($serviceManualId)->icon === '/manual-maintenance.png'
        && [$servicePostCount, count($serviceGetPaths)] === $beforeServiceRequests,
        'service apply/replay changed a manual/default image or performed network I/O');
    mapperExpect($categoryIcons->apply($wideSource, $iconReceipt, true)['status'] === 'rolled_back'
        && $categoryIcons->apply($wideSource, $iconReceipt, true)['status'] === 'already_rolled_back'
        && $localSnapshot() === $beforeServiceMaintenance, 'service rollback did not restore only the original icon delta');

    $beforeInvalidPlanPost = $servicePostCount;
    mapperFails(static fn() => $categoryIcons->preview($wideSource, range(11000, 11016)), 'preview exceeded the 16-category bound');
    mapperFails(static fn() => $categoryIcons->prepare($staleIconSource, $iconPlan), 'prepare accepted a stale source fingerprint');
    mapperFails(static fn() => $categoryIcons->apply($staleIconSource, $iconReceipt), 'apply accepted a stale source fingerprint');
    mapperExpect($servicePostCount === $beforeInvalidPlanPost, 'invalid scope or stale source caused metadata requests');
    Category::query()->where('id', $iconTargets[0]['local_id'])->update(['icon' => '/changed-after-preview.png']);
    $beforeLocalDrift = $localSnapshot();
    mapperFails(static fn() => $categoryIcons->prepare($wideSource, $iconPlan), 'prepare accepted a local icon changed after preview');
    mapperFails(static fn() => $categoryIcons->apply($wideSource, $iconReceipt), 'apply overwrote a local icon changed after preparation');
    mapperExpect($localSnapshot() === $beforeLocalDrift && [$servicePostCount, count($serviceGetPaths)] === $beforeServiceRequests,
        'local drift caused partial writes or fresh requests');
    Category::query()->where('id', $iconTargets[0]['local_id'])->update(['icon' => '/favicon.ico']);
    foreach (['name' => 'preview-renamed', 'pid' => 0] as $field => $value) {
        $original = $serviceNodes[11000][$field];
        $serviceNodes[11000][$field] = $value;
        mapperFails(static fn() => $categoryIcons->preview($wideSource, [11000]), 'preview accepted upstream name or parent drift');
        $serviceNodes[11000][$field] = $original;
    }
    foreach (['identity', 'icon', 'count', 'cache_path', 'skipped'] as $tamper) {
        $tamperedReceipt = $iconReceipt;
        match ($tamper) {
            'identity' => $tamperedReceipt['changes'][0]['local_id'] = $serviceManualId,
            'icon' => $tamperedReceipt['changes'][0]['new_icon'] = $iconA,
            'count' => array_pop($tamperedReceipt['changes']),
            'cache_path' => $tamperedReceipt['cache'][0]['path'] = '/favicon.ico',
            'skipped' => $tamperedReceipt['plan']['skipped']['manual'] = 0,
        };
        mapperFails(static fn() => $categoryIcons->apply($wideSource, $tamperedReceipt), 'tampered receipt passed its target/cache binding');
    }
    $receiptCachePath = BASE_PATH . ltrim($iconReceipt['cache'][0]['path'], '/');
    $receiptCacheBytes = (string)file_get_contents($receiptCachePath);
    file_put_contents($receiptCachePath, $receiptCacheBytes . 'changed');
    mapperFails(static fn() => $categoryIcons->apply($wideSource, $iconReceipt), 'changed cached bytes passed apply verification');
    file_put_contents($receiptCachePath, $receiptCacheBytes);
    mapperExpect($localSnapshot() === $beforeServiceMaintenance && $iconFiles() === $serviceFilesBefore
        && DB::table('commodity')->orderBy('id')->get()->toJson() === $iconProductsBefore
        && DB::table('order')->orderBy('id')->get()->toJson() === $iconOrdersBefore,
        'service failure changed products, orders, categories, mapping or existing jobs/snapshots');

    $skipOnlyPlan = $categoryIcons->preview($wideSource, [11001, 11002, 11003]);
    $skipOnlyReceipt = $categoryIcons->prepare($wideSource, $skipOnlyPlan);
    mapperExpect($skipOnlyReceipt['changes'] === [] && $skipOnlyReceipt['cache'] === []
        && $categoryIcons->apply($wideSource, $skipOnlyReceipt)['status'] === 'no_changes'
        && $categoryIcons->apply($wideSource, $skipOnlyReceipt, true)['status'] === 'no_changes'
        && $localSnapshot() === $beforeServiceMaintenance, 'all-default/manual plan changed state or was not an explicit no-op');
    fwrite(STDOUT, "local planned category mapper behavior: PASS\n");
}
