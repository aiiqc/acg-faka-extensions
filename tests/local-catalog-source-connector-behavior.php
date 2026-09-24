<?php
declare(strict_types=1);

namespace {
    $fixtureRoot = sys_get_temp_dir() . '/pika-source-connector-' . bin2hex(random_bytes(6));
    $webUid = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $webGid = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $officialRoot = realpath((string)(getenv('ACG_FAKA_OFFICIAL_ROOT') ?: ''));
    if (!is_int($webUid) || !is_int($webGid)
        || !function_exists('posix_setuid') || posix_geteuid() !== 0
        || $officialRoot === false || !is_file($officialRoot . '/vendor/autoload.php')) {
        throw new RuntimeException('source connector fixture requires root, WEB_UID WEB_GID and ACG_FAKA_OFFICIAL_ROOT');
    }
    if (!mkdir($fixtureRoot, 0750, true) && !is_dir($fixtureRoot)) {
        throw new RuntimeException('unable to create source connector fixture');
    }
    chown($fixtureRoot, $webUid);
    chgrp($fixtureRoot, $webGid);
    chmod($fixtureRoot, 0750);
    define('BASE_PATH', $fixtureRoot . '/');
}

namespace App\Util {
    final class Currency
    {
        public static function code(): string { return 'CNY'; }
        public static function rate(): string { return '1'; }
    }

    final class SharedCurrency
    {
        public static function resolveFactor(
            string $upCurrency,
            string $manualRate,
            string $siteCode,
            string $siteRate,
        ): ?string {
            if ($upCurrency === $siteCode) return '1';
            return (float)$manualRate > 0 ? $manualRate : null;
        }
    }

    final class Schema
    {
        public static function ensureSharedCurrency(): void {}
    }

    final class Date
    {
        public static function current(?string $format = null): string
        {
            return '2026-09-01 00:00:00';
        }
    }

    final class Str
    {
        public static function generateSignature(array $data, mixed $appKey): string
        {
            unset($data['sign']);
            ksort($data);
            foreach ($data as $key => $value) {
                if ($value === '') unset($data[$key]);
            }
            return md5(urldecode(http_build_query($data)) . '&key=' . (string)$appKey);
        }
    }
}

namespace {
    require $officialRoot . '/vendor/autoload.php';
    require dirname(__DIR__) . '/manager/site/local-extensions/src/PathGuard.php';
    require dirname(__DIR__) . '/manager/site/local-extensions/src/AtomicJson.php';
    require dirname(__DIR__) . '/extensions/PikaSupplySync/bootstrap.php';
    require dirname(__DIR__) . '/extensions/PikaCatalogHub/bootstrap.php';

    use App\Model\Shared;
    use Illuminate\Database\Capsule\Manager as DB;
    use Illuminate\Database\Schema\Blueprint;
    use Pika\LocalExtensions\PikaCatalogHub\Service\SafeSourceConnector;
    use Pika\LocalExtensions\PikaCatalogHub\Service\ConfigRepository;
    use Pika\LocalExtensions\PikaCatalogHub\Service\JobService;
    use Pika\LocalExtensions\PikaCatalogHub\Service\SourceConnectLock;
    use Pika\LocalExtensions\PikaSupplySync\Service\SourceIdentity;
    use Pika\LocalExtensions\PikaSupplySync\Service\StateStore as SupplyStateStore;

    function connectorExpect(bool $condition, string $message): void
    {
        if (!$condition) throw new RuntimeException($message);
    }

    function connectorFails(callable $callback, string $message): void
    {
        try {
            $callback();
        } catch (Throwable) {
            return;
        }
        throw new RuntimeException($message);
    }

    function connectorFailsWithMessage(callable $callback, string $expected, string $message): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            connectorExpect($exception->getMessage() === $expected, $message);
            return;
        }
        throw new RuntimeException($message);
    }

    function connectorSqliteChanges(): int
    {
        $statement = DB::connection()->getPdo()->query('SELECT total_changes()');
        if ($statement === false) {
            throw new RuntimeException('unable to read SQLite change count');
        }
        $value = $statement->fetchColumn();
        if (!is_int($value) && !is_string($value)) {
            throw new RuntimeException('SQLite change count is invalid');
        }
        return (int)$value;
    }

    /** @return array{dev:int,ino:int,size:int,mtime:int,ctime:int} */
    function connectorFileIdentity(string $path): array
    {
        clearstatcache(true, $path);
        $metadata = lstat($path);
        if (!is_array($metadata)) {
            throw new RuntimeException('unable to read fixture file identity');
        }
        return [
            'dev' => (int)$metadata['dev'],
            'ino' => (int)$metadata['ino'],
            'size' => (int)$metadata['size'],
            'mtime' => (int)$metadata['mtime'],
            'ctime' => (int)$metadata['ctime'],
        ];
    }

    $db = new DB();
    $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $db->setAsGlobal();
    $db->bootEloquent();
    DB::connection()->getSchemaBuilder()->create('shared', static function (Blueprint $table): void {
        $table->increments('id');
        $table->unsignedInteger('type');
        $table->string('name');
        $table->string('domain');
        $table->string('app_id');
        $table->string('app_key');
        $table->string('create_time');
        $table->decimal('balance', 18, 2);
        $table->string('currency', 8)->default('CNY');
        $table->decimal('currency_rate', 18, 6)->default(0);
    });
    DB::connection()->getSchemaBuilder()->create('commodity', static function (Blueprint $table): void {
        $table->increments('id');
        $table->unsignedInteger('shared_id');
    });
    $stateControl = '/var/lib/pika-local-extensions';
    $stateSites = $stateControl . '/sites';
    if (!is_dir($stateSites) && !mkdir($stateSites, 0755, true) && !is_dir($stateSites)) {
        throw new RuntimeException('unable to create source connector state root');
    }
    chown($stateControl, 0); chgrp($stateControl, 0); chmod($stateControl, 0755);
    chown($stateSites, 0); chgrp($stateSites, 0); chmod($stateSites, 0755);
    $stateSite = $stateSites . '/' . hash('sha256', realpath($fixtureRoot));
    mkdir($stateSite . '/runtime', 0750, true);
    chown($stateSite, 0); chgrp($stateSite, 0); chmod($stateSite, 0755);
    chown($stateSite . '/runtime', $webUid);
    chgrp($stateSite . '/runtime', $webGid);
    chmod($stateSite . '/runtime', 0750);

    if (!posix_setgid($webGid) || !posix_setuid($webUid)) {
        throw new RuntimeException('unable to drop source connector fixture identity');
    }

    $calls = [];
    $resolverCalls = 0;
    $resolver = static function (string $host) use (&$resolverCalls): array {
        $resolverCalls++;
        return ['93.184.216.34'];
    };
    $transport = static function (
        array $endpoint,
        string $address,
        string $method,
        array $headers,
        string $body,
        int $maxBytes,
        int $connectTimeoutMs,
        int $requestTimeoutMs,
    ) use (&$calls): array {
        $calls[] = compact('endpoint', 'address', 'method', 'headers', 'body', 'maxBytes', 'connectTimeoutMs', 'requestTimeoutMs');
        $typeOne = str_ends_with($endpoint['url'], '/plugin/open-api/connect');
        $data = $typeOne
            ? ['username' => 'V4 Store', 'balance' => '8.50']
            : ['shopName' => 'Acg Store', 'balance' => '9.25'];
        return [
            'status' => 200,
            'content_type' => 'application/json',
            'body' => json_encode(['code' => 200, 'data' => $data], JSON_THROW_ON_ERROR),
            'connected_ip' => $address,
        ];
    };
    $connector = new SafeSourceConnector($transport, $resolver);

    $base = [
        'app_id' => 'merchant-a',
        'app_key' => 'secret-value',
        'currency' => 'CNY',
        'currency_rate' => '',
    ];
    foreach ([
        0 => '/shared/authentication/connect',
        2 => '/plugin/SharedStock/api/connect',
        1 => '/plugin/open-api/connect',
    ] as $type => $path) {
        $result = $connector->connect($base + [
            'type' => (string)$type,
            'domain' => 'https://source-' . $type . '.example',
        ]);
        connectorExpect(($result['source_id'] ?? 0) === $type + 1 || (int)$result['source_id'] > 0, 'source id was not returned');
        $call = $calls[array_key_last($calls)];
        connectorExpect($call['endpoint']['url'] === 'https://source-' . $type . '.example' . $path, 'protocol endpoint is wrong');
        connectorExpect($call['requestTimeoutMs'] <= 120000, 'connection exceeded the FPM-safe total budget');
        if ($type === 1) {
            connectorExpect($call['body'] === '', 'V4 key was placed in the request body');
            connectorExpect(count(array_filter($call['headers'], static fn(string $header): bool => str_starts_with($header, 'Api-Signature: '))) === 1, 'V4 signature header is missing');
        } else {
            parse_str($call['body'], $form);
            connectorExpect(($form['app_key'] ?? null) === 'secret-value', 'legacy protocol key is missing from its required body');
            connectorExpect(isset($form['sign']), 'legacy protocol signature is missing');
        }
    }
    connectorExpect(Shared::query()->count() === 3, 'three protocol sources were not saved');
    (new ConfigRepository())->upsertAlias(3, '海外号批发店铺');

    Shared::query()->where('id', 1)->update(['domain' => 'https://source-0.example:443']);
    $repeat = $connector->connect($base + ['type' => '0', 'domain' => 'https://source-0.example']);
    connectorExpect(
        (int)$repeat['source_id'] === 1 && Shared::query()->count() === 3,
        'pre-existing explicit HTTPS port bypassed canonical source deduplication',
    );
    $explicitDefaultPort = $connector->connect($base + [
        'type' => '0',
        'domain' => 'https://source-0.example:443',
    ]);
    connectorExpect(
        (int)$explicitDefaultPort['source_id'] === 1 && Shared::query()->count() === 3,
        'explicit default HTTPS port bypassed canonical source deduplication',
    );
    Shared::query()->where('id', 2)->update(['domain' => 'https://[2606:4700:4700::1111]:443']);
    $explicitIpv6Port = $connector->connect($base + [
        'type' => '2',
        'domain' => 'https://[2606:4700:4700::1111]',
    ]);
    connectorExpect(
        (int)$explicitIpv6Port['source_id'] === 2 && Shared::query()->count() === 3,
        'pre-existing explicit IPv6 HTTPS port bypassed canonical source deduplication',
    );

    $supplyState = new SupplyStateStore();
    $zeroRateCategories = ['__root__' => 801, 'category:' . hash('sha256', 'zero-rate-category') => 802];
    $zeroRateState = [
        'cursor' => 'zero-rate-cursor',
        'priority_cursor' => 'zero-rate-priority',
        'categories' => $zeroRateCategories,
        'catalog_hash' => hash('sha256', 'zero-rate-catalog'),
        'last_run' => '2026-09-01 00:00:00',
        'last_result' => [],
    ];
    $supplyState->write(3, $zeroRateState);
    $zeroRateFingerprint = SourceIdentity::fingerprint(Shared::query()->find(3));
    $zeroRateSourceBefore = (array)Shared::query()->findOrFail(3)->getAttributes();
    $zeroRateCallsBefore = count($calls);
    $zeroRateResolverCallsBefore = $resolverCalls;
    $zeroRateDbChangesBefore = connectorSqliteChanges();
    $configPath = $stateSite . '/runtime/extensions/PikaCatalogHub/config.json';
    $zeroRateConfigIdentityBefore = connectorFileIdentity($configPath);
    $zeroRateConfigHashBefore = hash_file('sha256', $configPath);
    $zeroRateEdit = $connector->update(3, [
        'alias' => '海外号批发店铺',
        'type' => '1',
        'domain' => 'https://source-1.example',
        'app_id' => 'merchant-a',
        'app_key' => '',
        'currency' => 'CNY',
        'currency_rate' => '0.000000',
    ]);
    $zeroRateSource = Shared::query()->find(3);
    connectorExpect(
        $zeroRateSource !== null
            && $zeroRateSource->app_key === 'secret-value'
            && (string)$zeroRateSource->currency === 'CNY'
            && (float)$zeroRateSource->currency_rate === 0.0
            && hash_equals($zeroRateFingerprint, SourceIdentity::fingerprint($zeroRateSource))
            && ($zeroRateEdit['source']['currency_rate'] ?? null) === '0.000000'
            && !str_contains(json_encode($zeroRateEdit, JSON_THROW_ON_ERROR), 'secret-value')
            && count($calls) === $zeroRateCallsBefore
            && $resolverCalls === $zeroRateResolverCallsBefore
            && connectorSqliteChanges() === $zeroRateDbChangesBefore
            && (array)$zeroRateSource->getAttributes() === $zeroRateSourceBefore
            && connectorFileIdentity($configPath) === $zeroRateConfigIdentityBefore
            && hash_file('sha256', $configPath) === $zeroRateConfigHashBefore
            && $supplyState->read(3) === $zeroRateState,
        'same-value edit wrote state, used DNS/upstream I/O, returned the key, or reset synchronization ownership',
    );

    Shared::query()->where('id', 3)->update([
        'currency' => 'USD',
        'currency_rate' => '0.000000',
    ]);
    $invalidRateSourceBefore = (array)Shared::query()->findOrFail(3)->getAttributes();
    $invalidRateFingerprint = SourceIdentity::fingerprint(Shared::query()->find(3));
    $invalidRateCallsBefore = count($calls);
    $invalidRateResolverCallsBefore = $resolverCalls;
    $invalidRateDbChangesBefore = connectorSqliteChanges();
    $invalidRateConfigIdentityBefore = connectorFileIdentity($configPath);
    $invalidRateConfigHashBefore = hash_file('sha256', $configPath);
    $supplyStatePath = $stateSite . '/runtime/extensions/PikaSupplySync/source-3.json';
    $invalidRateSupplyIdentityBefore = connectorFileIdentity($supplyStatePath);
    $invalidRateSupplyHashBefore = hash_file('sha256', $supplyStatePath);
    connectorFailsWithMessage(
        static fn() => $connector->update(3, [
            'alias' => '海外号批发店铺',
            'type' => '1',
            'domain' => 'https://source-1.example',
            'app_id' => 'merchant-a',
            'app_key' => '',
            'currency' => 'USD',
            'currency_rate' => '0.000000',
        ]),
        '该货币组合需要填写有效结算汇率。',
        'same-value edit bypassed the cross-currency rate requirement',
    );
    connectorExpect(
        (array)Shared::query()->findOrFail(3)->getAttributes() === $invalidRateSourceBefore
            && hash_equals($invalidRateFingerprint, SourceIdentity::fingerprint(Shared::query()->find(3)))
            && count($calls) === $invalidRateCallsBefore
            && $resolverCalls === $invalidRateResolverCallsBefore
            && connectorSqliteChanges() === $invalidRateDbChangesBefore
            && connectorFileIdentity($configPath) === $invalidRateConfigIdentityBefore
            && hash_file('sha256', $configPath) === $invalidRateConfigHashBefore
            && connectorFileIdentity($supplyStatePath) === $invalidRateSupplyIdentityBefore
            && hash_file('sha256', $supplyStatePath) === $invalidRateSupplyHashBefore
            && $supplyState->read(3) === $zeroRateState,
        'rejected same-value invalid rate changed source/config state or used upstream I/O',
    );
    Shared::query()->where('id', 3)->update([
        'currency' => 'CNY',
        'currency_rate' => '0.000000',
    ]);

    Shared::query()->where('id', 3)->update(['app_id' => 'merchant-drifted']);
    $driftedSourceBefore = (array)Shared::query()->findOrFail(3)->getAttributes();
    $driftCallsBefore = count($calls);
    $driftResolverCallsBefore = $resolverCalls;
    $driftDbChangesBefore = connectorSqliteChanges();
    $driftConfigIdentityBefore = connectorFileIdentity($configPath);
    connectorFailsWithMessage(
        static fn() => $connector->update(3, [
            'alias' => '海外号批发店铺',
            'type' => '1',
            'domain' => 'https://source-1.example',
            'app_id' => 'merchant-a',
            'app_key' => '',
            'currency' => 'CNY',
            'currency_rate' => '0.000000',
        ]),
        '协议、店铺地址和商户 ID 不支持原地改绑，请按备份维护流程处理。',
        'same-value shortcut accepted a binding that drifted before the locked re-read',
    );
    connectorExpect(
        (array)Shared::query()->findOrFail(3)->getAttributes() === $driftedSourceBefore
            && count($calls) === $driftCallsBefore
            && $resolverCalls === $driftResolverCallsBefore
            && connectorSqliteChanges() === $driftDbChangesBefore
            && connectorFileIdentity($configPath) === $driftConfigIdentityBefore,
        'rejected binding drift changed source/config state or used upstream I/O',
    );
    Shared::query()->where('id', 3)->update(['app_id' => 'merchant-a']);

    connectorFails(static fn() => $connector->update(3, [
        'alias' => '海外号批发店铺',
        'type' => '1',
        'domain' => 'https://source-1.example',
        'app_id' => 'merchant-a',
        'app_key' => '',
        'currency' => 'USD',
        'currency_rate' => '0.000000',
    ]), 'cross-currency explicit zero rate was accepted');
    connectorExpect(
        (string)Shared::query()->find(3)?->currency === 'CNY'
            && (float)Shared::query()->find(3)?->currency_rate === 0.0
            && hash_equals($zeroRateFingerprint, SourceIdentity::fingerprint(Shared::query()->find(3)))
            && $supplyState->read(3) === $zeroRateState,
        'rejected cross-currency zero edit changed the source or synchronization ownership',
    );
    $supplyState->write(3, [
        'cursor' => 'legacy-cursor',
        'priority_cursor' => '',
        'categories' => [],
        'catalog_hash' => hash('sha256', 'legacy-catalog'),
        'last_run' => '2026-09-01 00:00:00',
        'last_result' => [],
    ]);
    connectorFails(static fn() => $connector->update(3, [
        'alias' => '海外号批发店铺',
        'type' => '0',
        'domain' => 'https://edited-source.example',
        'app_id' => 'merchant-edited',
        'app_key' => '',
        'currency' => 'USD',
        'currency_rate' => '2.5',
    ]), 'source binding identity was editable after save');
    $callsBeforeRename = count($calls);
    $resolverCallsBeforeRename = $resolverCalls;
    Shared::query()->where('id', 3)->update(['app_key' => 'old']);
    $sourceBeforeRename = (array)Shared::query()->findOrFail(3)->getAttributes();
    $dbChangesBeforeRename = connectorSqliteChanges();
    $offlineConnector = new SafeSourceConnector(
        $transport,
        static function (string $host): array {
            throw new RuntimeException('alias-only edit must not resolve upstream DNS');
        },
    );
    $renamed = $offlineConnector->update(3, [
        'alias' => '北美号码批发',
        'type' => '1',
        'domain' => 'https://source-1.example',
        'app_id' => 'merchant-a',
        'app_key' => '',
        'currency' => 'CNY',
        'currency_rate' => '0.000000',
    ]);
    connectorExpect(
        count($calls) === $callsBeforeRename
            && $resolverCalls === $resolverCallsBeforeRename
            && ($renamed['source']['alias'] ?? null) === '北美号码批发'
            && array_column((new ConfigRepository())->get()['aliases'], 'alias', 'source_id')[3] === '北美号码批发'
            && (array)Shared::query()->findOrFail(3)->getAttributes() === $sourceBeforeRename
            && connectorSqliteChanges() === $dbChangesBeforeRename,
        'alias-only edit used DNS or upstream I/O, rejected a legacy key, or changed shared source settings',
    );
    Shared::query()->where('id', 3)->update(['app_key' => 'secret-value']);
    $callsBeforeSourceSettingsEdit = count($calls);
    $resolverCallsBeforeSourceSettingsEdit = $resolverCalls;
    $updated = $connector->update(3, [
        'alias' => '北美号码批发',
        'type' => '1',
        'domain' => 'https://source-1.example',
        'app_id' => 'merchant-a',
        'app_key' => '',
        'currency' => 'USD',
        'currency_rate' => '2.5',
    ]);
    $updatedJson = json_encode($updated, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    connectorExpect(!str_contains($updatedJson, 'secret-value'), 'source update returned the preserved key');
    $edited = Shared::query()->find(3);
    connectorExpect(
        $edited !== null
            && (int)$edited->type === 1
            && $edited->domain === 'https://source-1.example'
            && $edited->app_id === 'merchant-a'
            && $edited->app_key === 'secret-value'
            && (string)$edited->currency === 'USD'
            && (float)$edited->currency_rate === 2.5
            && count($calls) === $callsBeforeSourceSettingsEdit + 1
            && $resolverCalls > $resolverCallsBeforeSourceSettingsEdit,
        'safe source edit did not persist, preserve the blank key, or run full upstream validation',
    );
    connectorExpect(
        ($updated['source']['alias'] ?? null) === '北美号码批发'
            && array_column((new ConfigRepository())->get()['aliases'], 'alias', 'source_id')[3] === '北美号码批发',
        'source edit did not persist and return the Pika alias',
    );
    connectorExpect(
        $supplyState->read(3) === [
            'cursor' => '',
            'priority_cursor' => '',
            'categories' => [],
            'catalog_hash' => '',
            'last_run' => '',
            'last_result' => [],
        ],
        'source setting edit retained stale synchronization progress',
    );

    (new ConfigRepository())->upsertAlias(2, '活动任务货源');
    $activeSource = Shared::query()->findOrFail(2);
    $activeJobs = new JobService();
    $activeEdit = static fn(string $alias): array => $connector->update(2, [
        'alias' => $alias,
        'type' => '2',
        'domain' => 'https://[2606:4700:4700::1111]',
        'app_id' => 'merchant-a',
        'app_key' => '',
        'currency' => 'CNY',
        'currency_rate' => '',
    ]);
    $activeSourceBeforeRejectedRenames = (array)$activeSource->getAttributes();
    $activeConfigBeforeRejectedRenames = (new ConfigRepository())->get();
    $callsBeforeRejectedRenames = count($calls);
    $resolverCallsBeforeRejectedRenames = $resolverCalls;
    $activeTask = $activeJobs->createAnalysis(
        2,
        'active-source',
        SourceIdentity::fingerprint($activeSource),
    );
    connectorFailsWithMessage(
        static fn() => $activeEdit('活动任务货源'),
        '该货源已有未完成的后台任务。',
        'same-value edit bypassed a queued CatalogHub task',
    );
    connectorFailsWithMessage(
        static fn() => $activeEdit('queued-display-name'),
        '该货源已有未完成的后台任务。',
        'display rename bypassed a queued CatalogHub task',
    );
    $analyzingTask = $activeJobs->beginWork(
        (string)$activeTask['task_id'],
        (int)$activeTask['revision'],
    );
    connectorFailsWithMessage(
        static fn() => $activeEdit('analyzing-display-name'),
        '该货源已有未完成的后台任务。',
        'display rename bypassed an analyzing CatalogHub task',
    );
    $pauseRequestedTask = $activeJobs->control(
        (string)$analyzingTask['task_id'],
        (int)$analyzingTask['revision'],
        'pause',
    );
    $pausedTask = $activeJobs->checkpoint(
        (string)$pauseRequestedTask['task_id'],
        (int)$pauseRequestedTask['revision'],
        ['total' => 0, 'processed' => 0, 'succeeded' => 0, 'failed' => 0, 'skipped' => 0],
    );
    connectorExpect(($pausedTask['state'] ?? null) === 'paused', 'active task did not reach the paused fixture state');
    connectorFailsWithMessage(
        static fn() => $activeEdit('paused-display-name'),
        '该货源已有未完成的后台任务。',
        'display rename bypassed a paused CatalogHub task',
    );
    connectorExpect(
        (array)Shared::query()->findOrFail(2)->getAttributes() === $activeSourceBeforeRejectedRenames
            && (new ConfigRepository())->get() === $activeConfigBeforeRejectedRenames
            && count($calls) === $callsBeforeRejectedRenames
            && $resolverCalls === $resolverCallsBeforeRejectedRenames,
        'rejected queued, analyzing, or paused display rename changed source, config, or upstream state',
    );
    $cancelledTask = $activeJobs->control(
        (string)$pausedTask['task_id'],
        (int)$pausedTask['revision'],
        'cancel',
    );
    connectorExpect(($cancelledTask['state'] ?? null) === 'cancelled', 'paused task did not reach the cancelled fixture state');
    $sourceBeforeCancelledHistoryRename = (array)Shared::query()->findOrFail(2)->getAttributes();
    $callsBeforeCancelledHistoryRename = count($calls);
    $resolverCallsBeforeCancelledHistoryRename = $resolverCalls;
    $renamedAfterCancellation = $activeEdit('cancelled-history-display-name');
    connectorExpect(
        ($renamedAfterCancellation['source']['alias'] ?? null) === 'cancelled-history-display-name'
            && array_column((new ConfigRepository())->get()['aliases'], 'alias', 'source_id')[2] === 'cancelled-history-display-name'
            && (array)Shared::query()->findOrFail(2)->getAttributes() === $sourceBeforeCancelledHistoryRename
            && count($calls) === $callsBeforeCancelledHistoryRename
            && $resolverCalls === $resolverCallsBeforeCancelledHistoryRename,
        'cancelled task blocked display rename or the rename changed source or upstream state',
    );

    $failedTask = $activeJobs->createAnalysis(
        2,
        'failed-history',
        SourceIdentity::fingerprint($activeSource),
    );
    $failedTask = $activeJobs->fail(
        (string)$failedTask['task_id'],
        (int)$failedTask['revision'],
        'TEST_FAILURE',
    );
    connectorExpect(($failedTask['state'] ?? null) === 'failed', 'task did not reach the failed fixture state');
    $sourceBeforeFailedHistoryRename = (array)Shared::query()->findOrFail(2)->getAttributes();
    $renamedAfterFailure = $activeEdit('failed-history-display-name');
    connectorExpect(
        ($renamedAfterFailure['source']['alias'] ?? null) === 'failed-history-display-name'
            && array_column((new ConfigRepository())->get()['aliases'], 'alias', 'source_id')[2] === 'failed-history-display-name'
            && (array)Shared::query()->findOrFail(2)->getAttributes() === $sourceBeforeFailedHistoryRename,
        'failed task blocked display rename or the rename changed the shared source',
    );

    $referencedFingerprint = SourceIdentity::fingerprint(Shared::query()->find(3));
    connectorFails(
        static fn() => $connector->update(3, [
            'alias' => '北美号码批发',
            'type' => '2',
            'domain' => 'https://replacement.example',
            'app_id' => 'replacement-merchant',
            'app_key' => '',
            'currency' => 'USD',
            'currency_rate' => '2.5',
        ]),
        'saved source identity was replaced in place',
    );
    connectorExpect(
        hash_equals($referencedFingerprint, SourceIdentity::fingerprint(Shared::query()->find(3))),
        'rejected source identity edit changed the source row',
    );
    $ownedCategoryMap = ['__root__' => 901, 'category:' . hash('sha256', 'owned-category') => 902];
    $supplyState->write(3, [
        'cursor' => 'credential-cursor',
        'priority_cursor' => 'priority-cursor',
        'categories' => $ownedCategoryMap,
        'catalog_hash' => hash('sha256', 'credential-catalog'),
        'last_run' => '2026-09-01 00:00:00',
        'last_result' => [],
    ]);
    $callsBeforeCredentialRotation = count($calls);
    $resolverCallsBeforeCredentialRotation = $resolverCalls;
    $rotated = $connector->update(3, [
        'alias' => '北美号码批发',
        'type' => '1',
        'domain' => 'https://source-1.example',
        'app_id' => 'merchant-a',
        'app_key' => 'rotated-secret',
        'currency' => 'USD',
        'currency_rate' => '2.5',
    ]);
    connectorExpect(
        Shared::query()->find(3)?->app_key === 'rotated-secret'
            && !str_contains(json_encode($rotated, JSON_THROW_ON_ERROR), 'rotated-secret')
            && count($calls) === $callsBeforeCredentialRotation + 1
            && $resolverCalls > $resolverCallsBeforeCredentialRotation,
        'credential-only source edit failed, skipped upstream validation, or returned the new key',
    );
    connectorExpect(
        $supplyState->read(3)['cursor'] === ''
            && $supplyState->read(3)['priority_cursor'] === ''
            && $supplyState->read(3)['categories'] === $ownedCategoryMap,
        'credential rotation retained stale progress or lost category ownership',
    );
    $supplyState->write(3, [
        'cursor' => 'old-pricing-cursor',
        'priority_cursor' => '',
        'categories' => $ownedCategoryMap,
        'catalog_hash' => hash('sha256', 'old-pricing-catalog'),
        'last_run' => '2026-09-01 00:00:00',
        'last_result' => [],
    ]);
    $repriced = $connector->update(3, [
        'alias' => '北美号码批发',
        'type' => '1',
        'domain' => 'https://source-1.example',
        'app_id' => 'merchant-a',
        'app_key' => '',
        'currency' => 'USD',
        'currency_rate' => '3.5',
    ]);
    connectorExpect(
        (float)Shared::query()->find(3)?->currency_rate === 3.5
            && $supplyState->read(3)['cursor'] === ''
            && $supplyState->read(3)['categories'] === $ownedCategoryMap
            && !str_contains(json_encode($repriced, JSON_THROW_ON_ERROR), 'rotated-secret'),
        'currency-rate edit retained stale pricing state, lost category ownership, or returned the stored key',
    );
    connectorFailsWithMessage(
        static fn() => $connector->update(3, [
            'alias' => '长期合作货源',
            'type' => '1',
            'domain' => 'https://source-1.example',
            'app_id' => 'merchant-a',
            'app_key' => 'combined-secret',
            'currency' => 'USD',
            'currency_rate' => '4',
        ]),
        '货源名称与密钥、货币或汇率的修改不能在一次请求中合并，请分两次保存。',
        'combined alias and source-setting edit was not rejected before writes',
    );
    connectorExpect(
        (float)Shared::query()->find(3)?->currency_rate === 3.5
            && Shared::query()->find(3)?->app_key === 'rotated-secret'
            && array_column((new ConfigRepository())->get()['aliases'], 'alias', 'source_id')[3] === '北美号码批发',
        'rejected combined edit changed the shared source or alias',
    );
    connectorFails(
        static fn() => $connector->update(3, [
            'alias' => '北美号码批发',
            'type' => '2',
            'domain' => 'https://replacement.example',
            'app_id' => 'replacement-merchant',
            'app_key' => '',
            'currency' => 'USD',
            'currency_rate' => '3.5',
        ]),
        'source binding edit bypassed SupplySync category ownership',
    );
    $referencedCommodityId = DB::table('commodity')->insertGetId(['shared_id' => 3]);
    $referencedCommodityBefore = (array)DB::table('commodity')->where('id', $referencedCommodityId)->first();
    $referencedSourceBefore = (array)Shared::query()->findOrFail(3)->getAttributes();
    $callsBeforeReferencedRename = count($calls);
    $resolverCallsBeforeReferencedRename = $resolverCalls;
    $renamedWithCommodity = $connector->update(3, [
            'alias' => '长期合作货源',
            'type' => '1',
            'domain' => 'https://source-1.example',
            'app_id' => 'merchant-a',
            'app_key' => '',
            'currency' => 'USD',
            'currency_rate' => '3.5',
        ]);
    connectorExpect(
        ($renamedWithCommodity['source']['alias'] ?? null) === '长期合作货源'
            && array_column((new ConfigRepository())->get()['aliases'], 'alias', 'source_id')[3] === '长期合作货源'
            && (array)DB::table('commodity')->where('id', $referencedCommodityId)->first() === $referencedCommodityBefore
            && (array)Shared::query()->findOrFail(3)->getAttributes() === $referencedSourceBefore
            && count($calls) === $callsBeforeReferencedRename
            && $resolverCalls === $resolverCallsBeforeReferencedRename,
        'display-name edit with an existing commodity changed business data or used upstream I/O',
    );
    connectorFails(
        static fn() => $connector->connect([
            ...$base,
            'type' => '0',
            'domain' => 'https://source-0.example',
            'app_id' => 'different-merchant',
        ]),
        'same domain with different credentials was accepted',
    );
    connectorFails(
        static fn() => $connector->connect([
            ...$base,
            'type' => '0',
            'domain' => 'https://short-key.example',
            'app_key' => 'short',
        ]),
        'short source key was accepted even though response leak detection would be ambiguous',
    );

    foreach ([
        'http://public.example',
        'https://public.example:8443',
        'https://public.example/path',
        'https://public.example////',
        'https://public.example/?query=1',
    ] as $unsafeDomain) {
        connectorFails(
            static fn() => $connector->connect($base + ['type' => '0', 'domain' => $unsafeDomain]),
            'unsafe source address was accepted',
        );
    }
    $privateConnector = new SafeSourceConnector($transport, static fn(string $host): array => ['127.0.0.1']);
    connectorFails(
        static fn() => $privateConnector->connect($base + ['type' => '0', 'domain' => 'https://private.example']),
        'private DNS result was accepted',
    );
    $rebindTransport = static fn(
        array $endpoint,
        string $address,
        string $method,
        array $headers,
        string $body,
        int $maxBytes,
        int $connectTimeoutMs,
        int $requestTimeoutMs,
    ): array => [
        'status' => 200,
        'content_type' => 'application/json',
        'body' => '{"code":200,"data":{"shopName":"Store","balance":0}}',
        'connected_ip' => '1.1.1.1',
    ];
    connectorFails(
        static fn() => (new SafeSourceConnector($rebindTransport, $resolver))->connect(
            $base + ['type' => '0', 'domain' => 'https://rebind.example'],
        ),
        'DNS rebinding response was accepted',
    );
    $redirectTransport = static fn(
        array $endpoint,
        string $address,
        string $method,
        array $headers,
        string $body,
        int $maxBytes,
        int $connectTimeoutMs,
        int $requestTimeoutMs,
    ): array => [
        'status' => 302,
        'content_type' => 'text/html',
        'body' => '',
        'connected_ip' => $address,
    ];
    connectorFails(
        static fn() => (new SafeSourceConnector($redirectTransport, $resolver))->connect(
            $base + ['type' => '0', 'domain' => 'https://redirect.example'],
        ),
        'redirect response was accepted',
    );
    $tlsFailureTransport = static function (): array {
        throw new RuntimeException('simulated certificate verification failure');
    };
    connectorFails(
        static fn() => (new SafeSourceConnector($tlsFailureTransport, $resolver))->connect(
            $base + ['type' => '0', 'domain' => 'https://tls-failure.example'],
        ),
        'TLS transport failure was accepted',
    );
    $echoTransport = static fn(
        array $endpoint,
        string $address,
        string $method,
        array $headers,
        string $body,
        int $maxBytes,
        int $connectTimeoutMs,
        int $requestTimeoutMs,
    ): array => [
        'status' => 200,
        'content_type' => 'application/json',
        'body' => '{"code":200,"msg":"echo secret-value","data":{"shopName":"Store","balance":0}}',
        'connected_ip' => $address,
    ];
    connectorFails(
        static fn() => (new SafeSourceConnector($echoTransport, $resolver))->connect(
            $base + ['type' => '0', 'domain' => 'https://echo.example'],
        ),
        'remote KEY echo was accepted',
    );
    $heldLock = new SourceConnectLock();
    $heldLock->acquire();
    try {
        connectorFails(
            static fn() => (new SafeSourceConnector($transport, $resolver))->connect(
                $base + ['type' => '0', 'domain' => 'https://concurrent.example'],
            ),
            'concurrent source save did not respect the global connect lock',
        );
    } finally {
        $heldLock->release();
    }
    $connectLockDirectory = $stateSite . '/runtime/extensions/PikaCatalogHub/locks';
    $connectLockPath = $connectLockDirectory . '/source-connect.lock';
    clearstatcache(true, $connectLockPath);
    connectorExpect(is_file($connectLockPath), 'first source-connect lock was not created');
    connectorExpect((fileperms($connectLockPath) & 0777) === 0600, 'first source-connect lock mode is unsafe');
    unlink($connectLockPath);

    $hardlinkTarget = $connectLockDirectory . '/hardlink-target';
    file_put_contents($hardlinkTarget, 'fixture');
    chmod($hardlinkTarget, 0644);
    link($hardlinkTarget, $connectLockPath);
    connectorFails(static fn() => (new SourceConnectLock())->acquire(), 'hardlinked source-connect lock was accepted');
    clearstatcache(true, $hardlinkTarget);
    connectorExpect(
        (fileperms($hardlinkTarget) & 0777) === 0644,
        'rejected source-connect hardlink target mode was modified',
    );
    unlink($connectLockPath);
    unlink($hardlinkTarget);

    symlink('/dev/null', $connectLockPath);
    connectorFails(static fn() => (new SourceConnectLock())->acquire(), 'symlink source-connect lock was accepted');
    unlink($connectLockPath);

    mkdir($connectLockPath, 0700);
    connectorFails(static fn() => (new SourceConnectLock())->acquire(), 'directory source-connect lock was accepted');
    rmdir($connectLockPath);

    file_put_contents($connectLockPath, 'fixture');
    chmod($connectLockPath, 0644);
    connectorFails(static fn() => (new SourceConnectLock())->acquire(), 'wrong-mode source-connect lock was accepted');
    clearstatcache(true, $connectLockPath);
    connectorExpect(
        (fileperms($connectLockPath) & 0777) === 0644,
        'rejected source-connect lock mode was modified',
    );
    unlink($connectLockPath);

    if (function_exists('posix_mkfifo')) {
        posix_mkfifo($connectLockPath, 0600);
        connectorFails(static fn() => (new SourceConnectLock())->acquire(), 'FIFO source-connect lock was accepted');
        unlink($connectLockPath);
    }
    connectorExpect(Shared::query()->count() === 3, 'failed connections changed the shared table');

    DB::connection()->getSchemaBuilder()->create('category', static function (Blueprint $table): void {
        $table->increments('id'); $table->string('name'); $table->integer('sort'); $table->string('create_time');
        $table->unsignedInteger('owner'); $table->string('icon'); $table->unsignedInteger('status');
        $table->unsignedInteger('hide'); $table->unsignedInteger('pid')->nullable();
    });
    $renameMapper = new \Pika\LocalExtensions\PikaSupplySync\Service\PlannedCategoryMapper();
    $renameConfig = new ConfigRepository();
    $renameConfig->upsertAlias(3, 'old-map-name');
    $connectorLeaf = $renameMapper->resolve(Shared::query()->findOrFail(3), 'old-map-name',
        ['group' => 'connector-group', 'family' => ''], 'upstream-original-name', str_repeat('a', 64));
    $renameConfig->upsertAlias(3, 'already-saved-alias');
    $sameValueInput = ['alias' => 'already-saved-alias', 'type' => '1', 'domain' => 'https://source-1.example',
        'app_id' => 'merchant-a', 'app_key' => '', 'currency' => 'USD', 'currency_rate' => '3.5'];
    $connectorSharedBefore = Shared::query()->orderBy('id')->get()->toJson();
    $connectorCommodityBefore = DB::table('commodity')->orderBy('id')->get()->toJson();
    $sameValueCalls = count($calls); $sameValueDns = $resolverCalls;
    $sameValueResult = $offlineConnector->update(3, $sameValueInput);
    connectorExpect(($sameValueResult['source']['alias'] ?? null) === 'already-saved-alias'
        && \App\Model\Category::query()->findOrFail($connectorLeaf->pid)->name === 'already-saved-alias'
        && $connectorLeaf->fresh()->name === 'upstream-original-name', 'connector no-op skipped same-value mapping repair');
    \App\Model\Category::query()->where('id', $connectorLeaf->pid)->update(['name' => 'same-key-db-drift']);
    $offlineConnector->update(3, $sameValueInput);
    connectorExpect(\App\Model\Category::query()->findOrFail($connectorLeaf->pid)->name === 'already-saved-alias', 'connector skipped same-key DB repair');
    connectorExpect(Shared::query()->orderBy('id')->get()->toJson() === $connectorSharedBefore
        && DB::table('commodity')->orderBy('id')->get()->toJson() === $connectorCommodityBefore
        && count($calls) === $sameValueCalls && $resolverCalls === $sameValueDns, 'connector nickname repair touched business or upstream state');

    fwrite(STDOUT, "local catalog source connector behavior: PASS\n");
}
