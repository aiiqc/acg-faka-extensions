<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\Manager {
    final class PathGuard
    {
        public static string $root = '';

        public static function stateDirectory(string $relative, int $mode = 0o750): string
        {
            if (!str_starts_with($relative, 'extensions/PikaCatalogHub')
                || preg_match('#^extensions/PikaCatalogHub(?:/[A-Za-z0-9._-]+)*$#D', $relative) !== 1
                || !in_array($mode, [0o700, 0o750], true)) {
                throw new \RuntimeException('unexpected CatalogHub state directory request');
            }
            $path = self::$root . '/' . $relative;
            if (!is_dir($path) && !mkdir($path, $mode, true) && !is_dir($path)) {
                throw new \RuntimeException('unable to create CatalogHub test state');
            }
            chmod($path, $mode);
            return $path;
        }

        public static function runtimeOwner(): int
        {
            $owner = fileowner(self::$root);
            if (!is_int($owner)) {
                throw new \RuntimeException('unable to resolve CatalogHub test owner');
            }
            return $owner;
        }

        public static function stateRoot(): string
        {
            return self::$root;
        }
    }

    final class AtomicJson
    {
        /** @var array<string,array<string,mixed>> */
        public static array $values = [];
        /** @var list<string> */
        public static array $paths = [];

        public static function read(string $path, array $default): array
        {
            self::$paths[] = $path;
            return self::$values[$path] ?? $default;
        }

        public static function update(string $path, array $default, callable $mutator): array
        {
            self::$paths[] = $path;
            $next = $mutator(self::$values[$path] ?? $default);
            self::$values[$path] = $next;
            return $next;
        }
    }
}

namespace App\Model {
    final class CatalogHubCommodityQueryStub
    {
        public function where(string $column, mixed $operator, mixed $value = null): self
        {
            return $this;
        }

        public function orderBy(string $column): self
        {
            return $this;
        }

        public function limit(int $limit): self
        {
            return $this;
        }

        public function get(array $columns): object
        {
            return new class implements \Countable, \IteratorAggregate {
                public function count(): int
                {
                    return 0;
                }

                public function getIterator(): \Traversable
                {
                    return new \ArrayIterator([]);
                }
            };
        }
    }

    final class Commodity
    {
        public static function query(): CatalogHubCommodityQueryStub
        {
            return new CatalogHubCommodityQueryStub();
        }
    }

    final class CatalogHubSharedQueryStub
    {
        /** @var list<string> */
        public static array $selected = [];
        /** @var list<object> */
        public static array $rows = [];

        public function orderBy(string $column): self
        {
            if ($column !== 'id') {
                throw new \RuntimeException('unexpected shared order');
            }
            return $this;
        }

        public function limit(int $limit): self
        {
            if ($limit !== 17) {
                throw new \RuntimeException('unexpected shared limit');
            }
            return $this;
        }

        /** @param list<string> $columns @return list<object> */
        public function get(array $columns): array
        {
            self::$selected = $columns;
            return self::$rows;
        }

        public function find(int $id): ?object
        {
            foreach (self::$rows as $row) {
                if ((int)$row->id === $id) {
                    return $row;
                }
            }
            return null;
        }
    }

    final class Shared
    {
        public int $id = 0;
        public int $type = 0;
        public string $name = '';
        public string $domain = '';
        public string $app_id = '';
        public string $app_key = '';
        public string $currency = 'CNY';
        public string $currency_rate = '0';

        public static function query(): CatalogHubSharedQueryStub
        {
            return new CatalogHubSharedQueryStub();
        }
    }
}

namespace {
    use App\Model\CatalogHubSharedQueryStub;
    use Pika\LocalExtensions\Manager\AtomicJson;
    use Pika\LocalExtensions\Manager\PathGuard;
    use Pika\LocalExtensions\PikaCatalogHub\Service\AdminService;
    use Pika\LocalExtensions\PikaCatalogHub\Service\ConfigRepository;
    use Pika\LocalExtensions\PikaCatalogHub\Service\ConfigSchema;
    use Pika\LocalExtensions\PikaCatalogHub\Service\PreviewLock;
    use Pika\LocalExtensions\PikaCatalogHub\Service\PreviewPlanner;

    function catalogHubExpect(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function catalogHubFails(callable $callback, string $message): void
    {
        try {
            $callback();
        } catch (Throwable) {
            return;
        }
        throw new RuntimeException($message);
    }

    /** @return array{priority:int,mode:string,keywords:list<string>,target:array{group:string,family:string}} */
    function catalogHubRule(
        array $keywords,
        string $group,
        string $family,
        int $priority = 100,
        string $mode = 'contains',
    ): array {
        return [
            'priority' => $priority,
            'mode' => $mode,
            'keywords' => $keywords,
            'target' => ['group' => $group, 'family' => $family],
        ];
    }

    /** @return array{code:string,name:string,category:string,item:array,stock:int} */
    function catalogHubItem(string $code, string $name, string $category = '其他'): array
    {
        return [
            'code' => $code,
            'name' => $name,
            'category' => $category,
            'stock' => 1,
            'item' => ['must_not_escape' => 'secret-like-remote-field'],
        ];
    }

    $fixture = sys_get_temp_dir() . '/pika-catalog-hub-behavior-' . bin2hex(random_bytes(8));
    if (!mkdir($fixture, 0o700, true) && !is_dir($fixture)) {
        throw new RuntimeException('unable to create CatalogHub behavior fixture');
    }
    PathGuard::$root = $fixture;
    define('BASE_PATH', $fixture . '/');

    $extensionRoot = dirname(__DIR__) . '/extensions/PikaCatalogHub';
    $registered = require $extensionRoot . '/bootstrap.php';
    catalogHubExpect($registered === true, 'CatalogHub bootstrap did not register its loader');
    catalogHubExpect(class_exists(ConfigSchema::class), 'CatalogHub namespace did not autoload');
    $supplyRegistered = require dirname(__DIR__) . '/extensions/PikaSupplySync/bootstrap.php';
    catalogHubExpect($supplyRegistered === true, 'SupplySync dependency did not register its loader');

    $manifest = json_decode(
        (string)file_get_contents($extensionRoot . '/local-extension.json'),
        true,
        16,
        JSON_THROW_ON_ERROR,
    );
    catalogHubExpect(($manifest['settings'] ?? null) === [], 'CatalogHub manifest settings must stay empty');
    $manifestJson = json_encode($manifest, JSON_THROW_ON_ERROR);
    catalogHubExpect(
        !preg_match('/(?:https?:\\/\\/|merchant|app[_-]?id|app[_-]?key|secret|token)/i', $manifestJson),
        'CatalogHub manifest must not contain sources or credentials',
    );

    $defaults = ConfigSchema::defaults();
    catalogHubExpect($defaults['aliases'] === [], 'public defaults must not include site aliases');
    $defaultTargets = array_map(
        static fn(array $rule): string => $rule['target']['group'] . '/' . $rule['target']['family'],
        $defaults['rules'],
    );
    sort($defaultTargets, SORT_STRING);
    $expectedTargets = ['AI工具/Claude', 'AI工具/GPT', 'Facebook/', 'Instagram/'];
    sort($expectedTargets, SORT_STRING);
    catalogHubExpect($defaultTargets === $expectedTargets, 'public defaults include unexpected classifications');

    $sixteenAliases = [];
    for ($sourceId = 1; $sourceId <= 16; $sourceId++) {
        $sixteenAliases[] = ['source_id' => $sourceId, 'alias' => '货源' . $sourceId];
    }
    $aliasBoundary = ConfigSchema::normalize(['aliases' => $sixteenAliases, 'rules' => []]);
    catalogHubExpect(count($aliasBoundary['aliases']) === 16, '16 aliases must be accepted');
    $seventeenAliases = $sixteenAliases;
    $seventeenAliases[] = ['source_id' => 17, 'alias' => '货源17'];
    catalogHubFails(
        static fn() => ConfigSchema::normalize(['aliases' => $seventeenAliases, 'rules' => []]),
        '17 aliases must be rejected',
    );
    foreach (['', 'a/b', 'a\\b', "bad\nname", "bad\n", "\tbad", "bad\u{202E}name", str_repeat('界', 65)] as $badAlias) {
        catalogHubFails(
            static fn() => ConfigSchema::normalize([
                'aliases' => [['source_id' => 1, 'alias' => $badAlias]],
                'rules' => [],
            ]),
            'invalid alias was accepted',
        );
    }
    catalogHubFails(
        static fn() => ConfigSchema::normalize([
            'aliases' => [
                ['source_id' => 1, 'alias' => 'Source A'],
                ['source_id' => 2, 'alias' => 'source a'],
            ],
            'rules' => [],
        ]),
        'aliases must be case-insensitively unique',
    );
    catalogHubFails(
        static fn() => ConfigSchema::normalize([
            'aliases' => [
                ['source_id' => 1, 'alias' => 'Source A'],
                ['source_id' => 1, 'alias' => 'Source B'],
            ],
            'rules' => [],
        ]),
        'source IDs must be unique',
    );

    $sixteenKeywords = array_map(static fn(int $index): string => 'keyword-' . $index, range(1, 16));
    $keywordBoundary = ConfigSchema::normalize([
        'aliases' => [],
        'rules' => [catalogHubRule($sixteenKeywords, 'Group', 'Family')],
    ]);
    catalogHubExpect(count($keywordBoundary['rules'][0]['keywords']) === 16, '16 keywords must be accepted');
    $directTarget = ConfigSchema::normalize([
        'aliases' => [],
        'rules' => [catalogHubRule(['Facebook'], 'Facebook', '')],
    ]);
    catalogHubExpect(
        $directTarget['rules'][0]['target'] === ['group' => 'Facebook', 'family' => ''],
        'an empty family must mean a direct source leaf below the group',
    );
    $seventeenKeywords = [...$sixteenKeywords, 'keyword-17'];
    catalogHubFails(
        static fn() => ConfigSchema::normalize([
            'aliases' => [],
            'rules' => [catalogHubRule($seventeenKeywords, 'Group', 'Family')],
        ]),
        '17 keywords must be rejected',
    );
    foreach (['', "bad\rkeyword", "bad\n", "bad\u{200B}keyword", str_repeat('词', 65)] as $badKeyword) {
        catalogHubFails(
            static fn() => ConfigSchema::normalize([
                'aliases' => [],
                'rules' => [catalogHubRule([$badKeyword], 'Group', 'Family')],
            ]),
            'invalid keyword was accepted',
        );
    }
    $rules128 = array_fill(0, 128, catalogHubRule(['literal'], 'Group', 'Family'));
    catalogHubExpect(
        count(ConfigSchema::normalize(['aliases' => [], 'rules' => $rules128])['rules']) === 1,
        'identical rules should be canonicalized after enforcing the input limit',
    );
    catalogHubFails(
        static fn() => ConfigSchema::normalize([
            'aliases' => [],
            'rules' => array_fill(0, 129, catalogHubRule(['literal'], 'Group', 'Family')),
        ]),
        '129 rules must be rejected',
    );

    $oversizedRules = [];
    for ($ruleIndex = 0; $ruleIndex < 128; $ruleIndex++) {
        $keywords = [];
        for ($keywordIndex = 0; $keywordIndex < 16; $keywordIndex++) {
            $keywords[] = str_repeat('词', 56) . sprintf('%04x%04x', $ruleIndex, $keywordIndex);
        }
        $oversizedRules[] = catalogHubRule($keywords, '分组' . $ruleIndex, '系列' . $ruleIndex);
    }
    catalogHubFails(
        static fn() => ConfigSchema::normalize(['aliases' => [], 'rules' => $oversizedRules]),
        'normalized configuration byte limit was not enforced',
    );

    catalogHubFails(
        static fn() => ConfigSchema::normalize(['aliases' => [], 'rules' => [], 'url' => 'https://invalid.test']),
        'unknown source URL field must fail closed',
    );
    catalogHubFails(
        static fn() => ConfigSchema::normalize([
            'aliases' => [['source_id' => 1, 'alias' => 'A', 'merchant_id' => 'x']],
            'rules' => [],
        ]),
        'unknown alias credential field must fail closed',
    );
    catalogHubFails(
        static fn() => ConfigSchema::normalize([
            'aliases' => [],
            'rules' => [[...catalogHubRule(['GPT'], 'AI', 'GPT'), 'app_key' => 'x']],
        ]),
        'unknown rule credential field must fail closed',
    );
    catalogHubFails(
        static fn() => ConfigSchema::normalize([
            'aliases' => [],
            'rules' => [[
                ...catalogHubRule(['GPT'], 'AI', 'GPT'),
                'target' => ['group' => 'AI', 'family' => 'GPT', 'token' => 'x'],
            ]],
        ]),
        'unknown target field must fail closed',
    );
    catalogHubFails(
        static fn() => ConfigSchema::normalize([
            'aliases' => [],
            'rules' => [catalogHubRule(['invalid'], '', '')],
        ]),
        'the root group must remain non-empty',
    );
    catalogHubFails(
        static fn() => ConfigSchema::normalize([
            'aliases' => [],
            'rules' => [catalogHubRule(['invalid'], "Group\u{2066}", '')],
        ]),
        'a bidi format character in a target group was accepted',
    );
    $legacyConfig = [
        'schema' => 1,
        'aliases' => [['source_id' => 1, 'alias' => 'Legacy']],
        'rules' => [catalogHubRule(['Facebook'], '社交媒体', 'Facebook')],
    ];
    catalogHubExpect(
        ConfigSchema::normalize(ConfigSchema::normalize($legacyConfig)) === ConfigSchema::normalize($legacyConfig),
        'an existing schema-1 three-level config must remain stable without migration',
    );

    $planner = new PreviewPlanner();
    $config = ConfigSchema::normalize([
        'schema' => 1,
        'aliases' => [
            ['source_id' => 2, 'alias' => '货源B'],
            ['source_id' => 1, 'alias' => '货源A'],
        ],
        'rules' => array_reverse($defaults['rules']),
    ]);
    $catalog = [
        'five' => catalogHubItem('5', 'FBA account'),
        'three' => catalogHubItem('3', 'Claude Pro'),
        'one' => catalogHubItem('1', 'ChatGPT Plus'),
        'four' => catalogHubItem('4', 'Instagram old account'),
        'two' => catalogHubItem('2', 'FB BM'),
    ];
    catalogHubFails(
        static fn() => $planner->preview(1, [], $config),
        'empty upstream catalog must not produce a ready preview',
    );
    $preview = $planner->preview(1, $catalog, $config);
    catalogHubExpect($preview['schema'] === 3, 'upstream-category preview contract must use schema 3');
    catalogHubExpect($preview['counts'] === [
        'total' => 5,
        'matched' => 4,
        'conflicts' => 0,
        'unclassified' => 1,
    ], 'default deterministic classification counts are wrong');
    catalogHubExpect($preview['ready'] === true, 'items routed to the visible other bucket must not fail readiness');
    catalogHubExpect(
        count($preview['unclassified']) === 1 && $preview['unclassified'][0]['name'] === 'FBA account',
        'short ASCII FB keyword must use token matching before the other fallback',
    );
    catalogHubExpect(strlen($preview['plan_hash']) === 64, 'preview hash must be a full SHA-256');
    catalogHubExpect(
        !str_contains(json_encode($preview, JSON_THROW_ON_ERROR), 'must_not_escape'),
        'raw remote item fields escaped into preview output',
    );
    $treeByName = [];
    foreach ($preview['tree'] as $group) {
        $treeByName[$group['name']] = $group;
    }
    catalogHubExpect(
        $treeByName['Facebook']['children'] === [[
            'name' => '货源A',
            'source_id' => 1,
            'count' => 1,
            'children' => [['name' => '其他', 'count' => 1]],
        ]]
            && $treeByName['Instagram']['children'] === [[
                'name' => '货源A',
                'source_id' => 1,
                'count' => 1,
                'children' => [['name' => '其他', 'count' => 1]],
            ]]
            && $treeByName['其他']['children'] === [[
                'name' => '货源A',
                'source_id' => 1,
                'count' => 1,
                'children' => [['name' => '其他', 'count' => 1]],
            ]],
        'direct platform and other trees must preserve the upstream category below the source alias',
    );
    catalogHubExpect(
        $treeByName['AI工具']['children'] === [
            ['name' => 'Claude', 'children' => [[
                'name' => '货源A',
                'source_id' => 1,
                'count' => 1,
                'children' => [['name' => '其他', 'count' => 1]],
            ]]],
            ['name' => 'GPT', 'children' => [[
                'name' => '货源A',
                'source_id' => 1,
                'count' => 1,
                'children' => [['name' => '其他', 'count' => 1]],
            ]]],
        ],
        'AI family trees must preserve the upstream category below the source alias',
    );

    $reorderedConfig = ConfigSchema::normalize([
        'aliases' => array_reverse($config['aliases']),
        'rules' => array_map(static function (array $rule): array {
            $rule['keywords'] = array_reverse($rule['keywords']);
            return $rule;
        }, array_reverse($config['rules'])),
    ]);
    $reorderedPreview = $planner->preview(1, array_reverse($catalog, true), $reorderedConfig);
    catalogHubExpect(
        hash_equals($preview['plan_hash'], $reorderedPreview['plan_hash']),
        'catalog/config order changed the deterministic preview hash',
    );

    $exactPreview = $planner->preview(1, [
        catalogHubItem('a', 'GPT'),
        catalogHubItem('b', 'ChatGPT'),
    ], [
        'aliases' => [['source_id' => 1, 'alias' => 'A']],
        'rules' => [catalogHubRule(['GPT'], 'AI', 'GPT', 100, 'exact')],
    ]);
    catalogHubExpect(
        $exactPreview['counts']['matched'] === 1 && $exactPreview['counts']['unclassified'] === 1,
        'exact literal matching is not exact',
    );
    catalogHubExpect($exactPreview['ready'] === true, 'exact-match fallback should remain ready without conflicts');

    $literalPreview = $planner->preview(1, [
        catalogHubItem('a', 'gpt premium'),
        catalogHubItem('b', 'offer gpt.* pack'),
    ], [
        'aliases' => [['source_id' => 1, 'alias' => 'A']],
        'rules' => [catalogHubRule(['gpt.*'], 'AI', 'Literal')],
    ]);
    catalogHubExpect(
        $literalPreview['counts']['matched'] === 1
            && $literalPreview['counts']['unclassified'] === 1,
        'user keyword was interpreted as a regular expression',
    );

    $conflictPreview = $planner->preview(1, [catalogHubItem('x', 'ChatGPT Plus')], [
        'aliases' => [['source_id' => 1, 'alias' => 'A']],
        'rules' => [
            catalogHubRule(['ChatGPT'], 'AI', 'GPT', 200),
            catalogHubRule(['ChatGPT'], 'Other', 'Account', 200),
            catalogHubRule(['ChatGPT'], 'Ignored', 'Lower', 100),
        ],
    ]);
    catalogHubExpect(
        $conflictPreview['counts']['conflicts'] === 1
            && count($conflictPreview['conflicts'][0]['targets']) === 2
            && $conflictPreview['ready'] === false
            && $conflictPreview['tree'] === []
            && $conflictPreview['counts']['unclassified'] === 0,
        'same-highest-priority different targets must conflict',
    );

    $flatPreview = $planner->preview(1, [catalogHubItem('flat', 'Facebook')], [
        'aliases' => [['source_id' => 1, 'alias' => 'A']],
        'rules' => [catalogHubRule(['Facebook'], 'Facebook', '', 100, 'exact')],
    ]);
    $nestedPreview = $planner->preview(1, [catalogHubItem('flat', 'Facebook')], [
        'aliases' => [['source_id' => 1, 'alias' => 'A']],
        'rules' => [catalogHubRule(['Facebook'], '社交媒体', 'Facebook', 100, 'exact')],
    ]);
    catalogHubExpect(
        !hash_equals($flatPreview['plan_hash'], $nestedPreview['plan_hash']),
        'changing a target between two and three levels must change the plan hash',
    );

    $rawCategoryPreview = $planner->preview(1, [
        catalogHubItem('telegram-api', 'Telegram API 商品', 'Telegram | API/真机账号'),
    ], [
        'aliases' => [['source_id' => 1, 'alias' => '货源A']],
        'rules' => [catalogHubRule(['Telegram'], 'Telegram', '', 100, 'contains')],
    ]);
    catalogHubExpect(
        $rawCategoryPreview['tree'] === [[
            'name' => 'Telegram',
            'children' => [[
                'name' => '货源A',
                'source_id' => 1,
                'count' => 1,
                'children' => [['name' => 'Telegram | API/真机账号', 'count' => 1]],
            ]],
        ]],
        'preview did not preserve the exact upstream category below the source alias',
    );

    $numericPreview = $planner->preview(1, [
        catalogHubItem('numeric-group', 'Numeric group'),
        catalogHubItem('numeric-family', 'Numeric family'),
    ], [
        'aliases' => [['source_id' => 1, 'alias' => 'A']],
        'rules' => [
            catalogHubRule(['Numeric group'], '123', '', 100, 'exact'),
            catalogHubRule(['Numeric family'], 'AI', '456', 100, 'exact'),
        ],
    ]);
    catalogHubExpect(
        $numericPreview['tree'][0]['name'] === '123'
            && $numericPreview['tree'][1]['children'][0]['name'] === '456',
        'numeric-looking group and family names must remain strings in preview output',
    );

    $manyUnclassified = [];
    for ($index = 1; $index <= 101; $index++) {
        $manyUnclassified[] = catalogHubItem('u' . $index, 'Unknown ' . $index);
    }
    $truncated = $planner->preview(1, $manyUnclassified, [
        'aliases' => [['source_id' => 1, 'alias' => 'A']],
        'rules' => [],
    ]);
    catalogHubExpect(
        count($truncated['unclassified']) === 100
            && $truncated['truncated']['unclassified'] === true
            && $truncated['counts']['unclassified'] === 101
            && $truncated['ready'] === true
            && $truncated['tree'][0] === [
                'name' => '其他',
                'children' => [[
                    'name' => 'A',
                    'source_id' => 1,
                    'count' => 101,
                    'children' => [['name' => '其他', 'count' => 101]],
                ]],
            ],
        'other fallback cap, truncation flag, or direct tree is wrong',
    );

    $repository = new ConfigRepository();
    $saved = $repository->save([
        'aliases' => [['source_id' => 1, 'alias' => '货源A']],
        'rules' => $defaults['rules'],
    ]);
    catalogHubExpect($repository->get() === $saved, 'CatalogHub external configuration did not round trip');
    catalogHubExpect($repository->categoryMode(1) === 'smart', 'legacy source must default to smart');
    $mirrorConfig = $repository->setCategoryMode(1, 'mirror');
    catalogHubExpect($mirrorConfig['aliases'][0]['category_mode'] === 'mirror', 'explicit mirror mode was not saved');
    $renamedMirror = $repository->upsertAlias(1, '镜像后台名');
    catalogHubExpect($renamedMirror['aliases'][0]['category_mode'] === 'mirror', 'display rename lost mirror mode');
    catalogHubFails(static fn() => (new AdminService())->preview(1), 'legacy preview must reject mirror before network');
    foreach ([null, true, '', 'automatic', []] as $invalidMode) {
        catalogHubFails(static fn() => ConfigSchema::normalize(['aliases' => [
            ['source_id' => 1, 'alias' => '测试源', 'category_mode' => $invalidMode],
        ]]), 'invalid explicit category mode accepted');
    }
    $repository->save($saved);
    $withUpdatedAlias = $repository->upsertAlias(1, '主货源');
    catalogHubExpect(
        $withUpdatedAlias['aliases'] === [['source_id' => 1, 'alias' => '主货源']]
            && $withUpdatedAlias['rules'] === $saved['rules'],
        'upserting one source alias changed unrelated classification rules',
    );
    $withSecondAlias = $repository->upsertAlias(2, '备用货源');
    catalogHubExpect(
        $withSecondAlias['aliases'] === [
            ['source_id' => 1, 'alias' => '主货源'],
            ['source_id' => 2, 'alias' => '备用货源'],
        ],
        'upserting a second source alias did not preserve canonical source order',
    );
    catalogHubFails(
        static fn() => $repository->upsertAlias(3, '备用货源'),
        'duplicate source aliases must fail closed during upsert',
    );
    $repository->save($saved);
    $adminSettings = (new AdminService())->save([
        'schema' => 1,
        'aliases' => $saved['aliases'],
        'rules' => [],
    ]);
    catalogHubExpect(
        $adminSettings['aliases'] === $saved['aliases'] && $adminSettings['rules'] === [],
        'saving literal rules changed the protected source aliases',
    );
    catalogHubFails(
        static fn() => (new AdminService())->save([
            'schema' => 1,
            'aliases' => [['source_id' => 1, 'alias' => '绕过编辑入口']],
            'rules' => [],
        ]),
        'generic settings save bypassed the source alias edit guard',
    );
    $repository->save($saved);
    catalogHubExpect(
        AtomicJson::$paths !== []
            && count(array_filter(
                AtomicJson::$paths,
                static fn(string $path): bool => str_ends_with($path, '/extensions/PikaCatalogHub/config.json'),
            )) === count(AtomicJson::$paths),
        'CatalogHub configuration escaped its fixed external state path',
    );

    CatalogHubSharedQueryStub::$rows = [
        (object)[
            'id' => 1,
            'name' => '上游一',
            'type' => 2,
            'domain' => 'https://source.example',
            'app_id' => 'merchant-1',
            'app_key' => 'must-not-leak',
            'currency' => 'USD',
            'currency_rate' => '2.500000',
        ],
    ];
    $bootstrap = (new AdminService())->bootstrap();
    catalogHubExpect(
        CatalogHubSharedQueryStub::$selected === [
            'id', 'name', 'type', 'domain', 'app_id', 'currency', 'currency_rate',
        ],
        'AdminService bootstrap source editor fields are incomplete',
    );
    catalogHubExpect(
        $bootstrap['sources'] === [[
            'id' => 1,
            'name' => '上游一',
            'type' => 2,
            'alias' => '货源A',
            'category_mode' => 'smart',
            'domain' => 'https://source.example',
            'app_id' => 'merchant-1',
            'currency' => 'USD',
            'currency_rate' => '2.500000',
        ]],
        'AdminService bootstrap source contract is wrong',
    );
    $bootstrapJson = json_encode($bootstrap, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    catalogHubExpect(
        !str_contains($bootstrapJson, 'must-not-leak'),
        'AdminService bootstrap leaked the stored key',
    );
    CatalogHubSharedQueryStub::$rows = array_map(
        static fn(int $id): object => (object)[
            'id' => $id,
            'name' => '上游' . $id,
            'type' => 0,
            'domain' => 'https://source-' . $id . '.example',
            'app_id' => 'merchant-' . $id,
            'currency' => 'CNY',
            'currency_rate' => '0',
        ],
        range(1, 17),
    );
    catalogHubFails(
        static fn() => (new AdminService())->bootstrap(),
        'AdminService must reject a seventeenth source in the public MVP',
    );

    $lockSource = new \App\Model\Shared();
    $lockSource->id = 18;
    $lockSource->name = str_repeat('店', 128);
    $lockSource->domain = 'https://source-lock.example';
    $lockSource->app_id = 'merchant-lock';
    $lockSource->app_key = 'credential-lock';
    $lockSource->currency = 'CNY';
    $lockSource->currency_rate = '0';
    CatalogHubSharedQueryStub::$rows = [$lockSource];
    $sourceLock = new \Pika\LocalExtensions\PikaSupplySync\Service\SourceLock();
    catalogHubExpect($sourceLock->acquire(18), 'source lock fixture could not acquire the source');
    catalogHubFails(
        static fn() => (new AdminService())->analyze(18, '货源锁测试'),
        'analysis task creation bypassed an active SupplySync source lock',
    );
    $sourceLock->release();
    $createdAnalysis = (new AdminService())->analyze(18, '货源锁测试');
    catalogHubExpect(
        ($createdAnalysis['state'] ?? null) === 'queued_analysis',
        'analysis task was not created after the shared source lock became available',
    );
    catalogHubExpect(
        (new AdminService())->bootstrap()['sources'][0]['name'] === str_repeat('店', 128),
        'connector and bootstrap source-name limits are inconsistent',
    );

    $bmPreview = $planner->preview(1, [catalogHubItem('bm', 'BM account')], [
        'aliases' => [['source_id' => 1, 'alias' => 'A']],
        'rules' => $defaults['rules'],
    ]);
    catalogHubExpect(
        $bmPreview['counts']['matched'] === 1
            && $bmPreview['tree'][0] === [
                'name' => 'Facebook',
                'children' => [[
                    'name' => 'A',
                    'source_id' => 1,
                    'count' => 1,
                    'children' => [['name' => '其他', 'count' => 1]],
                ]],
            ],
        'public Facebook defaults must include the literal BM token',
    );

    $manyConflicts = [];
    for ($index = 1; $index <= 101; $index++) {
        $manyConflicts[] = catalogHubItem('c' . $index, 'Shared conflict');
    }
    $conflictTruncated = $planner->preview(1, $manyConflicts, [
        'aliases' => [['source_id' => 1, 'alias' => 'A']],
        'rules' => [
            catalogHubRule(['Shared conflict'], 'One', 'Family', 100, 'exact'),
            catalogHubRule(['Shared conflict'], 'Two', 'Family', 100, 'exact'),
        ],
    ]);
    catalogHubExpect(
        count($conflictTruncated['conflicts']) === 100
            && $conflictTruncated['truncated']['conflicts'] === true
            && $conflictTruncated['counts']['conflicts'] === 101,
        'conflict preview cap or truncation flag is wrong',
    );

    $stressRules = [];
    for ($index = 1; $index <= 16; $index++) {
        $stressRules[] = catalogHubRule(['Stress match'], 'Stress ' . $index, 'Family', 100, 'exact');
    }
    $stressItems = [];
    for ($index = 1; $index <= 4000; $index++) {
        $stressItems[] = catalogHubItem('stress-' . $index, 'Stress match');
    }
    $stressPreview = $planner->preview(1, $stressItems, [
        'aliases' => [['source_id' => 1, 'alias' => 'A']],
        'rules' => $stressRules,
    ]);
    catalogHubExpect(
        $stressPreview['counts']['conflicts'] === 4000
            && count($stressPreview['conflicts']) === 100
            && $stressPreview['truncated']['conflicts'] === true,
        '4000-item bounded conflict preview failed',
    );
    $tooManyTargets = [...$stressRules, catalogHubRule(['Stress match'], 'Stress 17', 'Family', 100, 'exact')];
    catalogHubFails(
        static fn() => $planner->preview(1, [catalogHubItem('overflow', 'Stress match')], [
            'aliases' => [['source_id' => 1, 'alias' => 'A']],
            'rules' => $tooManyTargets,
        ]),
        'per-item conflict target limit was not enforced',
    );

    $clock = 1000;
    $lock = new PreviewLock(static function () use (&$clock): int { return $clock; });
    $lock->acquire();
    $lockPath = $fixture . '/extensions/PikaCatalogHub/preview.lock';
    $lockStat = lstat($lockPath);
    catalogHubExpect(
        is_array($lockStat)
            && ($lockStat['mode'] & 0o777) === 0o600
            && $lockStat['nlink'] === 1,
        'PreviewLock did not create a protected single-link file',
    );
    $parallel = new PreviewLock(static function () use (&$clock): int { return $clock; });
    catalogHubFails(static fn() => $parallel->acquire(), 'concurrent preview lock must fail non-blocking');
    $lock->release();
    catalogHubFails(static fn() => $parallel->acquire(), 'preview must enforce a 30-second cooldown');
    $clock += 30;
    $parallel->acquire();
    $parallel->release();

    $clock += 30;
    $hardLink = $fixture . '/preview-hardlink';
    if (!link($lockPath, $hardLink)) {
        throw new RuntimeException('unable to create PreviewLock hard-link fixture');
    }
    $unsafe = new PreviewLock(static function () use (&$clock): int { return $clock; });
    catalogHubFails(static fn() => $unsafe->acquire(), 'PreviewLock must reject nlink greater than one');

    fwrite(STDOUT, "PASS local catalog hub behavior\n");
}
