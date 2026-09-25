<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\Manager {
    // Reuse the existing behavior-fixture seam: only runtime paths are redirected to /tmp.
    final class PathGuard
    {
        public static string $root = '';

        public static function extensionId(string $id): string
        {
            if (preg_match('/^[A-Z][A-Za-z0-9]{1,63}$/D', $id) !== 1) {
                throw new \RuntimeException('invalid field-selection fixture extension');
            }
            return $id;
        }

        public static function stateRoot(): string
        {
            return self::$root;
        }

        public static function stateDirectory(string $relative, int $mode = 0o750): string
        {
            if ($relative !== 'config' || !in_array($mode, [0o700, 0o750], true)) {
                throw new \RuntimeException('unexpected field-selection state directory');
            }
            $path = self::$root . '/' . $relative;
            if (is_link($path) || (!is_dir($path) && !mkdir($path, $mode))) {
                throw new \RuntimeException('unable to create field-selection state directory');
            }
            if (!chmod($path, $mode)) {
                throw new \RuntimeException('unable to protect field-selection state directory');
            }
            return $path;
        }

        public static function runtimeOwner(): int
        {
            $owner = fileowner(self::$root);
            if (!is_int($owner)) {
                throw new \RuntimeException('unable to resolve field-selection fixture owner');
            }
            return $owner;
        }
    }

    final class Registry
    {
        public static array $supply = [];

        public static function extension(string $id): array
        {
            if ($id !== 'PikaSupplySync' || self::$supply === []) {
                throw new \RuntimeException('unexpected field-selection fixture extension');
            }
            return self::$supply;
        }
    }
}

namespace {
    use Pika\LocalExtensions\Manager\AtomicJson;
    use Pika\LocalExtensions\Manager\ConfigStore;
    use Pika\LocalExtensions\Manager\ManifestValidator;
    use Pika\LocalExtensions\Manager\PathGuard;
    use Pika\LocalExtensions\Manager\Registry;
    use Pika\LocalExtensions\PikaSupplySync\Service\Options;

    function fieldExpect(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function fieldReject(callable $callback, string $exceptionClass, string $message): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            fieldExpect($exception instanceof $exceptionClass, $message . ': unexpected exception class');
            return;
        }
        throw new RuntimeException($message);
    }

    function fieldRemoveFixture(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                fieldRemoveFixture($path . '/' . $entry);
            }
        }
        rmdir($path);
    }

    $fixture = '/tmp/pika-supply-field-selection-' . bin2hex(random_bytes(8));
    fieldExpect(mkdir($fixture, 0o700), 'unable to create field-selection fixture');
    try {
        require dirname(__DIR__) . '/manager/site/local-extensions/src/AtomicJson.php';
        require dirname(__DIR__) . '/manager/site/local-extensions/src/ManifestValidator.php';
        require dirname(__DIR__) . '/manager/site/local-extensions/src/ConfigStore.php';
        require dirname(__DIR__) . '/manager/site/local-extensions/src/StateStore.php';
        require dirname(__DIR__) . '/extensions/PikaSupplySync/Service/Options.php';
        require dirname(__DIR__) . '/extensions/PikaSupplySync/Service/SyncService.php';
        $manifest = json_decode((string)file_get_contents(
            dirname(__DIR__) . '/extensions/PikaSupplySync/local-extension.json',
        ), true, 32, JSON_THROW_ON_ERROR);
        Registry::$supply = ManifestValidator::plugin($manifest, 'PikaSupplySync');
        $fields = ['name', 'cover', 'description', 'price', 'inventory', 'options'];
        $keys = array_map(static fn(string $field): string => 'sync_' . $field, $fields);
        $keyMap = array_fill_keys($keys, true);
        fieldExpect(Options::SYNC_FIELDS === $fields, 'Options and the six-field contract disagree');
        foreach (Registry::$supply['settings'] as $setting) {
            if (isset($keyMap[$setting['key']])) {
                fieldExpect($setting['type'] === 'checkbox' && !array_key_exists('default', $setting),
                    'an optional sync field gained an implicit checkbox default');
            }
        }
        $followSetting = array_column(Registry::$supply['settings'], null, 'key')['follow_upstream_config'];
        $followSourceSetting = array_column(Registry::$supply['settings'], null, 'key')['follow_upstream_config_source_ids'];
        $followDefaults = ['follow_upstream_config_source_ids'=>'', 'follow_upstream_config'=>false];
        fieldExpect($manifest['version'] === '1.1.20', 'field-selection fixture requires the current SupplySync version');
        fieldExpect($followSetting['type'] === 'checkbox'
            && $followSetting['default'] === false, 'config following must be an independent opt-in checkbox');
        fieldExpect($followSourceSetting['type'] === 'text' && $followSourceSetting['default'] === '',
            'config following must use an independent empty-by-default source list');

        PathGuard::$root = $fixture . '/legacy';
        fieldExpect(mkdir(PathGuard::$root, 0o700), 'unable to create legacy fixture root');
        $path = PathGuard::$root . '/config/PikaSupplySync.json';
        $missing = ConfigStore::get('PikaSupplySync');
        fieldExpect(array_intersect_key($missing, $keyMap) === [] && array_intersect_key($missing, $followDefaults) === $followDefaults
            && !file_exists($path),
            'reading a missing legacy config persisted or invented the six optional fields');
        $legacy = ['mode'=>'full', 'source_ids'=>'1', 'premium_percent'=>10,
            'batch_limit'=>20, 'zero_fuse_percent'=>10, 'zero_fuse_min'=>5];
        AtomicJson::update($path, [], static fn(array $state): array => ['schema'=>1, 'values'=>$legacy]);
        $legacyBytes = file_get_contents($path);
        $legacyView = ConfigStore::get('PikaSupplySync');
        fieldExpect(array_diff_key($legacyView, $followDefaults) === $legacy
            && array_intersect_key($legacyView, $followDefaults) === $followDefaults
            && ConfigStore::publicView('PikaSupplySync')['values'] === $legacyView
            && file_get_contents($path) === $legacyBytes,
            'reading old config backfilled sync choices or changed its durable bytes');
        ConfigStore::save('PikaSupplySync', ['premium_percent'=>12]);
        $oldClient = ConfigStore::get('PikaSupplySync');
        fieldExpect(array_diff_key($oldClient, $followDefaults) === array_replace($legacy, ['premium_percent'=>12])
            && array_intersect_key($oldClient, $followDefaults) === $followDefaults
            && array_intersect_key(AtomicJson::read($path, [])['values'], $keyMap) === [],
            'an old-client save backfilled absent sync choices or changed unrelated settings');

        $selection = array_combine($keys, [true, false, true, false, true, false]);
        ConfigStore::save('PikaSupplySync', $selection);
        $selected = ConfigStore::get('PikaSupplySync');
        fieldExpect(array_intersect_key($selected, $keyMap) === $selection
            && array_diff_key($selected, $keyMap) === $oldClient,
            'the first complete selection did not preserve all six choices and legacy settings');
        $selectedBytes = file_get_contents($path);
        fieldExpect(ConfigStore::publicView('PikaSupplySync')['values'] === $selected
            && file_get_contents($path) === $selectedBytes,
            'public config reading mutated the saved field selection');
        ConfigStore::save('PikaSupplySync', ['premium_percent'=>15]);
        fieldExpect(array_intersect_key(ConfigStore::get('PikaSupplySync'), $keyMap) === $selection
            && ConfigStore::get('PikaSupplySync')['premium_percent'] === 15,
            'an old client erased a complete saved selection when omitting the six keys');

        $allFalse = array_fill_keys($keys, false);
        ConfigStore::save('PikaSupplySync', $allFalse);
        $falseEnvelope = AtomicJson::read($path, []);
        fieldExpect($falseEnvelope['schema'] === 1
            && array_intersect_key($falseEnvelope['values'], $keyMap) === $allFalse
            && array_intersect_key(ConfigStore::get('PikaSupplySync'), $keyMap) === $allFalse
            && $falseEnvelope['values']['premium_percent'] === 15,
            'all-false selection was omitted, replaced with defaults, or persisted incompletely');
        clearstatcache(true, $path);
        fieldExpect((fileperms($path) & 0o777) === 0o600
            && (lstat($path)['nlink'] ?? null) === 1
            && (glob(dirname($path) . '/.PikaSupplySync.json.tmp-*') ?: []) === [],
            'atomic selection persistence left unsafe state permissions or an unfinished temporary file');

        $beforeReject = file_get_contents($path);
        foreach ($keys as $key) {
            $partial = $selection;
            unset($partial[$key]);
            foreach ([[$key=>true], $partial] as $input) {
                fieldReject(static fn() => ConfigStore::save('PikaSupplySync', $input + ['premium_percent'=>99]),
                    RuntimeException::class, 'a partial sync selection was accepted');
                fieldExpect(file_get_contents($path) === $beforeReject,
                    'rejected partial choices changed stored choices or unrelated settings');
            }
        }
        foreach (['invalid', 2, -1, 1.0, [], new stdClass()] as $invalid) {
            $input = array_replace($selection, ['sync_options'=>$invalid, 'premium_percent'=>99]);
            fieldReject(static fn() => ConfigStore::save('PikaSupplySync', $input), RuntimeException::class,
                'a malformed checkbox value was accepted');
            fieldExpect(file_get_contents($path) === $beforeReject,
                'malformed complete choices partially changed the durable configuration');
        }

        // HTTP checkbox normalization remains in ConfigStore; Options receives booleans only.
        ConfigStore::save('PikaSupplySync', array_combine($keys, ['on', '0', 1, 'false', true, null]));
        fieldExpect(array_intersect_key(ConfigStore::get('PikaSupplySync'), $keyMap) === $selection,
            'existing checkbox wire values did not normalize into the complete boolean selection');

        PathGuard::$root = $fixture . '/first-save';
        fieldExpect(mkdir(PathGuard::$root, 0o700), 'unable to create first-save fixture root');
        $freshPath = PathGuard::$root . '/config/PikaSupplySync.json';
        fieldReject(static fn() => ConfigStore::save('PikaSupplySync', ['sync_name'=>true]), RuntimeException::class,
            'a first partial save was accepted');
        fieldExpect(!file_exists($freshPath), 'a rejected first partial save created a configuration file');
        ConfigStore::save('PikaSupplySync', $selection);
        $firstSaved = ConfigStore::get('PikaSupplySync');
        fieldExpect(array_intersect_key($firstSaved, $keyMap) === $selection
            && $firstSaved['mode'] === 'basic' && $firstSaved['premium_percent'] === 0,
            'a first complete save failed to initialize exactly six explicit choices with legacy defaults');

        $legacyOptions = Options::fromArray($legacy);
        fieldExpect($legacyOptions->syncFields === null && Options::fromArray([])->syncFields === null,
            'legacy Options were silently converted to an explicit field selection');
        foreach ($fields as $field) {
            fieldExpect($legacyOptions->syncs($field), 'legacy Options disabled an existing sync field');
        }
        $configuredOptions = Options::fromArray($firstSaved);
        fieldExpect($configuredOptions->syncFields === array_combine($fields, array_values($selection)),
            'Options lost or reordered a complete boolean field selection');
        $disabledOptions = Options::fromArray($allFalse);
        fieldExpect($disabledOptions->syncFields === array_fill_keys($fields, false),
            'all-false Options reverted to legacy null semantics');
        foreach ($fields as $field) {
            fieldExpect($configuredOptions->syncs($field) === $selection['sync_' . $field]
                && !$disabledOptions->syncs($field), 'Options did not honor an explicit field choice');
        }
        fieldExpect(Options::fromArray([], ['sync_name'=>null])->syncFields === null
            && Options::fromArray($selection, ['sync_name'=>null])->syncFields === $configuredOptions->syncFields,
            'an omitted/null override invented a partial selection or erased a configured choice');
        foreach ($keys as $key) {
            $partial = $selection;
            unset($partial[$key]);
            fieldReject(static fn() => Options::fromArray($partial), InvalidArgumentException::class,
                'Options accepted an incomplete field selection');
        }
        foreach (['true', 'false', 1, 0, null, [], new stdClass()] as $invalid) {
            fieldReject(static fn() => Options::fromArray(array_replace($selection, ['sync_name'=>$invalid])),
                InvalidArgumentException::class, 'Options accepted a non-boolean explicit selection');
        }

        PathGuard::$root = $fixture . '/config-follow';
        fieldExpect(mkdir(PathGuard::$root, 0o700), 'unable to create config-follow fixture root');
        $followPath = PathGuard::$root . '/config/PikaSupplySync.json';
        $allTrue = array_fill_keys($keys, true);
        fieldReject(static fn() => ConfigStore::save('PikaSupplySync',
            ['follow_upstream_config'=>true, 'follow_upstream_config_source_ids'=>'101']),
            RuntimeException::class, 'legacy missing choices allowed config following');
        fieldExpect(!file_exists($followPath), 'a rejected config-follow enable created durable config');
        fieldReject(static fn() => ConfigStore::save('PikaSupplySync', $allTrue
            + ['follow_upstream_config'=>true, 'source_ids'=>'101']), RuntimeException::class,
            'an existing sync source list substituted for the missing independent follow list');
        fieldExpect(!file_exists($followPath), 'a missing independent follow list created durable config');
        foreach (['', ' ', '0', '-1', '101,', '101,,202', '101;202', '101,a', '1.5', str_repeat('1,', 100) . '1'] as $invalid) {
            fieldReject(static fn() => ConfigStore::save('PikaSupplySync', $allTrue
                + ['follow_upstream_config'=>true, 'source_ids'=>'101', 'follow_upstream_config_source_ids'=>$invalid]), RuntimeException::class,
                'config following accepted an empty or invalid explicit source scope');
            fieldExpect(!file_exists($followPath), 'invalid source scope created durable config');
        }
        foreach (['invalid', 2, -1, 1.0, [], new stdClass()] as $invalid) {
            fieldReject(static fn() => ConfigStore::save('PikaSupplySync', $allTrue
                + ['follow_upstream_config'=>$invalid, 'follow_upstream_config_source_ids'=>'101']), RuntimeException::class,
                'config following accepted an invalid checkbox value');
            fieldExpect(!file_exists($followPath), 'invalid config-follow boolean created durable config');
        }
        ConfigStore::save('PikaSupplySync', $allTrue
            + ['follow_upstream_config'=>true, 'source_ids'=>'', 'follow_upstream_config_source_ids'=>'101,202']);
        $enabled = ConfigStore::get('PikaSupplySync');
        fieldExpect($enabled['follow_upstream_config'] === true && array_intersect_key($enabled, $keyMap) === $allTrue
            && $enabled['source_ids'] === '' && $enabled['follow_upstream_config_source_ids'] === '101,202',
            'one complete first save could not combine all sync sources with an explicit follow list');
        foreach (['101', '101,202,303', '', '303'] as $syncSources) {
            ConfigStore::save('PikaSupplySync', ['source_ids'=>$syncSources]);
            $scopeView = ConfigStore::get('PikaSupplySync');
            fieldExpect($scopeView['source_ids'] === $syncSources && $scopeView['follow_upstream_config'] === true
                && $scopeView['follow_upstream_config_source_ids'] === '101,202',
                'an old-client execution source change was blocked or changed the independent follow scope');
        }
        ConfigStore::save('PikaSupplySync', ['premium_percent'=>12]);
        fieldExpect(ConfigStore::get('PikaSupplySync')['follow_upstream_config'] === true,
            'an old client omitting the config-follow checkbox erased explicit true');
        ConfigStore::save('PikaSupplySync', ['follow_upstream_config_source_ids'=>' 0202,101,101 ']);
        fieldExpect(ConfigStore::get('PikaSupplySync')['follow_upstream_config'] === true,
            'an old client could not preserve a semantically equivalent config-follow scope');
        ConfigStore::save('PikaSupplySync', ['follow_upstream_config_source_ids'=>'202']);
        fieldExpect(ConfigStore::get('PikaSupplySync')['follow_upstream_config_source_ids'] === '202'
            && ConfigStore::get('PikaSupplySync')['source_ids'] === '303',
            'an old client could not reduce the config-follow scope');
        $beforeFollowReject = file_get_contents($followPath);
        foreach ([['follow_upstream_config_source_ids'=>'101,202'], ['follow_upstream_config_source_ids'=>''],
            ['sync_price'=>false], ['follow_upstream_config'=>'invalid']] as $input) {
            fieldReject(static fn() => ConfigStore::save('PikaSupplySync', $input + ['premium_percent'=>99]),
                RuntimeException::class, 'invalid or unconfirmed config-follow change was accepted');
            fieldExpect(file_get_contents($followPath) === $beforeFollowReject,
                'rejected config-follow save partially changed the durable configuration');
        }
        ConfigStore::save('PikaSupplySync', ['follow_upstream_config'=>true, 'follow_upstream_config_source_ids'=>'101,202']);
        fieldExpect(ConfigStore::get('PikaSupplySync')['follow_upstream_config_source_ids'] === '101,202',
            'explicit config-follow confirmation could not expand a valid scope');
        foreach (['sync_price', 'sync_options'] as $key) {
            ConfigStore::save('PikaSupplySync', array_replace($allTrue, [$key=>false]) + ['follow_upstream_config'=>true]);
            $paused = ConfigStore::get('PikaSupplySync');
            fieldExpect($paused['follow_upstream_config'] === true && $paused[$key] === false,
                'saving config following wrongly required effective price and options to remain enabled');
        }
        ConfigStore::save('PikaSupplySync', ['follow_upstream_config'=>false, 'follow_upstream_config_source_ids'=>'']);
        fieldExpect(ConfigStore::get('PikaSupplySync')['follow_upstream_config'] === false,
            'explicitly disabling config following required a nonempty source scope');
        ConfigStore::save('PikaSupplySync', $allTrue);
        ConfigStore::save('PikaSupplySync', ['source_ids'=>'101,202']);
        fieldExpect(ConfigStore::get('PikaSupplySync')['follow_upstream_config'] === false,
            'all six checked or an old-client scope update implicitly enabled config following');
        $oldScoped = $allTrue + ['follow_upstream_config'=>true, 'source_ids'=>'101'];
        AtomicJson::update($followPath, [], static fn(array $state): array => ['schema'=>1, 'values'=>$oldScoped]);
        $oldScopedBytes = file_get_contents($followPath);
        fieldExpect(ConfigStore::get('PikaSupplySync')['follow_upstream_config_source_ids'] === ''
            && file_get_contents($followPath) === $oldScopedBytes,
            'reading an old config migrated its execution source list into follow ownership');
        foreach ([['source_ids'=>'202'], ['follow_upstream_config_source_ids'=>'101']] as $input) {
            fieldReject(static fn() => ConfigStore::save('PikaSupplySync', $input), RuntimeException::class,
                'an old client implicitly established a missing independent follow scope');
            fieldExpect(file_get_contents($followPath) === $oldScopedBytes,
                'an invalid old-client config-follow save changed durable bytes');
        }
        ConfigStore::save('PikaSupplySync', ['follow_upstream_config'=>true, 'follow_upstream_config_source_ids'=>'202']);
        fieldExpect(ConfigStore::get('PikaSupplySync')['source_ids'] === '101'
            && ConfigStore::get('PikaSupplySync')['follow_upstream_config_source_ids'] === '202',
            'explicit confirmation could not establish a new independent follow list');

        $followConfig = $allTrue + ['source_ids'=>'', 'follow_upstream_config'=>true,
            'follow_upstream_config_source_ids'=>'202'];
        $allSourcesOptions = Options::fromArray($followConfig);
        fieldExpect($allSourcesOptions->sourceIds === [] && $allSourcesOptions->followsUpstreamConfig(202)
            && !$allSourcesOptions->followsUpstreamConfig(101), 'all-source execution expanded independent config-follow ownership');
        $disjointOptions = Options::fromArray(array_replace($followConfig, ['source_ids'=>'101']));
        fieldExpect($disjointOptions->sourceIds === [101] && !$disjointOptions->followsUpstreamConfig(101)
            && !$disjointOptions->followsUpstreamConfig(202), 'config following bypassed the execution source intersection');
        $expandedOptions = Options::fromArray(array_replace($followConfig, ['source_ids'=>'101,202']));
        fieldExpect($expandedOptions->sourceIds === [101, 202] && !$expandedOptions->followsUpstreamConfig(101)
            && $expandedOptions->followsUpstreamConfig(202), 'expanding execution sources expanded independent follow ownership');
        $overriddenOptions = Options::fromArray($followConfig, ['follow_upstream_config_source_ids'=>'101,202']);
        fieldExpect(!$overriddenOptions->followsUpstreamConfig(101) && $overriddenOptions->followsUpstreamConfig(202),
            'an override expanded the independent config-follow source list');
        $offOverrides = Options::fromArray(array_replace($followConfig, ['follow_upstream_config'=>false]),
            ['follow_upstream_config'=>true, 'follow_upstream_config_source_ids'=>'101,202']);
        fieldExpect(!$offOverrides->followsUpstreamConfig(101) && !$offOverrides->followsUpstreamConfig(202),
            'an override enabled a disabled config-follow policy');
        fieldReject(static fn() => Options::fromArray($allTrue + ['source_ids'=>'202', 'follow_upstream_config'=>true]),
            InvalidArgumentException::class, 'Options inferred a missing follow list from execution source IDs');
        $enabledPath = PathGuard::stateRoot() . '/state.json';
        AtomicJson::update($enabledPath, [], static fn(): array => ['schema' => 1, 'extensions' => [
            'PikaSupplySync' => ['enabled' => true, 'updated_at' => '2026-09-22T00:00:00Z'],
        ]]);
        fieldExpect(\Pika\LocalExtensions\Manager\StateStore::isEnabled('PikaSupplySync'), 'enabled fixture did not load');
        // Simulate another process changing the same durable state, bypassing this process's cache.
        AtomicJson::update($enabledPath, [], static fn(array $state): array => array_replace_recursive($state,
            ['extensions' => ['PikaSupplySync' => ['enabled' => false]]]));
        fieldExpect(\Pika\LocalExtensions\Manager\StateStore::isEnabled('PikaSupplySync'), 'ordinary cached lookup changed');
        fieldExpect(!\Pika\LocalExtensions\Manager\StateStore::isEnabled('PikaSupplySync', true),
            'fresh targeted enabled lookup ignored a durable external disable');
        ConfigStore::save('PikaSupplySync', $allTrue + ['source_ids' => '202', 'mode' => 'basic',
            'batch_limit' => 20, 'follow_upstream_config' => true, 'follow_upstream_config_source_ids' => '202']);
        $cliOverrides = ['source_ids' => '202', 'mode' => 'basic', 'batch_limit' => 20];
        $requested = Options::fromArray(ConfigStore::get('PikaSupplySync'), $cliOverrides);
        $readTargetOptions = static fn(): Options => \Pika\LocalExtensions\PikaSupplySync\Service\SyncService::targetedOptions(
            Options::fromArray(ConfigStore::get('PikaSupplySync')), $requested,
        );
        ConfigStore::save('PikaSupplySync', ['batch_limit' => 1]);
        fieldExpect($readTargetOptions()->batchLimit === 1, 'CLI batch override restored a reduced saved target ceiling');
        ConfigStore::save('PikaSupplySync', ['source_ids' => '101']);
        fieldReject($readTargetOptions, RuntimeException::class, 'CLI source override restored revoked target scope');
        ConfigStore::save('PikaSupplySync', ['source_ids' => '202', 'mode' => 'full']);
        fieldReject($readTargetOptions, RuntimeException::class, 'CLI basic override ignored a saved target mode change');
        $ordinaryOverride = Options::fromArray(ConfigStore::get('PikaSupplySync'), $cliOverrides);
        fieldExpect($ordinaryOverride->mode === 'basic' && $ordinaryOverride->batchLimit === 20,
            'target-specific authorization checks changed ordinary CLI override semantics');
        fwrite(STDOUT, "local supply field selection: PASS; fresh enablement recheck: PASS; targeted CLI saved-scope recheck: PASS\n");
    } finally {
        fieldRemoveFixture($fixture);
    }
}
