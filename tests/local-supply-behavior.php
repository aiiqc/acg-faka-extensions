<?php
declare(strict_types=1);

if (($argv[1] ?? null) === '--purifier-cache-only') {
    exit(runSupplyPurifierCacheBehavior());
}

/** Real-vendor cache gate; S0 prepares the site and external state before this non-root process. */
function runSupplyPurifierCacheBehavior(): int
{
    $report = [
        'schema' => 1, 'status' => 'FAIL', 'case' => null, 'stage' => 'preflight',
        'normalizeInvocations' => 0, 'normalizeCompleted' => 0, 'expectedRejections' => [],
        'resolverCalls' => 0, 'transportCalls' => 0, 'deprecationCount' => 0,
        'databaseConfigured' => false, 'rawMessagesRetained' => false, 'exception' => null,
    ];
    $require = static function (bool $condition): void {
        if (!$condition) { throw new LogicException('PURIFIER_CACHE_CHECK_FAILED'); }
    };
    $previousReporting = error_reporting(E_ALL);
    $previousIni = [];
    foreach (['display_errors' => '0', 'log_errors' => '0', 'zend.exception_ignore_args' => '1'] as $key => $value) {
        $previousIni[$key] = ini_set($key, $value);
    }
    set_error_handler(static function (int $severity, string $unused, string $file, int $line) use (&$report): bool {
        if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
            $report['deprecationCount']++;
            return true;
        }
        throw new ErrorException('purifier cache runtime warning', 0, $severity, $file, $line);
    });
    try {
        $official = getenv('ACG_FAKA_OFFICIAL_ROOT');
        $site = getenv('ACG_FAKA_PURIFIER_SITE_ROOT');
        $case = getenv('ACG_FAKA_PURIFIER_CASE');
        $require(PHP_SAPI === 'cli' && count($GLOBALS['argv'] ?? []) === 2
            && function_exists('posix_geteuid') && posix_geteuid() > 0
            && function_exists('posix_getegid') && !defined('BASE_PATH')
            && (string)ini_get('auto_prepend_file') === '' && (string)ini_get('auto_append_file') === ''
            && (string)ini_get('zend.exception_ignore_args') === '1'
            && is_string($official) && $official !== '/' && realpath($official) === $official && !is_link($official)
            && is_string($site) && $site !== '/' && realpath($site) === $site && !is_link($site)
            && is_dir($official) && is_dir($site) && in_array($case, ['fresh', 'legacy'], true));
        $report['case'] = $case;
        $report['uid'] = posix_geteuid();
        $report['gid'] = posix_getegid();
        $report['umask'] = sprintf('%04o', umask());
        foreach (['mbstring', 'bcmath', 'curl'] as $extension) { $require(extension_loaded($extension)); }
        define('BASE_PATH', $site);
        $report['stage'] = 'autoload';
        $sourceRoot = dirname(__DIR__);
        foreach ([
            $official . '/vendor/autoload.php',
            $sourceRoot . '/manager/site/local-extensions/src/PathGuard.php',
            $sourceRoot . '/extensions/PikaSupplySync/bootstrap.php',
        ] as $file) {
            $require(is_file($file) && !is_link($file) && realpath($file) === $file);
            require_once $file;
        }
        $require(\App\Model\Shared::getConnectionResolver() === null);
        $state = \Pika\LocalExtensions\Manager\PathGuard::stateRoot();
        $owner = \Pika\LocalExtensions\Manager\PathGuard::runtimeOwner();
        $require($owner === posix_geteuid());
        $cache = $state . '/extensions/PikaSupplySync/purifier';
        $report['stage'] = 'cache-preflight';
        clearstatcache();
        if ($case === 'legacy') {
            $require(!is_link($cache) && realpath($cache) === $cache && is_dir($cache)
                && fileowner($cache) === $owner && (fileperms($cache) & 0777) === 0700
                && scandir($cache) === ['.', '..', 'HTML']
                && !is_link($cache . '/HTML') && is_dir($cache . '/HTML')
                && fileowner($cache . '/HTML') === $owner && (fileperms($cache . '/HTML') & 0777) === 0600
                && scandir($cache . '/HTML') === ['.', '..']);
        } else {
            // S0 prepares only the base and foreign-owner input. Serializer types are fresh.
            // Keep every rename in this same parent, including the root-owned directory.
            $require($site !== $official && fileowner($site) === $owner && is_writable($site)
                && !is_link($cache) && is_dir($cache) && realpath($cache) === $cache
                && fileowner($cache) === $owner && (fileperms($cache) & 0777) === 0700
                && scandir($cache) === ['.', '..', 'purifier-foreign-owner']);
            $fixture = $cache;
            $report['freshSerializerTypesAbsent'] = true;
            foreach (['purifier-link-target', 'purifier-missing-target', 'purifier-valid-uri',
                'purifier-rejected-link', 'purifier-rejected-dangling', 'purifier-rejected-file'] as $name) {
                $require(!file_exists($fixture . '/' . $name) && !is_link($fixture . '/' . $name));
            }
            $foreign = $fixture . '/purifier-foreign-owner';
            $require(!is_link($foreign) && is_dir($foreign) && realpath($foreign) === $foreign
                && fileowner($foreign) === 0 && (fileperms($foreign) & 0777) === 0600);
        }
        $source = new \App\Model\Shared();
        $source->setRawAttributes([
            'id' => 1, 'type' => 0, 'name' => 'RecoveryFixture',
            'domain' => 'https://supply-recovery-fixture.invalid', 'currency' => 'CNY', 'currency_rate' => '1',
        ], true);
        $item = [
            'code' => 'A', 'name' => 'Recovery Fixture A', 'description' => 'Synthetic recovery fixture item A',
            'cover' => '', 'stock' => 1, 'price' => '10.00', 'user_price' => '8.00', 'config' => [], 'widget' => '[]',
        ];
        $input = json_encode($item, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $report['inputBytes'] = strlen($input);
        $report['inputSha256'] = hash('sha256', $input);
        $require(strlen($input) === 173
            && $report['inputSha256'] === 'fe3f75fc5f1a27520a40b81a1fc410461d613ee59006b3ff8a3897c5ab4064b0');
        $normalize = static function () use ($source, $item, $require, &$report): void {
            $budget = new \Pika\LocalExtensions\PikaSupplySync\Service\RunBudget();
            $policy = new \Pika\LocalExtensions\PikaSupplySync\Service\SourcePolicy(
                static function (string $host) use (&$report): array {
                    $report['resolverCalls']++;
                    throw new LogicException('PURIFIER_CACHE_DNS_FORBIDDEN');
                },
            );
            $http = new \Pika\LocalExtensions\PikaSupplySync\Service\SafeHttpClient(
                $policy,
                static function () use (&$report): array {
                    $report['transportCalls']++;
                    throw new LogicException('PURIFIER_CACHE_TRANSPORT_FORBIDDEN');
                },
                $budget,
            );
            $remote = new \Pika\LocalExtensions\PikaSupplySync\Service\RemoteItem(
                new \Pika\LocalExtensions\PikaSupplySync\Service\ImageCache($http, $budget), $budget,
            );
            $budget->beginSource(1);
            try {
                $report['normalizeInvocations']++;
                $normalized = $remote->normalize($source, $item, 'A');
                $checks = [
                    'codeMatches' => ($normalized['code'] ?? null) === 'A',
                    'nameMatches' => ($normalized['name'] ?? null) === $item['name'],
                    'descriptionMatches' => ($normalized['description'] ?? null) === $item['description'],
                    'emptyCoverFallback' => ($normalized['cover'] ?? null) === '/favicon.ico',
                    'stockMatches' => ($normalized['stock'] ?? null) === 1,
                    'priceMatches' => ($normalized['price'] ?? null) === 10.0,
                    'userPriceMatches' => ($normalized['user_price'] ?? null) === 8.0,
                    'configEmpty' => ($normalized['config'] ?? null) === '',
                    'widgetEmpty' => ($normalized['widget'] ?? null) === '[]',
                ];
                $require(!in_array(false, $checks, true));
                $report['safeChecks'] = $checks;
                $report['normalizeCompleted']++;
            } finally {
                $budget->endSource();
            }
        };
        // Only the three real Serializer types are inspected; no recursive state walk.
        $cacheFiles = static function () use ($cache, $owner, $require): array {
            $result = [];
            foreach (['HTML', 'CSS', 'URI'] as $type) {
                $directory = $cache . '/' . $type;
                clearstatcache(true, $directory);
                $require(!is_link($directory));
                if (!file_exists($directory)) { continue; }
                $require(is_dir($directory) && realpath($directory) === $directory
                    && fileowner($directory) === $owner && (fileperms($directory) & 0777) === 0700);
                $result[$type] = ['owner' => $owner, 'mode' => '0700', 'files' => []];
                foreach (array_diff(scandir($directory), ['.', '..']) as $name) {
                    $require(preg_match('/^[A-Za-z0-9_.,-]+\.ser$/D', $name) === 1);
                    $file = $directory . '/' . $name;
                    clearstatcache(true, $file);
                    $stat = lstat($file);
                    $require(is_array($stat) && ($stat['mode'] & 0170000) === 0100000
                        && $stat['nlink'] === 1 && $stat['uid'] === $owner && ($stat['mode'] & 0777) === 0600);
                    $hash = hash_file('sha256', $file);
                    $require(is_string($hash));
                    $result[$type]['files'][$name] = ['owner' => $stat['uid'], 'mode' => '0600', 'sha256' => $hash];
                }
            }
            $require(isset($result['HTML']) && count($result['HTML']['files']) > 0);
            return $result;
        };
        $report['stage'] = 'first-normalize';
        $normalize();
        $first = $cacheFiles();
        $report['firstCache'] = $first;
        if ($case === 'fresh') {
            $report['stage'] = 'negative-preparation';
            $unknown = $cache . '/unrelated-cache';
            $require(!file_exists($unknown) && !is_link($unknown) && mkdir($unknown, 0600));
            $unknownStat = lstat($unknown);
            $linkTarget = $fixture . '/purifier-link-target';
            $require(mkdir($linkTarget, 0600));
            $linkTargetStat = lstat($linkTarget);
            $uri = $cache . '/URI';
            $savedUri = file_exists($uri);
            if ($savedUri) { $require(rename($uri, $fixture . '/purifier-valid-uri')); }
            $require(chmod($cache . '/HTML', 0600));
            foreach (['symlink', 'dangling', 'file', 'owner'] as $invalid) {
                $report['stage'] = 'reject-' . $invalid;
                if ($invalid === 'symlink') {
                    $require(symlink($linkTarget, $uri));
                } elseif ($invalid === 'dangling') {
                    $require(symlink($fixture . '/purifier-missing-target', $uri));
                } elseif ($invalid === 'file') {
                    $handle = fopen($uri, 'x');
                    $require(is_resource($handle));
                    fclose($handle);
                    $require(chmod($uri, 0600));
                } else {
                    $require(rename($foreign, $uri));
                }
                clearstatcache();
                $before = lstat($uri);
                $require(is_array($before));
                $rejected = false;
                try {
                    $normalize();
                } catch (RuntimeException $exception) {
                    $require(get_class($exception) === RuntimeException::class
                        && $exception->getFile() === $sourceRoot . '/extensions/PikaSupplySync/Service/RemoteItem.php');
                    $rejected = true;
                }
                clearstatcache();
                $require($rejected && lstat($uri) === $before
                    && (fileperms($cache . '/HTML') & 0777) === 0600
                    && lstat($unknown) === $unknownStat && lstat($linkTarget) === $linkTargetStat);
                $report['expectedRejections'][] = $invalid;
                $destination = match ($invalid) {
                    'symlink' => $fixture . '/purifier-rejected-link',
                    'dangling' => $fixture . '/purifier-rejected-dangling',
                    'file' => $fixture . '/purifier-rejected-file',
                    'owner' => $foreign,
                };
                $require(!file_exists($destination) && !is_link($destination) && rename($uri, $destination));
            }
            if ($savedUri) { $require(rename($fixture . '/purifier-valid-uri', $uri)); }
            $report['stage'] = 'repair-three-types';
            $report['repairFixtureCreatedTypes'] = [];
            foreach (['HTML', 'CSS', 'URI'] as $type) {
                $directory = $cache . '/' . $type;
                if (!file_exists($directory)) {
                    // These are test inputs, not cache types generated by the first normalization.
                    $require(mkdir($directory, 0700));
                    $report['repairFixtureCreatedTypes'][] = $type;
                }
                $require(chmod($directory, 0600));
            }
            $normalize();
            $repaired = $cacheFiles();
            foreach ($first as $type => $identity) { $require($repaired[$type] === $identity); }
            clearstatcache();
            $require(lstat($unknown) === $unknownStat && lstat($linkTarget) === $linkTargetStat
                && fileowner($foreign) === 0 && (fileperms($foreign) & 0777) === 0600);
            $report['unknownSiblingUnchanged'] = true;
            $report['invalidObjectsPreserved'] = true;
        } else {
            $repaired = $first;
        }
        $report['stage'] = 'repeat-initialization';
        $normalize();
        $report['finalCache'] = $cacheFiles();
        $require($report['finalCache'] === $repaired
            && $report['normalizeInvocations'] === ($case === 'fresh' ? 7 : 2)
            && $report['normalizeCompleted'] === ($case === 'fresh' ? 3 : 2)
            && $report['resolverCalls'] === 0 && $report['transportCalls'] === 0
            && \App\Model\Shared::getConnectionResolver() === null);
        $report['repeatStable'] = true;
        $report['stage'] = 'complete';
        $report['status'] = 'PASS';
    } catch (Throwable $exception) {
        $class = get_class($exception);
        $file = basename($exception->getFile());
        $report['exception'] = [
            'class' => preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]{0,255}$/D', $class) === 1 ? $class : 'UNKNOWN_CLASS',
            'file' => preg_match('/^[A-Za-z0-9_.-]+$/D', $file) === 1 ? $file : 'UNKNOWN_FILE',
            'line' => $exception->getLine(),
            'severity' => $exception instanceof ErrorException ? $exception->getSeverity() : null,
        ];
    } finally {
        restore_error_handler();
        error_reporting($previousReporting);
        foreach ($previousIni as $key => $value) {
            if ($value !== false) { ini_set($key, $value); }
        }
    }
    fwrite(STDOUT, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    return $report['status'] === 'PASS' ? 0 : 1;
}

if (!class_exists('App\\Util\\Ini')) {
    final class LocalSupplyIniStub
    {
        public static bool $fail = false;
        public static ?string $configOutput = null;
        public static ?array $arrayOutput = null;

        public static function toConfig(array $value): string
        {
            if (self::$fail) {
                throw new RuntimeException('synthetic INI dependency failure');
            }
            return self::$configOutput ?? json_encode($value, JSON_THROW_ON_ERROR);
        }

        public static function toArray(string $value): array
        {
            if (self::$arrayOutput !== null) { return self::$arrayOutput; }
            throw new RuntimeException('synthetic INI parser failure');
        }
    }
    class_alias(LocalSupplyIniStub::class, 'App\\Util\\Ini');
}

if (!class_exists('App\\Util\\Str')) {
    final class LocalSupplyStrStub
    {
        public static function generateSignature(array $data, string $key): string { return 'fixture-signature'; }
    }
    class_alias(LocalSupplyStrStub::class, 'App\\Util\\Str');
}

if (!class_exists('App\\Util\\SharedCurrency')) {
    final class LocalSupplySharedCurrencyStub
    {
        public static bool $failFactor = false;
        public static bool $failItem = false;

        public static function factor(\App\Model\Shared $source): string
        {
            if (self::$failFactor) { throw new RuntimeException('synthetic currency factor failure'); }
            return '1';
        }

        public static function item(array $item, string $factor): array
        {
            if (self::$failItem) { throw new RuntimeException('synthetic currency conversion failure'); }
            return $item;
        }

        public static function tree(array $tree, string $factor): array
        {
            return $tree;
        }
    }
    class_alias(LocalSupplySharedCurrencyStub::class, 'App\\Util\\SharedCurrency');
}

if (!class_exists('App\\Model\\PriceTemplate')) {
    final class LocalSupplyPriceTemplateStub
    {
        public const TYPE_FIXED = 0;
        public const TYPE_PERCENT = 1;
    }
    class_alias(LocalSupplyPriceTemplateStub::class, 'App\\Model\\PriceTemplate');
}

if (!class_exists('App\\Model\\Shared')) {
    final class LocalSupplySharedStub
    {
        public int $id = 1;
        public string $domain = '';
        public string $app_id = '';
        public string $app_key = '';
        public int $type = 0;
    }
    class_alias(LocalSupplySharedStub::class, 'App\\Model\\Shared');
}

if (!class_exists('App\\Model\\Commodity')) {
    // These prewrite failure fixtures represent a new item, not an existing row.
    final class LocalSupplyMissingCommodityStub
    {
        public int $shared_id = 1;
        public int $shared_amount_sync = 0;
        public int $shared_config_sync = 0;
        public int $inventory_sync = 0;
        public string $config = '';
        private array $filters = [];
        public static int $lookups = 0;
        public static function query(): self { return new self(); }
        public function where(string $field, mixed $value): self
        {
            $this->filters[$field] = $value;
            return $this;
        }
        public function exists(): bool
        {
            expect($this->filters === ['owner' => 0, 'shared_id' => 1, 'shared_code' => 'SAFE-NAME'],
                'new-item precheck lost its exact ownership/source/code scope');
            self::$lookups++;
            return false;
        }
    }
    class_alias(LocalSupplyMissingCommodityStub::class, 'App\\Model\\Commodity');
}

if (!class_exists('HTMLPurifier_Config')) {
    final class HTMLPurifier_Config
    {
        public static function createDefault(): self { return new self(); }
        public function set(string $key, mixed $value): void {}
    }
}

if (!class_exists('HTMLPurifier')) {
    final class HTMLPurifier
    {
        public static bool $fail = false;
        public function __construct(HTMLPurifier_Config $config) {}
        public function purify(string $html): string
        {
            if (self::$fail) { throw new RuntimeException('synthetic purifier failure'); }
            return str_replace('&', '&amp;', $html);
        }
    }
}

if (($argv[1] ?? null) === '--diagnostic-only') {
    $diagnosticEntryClasses = get_declared_classes();
    ob_start();
    try {
        require_once dirname(__DIR__) . '/scripts/diagnose-supply-item.php';
        $diagnosticEntryOutput = ob_get_clean();
    } catch (Throwable $failure) {
        ob_end_clean();
        throw $failure;
    }
    expect($diagnosticEntryOutput === '', 'standalone diagnostic entry emitted output while loading');
    expect(!defined('BASE_PATH'), 'standalone diagnostic entry initialized the application');
    expect(get_declared_classes() === $diagnosticEntryClasses,
        'standalone diagnostic entry loaded a class dependency');
    $diagnosticServiceRoot = dirname(__DIR__) . '/extensions/PikaSupplySync/Service';
    foreach ([
        'SourcePolicy.php',
        'UpstreamFailure.php',
        'RunBudget.php',
        'SafeHttpClient.php',
        'SharedGateway.php',
    ] as $diagnosticDependency) {
        require_once $diagnosticServiceRoot . '/' . $diagnosticDependency;
    }
    runSupplyDiagnosticBehavior();
    fwrite(STDOUT, "local supply diagnostic behavior PASS\n");
    exit(0);
}

if (!defined('BASE_PATH')) {
    $supplySuffix = bin2hex(random_bytes(6));
    $supplyFixtureRoot = sys_get_temp_dir() . '/pika-supply-behavior-fixture-' . $supplySuffix;
    $supplyWebUid = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $supplyWebGid = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if (!is_int($supplyWebUid) || !is_int($supplyWebGid)
        || !function_exists('posix_setuid') || posix_geteuid() !== 0) {
        throw new RuntimeException('supply behavior fixture requires root plus WEB_UID WEB_GID');
    }
    if (!is_dir($supplyFixtureRoot) && !mkdir($supplyFixtureRoot, 0700, true) && !is_dir($supplyFixtureRoot)) {
        throw new RuntimeException('unable to create supply behavior fixture');
    }
    $supplyStateBase = '/var/lib/pika-local-extensions/sites';
    if (!mkdir($supplyStateBase, 0755, true) && !is_dir($supplyStateBase)) {
        throw new RuntimeException('unable to create supply state fixture');
    }
    chmod('/var/lib/pika-local-extensions', 0755);
    chmod($supplyStateBase, 0755);
    chown('/var/lib/pika-local-extensions', 0);
    chgrp('/var/lib/pika-local-extensions', 0);
    chown($supplyStateBase, 0);
    chgrp($supplyStateBase, 0);
    define('BASE_PATH', $supplyFixtureRoot . '/');
    $supplySiteState = $supplyStateBase . '/' . hash('sha256', realpath($supplyFixtureRoot));
    mkdir($supplySiteState . '/runtime', 0750, true);
    chmod($supplyStateBase, 0755);
    chmod($supplySiteState, 0755);
    chmod($supplySiteState . '/runtime', 0750);
    chown($supplySiteState, 0);
    chgrp($supplySiteState, 0);
    chown($supplySiteState . '/runtime', $supplyWebUid);
    chgrp($supplySiteState . '/runtime', $supplyWebGid);
    chown($supplyFixtureRoot, $supplyWebUid);
    chgrp($supplyFixtureRoot, $supplyWebGid);
    chmod($supplyFixtureRoot, 0750);
    if (!posix_setgid($supplyWebGid) || !posix_setuid($supplyWebUid)) {
        throw new RuntimeException('unable to drop to isolated supply web identity');
    }
}

require dirname(__DIR__) . '/manager/site/local-extensions/src/PathGuard.php';
require dirname(__DIR__) . '/extensions/PikaSupplySync/bootstrap.php';

use Pika\LocalExtensions\PikaSupplySync\Service\CatalogPlanner;
use Pika\LocalExtensions\PikaSupplySync\Service\CategoryMapper;
use Pika\LocalExtensions\PikaSupplySync\Service\CommodityImporter;
use Pika\LocalExtensions\PikaSupplySync\Service\CommodityImportFailure;
use Pika\LocalExtensions\PikaSupplySync\Service\BudgetExceeded;
use Pika\LocalExtensions\PikaSupplySync\Service\UpstreamFailure;
use Pika\LocalExtensions\PikaSupplySync\Service\ImageCache;
use Pika\LocalExtensions\PikaSupplySync\Service\LocalPath;
use Pika\LocalExtensions\PikaSupplySync\Service\Options;
use Pika\LocalExtensions\PikaSupplySync\Service\PlannedCategoryMapper;
use Pika\LocalExtensions\PikaSupplySync\Service\PriceAdjuster;
use Pika\LocalExtensions\PikaSupplySync\Service\RemoteItem;
use Pika\LocalExtensions\PikaSupplySync\Service\RemoteItemDataInvalid;
use Pika\LocalExtensions\PikaSupplySync\Service\Redactor;
use Pika\LocalExtensions\PikaSupplySync\Service\RetryableTransportFailure;
use Pika\LocalExtensions\PikaSupplySync\Service\RunBudget;
use Pika\LocalExtensions\PikaSupplySync\Service\SafeHttpClient;
use Pika\LocalExtensions\PikaSupplySync\Service\SharedGateway;
use Pika\LocalExtensions\PikaSupplySync\Service\SourceLock;
use Pika\LocalExtensions\PikaSupplySync\Service\SourcePolicy;
use Pika\LocalExtensions\PikaSupplySync\Service\StateStore;
use Pika\LocalExtensions\PikaSupplySync\Service\SyncService;

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fails(callable $callable, string $message): void
{
    try {
        $callable();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException($message);
}

function runSupplyDiagnosticBehavior(): void
{
    $reportKeys = [
        'schema_version', 'diagnostic_only', 'category', 'http_status', 'curl_code', 'elapsed_ms', 'attempts',
        'mime', 'body_bytes', 'body_complete', 'json_valid', 'json_error', 'top_level', 'within_json_limits',
        'business_success', 'shape',
    ];
    $mimeKeys = [
        'observed', 'response_blocks', 'final_status', 'final_headers_complete', 'final_header_count',
        'content_type_present', 'content_type_count', 'category', 'effective_category', 'counts_truncated',
    ];
    $shapeKeys = ['code', 'data', 'detail', 'children', 'name', 'stock', 'config', 'sku'];
    $valueTypes = ['object', 'array', 'string', 'number', 'boolean', 'null', 'missing', 'unobserved'];
    $mimeCategories = [
        'application_json', 'text_json', 'json_suffix', 'text_html', 'text_plain', 'missing', 'empty',
        'other', 'malformed', 'conflicting', 'unknown',
    ];
    $reportCategories = [
        'preflight', 'none', 'transport', 'http_retryable', 'http_rejected', 'content_type', 'json', 'schema',
        'business', 'response_size', 'budget', 'unknown',
    ];
    $assertKeys = static function (array $actual, array $expected, string $label): void {
        $actualKeys = array_keys($actual);
        sort($actualKeys, SORT_STRING);
        sort($expected, SORT_STRING);
        expect($actualKeys === $expected, "{$label} keys changed");
    };
    $assertReport = static function (array $report) use (
        $reportKeys,
        $mimeKeys,
        $shapeKeys,
        $valueTypes,
        $mimeCategories,
        $reportCategories,
        $assertKeys,
    ): void {
        $assertKeys($report, $reportKeys, 'diagnostic report');
        expect($report['schema_version'] === 1 && $report['diagnostic_only'] === true,
            'diagnostic report identity changed');
        expect(in_array($report['category'], $reportCategories, true), 'diagnostic report category escaped allowlist');
        foreach (['http_status', 'curl_code', 'elapsed_ms', 'attempts'] as $integerField) {
            expect(is_int($report[$integerField]) && $report[$integerField] >= 0,
                "diagnostic {$integerField} must be a nonnegative integer");
        }
        expect(is_array($report['mime']), 'diagnostic MIME report must be an object');
        $assertKeys($report['mime'], $mimeKeys, 'diagnostic MIME report');
        foreach (['response_blocks', 'final_status', 'final_header_count', 'content_type_count'] as $integerField) {
            expect(is_int($report['mime'][$integerField]) && $report['mime'][$integerField] >= 0,
                "diagnostic MIME {$integerField} must be a nonnegative integer");
        }
        foreach (['observed', 'final_headers_complete', 'counts_truncated'] as $booleanField) {
            expect(is_bool($report['mime'][$booleanField]), "diagnostic MIME {$booleanField} must be boolean");
        }
        expect(is_bool($report['mime']['content_type_present']) || $report['mime']['content_type_present'] === null,
            'diagnostic MIME presence must be boolean or unknown');
        expect(in_array($report['mime']['category'], $mimeCategories, true)
            && in_array($report['mime']['effective_category'], $mimeCategories, true),
            'diagnostic MIME category escaped allowlist');
        expect(is_int($report['body_bytes']) || $report['body_bytes'] === null,
            'diagnostic body size must be integer or unknown');
        expect(is_bool($report['body_complete']), 'diagnostic body completeness must be boolean');
        foreach (['json_valid', 'within_json_limits', 'business_success'] as $nullableBoolean) {
            expect(is_bool($report[$nullableBoolean]) || $report[$nullableBoolean] === null,
                "diagnostic {$nullableBoolean} must be boolean or unknown");
        }
        expect(in_array($report['json_error'], ['none', 'syntax', 'utf8', 'depth', 'nodes', 'unobserved'], true),
            'diagnostic JSON error escaped allowlist');
        expect(in_array($report['top_level'], $valueTypes, true), 'diagnostic top-level type escaped allowlist');
        expect(is_array($report['shape']), 'diagnostic shape must be an object');
        $assertKeys($report['shape'], $shapeKeys, 'diagnostic shape');
        foreach ($report['shape'] as $shapeType) {
            expect(in_array($shapeType, $valueTypes, true), 'diagnostic shape type escaped allowlist');
        }
    };

    $expectedUnavailable = [
        'schema_version' => 1,
        'diagnostic_only' => true,
        'category' => 'preflight',
        'http_status' => 0,
        'curl_code' => 0,
        'elapsed_ms' => 0,
        'attempts' => 0,
        'mime' => [
            'observed' => false,
            'response_blocks' => 0,
            'final_status' => 0,
            'final_headers_complete' => false,
            'final_header_count' => 0,
            'content_type_present' => null,
            'content_type_count' => 0,
            'category' => 'unknown',
            'effective_category' => 'unknown',
            'counts_truncated' => false,
        ],
        'body_bytes' => null,
        'body_complete' => false,
        'json_valid' => null,
        'json_error' => 'unobserved',
        'top_level' => 'unobserved',
        'within_json_limits' => null,
        'business_success' => null,
        'shape' => array_fill_keys($shapeKeys, 'unobserved'),
    ];
    expect(SafeHttpClient::diagnosticUnavailable() === $expectedUnavailable,
        'fixed unavailable diagnostic report changed');
    if (function_exists('pikaSupplyItemDiagnosticUnavailable')) {
        expect(pikaSupplyItemDiagnosticUnavailable() === $expectedUnavailable,
            'standalone entry and safe client unavailable reports diverged');
    }
    $assertReport($expectedUnavailable);

    $resolver = static fn(string $host): array => ['93.184.216.34'];
    $policy = new SourcePolicy($resolver);
    $runDiagnostic = static function (
        array|Throwable $outcome,
        array $headerLines = [],
        string $url = 'https://example.com/shared/commodity/item',
        int $sourceType = 0,
    ) use ($policy, $assertReport): array {
        $calls = 0;
        $sleeps = [];
        $client = null;
        $headerParser = new ReflectionMethod(SafeHttpClient::class, 'recordDiagnosticHeader');
        $transport = static function (
            array $endpoint,
            string $address,
            string $method,
            array $headers,
            string $body,
            int $maxBytes,
            int $connectTimeoutMs,
            int $requestTimeoutMs,
        ) use (&$calls, &$client, $headerParser, $headerLines, $outcome): array {
            $calls++;
            foreach ($headerLines as $headerLine) {
                $headerParser->invoke($client, $headerLine);
            }
            if ($outcome instanceof Throwable) {
                throw $outcome;
            }
            return $outcome + ['connected_ip' => $address];
        };
        $client = new SafeHttpClient(
            $policy,
            $transport,
            null,
            static function (int $milliseconds) use (&$sleeps): void { $sleeps[] = $milliseconds; },
        );
        $report = $client->diagnosePostJson($url, ['Accept: application/json'], ['code' => 'SAFE-CODE'], $sourceType);
        $assertReport($report);
        return [$report, $calls, $sleeps];
    };

    $validBody = json_encode(['code' => 200, 'data' => [
        'name' => 'TOPSECRET-NAME', 'stock' => 1, 'config' => [], 'sku' => [],
    ]], JSON_THROW_ON_ERROR);
    $validResponse = ['status' => 200, 'content_type' => 'application/json; charset=utf-8', 'body' => $validBody];
    [$validReport, $validCalls, $validSleeps] = $runDiagnostic($validResponse);
    expect($validCalls === 1 && $validSleeps === [] && $validReport['attempts'] === 1,
        'successful diagnostic did not use exactly one transport attempt');
    expect($validReport['category'] === 'none' && $validReport['http_status'] === 200
        && $validReport['body_bytes'] === strlen($validBody) && $validReport['body_complete'],
        'successful diagnostic lost bounded transport metadata');
    expect($validReport['mime']['observed'] === false
        && $validReport['mime']['effective_category'] === 'application_json',
        'missing raw headers were fabricated or effective MIME was lost');
    expect($validReport['json_valid'] === true && $validReport['json_error'] === 'none'
        && $validReport['top_level'] === 'object' && $validReport['within_json_limits'] === true
        && $validReport['business_success'] === true,
        'valid diagnostic JSON or business envelope was misclassified');
    expect($validReport['shape'] === [
        'code' => 'number', 'data' => 'object', 'detail' => 'object', 'children' => 'unobserved',
        'name' => 'string', 'stock' => 'number', 'config' => 'array', 'sku' => 'array',
    ], 'diagnostic shape exposed values or lost fixed field types');
    expect(!str_contains(json_encode($validReport, JSON_THROW_ON_ERROR), 'TOPSECRET'),
        'diagnostic report leaked a response value');

    $ordinaryJsonCalls = 0;
    $ordinaryJsonSleeps = [];
    $ordinaryJson = (new SafeHttpClient(
        $policy,
        static function ($endpoint, $address) use (&$ordinaryJsonCalls, $validResponse): array {
            $ordinaryJsonCalls++;
            return $validResponse + ['connected_ip' => $address];
        },
        null,
        static function (int $milliseconds) use (&$ordinaryJsonSleeps): void {
            $ordinaryJsonSleeps[] = $milliseconds;
        },
    ))->postJson('https://example.com/shared/commodity/item', [], ['code' => 'SAFE-CODE']);
    expect(($ordinaryJson['code'] ?? null) === 200 && $ordinaryJsonCalls === 1 && $ordinaryJsonSleeps === [],
        'diagnostic changes regressed an ordinary successful JSON request');

    $ordinaryTransportCalls = 0;
    $ordinaryTransportSleeps = [];
    $ordinaryTransportFailure = null;
    try {
        (new SafeHttpClient(
            $policy,
            static function () use (&$ordinaryTransportCalls): array {
                $ordinaryTransportCalls++;
                throw new RetryableTransportFailure(CURLE_OPERATION_TIMEDOUT);
            },
            null,
            static function (int $milliseconds) use (&$ordinaryTransportSleeps): void {
                $ordinaryTransportSleeps[] = $milliseconds;
            },
        ))->postJson('https://example.com/shared/commodity/item', [], ['code' => 'SAFE-CODE']);
    } catch (UpstreamFailure $failure) {
        $ordinaryTransportFailure = $failure;
    }
    expect($ordinaryTransportFailure instanceof UpstreamFailure
        && $ordinaryTransportFailure->diagnostics['category'] === 'transport'
        && $ordinaryTransportFailure->diagnostics['attempts'] === 3
        && $ordinaryTransportCalls === 3 && $ordinaryTransportSleeps === [500, 1000],
        'diagnostic changes altered the ordinary three-attempt transport boundary');

    $ordinaryHttpCalls = 0;
    $ordinaryHttpSleeps = [];
    $ordinaryHttpFailure = null;
    try {
        (new SafeHttpClient(
            $policy,
            static function ($endpoint, $address) use (&$ordinaryHttpCalls): array {
                $ordinaryHttpCalls++;
                return [
                    'status' => 429,
                    'content_type' => 'application/json',
                    'body' => '{"code":429,"data":{}}',
                    'connected_ip' => $address,
                ];
            },
            null,
            static function (int $milliseconds) use (&$ordinaryHttpSleeps): void {
                $ordinaryHttpSleeps[] = $milliseconds;
            },
        ))->postJson('https://example.com/shared/commodity/item', [], ['code' => 'SAFE-CODE']);
    } catch (UpstreamFailure $failure) {
        $ordinaryHttpFailure = $failure;
    }
    expect($ordinaryHttpFailure instanceof UpstreamFailure
        && $ordinaryHttpFailure->diagnostics['category'] === 'http_retryable'
        && $ordinaryHttpFailure->diagnostics['attempts'] === 3
        && $ordinaryHttpCalls === 3 && $ordinaryHttpSleeps === [500, 1000],
        'diagnostic changes altered the ordinary three-attempt HTTP 429 boundary');

    [$transportReport, $transportCalls, $transportSleeps] = $runDiagnostic(
        new RetryableTransportFailure(CURLE_OPERATION_TIMEDOUT),
    );
    expect($transportCalls === 1 && $transportSleeps === [] && $transportReport['attempts'] === 1
        && $transportReport['category'] === 'transport'
        && $transportReport['curl_code'] === CURLE_OPERATION_TIMEDOUT,
        'retryable diagnostic transport failure retried or lost its bounded category');
    [$retryableReport, $retryableCalls, $retryableSleeps] = $runDiagnostic([
        'status' => 429, 'content_type' => 'application/json', 'body' => '{"code":429,"data":{}}',
    ]);
    expect($retryableCalls === 1 && $retryableSleeps === [] && $retryableReport['attempts'] === 1
        && $retryableReport['category'] === 'http_retryable' && $retryableReport['http_status'] === 429,
        'retryable diagnostic HTTP response retried or lost its bounded category');

    foreach ([
        ['http://example.com/shared/commodity/item', 0],
        ['https://example.com/shared/commodity/items', 0],
        ['https://example.com/shared/commodity/item', 3],
    ] as [$rejectedUrl, $rejectedType]) {
        [$preflightReport, $preflightCalls] = $runDiagnostic($validResponse, [], $rejectedUrl, $rejectedType);
        expect($preflightCalls === 0 && $preflightReport['category'] === 'preflight'
            && $preflightReport['attempts'] === 0,
            'diagnostic preflight rejection initiated transport');
    }

    [$mismatchedIpReport, $mismatchedIpCalls] = $runDiagnostic([
        'status' => 200,
        'content_type' => 'application/json',
        'body' => '{"code":200,"data":{"name":"TOPSECRET-IP-MISMATCH"}}',
        'connected_ip' => '1.1.1.1',
    ]);
    expect($mismatchedIpCalls === 1 && $mismatchedIpReport['attempts'] === 1
        && $mismatchedIpReport['category'] === 'unknown'
        && $mismatchedIpReport['body_complete'] === false
        && $mismatchedIpReport['json_valid'] === null
        && $mismatchedIpReport['json_error'] === 'unobserved'
        && $mismatchedIpReport['top_level'] === 'unobserved'
        && $mismatchedIpReport['within_json_limits'] === null
        && $mismatchedIpReport['business_success'] === null
        && $mismatchedIpReport['shape'] === array_fill_keys($shapeKeys, 'unobserved')
        && !str_contains(json_encode($mismatchedIpReport, JSON_THROW_ON_ERROR), 'TOPSECRET'),
        'connected-IP mismatch was parsed, retried, or leaked response values');

    $privateSourceCalls = 0;
    $privateSourcePolicy = new SourcePolicy(static fn(string $host): array => ['127.0.0.1']);
    $privateSourceClient = new SafeHttpClient(
        $privateSourcePolicy,
        static function () use (&$privateSourceCalls): array {
            $privateSourceCalls++;
            throw new RuntimeException('private-source transport must not run');
        },
    );
    $privateSourceReport = $privateSourceClient->diagnosePostJson(
        'https://example.com/shared/commodity/item', [], ['code' => 'SAFE-CODE'], 0,
    );
    $assertReport($privateSourceReport);
    expect($privateSourceCalls === 0 && $privateSourceReport['attempts'] === 0
        && $privateSourceReport['category'] === 'preflight'
        && $privateSourceReport['body_bytes'] === null
        && $privateSourceReport['body_complete'] === false
        && $privateSourceReport['json_valid'] === null
        && $privateSourceReport['json_error'] === 'unobserved'
        && $privateSourceReport['top_level'] === 'unobserved'
        && $privateSourceReport['within_json_limits'] === null
        && $privateSourceReport['business_success'] === null
        && $privateSourceReport['shape'] === array_fill_keys($shapeKeys, 'unobserved'),
        'private-resolved diagnostic source reached transport or response parsing');

    $multiBlockHeaders = [
        "HTTP/1.1 100 Continue\r\n",
        "Content-Type: text/plain\r\n",
        "\r\n",
        "HTTP/2 200\r\n",
        "cOnTeNt-TyPe: Application/JSON; Charset=UTF-8\r\n",
        "X-Bounded: 1\r\n",
        "Set-Cookie: session=TOPSECRET-COOKIE\r\n",
        "X-Private: https://private.invalid/TOPSECRET-HEADER\r\n",
        "\r\n",
        "Content-Type: text/html\r\n",
    ];
    [$headerReport] = $runDiagnostic($validResponse, $multiBlockHeaders);
    expect($headerReport['mime'] === [
        'observed' => true, 'response_blocks' => 2, 'final_status' => 200,
        'final_headers_complete' => true, 'final_header_count' => 4,
        'content_type_present' => true, 'content_type_count' => 1,
        'category' => 'application_json', 'effective_category' => 'application_json',
        'counts_truncated' => false,
    ], 'diagnostic MIME parser lost final block, case/parameter handling, or trailer boundary');
    expect(!preg_match('/TOPSECRET|private\.invalid|session=/', json_encode($headerReport, JSON_THROW_ON_ERROR)),
        'diagnostic MIME report leaked an unknown header name or value');

    [$duplicateMime] = $runDiagnostic($validResponse, [
        "HTTP/1.1 200 OK\r\n",
        "Content-Type: application/json\r\n",
        "content-type: APPLICATION/JSON; CHARSET=UTF-8\r\n",
        "\r\n",
    ]);
    expect($duplicateMime['mime']['content_type_count'] === 2
        && $duplicateMime['mime']['category'] === 'application_json',
        'duplicate equivalent Content-Type fields became conflicting');
    [$conflictingMime] = $runDiagnostic($validResponse, [
        "HTTP/1.1 200 OK\r\n",
        "Content-Type: application/json\r\n",
        "Content-Type: text/html\r\n",
        "\r\n",
    ]);
    expect($conflictingMime['mime']['content_type_count'] === 2
        && $conflictingMime['mime']['category'] === 'conflicting',
        'conflicting Content-Type fields were not reduced to a safe category');
    foreach ([
        [["HTTP/1.1 200 OK\r\n", "\r\n"], false, 0, 'missing'],
        [["HTTP/1.1 200 OK\r\n", "Content-Type:\t\r\n", "\r\n"], true, 1, 'empty'],
        [["HTTP/1.1 200 OK\r\n", "Content-Type : application/json\r\n", "\r\n"], true, 1, 'malformed'],
    ] as [$headers, $present, $count, $category]) {
        [$mimeReport] = $runDiagnostic($validResponse, $headers);
        expect($mimeReport['mime']['content_type_present'] === $present
            && $mimeReport['mime']['content_type_count'] === $count
            && $mimeReport['mime']['category'] === $category,
            'missing, empty, or malformed Content-Type was misclassified');
    }

    $badMimeResponse = ['status' => 200, 'content_type' => 'text/html; charset=UTF-8', 'body' => $validBody];
    [$badMimeReport] = $runDiagnostic($badMimeResponse, [
        "HTTP/1.1 200 OK\r\n", "Content-Type: text/html; Charset=UTF-8\r\n", "\r\n",
    ]);
    expect($badMimeReport['category'] === 'content_type'
        && $badMimeReport['mime']['category'] === 'text_html'
        && $badMimeReport['json_valid'] === true && $badMimeReport['business_success'] === true,
        'bad MIME did not retain safe JSON observations behind the rejection gate');
    $ordinaryMimeCalls = 0;
    $ordinaryMimeClient = new SafeHttpClient($policy, static function ($endpoint, $address) use (&$ordinaryMimeCalls, $badMimeResponse): array {
        $ordinaryMimeCalls++;
        return $badMimeResponse + ['connected_ip' => $address];
    });
    $ordinaryMimeResult = $ordinaryMimeClient->postJson('https://example.com/shared/commodity/item', [], ['code' => 'SAFE-CODE']);
    expect($ordinaryMimeResult === json_decode($validBody, true, 32, JSON_THROW_ON_ERROR)
        && $ordinaryMimeCalls === 1 && $ordinaryMimeClient->detailDiagnostics()['mime_category'] === 'text_html'
        && $ordinaryMimeClient->detailDiagnostics()['json_valid'] === true
        && $ordinaryMimeClient->detailDiagnostics()['mime_compatibility'] === true,
        'ordinary detail did not accept exact JSON with a safe MIME compatibility observation');
    $ordinaryMimeRejected = false;
    try {
        (new SafeHttpClient($policy, static function ($endpoint, $address) use (&$ordinaryMimeCalls, $badMimeResponse): array {
            $ordinaryMimeCalls++;
            return $badMimeResponse + ['connected_ip' => $address];
        }))->postJson('https://example.com/shared/commodity/items', [], ['code' => 'SAFE-CODE']);
    } catch (UpstreamFailure $failure) {
        $ordinaryMimeRejected = $failure->diagnostics['category'] === 'content_type';
    }
    expect($ordinaryMimeRejected && $ordinaryMimeCalls === 2,
        'detail MIME compatibility weakened catalog MIME rejection');

    $runNormalDetail = static function (array|Throwable $outcome, string $path, array $headerLines = []) use ($policy): array {
        $calls = 0;
        $sleeps = [];
        $client = null;
        $parser = new ReflectionMethod(SafeHttpClient::class, 'recordDiagnosticHeader');
        $client = new SafeHttpClient($policy, static function ($endpoint, $address) use (
            &$calls, &$client, $outcome, $headerLines, $parser,
        ): array {
            $calls++;
            foreach ($headerLines as $line) { $parser->invoke($client, $line); }
            if ($outcome instanceof Throwable) { throw $outcome; }
            return $outcome + ['connected_ip' => $address];
        }, null, static function (int $ms) use (&$sleeps): void { $sleeps[] = $ms; });
        $failure = null;
        $result = null;
        try { $result = $client->postJson('https://example.com' . $path, [], ['code' => 'SAFE-CODE']); }
        catch (Throwable $caught) { $failure = $caught; }
        return [$result, $failure, $client->detailDiagnostics(), $calls, $sleeps];
    };
    foreach (['/shared/commodity/item', '/plugin/open-api/item', '/plugin/SharedStock/api/item'] as $detailPath) {
        foreach (['text/html', 'text/plain', 'application/problem+json', '', 'application/TOPSECRET-MIME'] as $mimeValue) {
            [$result, $failure, $safe, $calls, $sleeps] = $runNormalDetail(
                ['status' => 200, 'content_type' => $mimeValue, 'body' => $validBody], $detailPath,
            );
            expect($failure === null && is_array($result) && $calls === 1 && $sleeps === [],
                'exact detail JSON was rejected or retried because of MIME');
            expect($safe['category'] === 'none' && $safe['json_valid'] === true && $safe['mime_compatibility'] === true
                && $safe['mime_count'] === null && !str_contains(json_encode($safe), 'TOPSECRET'),
                'detail MIME observation leaked values or claimed an unobserved header count');
            expect(!array_key_exists('json_error_code', $safe) && !array_key_exists('json_error', $safe),
                'successful detail fabricated a JSON exception');
        }
    }
    foreach ([
        '/shared/commodity/items', '/plugin/open-api/items', '/plugin/SharedStock/api/items',
        '/shared/authentication/connect', '/shared/commodity/item/', '/SHARED/commodity/item',
        '/api/v1/order/create-transaction',
    ] as $nonDetailPath) {
        [$result, $failure, $safe, $calls] = $runNormalDetail($badMimeResponse, $nonDetailPath);
        expect($result === null && $failure instanceof UpstreamFailure
            && $failure->diagnostics['category'] === 'content_type' && $calls === 1 && $safe === null,
            'non-detail route gained MIME compatibility');
    }
    foreach ([
        [[], '', 'missing', 0, true],
        [["Content-Type: APPLICATION/JSON; charset=UTF-8\r\n"], 'APPLICATION/JSON; charset=UTF-8', 'application_json', 1, false],
        [["Content-Type: application/json\r\n", "Content-Type: text/plain\r\n"], 'text/plain', 'conflicting', 2, true],
        [["Content-Type: application/json\r\n", "Content-Type: application/json\r\n"], 'application/json', 'application_json', 2, true],
    ] as [$lines, $mimeValue, $mimeClass, $mimeCount, $compatibility]) {
        [$result, $failure, $safe] = $runNormalDetail(
            ['status' => 200, 'content_type' => $mimeValue, 'body' => $validBody], '/shared/commodity/item',
            array_merge(["HTTP/1.1 200 OK\r\n"], $lines, ["\r\n"]),
        );
        expect($failure === null && $safe['mime_category'] === $mimeClass && $safe['mime_count'] === $mimeCount
            && $safe['mime_compatibility'] === $compatibility,
            'normal detail MIME observation lost missing, duplicate, case, or parameter semantics');
    }
    foreach (['/shared/commodity/item', '/plugin/open-api/item', '/plugin/SharedStock/api/item'] as $detailPath) {
        foreach ([
            ['<html>TOPSECRET {"code":200}</html>', JSON_ERROR_SYNTAX, 'syntax'],
            ['{broken TOPSECRET', JSON_ERROR_SYNTAX, 'syntax'],
            ["{\"name\":\"\xC3\x28\"}", JSON_ERROR_UTF8, 'utf8'],
            [str_repeat('[', 33) . '0' . str_repeat(']', 33), JSON_ERROR_DEPTH, 'depth'],
        ] as [$payload, $errorCode, $errorKind]) {
            [$result, $failure, $safe, $calls, $sleeps] = $runNormalDetail(
                ['status' => 200, 'content_type' => 'text/html', 'body' => $payload], $detailPath,
            );
            expect($result === null && $failure instanceof UpstreamFailure && $calls === 1 && $sleeps === []
                && $failure->diagnostics === $safe && $safe['category'] === 'json'
                && $safe['http_status'] === 200 && $safe['curl_code'] === 0 && $safe['json_valid'] === false
                && $safe['json_error_code'] === $errorCode && $safe['json_error'] === $errorKind
                && !preg_match('/TOPSECRET|name|body|message/', json_encode($safe)),
                'detail JSON exception lost its exact code/kind, leaked content, or retried');
        }
    }
    foreach ([
        ['true', 'schema', true],
        [str_repeat('x', 16777217), 'response_size', null],
        ['[' . str_repeat('0,', 500000) . '0]', 'schema', true],
    ] as [$payload, $expectedCategory, $expectedJson]) {
        [$result, $failure, $safe, $calls, $sleeps] = $runNormalDetail(
            ['status' => 200, 'content_type' => 'text/html', 'body' => $payload], '/shared/commodity/item',
        );
        expect($result === null && $failure instanceof UpstreamFailure && $calls === 1 && $sleeps === []
            && $failure->diagnostics['category'] === $expectedCategory && $safe['json_valid'] === $expectedJson
            && !str_contains(json_encode($safe), 'TOPSECRET'),
            'detail MIME compatibility weakened JSON/UTF-8/depth/node/size rejection or retried it');
        expect(!array_key_exists('json_error_code', $safe) && !array_key_exists('json_error', $safe),
            'non-JSON failure fabricated a JSON exception');
    }
    $jsonResetPayload = '';
    $jsonResetCalls = 0;
    $jsonResetClient = new SafeHttpClient($policy, static function ($endpoint, $address) use (&$jsonResetPayload, &$jsonResetCalls): array {
        $jsonResetCalls++;
        return ['status' => 200, 'content_type' => 'application/json', 'body' => $jsonResetPayload, 'connected_ip' => $address];
    });
    foreach ([
        ['{broken TOPSECRET', '/shared/commodity/item', 'json', true],
        ['true', '/shared/commodity/item', 'schema', false],
        [$validBody, '/shared/commodity/item', 'none', false],
        ['{broken TOPSECRET', '/shared/commodity/item', 'json', true],
        ['{broken TOPSECRET', '/shared/commodity/items', 'json', false],
        [$validBody, '/shared/commodity/items', 'unknown', false],
    ] as $index => [$jsonResetPayload, $path, $category, $hasError]) {
        $failure = null;
        try { $jsonResetClient->postJson('https://example.com' . $path, [], []); }
        catch (UpstreamFailure $caught) { $failure = $caught; }
        $safe = $failure?->diagnostics ?? $jsonResetClient->diagnostics();
        expect($jsonResetCalls === $index + 1 && $safe['category'] === $category
            && array_key_exists('json_error_code', $safe) === $hasError
            && array_key_exists('json_error', $safe) === $hasError,
            'next request retained a stale JSON exception or added one to a non-detail route');
        if ($path === '/shared/commodity/items') {
            expect($jsonResetClient->detailDiagnostics() === null, 'non-detail request retained prior detail evidence');
        }
    }
    foreach ([1 => 'depth', 2 => 'syntax', 3 => 'syntax', 4 => 'syntax', 5 => 'utf8',
        9 => 'syntax', 10 => 'syntax', 12 => 'unknown', 255 => 'unknown'] as $code => $kind) {
        $safe = UpstreamFailure::sanitize(['json_error_code' => $code, 'json_error' => $kind, 'body' => 'TOPSECRET']);
        expect(UpstreamFailure::jsonErrorKind($code) === $kind && $safe['json_error_code'] === $code
            && $safe['json_error'] === $kind && !str_contains(json_encode($safe), 'TOPSECRET'),
            'bounded JSON code mapping lost its exact kind or leaked values');
    }
    foreach ([-1, 0, 6, 7, 8, 11, 256, PHP_INT_MAX] as $code) {
        expect(UpstreamFailure::jsonErrorKind($code) === null, 'invalid or encode-only JSON code was accepted');
    }
    foreach ([
        [], ['json_error_code' => 4], ['json_error' => 'syntax'],
        ['json_error_code' => '4', 'json_error' => 'syntax'], ['json_error_code' => 4.0, 'json_error' => 'syntax'],
        ['json_error_code' => true, 'json_error' => 'depth'], ['json_error_code' => 4, 'json_error' => 'utf8'],
        ['json_error_code' => 4, 'json_error' => 'TOPSECRET'], ['json_error_code' => 6, 'json_error' => 'unknown'],
        ['json_error_code' => 256, 'json_error' => 'unknown'], ['json_error_code' => 12, 'json_error' => 'syntax'],
    ] as $invalidPair) {
        $safe = UpstreamFailure::sanitize($invalidPair);
        expect(!array_key_exists('json_error_code', $safe) && !array_key_exists('json_error', $safe),
            'missing, mismatched, or invalid JSON error pair was repaired into valid evidence');
    }
    $sanitizedDetail = UpstreamFailure::sanitize([
        'category' => 'none', 'mime_category' => 'TOPSECRET-MIME', 'mime_count' => 65536,
        'json_valid' => 'true', 'mime_compatibility' => 'TOPSECRET', 'body' => 'TOPSECRET',
    ]);
    expect($sanitizedDetail['mime_category'] === 'unknown' && $sanitizedDetail['mime_count'] === null
        && $sanitizedDetail['json_valid'] === null && $sanitizedDetail['mime_compatibility'] === false
        && !str_contains(json_encode($sanitizedDetail), 'TOPSECRET'),
        'optional detail diagnostics accepted arbitrary values');
    $normalRetryCalls = 0;
    $normalRetrySleeps = [];
    $normalRetryClient = null;
    $normalRetryParser = new ReflectionMethod(SafeHttpClient::class, 'recordDiagnosticHeader');
    $normalRetryClient = new SafeHttpClient($policy, static function ($endpoint, $address) use (
        &$normalRetryCalls, &$normalRetryClient, $normalRetryParser, $validBody,
    ): array {
        $normalRetryCalls++;
        $status = $normalRetryCalls === 1 ? 503 : 200;
        $mime = $normalRetryCalls === 1 ? 'text/html' : 'application/json';
        foreach (["HTTP/1.1 $status Status\r\n", "Content-Type: $mime\r\n", "\r\n"] as $line) {
            $normalRetryParser->invoke($normalRetryClient, $line);
        }
        return ['status' => $status, 'body' => $validBody, 'content_type' => $mime, 'connected_ip' => $address];
    }, null, static function (int $ms) use (&$normalRetrySleeps): void { $normalRetrySleeps[] = $ms; });
    $normalRetryClient->postJson('https://example.com/shared/commodity/item', [], []);
    expect($normalRetryCalls === 2 && $normalRetrySleeps === [500]
        && $normalRetryClient->detailDiagnostics()['mime_category'] === 'application_json'
        && $normalRetryClient->detailDiagnostics()['mime_count'] === 1
        && $normalRetryClient->detailDiagnostics()['mime_compatibility'] === false,
        'normal detail retry changed backoff or retained an earlier attempt MIME observation');

    [$htmlReport] = $runDiagnostic([
        'status' => 200,
        'content_type' => 'text/html',
        'body' => '<html>TOPSECRET https://private.invalid app_key=PRIVATE</html>',
    ], ["HTTP/1.1 200 OK\r\n", "Content-Type: text/html\r\n", "\r\n"]);
    expect($htmlReport['category'] === 'content_type' && $htmlReport['json_valid'] === false
        && $htmlReport['json_error'] === 'syntax'
        && !preg_match('/TOPSECRET|private\.invalid|app_key|PRIVATE/', json_encode($htmlReport, JSON_THROW_ON_ERROR)),
        'HTML diagnostic was misclassified or leaked body values');
    [$utf8Report] = $runDiagnostic([
        'status' => 200,
        'content_type' => 'application/json',
        'body' => "{\"code\":200,\"data\":\"\xC3\x28TOPSECRET\"}",
    ]);
    expect($utf8Report['category'] === 'json' && $utf8Report['json_valid'] === false
        && $utf8Report['json_error'] === 'utf8'
        && !str_contains(json_encode($utf8Report, JSON_THROW_ON_ERROR), 'TOPSECRET'),
        'invalid UTF-8 diagnostic was misclassified or leaked body values');
    $deepBody = str_repeat('{"nested":', 40) . 'null' . str_repeat('}', 40);
    [$depthReport] = $runDiagnostic([
        'status' => 200, 'content_type' => 'application/json', 'body' => $deepBody,
    ]);
    expect($depthReport['category'] === 'json' && $depthReport['json_valid'] === false
        && $depthReport['json_error'] === 'depth' && $depthReport['within_json_limits'] === false,
        'over-depth diagnostic JSON was not rejected at the fixed limit');

    $largeBody = str_repeat('x', 16777217);
    [$sizeReport, $sizeCalls] = $runDiagnostic([
        'status' => 200, 'content_type' => 'application/json', 'body' => $largeBody,
    ]);
    expect($sizeCalls === 1 && $sizeReport['category'] === 'response_size'
        && $sizeReport['body_bytes'] === 16777217 && $sizeReport['body_complete'] === false
        && $sizeReport['json_valid'] === null && $sizeReport['json_error'] === 'unobserved'
        && $sizeReport['within_json_limits'] === false,
        'oversized diagnostic body was parsed, retried, or reported as complete');
    unset($largeBody);

    $nodeBody = '[' . str_repeat('0,', 500000) . '0]';
    [$nodeReport] = $runDiagnostic([
        'status' => 200, 'content_type' => 'application/json', 'body' => $nodeBody,
    ]);
    expect($nodeReport['category'] === 'schema' && $nodeReport['json_valid'] === true
        && $nodeReport['json_error'] === 'nodes' && $nodeReport['top_level'] === 'array'
        && $nodeReport['within_json_limits'] === false
        && $nodeReport['shape'] === array_fill_keys($shapeKeys, 'unobserved'),
        'over-node diagnostic JSON lost syntax validity or exposed an unbounded shape');
    unset($nodeBody);

    $type2Body = json_encode(['code' => 200, 'data' => [[
        'children' => [[
            'name' => 'TOPSECRET-TYPE2-NAME',
            'stock' => 7,
            'config' => (object)['TOPSECRET-TYPE2-CONFIG' => 'TOPSECRET-TYPE2-VALUE'],
        ]],
    ]]], JSON_THROW_ON_ERROR);
    $type2Response = ['status' => 200, 'content_type' => 'application/json', 'body' => $type2Body];
    $gatewayCalls = [];
    $gatewayHttp = new SafeHttpClient($policy, static function (
        array $endpoint,
        string $address,
        string $method,
        array $headers,
        string $body,
    ) use (&$gatewayCalls, $validResponse, $type2Response): array {
        $path = parse_url($endpoint['url'], PHP_URL_PATH);
        $gatewayCalls[] = ['path' => $path, 'body' => $body];
        $response = $path === '/plugin/SharedStock/api/item' ? $type2Response : $validResponse;
        return $response + ['connected_ip' => $address];
    });
    $diagnosticGateway = new SharedGateway($gatewayHttp, $policy);
    $baseSource = [
        'domain' => 'https://example.com',
        'app_id' => 'fixture-app',
        'app_key' => 'TOPSECRET-APP-KEY',
        'type' => 0,
    ];
    foreach ([
        0 => '/shared/commodity/item',
        1 => '/plugin/open-api/item',
        2 => '/plugin/SharedStock/api/item',
    ] as $sourceType => $expectedPath) {
        $report = $diagnosticGateway->diagnoseItem(array_replace($baseSource, ['type' => $sourceType]), 'SAFE-CODE');
        $assertReport($report);
        expect($report['category'] === 'none' && $report['attempts'] === 1
            && !str_contains(json_encode($report, JSON_THROW_ON_ERROR), 'TOPSECRET'),
            "source type {$sourceType} diagnostic failed or leaked credentials");
        expect($gatewayCalls[$sourceType]['path'] === $expectedPath,
            "source type {$sourceType} diagnostic used the wrong detail path");
        if ($sourceType === 2) {
            expect($report['shape'] === [
                'code' => 'number', 'data' => 'array', 'detail' => 'object', 'children' => 'array',
                'name' => 'string', 'stock' => 'number', 'config' => 'object', 'sku' => 'missing',
            ] && !str_contains(json_encode($report, JSON_THROW_ON_ERROR), 'TOPSECRET'),
                'source type 2 tree diagnostic lost its fixed positive shape or leaked values');
        }
    }
    expect(count($gatewayCalls) === 3, 'source-type diagnostic issued an unexpected number of requests');
    foreach ([
        array_replace($baseSource, ['domain' => 'http://example.com']),
        array_replace($baseSource, ['app_id' => 1]),
        array_replace($baseSource, ['app_key' => 'short']),
        array_replace($baseSource, ['type' => '0']),
        array_replace($baseSource, ['type' => 3]),
        $baseSource + ['extra' => 'value'],
    ] as $badSource) {
        $before = count($gatewayCalls);
        $report = $diagnosticGateway->diagnoseItem($badSource, 'SAFE-CODE');
        expect($report === $expectedUnavailable && count($gatewayCalls) === $before,
            'invalid standalone source initiated diagnostic transport');
    }
    foreach (['', 'bad code', str_repeat('A', 65)] as $badCode) {
        $before = count($gatewayCalls);
        $report = $diagnosticGateway->diagnoseItem($baseSource, $badCode);
        expect($report === $expectedUnavailable && count($gatewayCalls) === $before,
            'invalid standalone item code initiated diagnostic transport');
    }
}

expect(
    LocalPath::directory('runtime/local-extensions/extensions/PikaSupplySync', 0700)
        === $supplySiteState . '/runtime/extensions/PikaSupplySync',
    'supply runtime state did not move outside the document root'
);
expect(
    LocalPath::directory('assets/cache/pika-supply-sync', 0755)
        === $supplyFixtureRoot . '/assets/cache/pika-supply-sync',
    'public image cache did not remain under site assets'
);
fails(
    fn() => LocalPath::directory('runtime/local-extensions/.', 0700),
    'dot runtime path segment must fail'
);

$sourceLockDirectory = LocalPath::directory(
    'runtime/local-extensions/extensions/PikaSupplySync',
    0700,
);
$sourceLockPath = static fn(int $sourceId): string =>
    $sourceLockDirectory . '/source-' . $sourceId . '.run.lock';

$holder = new SourceLock();
expect($holder->acquire(900001), 'a safe new source lock must be acquired');
$normalLockMetadata = lstat($sourceLockPath(900001));
expect(
    is_array($normalLockMetadata)
        && (($normalLockMetadata['mode'] & 0777) === 0600)
        && $normalLockMetadata['uid'] === $supplyWebUid
        && $normalLockMetadata['nlink'] === 1,
    'a new source lock must be an owner-only, single-link runtime file',
);
$contender = new SourceLock();
expect($contender->acquire(900001) === false, 'a busy safe source lock must return false');
$holder->release();
expect($contender->acquire(900001), 'an existing safe source lock must reopen after release');
$contender->release();
unlink($sourceLockPath(900001));

$openHandle = new ReflectionMethod(SourceLock::class, 'openHandle');
$originalUmask = umask(0027);
try {
    expect(
        $openHandle->invoke(new SourceLock(), $sourceLockDirectory, false) === false,
        'the source lock open failure fixture must fail',
    );
    $restoredUmask = umask(0027);
    expect($restoredUmask === 0027, 'a failed x+b source lock open must restore the process umask');
} finally {
    umask($originalUmask);
}

$symlinkTarget = $sourceLockDirectory . '/source-lock-symlink-target';
touch($symlinkTarget);
chmod($symlinkTarget, 0600);
symlink($symlinkTarget, $sourceLockPath(900002));
fails(
    fn() => (new SourceLock())->acquire(900002),
    'a symlink source lock must fail closed',
);
unlink($sourceLockPath(900002));
unlink($symlinkTarget);

mkdir($sourceLockPath(900003), 0700);
fails(
    fn() => (new SourceLock())->acquire(900003),
    'a directory source lock must fail before fopen',
);
rmdir($sourceLockPath(900003));

if (!function_exists('posix_mkfifo') || !posix_mkfifo($sourceLockPath(900004), 0600)) {
    throw new RuntimeException('unable to create source lock FIFO fixture');
}
fails(
    fn() => (new SourceLock())->acquire(900004),
    'a FIFO source lock must fail before a blocking open',
);
unlink($sourceLockPath(900004));

$hardlinkTarget = $sourceLockDirectory . '/source-lock-hardlink-target';
touch($hardlinkTarget);
chmod($hardlinkTarget, 0600);
link($hardlinkTarget, $sourceLockPath(900005));
fails(
    fn() => (new SourceLock())->acquire(900005),
    'a hard-linked source lock must fail closed',
);
unlink($sourceLockPath(900005));
unlink($hardlinkTarget);

touch($sourceLockPath(900006));
chmod($sourceLockPath(900006), 0644);
fails(
    fn() => (new SourceLock())->acquire(900006),
    'a group-readable source lock must fail closed',
);
unlink($sourceLockPath(900006));

$identityHandlePath = $sourceLockDirectory . '/source-lock-identity-handle';
$identityPath = $sourceLockDirectory . '/source-lock-identity-path';
touch($identityHandlePath);
touch($identityPath);
chmod($identityHandlePath, 0600);
chmod($identityPath, 0600);
$identityHandle = fopen($identityHandlePath, 'r+b');
if ($identityHandle === false) {
    throw new RuntimeException('unable to open source lock identity fixture');
}
$assertSafeHandle = new ReflectionMethod(SourceLock::class, 'assertSafeHandle');
fails(
    fn() => $assertSafeHandle->invoke(new SourceLock(), $identityHandle, $identityPath),
    'a source lock handle/path dev-inode mismatch must fail closed',
);
fclose($identityHandle);
unlink($identityHandlePath);
unlink($identityPath);

$installedExtensionRoot = '/srv/acg-faka/local-extensions/extensions/PikaSupplySync';
expect(
    dirname($installedExtensionRoot, 3) === '/srv/acg-faka',
    'default CLI root must resolve from the installed extension directory to the site root'
);

$resolver = static fn(string $host): array => ['93.184.216.34'];
$policy = new SourcePolicy($resolver);
$root = $policy->resolve('https://example.com/', true, false);
expect($root['host'] === 'example.com', 'public HTTPS root should resolve');
fails(fn() => $policy->resolve('http://example.com/', true, false), 'HTTP source must fail');
fails(fn() => $policy->resolve('HTTPS://example.com/', true, false), 'uppercase HTTPS scheme must fail');
fails(fn() => $policy->resolve('https://EXAMPLE.com/', true, false), 'uppercase host must fail');
fails(fn() => $policy->resolve('https://example.com./', true, false), 'trailing-dot host must fail');
fails(fn() => $policy->resolve('https://example.com/path', true, false), 'non-root source must fail');
$explicitPort = $policy->resolve('https://example.com:443/path?item=1', false, true);
expect(
    $explicitPort['url'] === 'https://example.com:443/path?item=1'
        && $explicitPort['host'] === 'example.com',
    'explicit standard HTTPS port must retain one canonical transport host'
);
fails(
    fn() => (new SourcePolicy(static fn(string $host): array => ['127.0.0.1']))
        ->resolve('https://example.com/', true, false),
    'private DNS result must fail'
);
foreach (['100.64.0.1', '192.0.2.1', '198.18.0.1', '224.0.0.1', '2001:db8::1', 'fec0::1', '5f00::1', '100:0:0:1::1'] as $blocked) {
    fails(
        fn() => (new SourcePolicy(static fn(string $host): array => [$blocked]))
            ->resolve('https://example.com/', true, false),
        "non-public address {$blocked} must fail"
    );
}

runSupplyDiagnosticBehavior();

$calls = 0;
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
    $calls++;
    expect($endpoint['host'] === 'example.com', 'transport host mismatch');
    expect(
        parse_url($endpoint['url'], PHP_URL_HOST) === $endpoint['host'],
        'transport URL host must exactly match the DNS-pinned host'
    );
    expect($address === '93.184.216.34', 'transport must use resolved public IP');
    expect($method === 'POST', 'transport method mismatch');
    expect(str_contains($body, 'code=A1'), 'form body missing');
    expect($maxBytes === 16777216, 'JSON byte limit mismatch');
    expect($connectTimeoutMs === 5000, 'default connect timeout mismatch');
    expect($requestTimeoutMs === 90000, 'default request timeout mismatch');
    return [
        'status' => 200,
        'content_type' => 'application/json; charset=utf-8',
        'body' => '{"code":200,"data":{"ok":true}}',
        'connected_ip' => $address,
    ];
};
$http = new SafeHttpClient($policy, $transport);
$json = $http->postJson('https://example.com/shared/commodity/item', [], ['code' => 'A1']);
expect(($json['data']['ok'] ?? false) === true && $calls === 1, 'safe JSON request failed');

$retryCalls = 0;
$retrySleeps = [];
$retryTransport = static function (
    array $endpoint,
    string $address,
    string $method,
    array $headers,
    string $body,
    int $maxBytes,
    int $connectTimeoutMs,
    int $requestTimeoutMs,
) use (&$retryCalls): array {
    $retryCalls++;
    if ($retryCalls < 3) {
        throw new RetryableTransportFailure(CURLE_OPERATION_TIMEDOUT);
    }
    return [
        'status' => 200,
        'content_type' => 'application/json',
        'body' => '{"code":200,"data":[]}',
        'connected_ip' => $address,
    ];
};
$retrySleeper = static function (int $milliseconds) use (&$retrySleeps): void {
    $retrySleeps[] = $milliseconds;
};
(new SafeHttpClient($policy, $retryTransport, null, $retrySleeper))
    ->postJson('https://example.com/shared/commodity/items', [], []);
expect($retryCalls === 3, 'retryable transport failure must use at most three attempts');
expect($retrySleeps === [500, 1000], 'retryable transport failure must use bounded short backoff');

foreach ([408, 429, 502, 503, 504] as $retryableStatus) {
    $statusCalls = 0;
    $statusSleeps = [];
    $statusTransport = static function (
        array $endpoint,
        string $address,
        string $method,
        array $headers,
        string $body,
        int $maxBytes,
        int $connectTimeoutMs,
        int $requestTimeoutMs,
    ) use (&$statusCalls, $retryableStatus): array {
        $statusCalls++;
        return [
            'status' => $statusCalls === 1 ? $retryableStatus : 200,
            'content_type' => 'application/json',
            'body' => '{"code":200,"data":[]}',
            'connected_ip' => $address,
        ];
    };
    (new SafeHttpClient(
        $policy,
        $statusTransport,
        null,
        static function (int $milliseconds) use (&$statusSleeps): void {
            $statusSleeps[] = $milliseconds;
        },
    ))->postJson('https://example.com/shared/commodity/items', [], []);
    expect($statusCalls === 2, "HTTP {$retryableStatus} must retry exactly once before success");
    expect($statusSleeps === [500], "HTTP {$retryableStatus} must use the first bounded backoff");
}

foreach ([400, 401, 403, 404, 500, 520, 522, 525] as $terminalStatus) {
    $statusCalls = 0;
    $statusSleeps = [];
    $statusTransport = static function (
        array $endpoint,
        string $address,
        string $method,
        array $headers,
        string $body,
        int $maxBytes,
        int $connectTimeoutMs,
        int $requestTimeoutMs,
    ) use (&$statusCalls, $terminalStatus): array {
        $statusCalls++;
        return [
            'status' => $terminalStatus,
            'content_type' => 'application/json',
            'body' => '{"secret":"TOPSECRET"}',
            'connected_ip' => $address,
        ];
    };
    $terminalMessage = '';
    try {
        (new SafeHttpClient(
            $policy,
            $statusTransport,
            null,
            static function (int $milliseconds) use (&$statusSleeps): void {
                $statusSleeps[] = $milliseconds;
            },
        ))->postJson('https://example.com/shared/commodity/items', [], []);
    } catch (RuntimeException $exception) {
        $terminalMessage = $exception->getMessage();
    }
    expect($statusCalls === 1, "HTTP {$terminalStatus} must not retry");
    expect($statusSleeps === [], "HTTP {$terminalStatus} must not back off");
    expect(
        $terminalMessage === '远端 HTTPS 请求失败'
            && !preg_match('/(?:TOPSECRET|retry\.invalid|app_key|secret|https?:)/i', $terminalMessage),
        "HTTP {$terminalStatus} failure must stay sanitized",
    );
}

// Keep each actual attempt, not just the final status of a logical request.
foreach ([[502, 522], ['timeout', 525], [503, 200]] as $sequence) {
    $sequenceCalls = 0;
    $sequenceSleeps = [];
    $sequenceClient = new SafeHttpClient($policy,
        static function ($endpoint, $address) use (&$sequenceCalls, $sequence): array {
            $status = $sequence[$sequenceCalls++];
            if ($status === 'timeout') throw new RetryableTransportFailure(CURLE_OPERATION_TIMEDOUT);
            return ['status' => $status, 'content_type' => 'application/json', 'body' => '{}',
                'connected_ip' => $address];
        }, null, static function (int $ms) use (&$sequenceSleeps): void { $sequenceSleeps[] = $ms; });
    try { $sequenceClient->postJson('https://example.com/shared/commodity/items', [], []); }
    catch (UpstreamFailure $failure) {
        expect($sequence[1] !== 200 && $failure->diagnostics['http_status'] === $sequence[1],
            'sequence changed the terminal outcome');
    }
    $diagnostic = $sequenceClient->diagnostics();
    expect($sequenceCalls === 2 && $sequenceSleeps === [500], 'diagnostics changed retries or backoff');
    expect(($diagnostic['stage'] ?? null) === 'catalog'
        && count($diagnostic['attempt_history'] ?? []) === 2,
        'logical request lost its stage or actual attempt history');
    foreach ($diagnostic['attempt_history'] as $index => $attempt) {
        expect($attempt['http_status'] === ($sequence[$index] === 'timeout' ? 0 : $sequence[$index])
            && $attempt['curl_code'] === ($sequence[$index] === 'timeout' ? CURLE_OPERATION_TIMEDOUT : 0)
            && is_int($attempt['elapsed_ms']) && $attempt['elapsed_ms'] >= 0
            && $attempt['elapsed_ms'] <= 480000, 'attempt outcome or timing was lost');
    }
}

// Retain a prior failure across a later success, with only three bounded stage slots.
$summaryStatus = 525;
$summaryCalls = 0;
$summaryClient = new SafeHttpClient($policy, static function ($endpoint, $address) use (&$summaryStatus, &$summaryCalls): array {
    $summaryCalls++;
    return ['status' => $summaryStatus, 'content_type' => 'application/json', 'body' => '{}', 'connected_ip' => $address];
});
try { $summaryClient->postJson('https://example.com/shared/commodity/items', [], []); }
catch (UpstreamFailure) {}
$summaryStatus = 200;
$summaryClient->postJson('https://example.com/shared/commodity/items', [], []);
$summary = $summaryClient->requestDiagnostics();
expect($summaryCalls === 2 && $summary['catalog']['count'] === 2
    && $summary['catalog']['last']['category'] === 'none'
    && $summary['catalog']['last_failure']['http_status'] === 525,
    'later success erased the bounded failure observation');
$summaryClient->resetRequestDiagnostics();
expect($summaryClient->requestDiagnostics() === [], 'source reset retained old request evidence');
fails(static fn() => $summaryClient->postJson('https://example.com/shared/commodity/items', [], ['code' => []]),
    'invalid form unexpectedly passed');
$preRequest = $summaryClient->requestDiagnostics()['catalog']['last'];
expect($summaryCalls === 2 && $preRequest['attempts'] === 0
    && !isset($preRequest['http_status']) && !isset($preRequest['curl_code']) && !isset($preRequest['elapsed_ms'])
    && array_intersect_key($summaryClient->diagnostics(), array_flip(['category', 'http_status', 'curl_code', 'elapsed_ms', 'attempts']))
        === ['category' => 'unknown', 'http_status' => 0, 'curl_code' => 0, 'elapsed_ms' => 0, 'attempts' => 0],
    'new pre-request observation fabricated measurements or broke the legacy five-field contract');
fails(static fn() => $summaryClient->postJson('https://example.com/shared/commodity/items', ["invalid\r\nheader"], []),
    'invalid header unexpectedly passed');
$preTransport = $summaryClient->requestDiagnostics()['catalog']['last'];
expect($summaryCalls === 2 && $preTransport['attempts'] === 0
    && !isset($preTransport['http_status']) && !isset($preTransport['curl_code'])
    && is_int($preTransport['elapsed_ms']) && $preTransport['elapsed_ms'] >= 0,
    'pre-transport observation lost measured time or fabricated HTTP/cURL');
// Deliberately break observation state; neither success nor original failure may be masked.
$summaryProperty = new ReflectionProperty(SafeHttpClient::class, 'requestDiagnostics');
foreach ([200, 525] as $summaryStatus) {
    $summaryProperty->setValue($summaryClient, ['catalog' => ['count' => []]]);
    $summaryFailure = null;
    try { $summaryClient->postJson('https://example.com/shared/commodity/items', [], []); }
    catch (UpstreamFailure $failure) { $summaryFailure = $failure; }
    expect(($summaryStatus === 200 && $summaryFailure === null)
        || ($summaryStatus === 525 && $summaryFailure?->diagnostics['http_status'] === 525),
        'diagnostic collection failure replaced the original request outcome');
}

$terminalTransportCalls = 0;
$terminalTransportSleeps = [];
$terminalTransport = static function () use (&$terminalTransportCalls): array {
    $terminalTransportCalls++;
    throw new RuntimeException('secret=https://terminal.invalid app_key=TOPSECRET');
};
$terminalTransportMessage = '';
try {
    (new SafeHttpClient(
        $policy,
        $terminalTransport,
        null,
        static function (int $milliseconds) use (&$terminalTransportSleeps): void {
            $terminalTransportSleeps[] = $milliseconds;
        },
    ))->postJson('https://example.com/shared/commodity/items', [], []);
} catch (RuntimeException $exception) {
    $terminalTransportMessage = $exception->getMessage();
}
expect($terminalTransportCalls === 1, 'unclassified transport failure must not retry');
expect($terminalTransportSleeps === [], 'unclassified transport failure must not back off');
expect(
    $terminalTransportMessage === '远端 HTTPS 请求失败'
        && !preg_match('/(?:TOPSECRET|terminal\.invalid|app_key|secret|https?:)/i', $terminalTransportMessage),
    'unclassified transport failure must stay sanitized',
);

$nodeBoundaryCount = 500000;
$nodeBoundaryTransport = static function (
    array $endpoint,
    string $address,
    string $method,
    array $headers,
    string $body,
    int $maxBytes,
    int $connectTimeoutMs,
    int $requestTimeoutMs,
) use (&$nodeBoundaryCount): array {
    return [
        'status' => 200,
        'content_type' => 'application/json',
        'body' => '[' . str_repeat('0,', $nodeBoundaryCount - 1) . '0]',
        'connected_ip' => $address,
    ];
};
$nodeBoundaryHttp = new SafeHttpClient($policy, $nodeBoundaryTransport);
$nodeBoundaryJson = $nodeBoundaryHttp
    ->postJson('https://example.com/shared/commodity/items', [], []);
expect(count($nodeBoundaryJson) === 500000, '500000 JSON nodes must be accepted');
unset($nodeBoundaryJson);

$nodeBoundaryCount = 500001;
$nodeBoundaryRejected = false;
try {
    $nodeBoundaryHttp->postJson('https://example.com/shared/commodity/items', [], []);
} catch (RuntimeException $exception) {
    $nodeBoundaryRejected = $exception instanceof UpstreamFailure && $exception->diagnostics['category'] === 'schema';
}
expect($nodeBoundaryRejected, '500001 JSON nodes must be rejected by the node limit');

$gateway = new SharedGateway($http, $policy);
$treeSource = new LocalSupplySharedStub();
$treeSource->domain = 'https://example.com';
$treeSource->app_id = '101';
$treeSource->app_key = 'synthetic-tree-key';
$treeV2Response = ['schema' => 2, 'capability' => 'pika_category_tree',
    'categories' => [['id' => 1, 'pid' => 0, 'name' => 'Synthetic category', 'sort' => 0]],
    'items' => [['code' => 'synthetic-tree-item', 'category_id' => 1, 'stock' => 1]]];
foreach (['v2', 'v1', 'native-list', 'unknown-version', 'string-version', 'business-failure'] as $treeCase) {
    $treeCalls = 0;
    $treeData = $treeV2Response;
    if ($treeCase === 'v1') {
        $treeData['schema'] = 1;
        $treeData['items'][0]['name'] = 'Synthetic item';
    } elseif ($treeCase === 'native-list') {
        $treeData = [['name' => 'Synthetic category', 'children' => []]];
    } elseif ($treeCase === 'unknown-version') {
        $treeData['schema'] = 3;
    } elseif ($treeCase === 'string-version') {
        $treeData['schema'] = '2';
    }
    $treeEnvelope = $treeCase === 'business-failure'
        ? ['code' => 0, 'msg' => 'PIKA_TREE_UNAVAILABLE'] : ['code' => 200, 'data' => $treeData];
    $treeHttp = new SafeHttpClient($policy, static function ($endpoint, $address, $method, $headers, $body) use (
        &$treeCalls, $treeEnvelope,
    ): array {
        $treeCalls++;
        parse_str($body, $form);
        expect($method === 'POST' && parse_url($endpoint['url'], PHP_URL_PATH) === '/shared/commodity/items',
            'v2 changed the native directory method or route');
        expect(array_keys($form) === ['app_id', 'pika_category_tree', 'sign']
            && $form['pika_category_tree'] === '2' && !array_key_exists('app_key', $form),
            'v2 opt-in was missing, downgraded or transmitted the secret');
        return ['status' => 200, 'body' => json_encode($treeEnvelope, JSON_THROW_ON_ERROR),
            'content_type' => 'application/json', 'connected_ip' => $address];
    });
    $treeGateway = new SharedGateway($treeHttp, $policy);
    if ($treeCase === 'v2') {
        expect($treeGateway->categoryTree($treeSource) === $treeV2Response,
            'explicit v2 gateway altered the projected snapshot');
    } else {
        fails(fn() => $treeGateway->categoryTree($treeSource), 'v2 gateway accepted ' . $treeCase);
    }
    expect($treeCalls === 1, 'unsupported tree version caused a second request or fallback');
}
$credentials = new ReflectionMethod($gateway, 'credentials');
$shortKeySource = new LocalSupplySharedStub();
$shortKeySource->app_id = 'merchant-a';
$shortKeySource->app_key = 'short';
fails(
    fn() => $credentials->invoke($gateway, $shortKeySource),
    'shared credentials shorter than eight characters must fail closed'
);
$v4Item = new ReflectionMethod($gateway, 'v4Item');
$v4Base = [
    'id' => 'v4-zero',
    'name' => 'V4 zero stock',
    'sku' => [[
        'id' => 'sku-1',
        'name' => 'default',
        'stock_price' => '1.00',
        'stock' => 0,
    ]],
];
expect($v4Item->invoke($gateway, $v4Base)['stock'] === 0, 'explicit V4 zero stock must stay zero');
unset($v4Base['sku'][0]['stock']);
expect(
    $v4Item->invoke($gateway, $v4Base)['stock'] === 10000000,
    'V4 item without a stock field must retain the official unlimited-stock fallback'
);
$invalidV4Stocks = [null, 'unknown', '-1', '1', -1, 1.5, 2147483648];
foreach ($invalidV4Stocks as $invalidV4Stock) {
    $invalidV4 = $v4Base;
    $invalidV4['sku'][0]['stock'] = $invalidV4Stock;
    fails(
        fn() => $v4Item->invoke($gateway, $invalidV4),
        'V4 stock must reject null, text, numeric strings, negatives, decimals and overflow'
    );
}
$partialV4 = $v4Base;
$partialV4['sku'][] = [
    'id' => 'sku-2',
    'name' => 'second',
    'stock_price' => '1.00',
    'stock' => 1,
];
fails(
    fn() => $v4Item->invoke($gateway, $partialV4),
    'partially missing V4 stock fields must fail closed'
);
$overflowV4 = $v4Base;
$overflowV4['sku'][0]['stock'] = 2147483647;
$overflowV4['sku'][] = [
    'id' => 'sku-2',
    'name' => 'second',
    'stock_price' => '1.00',
    'stock' => 1,
];
fails(
    fn() => $v4Item->invoke($gateway, $overflowV4),
    'aggregate V4 stock overflow must fail closed'
);
$duplicateNameV4 = $v4Base;
$duplicateNameV4['sku'][0]['stock'] = 1;
$duplicateNameV4['sku'][] = [
    'id' => 'sku-2',
    'name' => 'default',
    'stock_price' => '1.00',
    'stock' => 1,
];
fails(
    fn() => $v4Item->invoke($gateway, $duplicateNameV4),
    'duplicate V4 SKU names must fail before overwriting category mappings'
);
$duplicateIdV4 = $v4Base;
$duplicateIdV4['sku'][0]['stock'] = 1;
$duplicateIdV4['sku'][] = [
    'id' => 'sku-1',
    'name' => 'second',
    'stock_price' => '1.00',
    'stock' => 1,
];
fails(
    fn() => $v4Item->invoke($gateway, $duplicateIdV4),
    'duplicate V4 SKU IDs must fail before creating ambiguous mappings'
);

$badTransportCalls = 0;
$badTransport = static function (
    array $endpoint,
    string $address,
    string $method,
    array $headers,
    string $body,
    int $maxBytes,
    int $connectTimeoutMs,
    int $requestTimeoutMs,
) use (&$badTransportCalls): array {
    $badTransportCalls++;
    return [
        'status' => 200,
        'content_type' => 'application/json',
        'body' => '{"code":200,"data":[]}',
        'connected_ip' => '1.1.1.1',
    ];
};
fails(
    fn() => (new SafeHttpClient($policy, $badTransport))
        ->postJson('https://example.com/shared/commodity/items', [], []),
    'connected-IP mismatch must fail'
);
expect($badTransportCalls === 1, 'connected-IP mismatch must not retry');

$invalidJsonCalls = 0;
$invalidJsonTransport = static function (
    array $endpoint,
    string $address,
    string $method,
    array $headers,
    string $body,
    int $maxBytes,
    int $connectTimeoutMs,
    int $requestTimeoutMs,
) use (&$invalidJsonCalls): array {
    $invalidJsonCalls++;
    return [
        'status' => 200,
        'content_type' => 'application/json',
        'body' => '{"code":200,"data":TOPSECRET}',
        'connected_ip' => $address,
    ];
};
$invalidJsonMessage = '';
try {
    (new SafeHttpClient($policy, $invalidJsonTransport))
        ->postJson('https://example.com/shared/commodity/items', [], []);
} catch (RuntimeException $exception) {
    $invalidJsonMessage = $exception->getMessage();
}
expect($invalidJsonCalls === 1, 'invalid JSON must not retry');
expect(
    $invalidJsonMessage === '远端 HTTPS 请求失败' && !str_contains($invalidJsonMessage, 'TOPSECRET'),
    'invalid JSON failure must remain sanitized',
);

$sourceNow = 0.0;
$sourceClock = static function () use (&$sourceNow): float { return $sourceNow; };
$sourceWallBudget = new RunBudget($sourceClock);
$sourceWallBudget->beginSource(1);
$sourceAttemptCalls = 0;
$sourceTimeoutTransport = static function (
    array $endpoint,
    string $address,
    string $method,
    array $headers,
    string $body,
    int $maxBytes,
    int $connectTimeoutMs,
    int $requestTimeoutMs,
) use (&$sourceNow, &$sourceAttemptCalls): array {
    $sourceAttemptCalls++;
    expect($connectTimeoutMs === 5000, 'source first connect timeout mismatch');
    expect($requestTimeoutMs === 90000, 'source first request timeout mismatch');
    $sourceNow = 121.0;
    throw new RetryableTransportFailure(CURLE_OPERATION_TIMEDOUT);
};
$sourceExceeded = false;
try {
    (new SafeHttpClient($policy, $sourceTimeoutTransport, $sourceWallBudget))
        ->postJson('https://example.com/shared/commodity/items', [], []);
} catch (Throwable $exception) {
    $sourceExceeded = method_exists($exception, 'isSource') && $exception->isSource();
}
expect($sourceExceeded, 'source deadline must stop before a retry begins');
expect($sourceAttemptCalls === 1, 'source deadline must prevent the second transport attempt');
expect($exception instanceof BudgetExceeded && $exception->safeDiagnostics['stage'] === 'catalog'
    && $exception->safeDiagnostics['attempt_history'][0]['category'] === 'transport'
    && $exception->safeDiagnostics['remaining_budget'] === ['round_ms' => 179000, 'source_ms' => 0],
    'source exhaustion lost the actual attempt or last sampled remaining budget');
$sourceWallBudget->endSource();
$sourceWallBudget->beginSource(2);
expect($sourceWallBudget->diagnosticRemaining() === ['round_ms' => 179000, 'source_ms' => 120000],
    'source observation reset the round or failed to reset the source');
$secondSourceCalls = 0;
$secondSourceTransport = static function (
    array $endpoint,
    string $address,
    string $method,
    array $headers,
    string $body,
    int $maxBytes,
    int $connectTimeoutMs,
    int $requestTimeoutMs,
) use (&$secondSourceCalls): array {
    $secondSourceCalls++;
    return [
        'status' => 200,
        'content_type' => 'application/json',
        'body' => '{"code":200,"data":[]}',
        'connected_ip' => $address,
    ];
};
(new SafeHttpClient($policy, $secondSourceTransport, $sourceWallBudget))
    ->postJson('https://example.com/shared/commodity/items', [], []);
expect($secondSourceCalls === 1, 'later source must still run after the prior source deadline');
$sourceWallBudget->endSource();

$roundNow = 0.0;
$roundClock = static function () use (&$roundNow): float { return $roundNow; };
$roundWallBudget = new RunBudget($roundClock);
$roundWallBudget->beginSource(1);
$roundAttemptCalls = 0;
$roundTimeoutTransport = static function (
    array $endpoint,
    string $address,
    string $method,
    array $headers,
    string $body,
    int $maxBytes,
    int $connectTimeoutMs,
    int $requestTimeoutMs,
) use (&$roundNow, &$roundAttemptCalls): array {
    $roundAttemptCalls++;
    $roundNow = 301.0;
    throw new RetryableTransportFailure(CURLE_OPERATION_TIMEDOUT);
};
$roundExceeded = false;
$roundHttp = new SafeHttpClient($policy, $roundTimeoutTransport, $roundWallBudget);
try {
    $roundHttp->postJson('https://example.com/shared/commodity/items', [], []);
} catch (Throwable $exception) {
    $roundExceeded = property_exists($exception, 'scope') && $exception->scope === 'round';
}
expect($roundExceeded, 'round deadline must stop before a retry begins');
expect($roundAttemptCalls === 1, 'round deadline must prevent the second transport attempt');
fails(
    fn() => $roundHttp->postJson('https://example.com/shared/commodity/items', [], []),
    'expired round must reject a later HTTP request'
);
expect($roundAttemptCalls === 1, 'expired round must not invoke transport again');

$clampNow = 0.0;
$clampClock = static function () use (&$clampNow): float { return $clampNow; };
$clampBudget = new RunBudget($clampClock);
$clampBudget->beginSource(1);
$clampNow = 119.5;
$clampedTimeoutTransport = static function (
    array $endpoint,
    string $address,
    string $method,
    array $headers,
    string $body,
    int $maxBytes,
    int $connectTimeoutMs,
    int $requestTimeoutMs,
): array {
    expect($connectTimeoutMs >= 499 && $connectTimeoutMs <= 500, 'connect timeout was not clamped');
    expect($requestTimeoutMs >= 499 && $requestTimeoutMs <= 500, 'request timeout was not clamped');
    return [
        'status' => 200,
        'content_type' => 'application/json',
        'body' => '{"code":200,"data":[]}',
        'connected_ip' => $address,
    ];
};
(new SafeHttpClient($policy, $clampedTimeoutTransport, $clampBudget))
    ->postJson('https://example.com/shared/commodity/items', [], []);
$clampBudget->endSource();

$backoffNow = 0.0;
$backoffClock = static function () use (&$backoffNow): float { return $backoffNow; };
$backoffBudget = new RunBudget($backoffClock);
$backoffBudget->beginSource(1);
$backoffNow = 119.95;
$backoffCalls = 0;
$backoffSleeps = [];
$backoffTransport = static function () use (&$backoffCalls): array {
    $backoffCalls++;
    throw new RetryableTransportFailure(CURLE_OPERATION_TIMEDOUT);
};
$backoffExceeded = false;
try {
    (new SafeHttpClient(
        $policy,
        $backoffTransport,
        $backoffBudget,
        static function (int $milliseconds) use (&$backoffSleeps, &$backoffNow): void {
            $backoffSleeps[] = $milliseconds;
            $backoffNow += $milliseconds / 1000;
        },
    ))->postJson('https://example.com/shared/commodity/items', [], []);
} catch (Throwable $exception) {
    $backoffExceeded = method_exists($exception, 'isSource') && $exception->isSource();
}
expect($backoffExceeded, 'backoff must remain inside the source RunBudget');
expect($backoffCalls === 1, 'expired backoff budget must prevent the second transport attempt');
expect($backoffSleeps === [], 'insufficient retry budget must reject before sleeping');

// Exercise the real HTTP -> gateway -> planned-import taxonomy without network or writes.
$importStage = new ReflectionMethod(CommodityImporter::class, 'stage');
$classifier = (new ReflectionClass(CommodityImporter::class))->newInstanceWithoutConstructor();
$responseData = new ReflectionMethod(SharedGateway::class, 'responseData');
foreach ([200, 200.0, '200', ' 200 '] as $compatibleCode) {
    expect($responseData->invoke($gateway, ['code' => $compatibleCode, 'data' => []], 'fixture-safe-key') === [],
        'legacy successful business-code representation was rejected');
}
$failureCases = [
    ['transport', 200, '{}', 'application/json', 3, 'ITEM_DETAIL_TRANSPORT_FAILED'],
    ['unknown', 200, '{}', 'application/json', 1, 'ITEM_DETAIL_UNKNOWN_FAILED'],
    ['http_retryable', 429, '{}', 'application/json', 3, 'ITEM_DETAIL_HTTP_RETRYABLE'],
    ['http_rejected', 401, '{}', 'application/json', 1, 'ITEM_DETAIL_HTTP_REJECTED'],
    ['schema', 200, '{}', 'text/html', 1, 'ITEM_DETAIL_RESPONSE_INVALID'],
    ['json', 200, '<html>TOPSECRET</html>', 'text/html', 1, 'ITEM_DETAIL_JSON_INVALID'],
    ['json', 200, '{invalid TOPSECRET', 'application/json', 1, 'ITEM_DETAIL_JSON_INVALID'],
    ['schema', 200, '{"data":[]}', 'application/json', 1, 'ITEM_DETAIL_RESPONSE_INVALID'],
    ['schema', 200, '{"code":200,"data":"invalid"}', 'application/json', 1, 'ITEM_DETAIL_RESPONSE_INVALID'],
    ['business', 200, '{"code":403,"msg":"TOPSECRET https://private.invalid"}', 'application/json', 1, 'ITEM_DETAIL_BUSINESS_REJECTED'],
];
foreach ([408, 502, 503, 504] as $retryStatus) {
    $failureCases[] = ['http_retryable', $retryStatus, '{}', 'application/json', 3, 'ITEM_DETAIL_HTTP_RETRYABLE'];
}
foreach ($failureCases as [$category, $status, $payload, $contentType, $expectedAttempts, $safeCode]) {
    $actualAttempts = 0;
    $taxonomyHttp = new SafeHttpClient($policy, static function ($endpoint, $address) use (
        &$actualAttempts, $category, $status, $payload, $contentType,
    ): array {
        $actualAttempts++;
        if ($category === 'transport') {
            throw new RetryableTransportFailure(CURLE_OPERATION_TIMEDOUT);
        }
        if ($category === 'unknown') {
            throw new RuntimeException('TOPSECRET https://private.invalid');
        }
        return ['status' => $status, 'body' => $payload, 'content_type' => $contentType, 'connected_ip' => $address];
    }, null, static function (int $ms): void {});
    $taxonomyGateway = new SharedGateway($taxonomyHttp, $policy);
    try {
        $importStage->invoke($classifier, CommodityImportFailure::DETAIL_FETCH_FAILED, true,
            static fn() => $responseData->invoke($taxonomyGateway,
                $taxonomyHttp->postJson('https://example.com/shared/commodity/item', [], []), 'fixture-safe-key'));
        throw new RuntimeException('taxonomy failure was accepted');
    } catch (CommodityImportFailure $failure) {
        expect($failure->safeCode === $safeCode, 'wrong planned-import detail code for ' . $category);
        expect($failure->safeDiagnostics['category'] === $category, 'wrong diagnostic category');
        expect($failure->safeDiagnostics['attempts'] === $expectedAttempts && $actualAttempts === $expectedAttempts,
            'diagnostic attempts did not match real transport calls');
        expect($failure->safeDiagnostics['http_status'] === (in_array($category, ['transport', 'unknown'], true) ? 0 : $status),
            'diagnostic HTTP status was lost');
        expect($failure->safeDiagnostics['curl_code'] === ($category === 'transport' ? CURLE_OPERATION_TIMEDOUT : 0),
            'diagnostic curl code was lost');
        expect(is_int($failure->safeDiagnostics['elapsed_ms']), 'elapsed diagnostic is not numeric');
        expect(!preg_match('/TOPSECRET|https?:|private|payload|message/', json_encode($failure->safeDiagnostics)),
            'diagnostics leaked upstream content');
    }
}
$jsonEvidence = [
    'category' => 'json', 'http_status' => 200, 'curl_code' => 0, 'elapsed_ms' => 1, 'attempts' => 1,
    'mime_category' => 'application_json', 'mime_count' => 1, 'json_valid' => false, 'mime_compatibility' => false,
    'json_error_code' => JSON_ERROR_SYNTAX, 'json_error' => 'syntax',
];
foreach ([
    ['http_status' => 0], ['http_status' => 201], ['curl_code' => 7], ['attempts' => 0],
    ['json_valid' => true], ['json_valid' => null], ['json_error_code' => null], ['json_error' => null],
    ['json_error_code' => 6, 'json_error' => 'unknown'], ['json_error_code' => 4, 'json_error' => 'utf8'],
] as $invalidEvidence) {
    try {
        $importStage->invoke($classifier, CommodityImportFailure::DETAIL_FETCH_FAILED, true,
            static fn() => throw new UpstreamFailure('json', array_replace($jsonEvidence, $invalidEvidence)));
        throw new RuntimeException('incomplete JSON evidence was accepted');
    } catch (CommodityImportFailure $failure) {
        expect($failure->safeCode === 'ITEM_DETAIL_RESPONSE_INVALID'
            && !CommodityImportFailure::isIsolatableItemCode($failure->safeCode),
            'incomplete parser/transport evidence gained JSON item isolation');
    }
}
try {
    $importStage->invoke($classifier, CommodityImportFailure::DETAIL_FETCH_FAILED, true,
        static fn() => throw new UpstreamFailure('json', array_replace($jsonEvidence, [
            'json_error_code' => 12, 'json_error' => 'unknown',
        ])));
    throw new RuntimeException('future parser-code fixture was accepted');
} catch (CommodityImportFailure $failure) {
    expect($failure->safeCode === 'ITEM_DETAIL_JSON_INVALID' && $failure->safeDiagnostics['json_error'] === 'unknown',
        'bounded future parser code was mislabeled syntax or lost item isolation');
}
try {
    $importStage->invoke($classifier, CommodityImportFailure::DETAIL_FETCH_FAILED, true,
        static fn() => throw new JsonException('TOPSECRET', JSON_ERROR_SYNTAX));
    throw new RuntimeException('unproven JSON exception was accepted');
} catch (CommodityImportFailure $failure) {
    expect($failure->safeCode === 'ITEM_DETAIL_UNKNOWN_FAILED' && $failure->safeDiagnostics === null,
        'JSON exception outside the exact HTTP detail boundary became isolatable');
}
foreach ([CommodityImportFailure::SOURCE_POLICY_FAILED, CommodityImportFailure::DETAIL_NORMALIZATION_FAILED,
    CommodityImportFailure::PRICE_ADJUSTMENT_FAILED, CommodityImportFailure::CATEGORY_TRANSACTION_FAILED,
    CommodityImportFailure::PERSISTENCE_FAILED] as $nonDetailStage) {
    try {
        $importStage->invoke($classifier, $nonDetailStage, true,
            static fn() => throw new UpstreamFailure('json', $jsonEvidence));
        throw new RuntimeException('wrong-stage JSON fixture was accepted');
    } catch (CommodityImportFailure $failure) {
        expect($failure->safeCode === $nonDetailStage
            && !CommodityImportFailure::isIsolatableItemCode($failure->safeCode),
            'JSON evidence escaped the prewrite detail-fetch stage');
    }
}
try {
    $credentials->invoke($gateway, $shortKeySource);
    throw new RuntimeException('invalid credential fixture was accepted');
} catch (UpstreamFailure $failure) {
    expect($failure->diagnostics['category'] === 'credentials' && $failure->diagnostics['attempts'] === 0,
        'credential validation was misclassified as transport');
}
expect(UpstreamFailure::sanitize(['category' => 'TOPSECRET', 'http_status' => 'https://private.invalid',
    'curl_code' => [], 'attempts' => 100, 'elapsed_ms' => -1, 'body' => 'TOPSECRET']) === [
    'category' => 'unknown', 'http_status' => 0, 'curl_code' => 0, 'elapsed_ms' => 0, 'attempts' => 0,
], 'diagnostic whitelist accepted arbitrary values');

// Check the real write boundary, including malicious nested diagnostic input.
$unsafeAttempt = ['category' => 'transport', 'http_status' => 0, 'curl_code' => 28,
    'elapsed_ms' => 17, 'request_timeout_ms' => 45000, 'remaining_before' => ['source_ms' => 45000,
        'round_ms' => 225000, 'url' => 'https://TOPSECRET.invalid'], 'headers' => ['TOPSECRET']];
$unsafeRequest = ['stage' => 'detail', 'category' => 'budget', 'http_status' => 525,
    'curl_code' => 0, 'attempts' => 2, 'elapsed_ms' => 18, 'attempt_history' => [$unsafeAttempt],
    'remaining_budget' => ['source_ms' => 0, 'round_ms' => 180000, 'token' => 'TOPSECRET'],
    'body' => 'TOPSECRET', 'message' => 'TOPSECRET', 'mime_category' => 'TOPSECRET',
    'mime_count' => '1', 'json_valid' => 'TOPSECRET', 'mime_compatibility' => 'TOPSECRET'];
$unsafeLog = ['source_id' => 7, 'status' => 'partial', 'mode' => 'basic', 'dry_run' => false,
    'phase' => 'actions', 'budget_scope' => 'source', 'applied' => ['sync' => 1, 'TOPSECRET' => 'TOPSECRET'],
    'planned' => ['sync' => 2, 'url' => 'TOPSECRET'], 'failed' => 0, 'message' => 'TOPSECRET',
    'errors' => ['TOPSECRET'], 'TOPSECRET' => 'TOPSECRET', 'failure_diagnostic' => $unsafeRequest,
    'request_diagnostics' => ['detail' => ['count' => 1, 'last' => $unsafeRequest,
        'last_failure' => $unsafeRequest, 'raw' => 'TOPSECRET'], 'TOPSECRET' => ['count' => 1]]];
$safeLogger = new \Pika\LocalExtensions\PikaSupplySync\Service\ExtensionLogger();
$logDirectory = LocalPath::directory('runtime/local-extensions/extensions/PikaSupplySync', 0700);
foreach ([false, true] as $targeted) {
    $safeLogger->write($unsafeLog + ['targeted' => $targeted]);
    $logPath = $logDirectory . ($targeted ? '/targeted-sync.log' : '/sync.log');
    $logLines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $logLine = $logLines[count($logLines) - 1];
    $logged = json_decode(substr($logLine, strpos($logLine, ' ') + 1), true, 16, JSON_THROW_ON_ERROR);
    expect(!preg_match('/TOPSECRET|https?:|headers|"body"|"message"|"errors"/', $logLine)
        && strlen($logLine) < 32768 && $logged['status'] === 'partial' && $logged['applied'] === ['sync' => 1]
        && $logged['targeted'] === $targeted
        && $logged['request_diagnostics']['detail']['last']['attempt_history'][0]['curl_code'] === 28,
        'actual log leaked arbitrary data, changed source outcome or lost safe attempt evidence');
    foreach (['mime_category', 'mime_count', 'json_valid', 'mime_compatibility'] as $invalidField) {
        expect(!array_key_exists($invalidField, $logged['request_diagnostics']['detail']['last'])
            && !array_key_exists($invalidField, $logged['failure_diagnostic']),
            'invalid optional input became a fabricated observation in the actual log');
    }
}
foreach (['45', -1, 480001, [], (object)[], NAN, INF, null] as $invalidMeasurement) {
    $invalidAttempt = ['elapsed_ms' => $invalidMeasurement, 'category' => 'TOPSECRET', 'body' => 'TOPSECRET'];
    $sanitized = UpstreamFailure::sanitize(['attempt_history' => [$invalidAttempt],
        'remaining_budget' => ['source_ms' => $invalidMeasurement], 'stage' => 'TOPSECRET']);
    expect(($sanitized['attempt_history'] ?? null) === [] && !isset($sanitized['remaining_budget'])
        && !isset($sanitized['stage']), 'invalid new observations were fabricated or leaked');
}
expect(!isset(UpstreamFailure::sanitize(['attempt_history' => array_fill(0, 4, $unsafeAttempt)])['attempt_history']),
    'oversized attempt history was accepted');
$clockReads = 0;
$observationBudget = new RunBudget(static function () use (&$clockReads): float { $clockReads++; return 0.0; });
$observationBudget->beginSource(1);
$beforeReads = $clockReads;
$observationBudget->diagnosticRemaining();
$observationBudget->diagnosticRemaining();
expect($clockReads === $beforeReads, 'diagnostic observation added clock reads or checkpoints');
foreach ([45, 75] as $remainingSeconds) {
    $timeoutNow = 0.0;
    $timeoutBudget = new RunBudget(static function () use (&$timeoutNow): float { return $timeoutNow; });
    $timeoutBudget->beginSource(1);
    $timeoutNow = 120 - $remainingSeconds;
    $timeoutClient = new SafeHttpClient($policy,
        static function ($endpoint, $address, $method, $headers, $body, $max, $connect, $request) use ($remainingSeconds): array {
            expect($connect === 5000 && $request === $remainingSeconds * 1000, 'diagnostics changed timeout clamping');
            return ['status' => 200, 'content_type' => 'application/json', 'body' => '{}', 'connected_ip' => $address];
        }, $timeoutBudget);
    $timeoutClient->postJson('https://example.com/shared/commodity/items', [], []);
    $attempt = $timeoutClient->requestDiagnostics()['catalog']['last']['attempt_history'][0];
    expect($attempt['request_timeout_ms'] === $remainingSeconds * 1000
        && $attempt['remaining_before']['source_ms'] === $remainingSeconds * 1000,
        'attempt observation differs from the actual supplied timeout and budget');
}

$safeStructure = [
    'business_code' => 200, 'data_type' => 'list', 'data_count' => 1,
    'first_children_type' => 'list', 'first_children_count' => 1, 'first_item_type' => 'object',
];
expect(UpstreamFailure::sanitizeResponseStructure($safeStructure) === $safeStructure,
    'response structure lost a valid bounded observation');
expect(UpstreamFailure::sanitizeResponseStructure($safeStructure + ['body' => 'TOPSECRET']) === $safeStructure
    && UpstreamFailure::sanitize(['response_structure' => $safeStructure])['response_structure'] === $safeStructure,
    'response structure whitelist leaked an extra key or failed diagnostic propagation');
foreach ([null, false, 200, 'TOPSECRET', [], ['body' => 'TOPSECRET']] as $invalidStructure) {
    expect(UpstreamFailure::sanitizeResponseStructure($invalidStructure) === null
        && !array_key_exists('response_structure', UpstreamFailure::sanitize(['response_structure' => $invalidStructure])),
        'invalid or empty response structure fabricated an observation');
}
foreach (['business_code' => [-1000000, 1000000, '200', 200.0, true, null, []],
    'data_count' => [-1, 10001, '1', 1.0, false, null, []],
    'first_children_count' => [-1, 10001, '1', 1.0, false, null, []]] as $key => $invalidValues) {
    foreach ($invalidValues as $invalidValue) {
        expect(UpstreamFailure::sanitizeResponseStructure([$key => $invalidValue]) === null,
            'response structure coerced or clamped an invalid number');
    }
}
foreach (['business_code' => [-999999, 0, 999999], 'data_count' => [0, 10000],
    'first_children_count' => [0, 10000]] as $key => $validValues) {
    foreach ($validValues as $validValue) {
        expect(UpstreamFailure::sanitizeResponseStructure([$key => $validValue]) === [$key => $validValue],
            'response structure rejected an exact numeric boundary');
    }
}
foreach (['data_type', 'first_children_type', 'first_item_type'] as $key) {
    foreach (['missing', 'null', 'boolean', 'number', 'string', 'list', 'object', 'empty_array_or_object'] as $type) {
        expect(UpstreamFailure::sanitizeResponseStructure([$key => $type]) === [$key => $type],
            'response structure rejected a type enum');
    }
    foreach (['array', 'TOPSECRET', 1, true, null, []] as $invalidType) {
        expect(UpstreamFailure::sanitizeResponseStructure([$key => $invalidType]) === null,
            'response structure accepted an unknown type');
    }
}
$summarizeStructure = new ReflectionMethod(SharedGateway::class, 'summarizeResponseStructure');
foreach ([200, 200.0, '200', ' 200 '] as $compatibleCode) {
    expect($responseData->invoke($gateway, ['code' => $compatibleCode, 'data' => []], 'fixture-safe-key', true) === [],
        'detail structure collection changed legacy successful business-code compatibility');
    $expectedStructure = is_int($compatibleCode) ? ['business_code' => $compatibleCode] : [];
    expect($summarizeStructure->invoke($gateway, ['code' => $compatibleCode, 'data' => []], false)
        === $expectedStructure + ['data_type' => 'empty_array_or_object', 'data_count' => 0],
        'detail structure fabricated an integer business code');
}
foreach ([10000, 10001] as $observedCount) {
    $manyRows = array_fill(0, $observedCount, null);
    $expectedCount = $observedCount === 10000 ? ['data_count' => 10000] : [];
    expect($summarizeStructure->invoke($gateway, ['data' => $manyRows], false)
        === ['data_type' => 'list'] + $expectedCount,
        'data count was clamped, fabricated or rejected at its exact bound');
    $expectedChildrenCount = $observedCount === 10000 ? ['first_children_count' => 10000] : [];
    expect($summarizeStructure->invoke($gateway, ['data' => [['children' => $manyRows]]], true)
        === ['data_type' => 'list', 'data_count' => 1, 'first_children_type' => 'list']
            + $expectedChildrenCount + ['first_item_type' => 'null'],
        'children count was clamped, fabricated or rejected at its exact bound');
}
unset($manyRows);

foreach (['CURLE_COULDNT_RESOLVE_HOST', 'CURLE_COULDNT_CONNECT', 'CURLE_OPERATION_TIMEDOUT', 'CURLE_PARTIAL_FILE',
    'CURLE_HTTP2', 'CURLE_SSL_CONNECT_ERROR', 'CURLE_SEND_ERROR', 'CURLE_RECV_ERROR', 'CURLE_GOT_NOTHING',
    'CURLE_AGAIN', 'CURLE_HTTP2_STREAM', 'CURLE_SSL_CACERT'] as $curlName) {
    if (!defined($curlName)) { continue; }
    $curlCode = constant($curlName);
    $calls = 0;
    $curlFixture = new SafeHttpClient($policy, static function () use (&$calls, $curlCode): array {
        $calls++;
        throw new RetryableTransportFailure($curlCode);
    }, null, static function (int $ms): void {});
    try {
        $curlFixture->postJson('https://example.com/shared/commodity/item', [], []);
        throw new RuntimeException('curl failure fixture was accepted');
    } catch (UpstreamFailure $failure) {
        $expected = $curlName === 'CURLE_SSL_CACERT' ? 1 : 3;
        expect($calls === $expected && $failure->diagnostics['attempts'] === $expected
            && $failure->diagnostics['curl_code'] === $curlCode,
            'curl taxonomy changed the explicit retry allowlist');
        expect($failure->diagnostics['category'] === ($expected === 1 ? 'unknown' : 'transport'),
            'non-retryable certificate failure was labeled transient');
    }
}

foreach ([['source', 119.7, '1'], ['source', 0.0, '99999999999999999999'], ['round', 299.7, '1']] as [$scope, $now, $retryAfter]) {
    $retryNow = 0.0;
    $retryBudget = new RunBudget(static function () use (&$retryNow): float { return $retryNow; });
    if ($scope === 'source') { $retryBudget->beginSource(1); }
    $retryNow = $now;
    $attempts = 0;
    $sleeps = [];
    $limited = new SafeHttpClient($policy, static function ($endpoint, $address) use (&$attempts, $retryAfter): array {
        $attempts++;
        return ['status' => 429, 'body' => '{}', 'content_type' => 'application/json',
            'connected_ip' => $address, 'retry_after' => $retryAfter];
    }, $retryBudget, static function (int $ms) use (&$sleeps): void { $sleeps[] = $ms; });
    try {
        $importStage->invoke($classifier, CommodityImportFailure::DETAIL_FETCH_FAILED, true,
            static fn() => $limited->postJson('https://example.com/shared/commodity/item', [], []));
        throw new RuntimeException('insufficient Retry-After budget was accepted');
    } catch (CommodityImportFailure $failure) {
        expect($failure->safeCode === 'ITEM_DETAIL_BUDGET_EXCEEDED', 'budget was mislabeled transient');
        expect($failure->getPrevious() instanceof BudgetExceeded && $failure->getPrevious()->scope === $scope,
            'retry budget lost source/round scope');
        expect($attempts === 1 && $sleeps === [] && $failure->safeDiagnostics['attempts'] === 1,
            'insufficient Retry-After budget slept or inflated attempts');
    }
}
$retryNow = 0.0;
$retryBudget = new RunBudget(static function () use (&$retryNow): float { return $retryNow; });
$retryBudget->beginSource(1);
$attempts = 0;
$sleeps = [];
$limited = new SafeHttpClient($policy, static function ($endpoint, $address) use (&$attempts): array {
    $attempts++;
    return ['status' => $attempts === 1 ? 429 : 200, 'body' => '{}', 'content_type' => 'application/json',
        'connected_ip' => $address, 'retry_after' => '2'];
}, $retryBudget, static function (int $ms) use (&$sleeps, &$retryNow): void { $sleeps[] = $ms; $retryNow += $ms / 1000; });
$limited->postJson('https://example.com/shared/commodity/item', [], []);
expect($attempts === 2 && $sleeps === [2000], 'Retry-After was ignored or retried early');
$retryAfterParser = new ReflectionMethod(SafeHttpClient::class, 'retryAfterMilliseconds');
$parsedDate = $retryAfterParser->invoke($limited, gmdate('D, d M Y H:i:s \G\M\T', time() + 10));
expect($parsedDate >= 9000 && $parsedDate <= 10000, 'HTTP-date Retry-After was not parsed');
expect($retryAfterParser->invoke($limited, 'secret=https://private.invalid') === 0, 'invalid Retry-After was accepted');
// This exact helper is called by CURLOPT_HEADERFUNCTION in the production curl path.
$retryHeaderParser = new ReflectionMethod(SafeHttpClient::class, 'retryAfterHeaderMilliseconds');
$longOwsHeader = 'Retry-After: ' . str_repeat(" \t", 150) . "2\r\n";
$longDigitsHeader = 'Retry-After: ' . str_repeat('9', 300) . "\r\n";
foreach ([$longOwsHeader, $longDigitsHeader] as $longRetryHeader) {
    $requiredDelay = $retryHeaderParser->invoke($limited, $longRetryHeader, 0);
    expect($requiredDelay === 300000, 'long Retry-After header was ignored instead of failing closed');
    $headerAttempts = 0;
    $headerSleeps = [];
    $headerBudget = new RunBudget(static fn(): float => 0.0);
    $headerBudget->beginSource(1);
    $headerClient = new SafeHttpClient($policy, static function ($endpoint, $address) use (&$headerAttempts, $requiredDelay): array {
        $headerAttempts++;
        return ['status' => 429, 'body' => '{}', 'content_type' => 'application/json',
            'connected_ip' => $address, 'retry_after_ms' => $requiredDelay];
    }, $headerBudget, static function (int $ms) use (&$headerSleeps): void { $headerSleeps[] = $ms; });
    $headerRejected = false;
    try { $headerClient->postJson('https://example.com/shared/commodity/item', [], []); }
    catch (BudgetExceeded $failure) {
        $headerRejected = $failure->isSource() && $failure->safeDiagnostics['attempts'] === 1;
    }
    expect($headerRejected && $headerAttempts === 1 && $headerSleeps === [],
        'oversized supplier delay slept or retried early');
}
expect($retryHeaderParser->invoke($limited, 'X-Unrelated: ' . str_repeat('a', 2048) . "\r\n", 2000) === 2000,
    'unrelated long header changed supplier delay');
expect($retryHeaderParser->invoke($limited, "HTTP/1.1 429 Too Many Requests\r\n", 300000) === 0,
    'new HTTP status line did not reset previous response headers');
$duplicateDelay = $retryHeaderParser->invoke($limited, "retry-after:\t2 \r\n", 0);
$duplicateDelay = $retryHeaderParser->invoke($limited, "Retry-After: 1\r\n", $duplicateDelay);
$duplicateDelay = $retryHeaderParser->invoke($limited, "Retry-After: 4\r\n", $duplicateDelay);
expect($duplicateDelay === 4000, 'duplicate Retry-After fields did not preserve the largest delay');
foreach ([0, 1, 99, 100, 599, 600] as $diagnosticStatus) {
    expect(UpstreamFailure::sanitize(['http_status' => $diagnosticStatus])['http_status']
        === ($diagnosticStatus >= 100 && $diagnosticStatus <= 599 ? $diagnosticStatus : 0),
        'diagnostic HTTP status was outside 0 or 100..599');
}
$retryNow = 121.0;
$expiredRejected = false;
try { $limited->postJson('https://example.com/shared/commodity/item', [], []); }
catch (BudgetExceeded $failure) {
    $expiredRejected = true;
    expect($failure->safeDiagnostics['attempts'] === 0 && $attempts === 2, 'expired budget initiated or counted transport');
    expect($failure->safeDiagnostics['attempt_history'] === [], 'pre-request exhaustion retained old attempts');
}
expect($expiredRejected, 'expired budget returned false success');
$expiredObservation = $limited->requestDiagnostics()['detail']['last'];
expect($expiredObservation['attempts'] === 0 && !isset($expiredObservation['http_status'])
    && !isset($expiredObservation['curl_code']) && is_int($expiredObservation['elapsed_ms'])
    && $expiredObservation['remaining_budget']['source_ms'] === 0,
    'pre-request budget stop fabricated transport measurements or lost its measured budget');
$minimumNow = 0.0;
$minimumBudget = new RunBudget(static function () use (&$minimumNow): float { return $minimumNow; });
$minimumBudget->beginSource(1);
$minimumNow = 119.5;
$remaining = $minimumBudget->remainingMilliseconds();
expect($remaining === $minimumBudget->remainingMilliseconds(1), 'default budget compatibility changed');
fails(static fn() => $minimumBudget->remainingMilliseconds(0), 'zero minimum budget was accepted');
fails(static fn() => $minimumBudget->remainingMilliseconds(-1), 'negative minimum budget was accepted');
expect($minimumBudget->remainingMilliseconds() === $remaining, 'minimum check consumed or extended budget');
echo "detail taxonomy and bounded retry PASS\n";

// Run the real planned-import preparation boundary; no category/commodity writes may begin.
$itemSource = new \App\Model\Shared();
$itemSource->domain = 'https://example.com';
$itemSource->app_id = 'fixture-app';
$itemSource->app_key = 'fixture-safe-key';
$itemSource->type = 2;
$itemTransactions = 0;
$itemMapper = new PlannedCategoryMapper(static function (callable $callback) use (&$itemTransactions): mixed {
    $itemTransactions++;
    throw new RuntimeException('item failure entered a write transaction');
});
$assertPlannedItemFailure = static function (
    string $payload,
    string $expectedCode,
    ?string $expectedCategory = null,
    int $expectedAttempts = 1,
    string $contentType = 'application/json',
    ?array $expectedStructure = null,
) use ($policy, $itemSource, $itemMapper, &$itemTransactions): void {
    $calls = 0;
    $detailPaths = [];
    $lookupsBefore = LocalSupplyMissingCommodityStub::$lookups;
    $itemHttp = new SafeHttpClient($policy, static function ($endpoint, $address) use (&$calls, &$detailPaths, $payload, $contentType): array {
        $calls++;
        $detailPaths[] = parse_url($endpoint['url'], PHP_URL_PATH);
        return ['status' => 200, 'body' => $payload, 'content_type' => $contentType, 'connected_ip' => $address];
    }, null, static function (int $ms): void {});
    $itemImporter = new CommodityImporter(new SharedGateway($itemHttp, $policy), new PriceAdjuster(),
        new RemoteItem(new ImageCache($itemHttp)), $policy);
    try {
        $itemImporter->importPlanned($itemSource, ['code' => 'SAFE-NAME', 'category' => 'Category', 'stock' => 1],
            $itemMapper, 'Fixture', ['group' => 'Group', 'family' => ''], str_repeat('a', 64), Options::fromArray([]));
        throw new RuntimeException('invalid remote item was imported');
    } catch (CommodityImportFailure $failure) {
        expect($failure->safeCode === $expectedCode, 'wrong prewrite failure code: ' . $failure->safeCode);
        expect(($failure->safeDiagnostics['category'] ?? null) === $expectedCategory, 'wrong prewrite diagnostics');
        expect($calls === $expectedAttempts && $itemTransactions === 0, 'item failure retried or began database writes');
        expect(LocalSupplyMissingCommodityStub::$lookups === $lookupsBefore + 1,
            'new-item failure fixture did not exercise its local precheck');
        if ($expectedCategory !== null) {
            expect($failure->safeDiagnostics['attempts'] === $calls, 'item classification fabricated HTTP attempts');
        }
        $detailObservation = $itemImporter->detailDiagnostics();
        if ($calls === 0) {
            expect($detailObservation === null, 'preflight failure fabricated a detail observation');
        } else {
            $expectedPath = [0 => '/shared/commodity/item', 1 => '/plugin/open-api/item', 2 => '/plugin/SharedStock/api/item'][$itemSource->type];
            expect($detailPaths === array_fill(0, $calls, $expectedPath), 'planned-import fixture used an unexpected detail route');
            expect($detailObservation['attempts'] === $calls
                && $detailObservation['mime_compatibility'] === ($contentType === 'text/html')
                && !preg_match('/TOPSECRET|fixture-safe-key|SAFE-NAME|https?:|payload|message/', json_encode($detailObservation)),
                'planned-import detail observation leaked values or changed request accounting');
            expect($itemImporter->detailDiagnostics() === $detailObservation && $calls === $expectedAttempts,
                'reading detail diagnostics changed import state or issued a request');
            if ($expectedCategory !== null) {
                expect(($detailObservation['category'] ?? null) === $expectedCategory,
                    'importer replaced the actual detail failure category with the HTTP success observation');
            }
            if ($expectedStructure !== null) {
                expect(($detailObservation['response_structure'] ?? null) === $expectedStructure,
                    'planned-import detail observation lost the exact response structure');
                if ($expectedCategory !== null) {
                    expect(($failure->safeDiagnostics['response_structure'] ?? null) === $expectedStructure,
                        'later upstream failure lost the exact response structure');
                }
            }
            if ($expectedCode === CommodityImportFailure::DETAIL_JSON_INVALID) {
                expect($detailObservation === $failure->safeDiagnostics
                    && $detailObservation['category'] === 'json' && $detailObservation['json_valid'] === false
                    && is_int($detailObservation['json_error_code'])
                    && $detailObservation['json_error'] === UpstreamFailure::jsonErrorKind($detailObservation['json_error_code'])
                    && CommodityImportFailure::isIsolatableItemCode($expectedCode),
                    'prewrite JSON isolation lost its real parser evidence');
                expect(!array_key_exists('response_structure', $detailObservation),
                    'invalid JSON fabricated a decoded response structure');
            } else {
                expect(!array_key_exists('json_error_code', $detailObservation) && !array_key_exists('json_error', $detailObservation),
                    'later validation failure inherited a JSON exception');
            }
        }
    }
};
foreach ([0, 1, 2] as $sourceType) {
    $itemSource->type = $sourceType;
    foreach (['{broken TOPSECRET', "{\"name\":\"\xC3\x28\"}", str_repeat('[', 33) . '0' . str_repeat(']', 33)] as $payload) {
        $assertPlannedItemFailure($payload, 'ITEM_DETAIL_JSON_INVALID', 'json');
    }
}
$itemSource->type = 2;
// Associative JSON decoding loses {} versus [] for empty values. Both remain no-write
// observations only: neither proves delisting or permits creating/updating a commodity.
foreach (['[]', '{}', '[{"name":"Category","children":[]}]', '[{"name":"Category","children":{}}]'] as $emptyDetail) {
    $emptyStructure = str_starts_with($emptyDetail, '[{')
        ? ['business_code' => 200, 'data_type' => 'list', 'data_count' => 1,
            'first_children_type' => 'empty_array_or_object', 'first_children_count' => 0, 'first_item_type' => 'missing']
        : ['business_code' => 200, 'data_type' => 'empty_array_or_object', 'data_count' => 0];
    $assertPlannedItemFailure('{"code":200,"data":' . $emptyDetail . '}', 'ITEM_DETAIL_UNAVAILABLE', 'item_unavailable',
        1, 'application/json', $emptyStructure);
}
foreach ([
    ['{}', ['first_children_type' => 'missing']],
    ['{"name":"Category"}', ['first_children_type' => 'missing']],
    ['{"name":"Category","children":null}', ['first_children_type' => 'null']],
    ['{"name":"Category","children":"bad"}', ['first_children_type' => 'string']],
    ['{"name":"Category","children":{"bad":[]}}',
        ['first_children_type' => 'object', 'first_children_count' => 1, 'first_item_type' => 'missing']],
    ['{"name":[],"children":[]}',
        ['first_children_type' => 'empty_array_or_object', 'first_children_count' => 0, 'first_item_type' => 'missing']],
    ['{"name":"","children":[]}',
        ['first_children_type' => 'empty_array_or_object', 'first_children_count' => 0, 'first_item_type' => 'missing']],
    ['null', []], ['"bad"', []],
] as [$invalidCategory, $childrenStructure]) {
    $assertPlannedItemFailure('{"code":200,"data":[' . $invalidCategory . ']}', 'ITEM_DETAIL_RESPONSE_INVALID', 'schema',
        1, 'application/json', ['business_code' => 200, 'data_type' => 'list', 'data_count' => 1] + $childrenStructure);
}
$assertPlannedItemFailure('{"code":200,"data":{"name":"Category","children":[]}}', 'ITEM_DETAIL_RESPONSE_INVALID', 'schema',
    1, 'application/json', ['business_code' => 200, 'data_type' => 'object', 'data_count' => 2]);
foreach (['null' => 'null', '"bad"' => 'string', '7' => 'number', 'false' => 'boolean'] as $invalidLeaf => $leafType) {
    $assertPlannedItemFailure('{"code":200,"data":[{"name":"Category","children":[' . $invalidLeaf . ']}]}',
        'ITEM_REMOTE_DATA_INVALID', 'item_invalid', 1, 'application/json', [
            'business_code' => 200, 'data_type' => 'list', 'data_count' => 1,
            'first_children_type' => 'list', 'first_children_count' => 1, 'first_item_type' => $leafType,
        ]);
}
$detailPayload = static fn(array $item): string => json_encode(
    ['code' => 200, 'data' => [['name' => 'Category', 'children' => [$item]]]], JSON_THROW_ON_ERROR);
$itemFixture = ['code' => 'SAFE-NAME', 'name' => 'Safe', 'stock' => 1, 'cover' => ''];
// Feed each real protocol adapter and the unchanged normalizer the same JSON
// under both MIME values. Empty covers keep this fixture free of image requests.
$pricedFixture = $itemFixture + ['description' => 'Safe description', 'price' => '1.25', 'user_price' => '1.25', 'widget' => '[]'];
$protocolSource = clone $itemSource;
$previousConfigOutput = LocalSupplyIniStub::$configOutput;
try {
    LocalSupplyIniStub::$configOutput = "[category]\ndefault=1.25\n[shared_mapping]\ndefault=sku-safe\n";
    foreach ([
        [0, $pricedFixture, '/shared/commodity/item'],
        [2, [['name' => 'Category', 'children' => [$pricedFixture]]], '/plugin/SharedStock/api/item'],
        [1, ['id' => 'SAFE-NAME', 'name' => 'Safe', 'introduce' => 'Safe description', 'picture_url' => '',
            'sku' => [['id' => 'sku-safe', 'name' => 'default', 'stock_price' => '1.25', 'stock' => 1]],
            'widget' => '[]'], '/plugin/open-api/item'],
    ] as [$protocolType, $protocolData, $protocolPath]) {
        $protocolSource->type = $protocolType;
        $protocolPayload = json_encode(['code' => 200, 'data' => $protocolData], JSON_THROW_ON_ERROR);
        $normalizedVariants = [];
        foreach (['application/json', 'text/html'] as $protocolMime) {
            $protocolCalls = 0;
            $protocolHttp = new SafeHttpClient($policy, static function ($endpoint, $address) use (
                &$protocolCalls, $protocolPayload, $protocolMime, $protocolPath,
            ): array {
                $protocolCalls++;
                expect(parse_url($endpoint['url'], PHP_URL_PATH) === $protocolPath,
                    'protocol adapter changed its exact detail route');
                return ['status' => 200, 'body' => $protocolPayload, 'content_type' => $protocolMime, 'connected_ip' => $address];
            });
            $protocolGateway = new SharedGateway($protocolHttp, $policy);
            $protocolItem = $protocolGateway->item($protocolSource, 'SAFE-NAME');
            $normalized = (new RemoteItem(new ImageCache($protocolHttp)))->normalize($protocolSource, $protocolItem, 'SAFE-NAME');
            expect($normalized['name'] === 'Safe' && $normalized['stock'] === 1
                && (float)$normalized['price'] === 1.25 && (float)$normalized['user_price'] === 1.25
                && $normalized['description'] === 'Safe description' && $normalized['cover'] === '/favicon.ico'
                && $protocolCalls === 1,
                'real detail protocol fields or normalizer changed under MIME compatibility');
            expect($protocolGateway->detailDiagnostics()['json_valid'] === true
                && $protocolGateway->detailDiagnostics()['mime_compatibility'] === ($protocolMime === 'text/html'),
                'protocol adapter lost safe MIME observations');
            $protocolStructure = $protocolType === 2 ? $safeStructure
                : ['business_code' => 200, 'data_type' => 'object', 'data_count' => count($protocolData)];
            $protocolDiagnostics = $protocolGateway->detailDiagnostics();
            expect($protocolDiagnostics['response_structure'] === $protocolStructure
                && $protocolGateway->detailDiagnostics() === $protocolDiagnostics
                && $protocolCalls === 1,
                'protocol response structure was inaccurate, fabricated tree fields or issued another request');
            $normalizedVariants[] = $normalized;
        }
        expect($normalizedVariants[0] === $normalizedVariants[1], 'MIME changed normalized import data');
    }
} finally {
    LocalSupplyIniStub::$configOutput = $previousConfigOutput;
}
// Valid leaves retain the old path even with absent wrapper names or unrelated
// wrapper entries. New schema checks apply only after that old path has failed.
foreach ([
    [['children' => [$itemFixture]]],
    [['children' => [$itemFixture]], 'unrelated-invalid-wrapper'],
    [0 => ['children' => [$itemFixture]], 'extra' => null],
] as $compatibleTree) {
    $compatiblePayload = json_encode(['code' => 200, 'data' => $compatibleTree], JSON_THROW_ON_ERROR);
    $compatibleHttp = new SafeHttpClient($policy, static fn($endpoint, $address): array => [
        'status' => 200, 'body' => $compatiblePayload, 'content_type' => 'application/json', 'connected_ip' => $address,
    ]);
    $compatibleItem = (new SharedGateway($compatibleHttp, $policy))->item($itemSource, 'SAFE-NAME');
    expect($compatibleItem === $itemFixture, 'valid legacy detail gained a wrapper schema gate');
    expect((new RemoteItem(new ImageCache($compatibleHttp)))->normalize($itemSource, $compatibleItem, 'SAFE-NAME')['name'] === 'Safe',
        'valid legacy detail no longer normalizes for import');
}
foreach ([
    ['name' => []], ['name' => ''], ['name' => str_repeat('x', 256)],
    ['description' => []], ['description' => str_repeat('x', 1048577)], ['cover' => []],
    ['stock' => -1], ['stock' => 'bad'], ['price' => -1], ['price' => 'bad'],
    ['widget' => '{bad'], ['widget' => [['name' => 'bad-name', 'type' => 'text']]],
    ['minimum' => -1],
    ['seckill_status' => 1, 'seckill_start_time' => '', 'seckill_end_time' => ''],
] as $invalidFields) {
    $assertPlannedItemFailure($detailPayload(array_merge($itemFixture, $invalidFields)), 'ITEM_REMOTE_DATA_INVALID',
        null, 1, 'application/json', $safeStructure);
}
foreach ([['code' => 'OTHER'], ['code' => []], ['code' => 'bad code']] as $wrongIdentity) {
    $assertPlannedItemFailure($detailPayload(array_merge($itemFixture, $wrongIdentity)), 'ITEM_DETAIL_NORMALIZATION_FAILED',
        null, 1, 'application/json', $safeStructure);
}
foreach (['403' => ['business_code' => 403], '"403"' => [], '403.0' => [], 'false' => [],
    '[]' => [], '1000000' => [], '-1000000' => []] as $businessValue => $businessStructure) {
    $assertPlannedItemFailure('{"code":' . $businessValue . ',"data":[]}', 'ITEM_DETAIL_BUSINESS_REJECTED', 'business',
        1, 'application/json', $businessStructure + ['data_type' => 'empty_array_or_object', 'data_count' => 0]);
}
$assertPlannedItemFailure('{"data":[]}', 'ITEM_DETAIL_RESPONSE_INVALID', 'schema', 1, 'application/json',
    ['data_type' => 'empty_array_or_object', 'data_count' => 0]);
$assertPlannedItemFailure('{"code":200}', 'ITEM_DETAIL_RESPONSE_INVALID', 'schema', 1, 'application/json',
    ['business_code' => 200, 'data_type' => 'missing']);
foreach (['null' => 'null', 'true' => 'boolean', '7' => 'number', '"bad"' => 'string'] as $dataValue => $dataType) {
    $assertPlannedItemFailure('{"code":200,"data":' . $dataValue . '}', 'ITEM_DETAIL_RESPONSE_INVALID', 'schema',
        1, 'application/json', ['business_code' => 200, 'data_type' => $dataType]);
}
$assertPlannedItemFailure('{"code":200,"data":[],"msg":"fixture-safe-key"}', 'ITEM_DETAIL_RESPONSE_INVALID', 'schema');
// Compatibility is an observation, never permission to skip later failure gates.
foreach ([
    ['<html>TOPSECRET</html>', 'ITEM_DETAIL_JSON_INVALID', 'json'],
    ['{"code":403,"data":[]}', 'ITEM_DETAIL_BUSINESS_REJECTED', 'business'],
    ['{"code":200,"data":"TOPSECRET"}', 'ITEM_DETAIL_RESPONSE_INVALID', 'schema'],
    [$detailPayload(array_merge($itemFixture, ['price' => -1])), 'ITEM_REMOTE_DATA_INVALID', null],
    [$detailPayload(array_merge($itemFixture, ['stock' => -1])), 'ITEM_REMOTE_DATA_INVALID', null],
    [$detailPayload(array_merge($itemFixture, ['widget' => '{bad'])), 'ITEM_REMOTE_DATA_INVALID', null],
    [$detailPayload(array_merge($itemFixture, ['code' => 'OTHER'])), 'ITEM_DETAIL_NORMALIZATION_FAILED', null],
    [$detailPayload(array_merge($itemFixture, ['config' => ['bad[key]' => 'value']])), 'ITEM_DETAIL_NORMALIZATION_FAILED', null],
] as [$invalidPayload, $invalidCode, $invalidCategory]) {
    $assertPlannedItemFailure($invalidPayload, $invalidCode, $invalidCategory, 1, 'text/html');
}
// Gateway may already have parsed a config string. Configuration validation
// therefore remains fatal without claiming that its input is the remote raw tree.
$assertPlannedItemFailure($detailPayload(array_merge($itemFixture, ['config' => ['bad[key]' => 'value']])),
    'ITEM_DETAIL_NORMALIZATION_FAILED');
$assertPlannedItemFailure($detailPayload(array_merge($itemFixture, ['config' => 'key=value'])), 'ITEM_DETAIL_RESPONSE_INVALID', 'schema',
    1, 'application/json', $safeStructure);
LocalSupplyIniStub::$fail = true;
$assertPlannedItemFailure($detailPayload(array_merge($itemFixture, ['config' => []])), 'ITEM_DETAIL_NORMALIZATION_FAILED');
LocalSupplyIniStub::$fail = false;
LocalSupplyIniStub::$configOutput = 'malformed synthetic converted text';
$assertPlannedItemFailure($detailPayload(array_merge($itemFixture, ['config' => ['key' => 'value']])),
    'ITEM_DETAIL_NORMALIZATION_FAILED');
LocalSupplyIniStub::$configOutput = null;
LocalSupplyIniStub::$arrayOutput = ['bad[key]' => 'value'];
$assertPlannedItemFailure($detailPayload(array_merge($itemFixture, ['config' => 'key=value'])),
    'ITEM_DETAIL_NORMALIZATION_FAILED');
try {
    $importStage->invoke($classifier, CommodityImportFailure::DETAIL_NORMALIZATION_FAILED, true,
        static fn(): array => (new RemoteItem(new ImageCache($http)))->normalize($itemSource,
            array_merge($itemFixture, ['config' => 'key=value']), 'SAFE-NAME'));
    throw new RuntimeException('malformed INI parser output was accepted');
} catch (CommodityImportFailure $failure) {
    expect($failure->safeCode === 'ITEM_DETAIL_NORMALIZATION_FAILED' && $itemTransactions === 0,
        'malformed INI parser output was labeled remote input or entered writes');
}
LocalSupplyIniStub::$arrayOutput = null;
LocalSupplySharedCurrencyStub::$failFactor = true;
$assertPlannedItemFailure($detailPayload($itemFixture), 'ITEM_DETAIL_UNKNOWN_FAILED', null, 0);
LocalSupplySharedCurrencyStub::$failFactor = false;
LocalSupplySharedCurrencyStub::$failItem = true;
$assertPlannedItemFailure($detailPayload($itemFixture), 'ITEM_DETAIL_RESPONSE_INVALID', 'schema',
    1, 'application/json', $safeStructure);
$assertPlannedItemFailure($detailPayload($itemFixture), 'ITEM_DETAIL_RESPONSE_INVALID', 'schema', 1, 'text/html');
LocalSupplySharedCurrencyStub::$failItem = false;
HTMLPurifier::$fail = true;
$assertPlannedItemFailure($detailPayload($itemFixture), 'ITEM_DETAIL_NORMALIZATION_FAILED');
$assertPlannedItemFailure($detailPayload($itemFixture), 'ITEM_DETAIL_NORMALIZATION_FAILED', null, 1, 'text/html');
HTMLPurifier::$fail = false;
$assertPlannedItemFailure($detailPayload(array_merge($itemFixture, ['description' => str_repeat('&', 300000)])),
    'ITEM_DETAIL_NORMALIZATION_FAILED');

$resetSource = clone $itemSource;
$resetCalls = 0;
$resetHttp = new SafeHttpClient($policy, static function ($endpoint, $address) use (&$resetCalls): array {
    $resetCalls++;
    return ['status' => 200, 'body' => '{invalid', 'content_type' => 'text/html', 'connected_ip' => $address];
});
$resetImporter = new CommodityImporter(new SharedGateway($resetHttp, $policy), new PriceAdjuster(),
    new RemoteItem(new ImageCache($resetHttp)), $policy);
foreach ([false, true] as $preflightOnly) {
    if ($preflightOnly) { $resetSource->app_key = 'short'; }
    try {
        $resetImporter->importPlanned($resetSource, ['code' => 'SAFE-NAME', 'category' => 'Category', 'stock' => 1],
            $itemMapper, 'Fixture', ['group' => 'Group', 'family' => ''], str_repeat('a', 64), Options::fromArray([]));
        throw new RuntimeException('invalid reset fixture was imported');
    } catch (CommodityImportFailure $failure) {
        expect($resetCalls === 1 && $itemTransactions === 0, 'preflight reset issued another request or wrote data');
        expect($preflightOnly ? $resetImporter->detailDiagnostics() === null
            : $resetImporter->detailDiagnostics()['json_valid'] === false,
            'preflight-only item reused the previous item detail observation');
    }
}

// One gateway instance must never reuse the previous detail's structure, even
// when the next operation stops before transport or is a catalog-only request.
foreach (['json', 'scalar_json', 'secret', 'numeric_secret', 'item_preflight', 'item_credentials',
    'items', 'items_failure', 'items_preflight', 'diagnose_preflight', 'new_schema'] as $resetCase) {
    $structureSource = clone $itemSource;
    if ($resetCase === 'numeric_secret') { $structureSource->app_key = '12345678'; }
    $structureCalls = 0;
    $secondPayload = match ($resetCase) {
        'json' => '{invalid',
        'scalar_json' => 'null',
        'secret' => '{"code":200,"data":[],"nested":{"echo":"fixture-safe-key"}}',
        'numeric_secret' => '{"code":200,"data":[],"nested":{"echo":12345678}}',
        'items_failure', 'new_schema' => '{"code":200,"data":null}',
        default => '{"code":200,"data":[]}',
    };
    $structureHttp = new SafeHttpClient($policy, static function ($endpoint, $address) use (
        &$structureCalls, $detailPayload, $itemFixture, $secondPayload,
    ): array {
        $structureCalls++;
        return ['status' => 200, 'body' => $structureCalls === 1 ? $detailPayload($itemFixture) : $secondPayload,
            'content_type' => 'application/json', 'connected_ip' => $address];
    });
    $structureGateway = new SharedGateway($structureHttp, $policy);
    expect($structureGateway->item($structureSource, 'SAFE-NAME') === $itemFixture
        && $structureGateway->detailDiagnostics()['response_structure'] === $safeStructure
        && $structureCalls === 1, 'structure reset fixture did not establish a real successful detail');
    $nextFailure = null;
    try {
        if (in_array($resetCase, ['item_credentials', 'items_preflight'], true)) {
            $structureSource->app_key = 'short';
        }
        if (str_starts_with($resetCase, 'items')) {
            expect($structureGateway->items($structureSource) === [], 'catalog reset changed successful catalog output');
        } elseif ($resetCase === 'diagnose_preflight') {
            expect($structureGateway->diagnoseItem([], 'SAFE-NAME') === SafeHttpClient::diagnosticUnavailable(),
                'standalone preflight reset changed the diagnostic report');
        } else {
            $structureGateway->item($structureSource, $resetCase === 'item_preflight' ? '' : 'SAFE-NAME');
        }
    } catch (Throwable $caught) {
        $nextFailure = $caught;
    }
    $preflightReset = in_array($resetCase, ['item_preflight', 'item_credentials', 'items_preflight', 'diagnose_preflight'], true);
    expect($structureCalls === ($preflightReset ? 1 : 2), 'structure reset changed transport attempts');
    expect(in_array($resetCase, ['items', 'diagnose_preflight'], true) ? $nextFailure === null : $nextFailure !== null,
        'structure reset changed success/failure behavior');
    $resetCategory = match ($resetCase) {
        'json' => 'json',
        'scalar_json', 'secret', 'numeric_secret', 'items_failure', 'new_schema' => 'schema',
        'item_credentials', 'items_preflight' => 'credentials',
        default => null,
    };
    if ($resetCategory !== null) {
        expect($nextFailure instanceof UpstreamFailure && $nextFailure->diagnostics['category'] === $resetCategory,
            'structure reset changed the next failure category');
    }
    $nextObservation = $structureGateway->detailDiagnostics();
    $nextStructure = $resetCase === 'new_schema' ? ['business_code' => 200, 'data_type' => 'null'] : null;
    expect(($nextObservation['response_structure'] ?? null) === $nextStructure,
        'new operation retained the previous detail structure');
    if ($nextStructure === null && $nextObservation !== null) {
        expect(!array_key_exists('response_structure', $nextObservation),
            'rejected/unparsed response retained a structure placeholder');
    }
    if ($preflightReset || str_starts_with($resetCase, 'items')) {
        expect($nextObservation === null, 'non-detail or preflight operation retained old detail diagnostics');
    }
    if ($nextFailure instanceof UpstreamFailure) {
        expect(($nextFailure->diagnostics['response_structure'] ?? null) === $nextStructure,
            'new failure retained the previous detail structure');
    }
    expect(!preg_match('/fixture-safe-key|12345678|SAFE-NAME|nested|echo/', json_encode($nextObservation)),
        'rejected string or numeric secret leaked through response structure');
    expect($structureGateway->detailDiagnostics() === $nextObservation
        && $structureCalls === ($preflightReset ? 1 : 2), 'reset diagnostic getter issued a request');
}

$isolatableCodes = ['ITEM_DETAIL_TRANSPORT_FAILED', 'ITEM_DETAIL_HTTP_RETRYABLE', 'ITEM_DETAIL_UNAVAILABLE',
    'ITEM_REMOTE_DATA_INVALID', 'ITEM_DETAIL_JSON_INVALID'];
foreach ((new ReflectionClass(CommodityImportFailure::class))->getConstants() as $constant) {
    if (is_string($constant)) {
        expect(CommodityImportFailure::isIsolatableItemCode($constant) === in_array($constant, $isolatableCodes, true),
            'item isolation allowlist broadened unexpectedly');
    }
}
foreach ([null, '', 'ITEM_IMPORT_FAILED', 'untrusted'] as $unknownCode) {
    expect(!CommodityImportFailure::isIsolatableItemCode($unknownCode), 'unknown item failure became isolatable');
}
expect(CommodityImportFailure::isResumableDetailCode('ITEM_DETAIL_FETCH_FAILED'), 'legacy manual resume compatibility changed');
expect(!CommodityImportFailure::isResumableDetailCode('ITEM_DETAIL_JSON_INVALID'), 'JSON isolation broadened the resumable-detail allowlist');
foreach ([CommodityImportFailure::DETAIL_FETCH_FAILED, CommodityImportFailure::DETAIL_NORMALIZATION_FAILED,
    CommodityImportFailure::PRICE_ADJUSTMENT_FAILED, CommodityImportFailure::PERSISTENCE_FAILED] as $stageCode) {
    try {
        $importStage->invoke($classifier, $stageCode, true, static function (): never {
            throw new RemoteItemDataInvalid('synthetic item field failure');
        });
        throw new RuntimeException('typed item failure fixture was accepted');
    } catch (CommodityImportFailure $failure) {
        $expectedCode = in_array($stageCode, [CommodityImportFailure::DETAIL_FETCH_FAILED, CommodityImportFailure::DETAIL_NORMALIZATION_FAILED], true)
            ? 'ITEM_REMOTE_DATA_INVALID' : $stageCode;
        expect($failure->safeCode === $expectedCode && $failure->safeDiagnostics === null,
            'typed item error escaped its prewrite stage boundary');
    }
}
foreach ([new RuntimeException('synthetic dependency failure'), new TypeError('synthetic dependency type failure'),
    new BudgetExceeded('source', 'synthetic budget failure')] as $fatalException) {
    try {
        $importStage->invoke($classifier, CommodityImportFailure::DETAIL_NORMALIZATION_FAILED, true,
            static function () use ($fatalException): never { throw $fatalException; });
        throw new RuntimeException('fatal normalization fixture was accepted');
    } catch (CommodityImportFailure $failure) {
        expect($failure->safeCode === 'ITEM_DETAIL_NORMALIZATION_FAILED'
            && !CommodityImportFailure::isIsolatableItemCode($failure->safeCode), 'dependency or budget failure became isolatable');
    }
}
$typedUnclassified = new RemoteItemDataInvalid('synthetic unclassified field failure');
try {
    $importStage->invoke($classifier, CommodityImportFailure::DETAIL_NORMALIZATION_FAILED, false,
        static function () use ($typedUnclassified): never { throw $typedUnclassified; });
    throw new RuntimeException('ordinary importer swallowed its original exception');
} catch (RemoteItemDataInvalid $failure) {
    expect($failure === $typedUnclassified, 'ordinary importer changed legacy exception propagation');
}
echo "prewrite item isolation boundary PASS\n";

$remoteItem = new RemoteItem(new ImageCache($http));
$productSource = new \App\Model\Shared();
$productSource->domain = 'https://example.com';
$productFixture = [
    'code' => 'SAFE-NAME',
    'name' => "Alpha  Beta\t\r\nGamma",
    'cover' => '',
];
$normalizedProduct = $remoteItem->normalize($productSource, $productFixture, 'SAFE-NAME');
$unselectedCoverCalls = 0;
$unselectedCoverHttp = new SafeHttpClient(
    new SourcePolicy(static function () use (&$unselectedCoverCalls): array {
        $unselectedCoverCalls++;
        throw new RuntimeException('unselected cover must not resolve');
    }),
    static function () use (&$unselectedCoverCalls): array {
        $unselectedCoverCalls++;
        throw new RuntimeException('unselected cover must not download');
    },
);
$withoutCover = (new RemoteItem(new ImageCache($unselectedCoverHttp)))->normalize(
    $productSource, array_replace($productFixture, ['cover' => 'https://example.com/unused.png']), 'SAFE-NAME', false,
);
expect($withoutCover['cover'] === '' && $unselectedCoverCalls === 0,
    'unselected image must not resolve or download a cover');
expect(
    $normalizedProduct['name'] === 'Alpha  Beta Gamma',
    'remote item name must normalize Tab/CR/LF runs without collapsing ordinary spaces'
);
$invalidProductNames = [
    "\x0BAlpha",
    "Alpha\x0C",
    "Alpha\x7F",
    "<b\x0B>Alpha</b>",
    "\xC3\x28",
    "\t\r\n",
    str_repeat('界', 256),
    ['not-scalar'],
];
$rejectedControls = array_merge(range(0x00, 0x08), [0x0B, 0x0C], range(0x0E, 0x1F), [0x7F]);
foreach ($rejectedControls as $rejectedControl) {
    $invalidProductNames[] = 'Alpha' . chr($rejectedControl) . 'Beta';
}
foreach ($invalidProductNames as $invalidProductName) {
    fails(
        fn() => $remoteItem->normalize(
            $productSource,
            array_merge($productFixture, ['name' => $invalidProductName]),
            'SAFE-NAME'
        ),
        'remote item name validation must stay fail-closed outside Tab/CR/LF normalization'
    );
}
$acceptedBoundaryProduct = $remoteItem->normalize(
    $productSource,
    array_merge($productFixture, ['name' => str_repeat('界', 255)]),
    'SAFE-NAME'
);
expect(
    mb_strlen($acceptedBoundaryProduct['name'], 'UTF-8') === 255,
    'remote item name must accept the 255-code-point boundary'
);
fails(
    fn() => $remoteItem->normalize(
        $productSource,
        array_merge($productFixture, ['name' => 'Safe', 'seckill_start_time' => "2026-08-31\n00:00:00"]),
        'SAFE-NAME'
    ),
    'non-name fields must not receive product-name whitespace normalization'
);

$catalogPlanner = new CatalogPlanner();
$normalizedCatalog = $catalogPlanner->flatten([[
    'name' => 'Category',
    'children' => [[
        'code' => 'SAFE-NAME',
        'name' => "Alpha  Beta\t\r\nGamma",
        'stock' => 1,
    ]],
]]);
expect(
    $normalizedCatalog['SAFE-NAME']['name'] === 'Alpha  Beta Gamma',
    'catalog item name must normalize Tab/CR/LF runs without collapsing ordinary spaces'
);
foreach ($invalidProductNames as $invalidProductName) {
    fails(
        fn() => $catalogPlanner->flatten([[
            'name' => 'Category',
            'children' => [[
                'code' => 'SAFE-NAME',
                'name' => $invalidProductName,
                'stock' => 1,
            ]],
        ]]),
        'catalog item name validation must stay fail-closed outside Tab/CR/LF normalization'
    );
}
$acceptedBoundaryCatalog = $catalogPlanner->flatten([[
    'name' => 'Category',
    'children' => [[
        'code' => 'SAFE-NAME',
        'name' => str_repeat('界', 255),
        'stock' => 1,
    ]],
]]);
expect(
    mb_strlen($acceptedBoundaryCatalog['SAFE-NAME']['name'], 'UTF-8') === 255,
    'catalog item name must accept the 255-code-point boundary'
);
fails(
    fn() => $catalogPlanner->flatten([[
        'name' => "Category\tName",
        'children' => [[
            'code' => 'SAFE-NAME',
            'name' => 'Safe',
            'stock' => 1,
        ]],
    ]]),
    'category names must not receive product-name whitespace normalization'
);

$method = new ReflectionMethod($remoteItem, 'normalizeWidgetRegex');
expect($method->invoke($remoteItem, '/^[a-z0-9_]+$/') === '^[a-z0-9_]+$', 'slash delimiter not normalized');
expect($method->invoke($remoteItem, '[a-z]+') === '^[a-z]+$', 'both missing anchors were not normalized');
expect($method->invoke($remoteItem, '[a-z]+$') === '^[a-z]+$', 'missing start anchor was not normalized');
expect($method->invoke($remoteItem, '^[a-z]+') === '^[a-z]+$', 'missing end anchor was not normalized');
expect($method->invoke($remoteItem, '^[a-z]+$') === '^[a-z]+$', 'fully anchored regex changed');
expect($method->invoke($remoteItem, 'a+\\$') === '^a+\\$$', 'escaped terminal dollar was mistaken for an anchor');
expect($method->invoke($remoteItem, '\\^a+') === '^\\^a+$', 'escaped leading caret was mistaken for an anchor');
foreach ([
    '(a+)',
    'a+|b',
    '(a)\\1',
    '\\1+',
    '\\k<name>+',
    '(?=a)a+',
    '(?i)a+',
    'a++',
    'a+?',
    'a{1,2}+',
    'a{1,2}?',
    'a+b+',
] as $unsafeRegex) {
    fails(
        fn() => $method->invoke($remoteItem, $unsafeRegex),
        'unsafe regex feature must remain rejected: ' . $unsafeRegex,
    );
}
fails(
    fn() => $method->invoke($remoteItem, str_repeat('a', 127) . '+'),
    'anchor normalization must not exceed the 128-byte persisted limit',
);
fails(fn() => $method->invoke($remoteItem, '(a+)+'), 'unsafe nested regex must fail');
fails(fn() => $method->invoke($remoteItem, "a+\\"), 'orphan backslash must fail before normalization');
fails(fn() => $method->invoke($remoteItem, 'a{30000}b'), 'large fixed repetition must fail');
fails(
    fn() => $method->invoke($remoteItem, '^a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*b$'),
    'multiple variable quantifiers must fail before reaching the browser'
);

// Real PNG bytes and the real disk cache; only DNS/HTTP are synthetic.
$pngChunk = static fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data
    . hash('crc32b', $type . $data, true);
$pngPixel = static fn(string $rgb): string => "\x89PNG\r\n\x1a\n"
    . $pngChunk('IHDR', pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0))
    . $pngChunk('IDAT', gzcompress("\0" . $rgb)) . $pngChunk('IEND', '');
$redImage = $pngPixel("\xff\0\0");
$blueImage = $pngPixel("\0\0\xff");
$refreshBytes = $redImage;
$refreshRequests = 0;
$refreshStatus = 200;
$refreshFailure = null;
$refreshSource = new \App\Model\Shared();
$refreshSource->domain = 'https://example.com';
$refreshPolicy = new SourcePolicy(static fn(string $host): array => ['93.184.216.34']);
$refreshBudget = new RunBudget();
$refreshHttp = new SafeHttpClient($refreshPolicy,
    static function (array $endpoint, string $address, string $method) use (
        &$refreshBytes, &$refreshRequests, &$refreshStatus, &$refreshFailure,
    ): array {
        $refreshRequests++;
        expect($method === 'GET', 'cover refresh must only issue GET');
        if ($refreshFailure !== null) throw $refreshFailure;
        return ['status' => $refreshStatus, 'content_type' => 'image/png',
            'body' => $refreshBytes, 'connected_ip' => $address];
    }, $refreshBudget);
$refreshCache = new ImageCache($refreshHttp, $refreshBudget);
$oldImage = $refreshCache->localize($refreshSource, '/refresh.png');
$imageFile = static fn(string $path): string => rtrim((string)BASE_PATH, '/') . $path;
expect($refreshRequests === 1 && hash_file('sha256', $imageFile($oldImage)) === hash('sha256', $redImage),
    'first image download must persist validated PNG bytes');
$refreshBytes = $blueImage;
// A fresh service instance represents the next run, including legacy cache migration.
$refreshCache = new ImageCache($refreshHttp, $refreshBudget);
$newImage = $refreshCache->localize($refreshSource, '/refresh.png', true);
expect($refreshRequests === 2 && $newImage !== $oldImage
    && hash_file('sha256', $imageFile($newImage)) === hash('sha256', $blueImage),
    'same upstream URL with changed pixels must download and publish a new content path');
expect(hash_file('sha256', $imageFile($oldImage)) === hash('sha256', $redImage),
    'refresh must preserve old bytes still referenced by unselected products');
expect($refreshCache->localize($refreshSource, '/refresh.png', true) === $newImage && $refreshRequests === 2,
    'shared URL must reuse the completed refresh within one run');
$refreshCache->beginRun();
expect($refreshCache->localize($refreshSource, '/refresh.png', true) === $newImage && $refreshRequests === 3,
    'next run must recheck the URL but reuse the content path when bytes match');
$refreshCache->beginRun();
$refreshBytes = 'not an image';
$strictNormalizer = new RemoteItem($refreshCache, $refreshBudget);
$refreshItem = ['name' => 'Refresh fixture', 'cover' => '/refresh.png', 'stock' => 1,
    'price' => '1', 'user_price' => '1'];
for ($index = 0; $index < 2; $index++) {
    $normalizedRefresh = $strictNormalizer->normalize($refreshSource, $refreshItem, 'REFRESH', true, true);
    expect(($normalizedRefresh['cover_unavailable'] ?? false) === true && $normalizedRefresh['cover'] === ''
        && $normalizedRefresh['stock'] === 1 && $normalizedRefresh['price'] === 1.0,
        'ordinary cover failure must remain explicit while other product fields finish normalization');
}
expect($refreshRequests === 4 && hash_file('sha256', $imageFile($newImage)) === hash('sha256', $blueImage),
    'failed shared URL must be attempted once per run and keep the previous bytes');
$unselectedRefresh = $strictNormalizer->normalize($refreshSource, $refreshItem, 'REFRESH', false, true);
expect($unselectedRefresh['cover'] === '' && !isset($unselectedRefresh['cover_unavailable'])
    && $refreshRequests === 4, 'unselected periodic cover must not download or report a cover failure');
$emptyRefresh = $strictNormalizer->normalize($refreshSource, array_replace($refreshItem, ['cover' => '']), 'REFRESH', true, true);
expect(($emptyRefresh['cover_unavailable'] ?? false) === true && $emptyRefresh['cover'] === ''
    && $refreshRequests === 4, 'missing periodic image must explicitly preserve the previous cover without a request');
$invalidPricePropagated = false;
try {
    $strictNormalizer->normalize($refreshSource, array_replace($refreshItem, ['price' => 'invalid']), 'REFRESH', true, true);
} catch (\Pika\LocalExtensions\PikaSupplySync\Service\RemoteItemDataInvalid) {
    $invalidPricePropagated = true;
}
expect($invalidPricePropagated && $refreshRequests === 4,
    'ordinary cover failure must not suppress independent price validation or repeat the image request');
foreach (['credentials', 'schema', 'response_size', 'unknown'] as $fatalImageCategory) {
    $refreshCache->beginRun();
    $refreshFailure = new UpstreamFailure($fatalImageCategory);
    $fatalPropagated = false;
    try {
        $refreshCache->localize($refreshSource, '/refresh.png', true);
    } catch (UpstreamFailure $exception) {
        $fatalPropagated = $exception->diagnostics['category'] === $fatalImageCategory;
    }
    expect($fatalPropagated, 'image refresh must not downgrade fatal upstream category ' . $fatalImageCategory);
}
$refreshFailure = null;
$refreshBytes = $blueImage;
foreach ([401, 403] as $fatalImageStatus) {
    $refreshCache->beginRun();
    $refreshStatus = $fatalImageStatus;
    $fatalPropagated = false;
    try {
        $refreshCache->localize($refreshSource, '/refresh.png', true);
    } catch (UpstreamFailure $exception) {
        $fatalPropagated = $exception->diagnostics['http_status'] === $fatalImageStatus;
    }
    expect($fatalPropagated, 'image authentication rejection must not degrade to a favicon');
}
$refreshStatus = 200;
$requestsBeforeSourceBudget = $refreshRequests;
$sourceRefreshCache = new ImageCache($refreshHttp, $refreshBudget);
$refreshBudget->beginSource(1);
for ($index = 0; $index < 25; $index++) $refreshBudget->reserveImage();
$sourceRefreshExhausted = false;
try {
    $sourceRefreshCache->localize($refreshSource, '/source-budget.png', true);
} catch (BudgetExceeded $exception) {
    $sourceRefreshExhausted = $exception->isSource();
}
expect($sourceRefreshExhausted && $refreshRequests === $requestsBeforeSourceBudget,
    'source budget must reject refresh before another transport call');
$refreshBudget->endSource();
$refreshBudget->beginSource(2);
$secondSourceImage = $sourceRefreshCache->localize($refreshSource, '/source-budget.png', true);
expect($refreshRequests === $requestsBeforeSourceBudget + 1
    && hash_file('sha256', $imageFile($secondSourceImage)) === hash('sha256', $blueImage),
    'source one budget failure must not poison source two refresh of the same URL');
$refreshBudget->endSource();

// First imports retain their own legacy fallback/cache contract. In particular
// a downgraded import failure must never mask a later strict refresh failure.
$importCompatibilityCache = new ImageCache($refreshHttp, $refreshBudget);
$refreshStatus = 404;
$requestsBeforeImports = $refreshRequests;
for ($index = 0; $index < 2; $index++) {
    $importRejected = false;
    try {
        $importCompatibilityCache->localize($refreshSource, '/import-failure.png');
    } catch (\Pika\LocalExtensions\PikaSupplySync\Service\RemoteCoverUnavailable) {
        $importRejected = true;
    }
    expect($importRejected, 'first-import missing cover must retain its legacy failure type');
}
expect($refreshRequests === $requestsBeforeImports + 2, 'first imports must not inherit periodic negative caching');
$refreshStatus = 401;
$strictCredentials = false;
try {
    $importCompatibilityCache->localize($refreshSource, '/import-failure.png', true);
} catch (UpstreamFailure $exception) {
    $strictCredentials = $exception->diagnostics['http_status'] === 401;
}
expect($strictCredentials && $refreshRequests === $requestsBeforeImports + 3,
    'an import failure must not hide a strict credentials failure');
$refreshStatus = 200;
$defaultAfterStrict = $importCompatibilityCache->localize($refreshSource, '/import-failure.png');
expect($refreshRequests === $requestsBeforeImports + 4
    && hash_file('sha256', $imageFile($defaultAfterStrict)) === hash('sha256', $blueImage),
    'a periodic failure must not change the independent first-import path');

$integrityCache = new ImageCache($refreshHttp, $refreshBudget);
$versionedImage = $integrityCache->localize($refreshSource, '/integrity-refresh.png', true);
file_put_contents($imageFile($versionedImage), 'damaged nonempty cache');
$integrityCache->beginRun();
$integrityRejected = false;
try {
    $integrityCache->localize($refreshSource, '/integrity-refresh.png', true);
} catch (RuntimeException $exception) {
    $integrityRejected = !$exception instanceof \Pika\LocalExtensions\PikaSupplySync\Service\RemoteCoverUnavailable
        && str_contains($exception->getMessage(), '缓存校验失败');
}
expect($integrityRejected && file_get_contents($imageFile($versionedImage)) === 'damaged nonempty cache',
    'tampered content-addressed cache must fail closed rather than overwrite or fallback');
fwrite(STDOUT, "image refresh bytes, shared URL reuse, old bytes, source budgets and fatal boundaries PASS\n");

$budget = new RunBudget();
for ($index = 0; $index < 100; $index++) {
    $budget->reserveImage();
    $budget->consumeImage(1);
}
fails(fn() => $budget->reserveImage(), 'per-run image count budget must fail closed before another download');

$fairSourceBudget = new RunBudget();
$fairSourceBudget->beginSource(1);
for ($index = 0; $index < 25; $index++) {
    $fairSourceBudget->reserveImage();
}
$sourceBudgetFailed = false;
try {
    $fairSourceBudget->reserveImage();
} catch (Throwable $exception) {
    $sourceBudgetFailed = method_exists($exception, 'isSource') && $exception->isSource();
}
expect($sourceBudgetFailed, 'first source must stop at its own image budget');
$fairSourceBudget->endSource();
$fairSourceBudget->beginSource(2);
$fairSourceBudget->reserveImage();
$fairSourceBudget->endSource();

$coverSource = new \App\Model\Shared();
$coverSource->domain = 'https://example.com';
$coverBudget = new RunBudget();
$coverBudget->beginSource(1);
for ($index = 0; $index < 25; $index++) {
    $coverBudget->reserveImage();
}
$coverNormalizer = new RemoteItem(new ImageCache($http, $coverBudget), $coverBudget);
$coverMethod = new ReflectionMethod($coverNormalizer, 'cover');
$coverBudgetPropagated = false;
try {
    $coverMethod->invoke($coverNormalizer, $coverSource, '/budget.png');
} catch (Throwable $exception) {
    $coverBudgetPropagated = method_exists($exception, 'isSource') && $exception->isSource();
}
expect($coverBudgetPropagated, 'cover image budget exhaustion must propagate to SyncService');
$coverBudget->endSource();

$fallbackNormalizer = new RemoteItem(new ImageCache($http));
$fallbackCover = new ReflectionMethod($fallbackNormalizer, 'cover');
expect(
    $fallbackCover->invoke($fallbackNormalizer, $coverSource, 'http://example.com/cover.png') === '/favicon.ico',
    'an invalid remote cover should degrade to the favicon'
);
$cacheDirectory = LocalPath::directory('assets/cache/pika-supply-sync', 0755);
$symlinkCover = '/cache-integrity.png';
$symlinkUrl = rtrim($coverSource->domain, '/') . '/' . ltrim($symlinkCover, '/');
$symlinkPath = $cacheDirectory . '/' . hash('sha256', $symlinkUrl) . '.jpg';
if (!symlink('/tmp/pika-cover-cache-symlink-target', $symlinkPath)) {
    throw new RuntimeException('unable to create cover cache symlink fixture');
}
$cacheIntegrityPropagated = false;
try {
    $fallbackCover->invoke($fallbackNormalizer, $coverSource, $symlinkCover);
} catch (Throwable $exception) {
    $cacheIntegrityPropagated = str_contains($exception->getMessage(), '封面缓存不能是符号链接');
} finally {
    unlink($symlinkPath);
}
expect($cacheIntegrityPropagated, 'cover cache integrity failures must not degrade to the favicon');

$rotation = new StateStore();
expect($rotation->orderSources([1, 2, 3]) === [1, 2, 3], 'fresh source order mismatch');
$rotation->markSourceAttempted(1);
expect($rotation->orderSources([1, 2, 3]) === [2, 3, 1], 'source rotation did not advance after source one');
$rotation->markSourceAttempted(3);
expect($rotation->orderSources([1, 2, 3]) === [1, 2, 3], 'source rotation did not wrap after source three');

$widgetBudget = new RunBudget();
$widgetItem = new RemoteItem(new ImageCache($http), $widgetBudget);
$widgetMethod = new ReflectionMethod($widgetItem, 'widget');
$widget = [[
    'cn' => '账号', 'name' => 'account', 'placeholder' => '', 'type' => 'text',
    'regex' => '^[a-z]+$', 'error' => '', 'dict' => 'one,two',
]];
$widgetMethod->invoke($widgetItem, $widget);
$normalizedWidget = json_decode(
    $widgetMethod->invoke($widgetItem, [array_merge($widget[0], ['regex' => '[a-z]+'])]),
    true,
    8,
    JSON_THROW_ON_ERROR,
);
expect(
    ($normalizedWidget[0]['regex'] ?? null) === '^[a-z]+$',
    'widget JSON did not persist the normalized anchored regex',
);
$unicodeWidget = [[
    'cn' => '账号', 'name' => '账号名称_1', 'placeholder' => '', 'type' => 'text',
    'regex' => '', 'error' => '', 'dict' => '',
]];
$unicodeEncoded = $widgetMethod->invoke($widgetItem, $unicodeWidget);
$unicodeDecoded = json_decode($unicodeEncoded, true, 8, JSON_THROW_ON_ERROR);
expect(
    ($unicodeDecoded[0]['name'] ?? null) === '账号名称_1',
    'a safe Unicode widget name must be preserved for upstream order forwarding'
);
$maximumUnicodeName = str_repeat('账', 32);
$maximumUnicodeEncoded = $widgetMethod->invoke(
    $widgetItem,
    [array_merge($widget[0], ['name' => $maximumUnicodeName])],
);
$maximumUnicodeDecoded = json_decode($maximumUnicodeEncoded, true, 8, JSON_THROW_ON_ERROR);
expect(
    ($maximumUnicodeDecoded[0]['name'] ?? null) === $maximumUnicodeName,
    'a 32-codepoint Unicode widget name must remain byte-for-byte intact'
);
$formRoundTrip = [];
parse_str(http_build_query([$maximumUnicodeName => 'value']), $formRoundTrip);
expect(
    ($formRoundTrip[$maximumUnicodeName] ?? null) === 'value',
    'a safe Unicode widget name must survive the PHP form-key round trip'
);
foreach ([
    '1account', 'account-name', 'account name', 'account[name]', 'account"name',
    ' account', 'account ', '<b>account</b>', 'account&name', "account\nname",
    "account\x7Fname", "\xC3\x28", "账\u{202E}号", "账\u{200D}号", "账\u{0301}",
    str_repeat('账', 33),
] as $unsafeWidgetName) {
    fails(
        fn() => $widgetMethod->invoke(
            $widgetItem,
            [array_merge($widget[0], ['name' => $unsafeWidgetName])],
        ),
        'unsafe widget names must remain rejected'
    );
}
foreach (['email', 'text ', '<b>text</b>', "text\n"] as $unsafeWidgetType) {
    fails(
        fn() => $widgetMethod->invoke(
            $widgetItem,
            [array_merge($widget[0], ['type' => $unsafeWidgetType])],
        ),
        'widget types must match the original allowlist without normalization'
    );
}
$textBytes = new ReflectionProperty($widgetBudget, 'textBytes');
expect($textBytes->getValue($widgetBudget) > 0, 'array widget must consume the per-run text budget');
$oversizedWidgets = array_fill(0, 32, array_merge($widget[0], ['dict' => str_repeat('x', 4096)]));
fails(fn() => $widgetMethod->invoke($widgetItem, $oversizedWidgets), 'canonical array widget must respect byte limit');

$descriptionBudget = new RunBudget();
$descriptionItem = new RemoteItem(new ImageCache($http), $descriptionBudget);
$descriptionMethod = new ReflectionMethod($descriptionItem, 'description');
$descriptionMethod->invoke($descriptionItem, str_repeat('&', 1000));
$descriptionBytes = new ReflectionProperty($descriptionBudget, 'textBytes');
expect($descriptionBytes->getValue($descriptionBudget) === 5000, 'purified description size must drive the text budget');
fails(
    fn() => $descriptionMethod->invoke($descriptionItem, str_repeat('&', 300000)),
    'purified description output must respect the 1MB item limit'
);
expect(
    !str_contains(Redactor::text('remote app_key=LEAKME1234', ['LEAKME1234']), 'LEAKME1234'),
    'exact source secret must be redacted'
);

$options = Options::fromArray([
    'mode' => 'full',
    'source_ids' => '2,1,2',
    'premium_percent' => '10',
    'batch_limit' => '2',
    'zero_fuse_percent' => '50',
    'zero_fuse_min' => '2',
]);
expect($options->sourceIds === [1, 2], 'source ids not normalized');
expect(abs($options->premiumFactor() - 0.1) < 0.000001, '10 percent factor mismatch');

$defaultOptions = Options::fromArray([]);
expect(abs($defaultOptions->premiumFactor()) < 0.000001, 'default premium factor must be zero');

$defaultConfig = require dirname(__DIR__) . '/extensions/PikaSupplySync/Config/Config.php';
$savedOptions = Options::fromArray(array_merge($defaultConfig, ['premium_percent' => '10']));
expect(abs($savedOptions->premiumFactor() - 0.1) < 0.000001, 'saved 10 percent must override zero default');

$catalog = [
    'A' => ['code' => 'A', 'name' => 'A', 'category' => 'C', 'stock' => 8, 'item' => []],
    'B' => ['code' => 'B', 'name' => 'B', 'category' => 'C', 'stock' => 1, 'item' => []],
    'C' => ['code' => 'C', 'name' => 'C', 'category' => 'C', 'stock' => 1, 'item' => []],
];
$local = [
    'A' => ['id' => 1, 'status' => 0, 'stock' => 0],
    'B' => ['id' => 2, 'status' => 1, 'stock' => 1],
];
$plan = (new CatalogPlanner())->plan(
    $catalog,
    $local,
    '',
    $options
);
expect($plan['actions'][0] === ['type' => 'sync', 'code' => 'A', 'lane' => 'normal'], 'stock priority missing');
expect(count($plan['actions']) === 2, 'batch limit not enforced');

$numericCatalog = $catalogPlanner->flatten([[
    'name' => 'Numeric codes',
    'children' => [
        ['code' => 1001, 'name' => 'One', 'stock' => 2],
        ['code' => 1002, 'name' => 'Two', 'stock' => 2],
    ],
]]);
$numericPriorityPlan = $catalogPlanner->plan(
    $numericCatalog,
    [1001 => ['id' => 1001, 'status' => 1, 'stock' => 1, 'managed' => true, 'inventory_sync' => 1]],
    '',
    Options::fromArray(['mode' => 'basic', 'batch_limit' => 4]),
    '1000',
);
expect(
    $numericPriorityPlan['actions'][0] === ['type' => 'sync', 'code' => '1001', 'lane' => 'priority'],
    'numeric product codes must remain strings in the priority cursor lane'
);
expect(
    $numericPriorityPlan['next_priority_cursor'] === '1001',
    'numeric priority cursor must persist as a string'
);
$numericNormalPlan = $catalogPlanner->plan(
    $numericCatalog,
    [],
    '1001',
    Options::fromArray(['mode' => 'full', 'batch_limit' => 1]),
);
expect(
    $numericNormalPlan['actions'][0] === ['type' => 'import', 'code' => '1002', 'lane' => 'normal'],
    'numeric product codes must remain strings in the normal cursor lane'
);
expect($numericNormalPlan['next_cursor'] === '1002', 'numeric cursor must persist as a string');

$opaqueNumericCatalog = $catalogPlanner->flatten([[
    'name' => 'Opaque numeric codes',
    'children' => [
        ['code' => '2', 'name' => 'Two', 'stock' => 1],
        ['code' => '10', 'name' => 'Ten', 'stock' => 1],
    ],
]]);
$opaqueNumericPlan = $catalogPlanner->plan(
    $opaqueNumericCatalog,
    [],
    '',
    Options::fromArray(['mode' => 'full', 'batch_limit' => 2]),
);
expect(
    $opaqueNumericPlan['actions'] === [
        ['type' => 'import', 'code' => '10', 'lane' => 'normal'],
        ['type' => 'import', 'code' => '2', 'lane' => 'normal'],
    ],
    'opaque numeric product codes must use string ordering and string action values'
);
expect($opaqueNumericPlan['next_cursor'] === '2', 'opaque numeric cursor must retain the final string code');
$opaqueNumericWrapPlan = $catalogPlanner->plan(
    $opaqueNumericCatalog,
    [],
    '2',
    Options::fromArray(['mode' => 'full', 'batch_limit' => 1]),
);
expect(
    $opaqueNumericWrapPlan['actions'][0] === ['type' => 'import', 'code' => '10', 'lane' => 'normal'],
    'opaque numeric cursor must wrap according to string ordering'
);
expect($opaqueNumericWrapPlan['next_cursor'] === '10', 'wrapped opaque numeric cursor must remain a string');

$manualPlan = (new CatalogPlanner())->plan($catalog, $local, '', $options);
expect($manualPlan['actions'][0] === ['type' => 'sync', 'code' => 'A', 'lane' => 'normal'], 'manual status must not change stock planning');

$zeroNew = (new CatalogPlanner())->plan(
    ['Z' => ['code' => 'Z', 'name' => 'Z', 'category' => 'C', 'stock' => 0, 'item' => []]],
    [],
    '',
    Options::fromArray(['mode' => 'full', 'batch_limit' => 1]),
);
expect($zeroNew['actions'][0] === ['type' => 'hold_zero', 'code' => 'Z', 'lane' => 'normal'], 'zero-stock new item must wait for restock');

$inventoryOff = (new CatalogPlanner())->plan(
    ['I' => ['code' => 'I', 'name' => 'I', 'category' => 'C', 'stock' => 0, 'item' => []]],
    ['I' => ['id' => 9, 'status' => 1, 'stock' => 5, 'managed' => true, 'inventory_sync' => 0]],
    '',
    Options::fromArray(['mode' => 'basic', 'batch_limit' => 1]),
);
expect($inventoryOff['actions'][0] === ['type' => 'sync', 'code' => 'I', 'lane' => 'normal'], 'inventory opt-out must not offline item');

$zeroExisting = (new CatalogPlanner())->plan(
    ['O' => ['code' => 'O', 'name' => 'O', 'category' => 'C', 'stock' => 0, 'item' => []]],
    ['O' => ['id' => 11, 'status' => 0, 'stock' => 5, 'managed' => true, 'inventory_sync' => 1]],
    '',
    Options::fromArray(['mode' => 'basic', 'batch_limit' => 1, 'zero_fuse_min' => 2]),
);
expect($zeroExisting['actions'][0] === ['type' => 'zero', 'code' => 'O', 'lane' => 'normal'], 'zero stock must not become a status change');

// Explicit zeroes have their own fuse; missing entries retain the aggregate gate.
$zeroSplitCases = [
    [100, 6, 10, false, true],
    [100, 11, 0, true, true],
    [100, 10, 0, false, false],
    [20, 4, 0, false, false],
    [50, 5, 0, false, false],
    [40, 5, 0, true, true],
    [100, 0, 11, false, true],
    [100, 0, 10, false, false],
    [10, 1, 1, false, false],
];
foreach ($zeroSplitCases as [$active, $explicit, $missing, $explicitHeld, $aggregateHeld]) {
    $splitCatalog = $splitLocal = $expectedTypes = [];
    for ($index = 0; $index < $active; $index++) {
        $isZero = $index < $explicit;
        $isMissing = !$isZero && $index < $explicit + $missing;
        $code = ($isZero ? 'Z' : ($isMissing ? 'M' : 'P')) . sprintf('%03d', $index);
        $splitLocal[$code] = ['id' => $index + 1, 'status' => $index % 2,
            'stock' => 7, 'managed' => true, 'inventory_sync' => 1];
        if (!$isMissing) $splitCatalog[$code] = ['code' => $code, 'name' => $code,
            'category' => 'C', 'stock' => $isZero ? 0 : 7, 'item' => []];
        $expectedTypes[$code] = $isZero ? ($explicitHeld ? 'hold_zero' : 'zero')
            : ($isMissing ? ($aggregateHeld ? 'hold_zero' : 'zero') : 'sync');
    }
    $splitPlan = $catalogPlanner->plan($splitCatalog, $splitLocal, '',
        Options::fromArray(['mode' => 'basic', 'batch_limit' => 100]));
    expect($splitPlan['fuse'] === $aggregateHeld
        && $splitPlan['fuse_ratio'] === round(($explicit + $missing) / $active * 100, 2),
        'zero split must preserve the aggregate fuse and ratio');
    expect(count($splitPlan['actions']) === $active, 'zero split lost or duplicated batch work');
    $expectedCounts = ['sync' => 0, 'import' => 0, 'zero' => 0, 'hold_zero' => 0];
    foreach ($splitPlan['actions'] as $action) {
        $expected = $expectedTypes[$action['code']];
        expect($action['type'] === $expected,
            "zero split D={$active} E={$explicit} M={$missing}: {$action['code']} expected {$expected}");
        expect($action['lane'] === ($expected === 'zero' ? 'priority' : 'normal'),
            'priority selection must use the same zero gate as execution');
        $expectedCounts[$expected]++;
    }
    expect($splitPlan['counts'] === $expectedCounts, 'zero split action counts do not match execution');
}

foreach ([[], ['stock' => null], ['stock' => false], ['stock' => -1], ['stock' => 0.5],
    ['stock' => 'unknown'], ['stock' => []], ['stock' => 2147483648]] as $invalidStock) {
    fails(fn() => $catalogPlanner->flatten([['name' => 'C', 'children' => [
        ['code' => 'INVALID', 'name' => 'Invalid stock'] + $invalidStock,
    ]]]), 'missing, null or invalid catalog stock must not be normalized to explicit zero');
}
foreach ([0, '0', 0.0, 7, '7', 7.0] as $validStock) {
    $validCatalog = $catalogPlanner->flatten([['name' => 'C', 'children' => [
        ['code' => 'VALID', 'name' => 'Valid stock', 'stock' => $validStock],
    ]]]);
    expect($validCatalog['VALID']['stock'] === (int)$validStock, 'valid catalog stock compatibility changed');
}
fwrite(STDOUT, "zero split planner PASS: 9 gate cases, 8 invalid and 6 valid stock cases\n");

$unmanaged = (new CatalogPlanner())->plan(
    ['T' => ['code' => 'T', 'name' => 'T', 'category' => 'C', 'stock' => 8, 'item' => []]],
    ['T' => ['id' => 10, 'status' => 1, 'stock' => 1, 'managed' => false, 'inventory_sync' => 1]],
    '',
    Options::fromArray(['mode' => 'full', 'batch_limit' => 1]),
);
expect($unmanaged['actions'] === [], 'officially managed template item must stay untouched');

$manyCatalog = [];
$manyLocal = [];
foreach (['A', 'B', 'C', 'D', 'E'] as $code) {
    $manyCatalog[$code] = ['code' => $code, 'name' => $code, 'category' => 'C', 'stock' => 2, 'item' => []];
    $manyLocal[$code] = ['id' => ord($code), 'status' => 1, 'stock' => $code === 'E' ? 2 : 1];
}
$fair = (new CatalogPlanner())->plan(
    $manyCatalog,
    $manyLocal,
    '',
    Options::fromArray(['mode' => 'basic', 'batch_limit' => 4]),
);
expect(in_array(['type' => 'sync', 'code' => 'E', 'lane' => 'normal'], $fair['actions'], true), 'priority work must reserve normal rotation capacity');

$syncReflection = new ReflectionClass(SyncService::class);
$syncObject = $syncReflection->newInstanceWithoutConstructor();
$categoryReflection = new ReflectionClass(CategoryMapper::class);
$categoryObject = $categoryReflection->newInstanceWithoutConstructor();
$rootName = $categoryReflection->getMethod('rootName');
$rootMarker = $categoryReflection->getMethod('rootMarker');
$mappingMatches = $categoryReflection->getMethod('mappingMatches');
$sourceOneRoot = $rootName->invoke($categoryObject, 1, '同名货源');
$sourceTwoRoot = $rootName->invoke($categoryObject, 2, '同名货源');
expect($sourceOneRoot !== $sourceTwoRoot, 'same-name sources must receive source-ID-isolated roots');
$sourceOneMarker = $rootMarker->invoke($categoryObject, 1);
expect(
    $mappingMatches->invoke($categoryObject, 0, null, null, '同名货源', $sourceOneMarker) === false,
    'manual same-name root must never satisfy the extension mapping'
);
expect(
    $mappingMatches->invoke($categoryObject, 0, null, null, $sourceOneRoot, $sourceOneMarker) === true,
    'source-marked root must satisfy its own persisted mapping'
);
expect(
    $mappingMatches->invoke($categoryObject, 0, 99, 100, '远端分类', null) === false,
    'mapped child under another root must be rejected'
);

$importerReflection = new ReflectionClass(CommodityImporter::class);
$importerObject = $importerReflection->newInstanceWithoutConstructor();
$assertImportableStock = $importerReflection->getMethod('assertImportableStock');
$assertImportableStock->invoke(
    $importerObject,
    ['stock' => 0],
    ['stock' => 0],
);
$assertImportableStock->invoke(
    $importerObject,
    ['stock' => 5],
    ['stock' => 0],
);
fails(
    static fn() => $assertImportableStock->invoke(
        $importerObject,
        ['stock' => '0'],
        ['stock' => 0],
    ),
    'non-integer catalog stock must fail closed',
);
fails(
    static fn() => $assertImportableStock->invoke(
        $importerObject,
        ['stock' => -1],
        ['stock' => 0],
    ),
    'negative catalog stock must fail closed',
);
fails(
    static fn() => $assertImportableStock->invoke(
        $importerObject,
        ['stock' => 1],
        ['stock' => '0'],
    ),
    'non-integer detail stock must fail closed',
);
fails(
    static fn() => $assertImportableStock->invoke(
        $importerObject,
        ['stock' => 1],
        ['stock' => -1],
    ),
    'negative detail stock must fail closed',
);
fails(
    static fn() => $assertImportableStock->invoke(
        $importerObject,
        ['stock' => 0x80000000],
        ['stock' => 0],
    ),
    'out-of-range catalog stock must fail closed',
);
fails(
    static fn() => $assertImportableStock->invoke(
        $importerObject,
        ['stock' => 1],
        ['stock' => 0x80000000],
    ),
    'out-of-range detail stock must fail closed',
);
$classifyExisting = $importerReflection->getMethod('classifyExisting');
$managedOutcome = $classifyExisting->invoke(
    $importerObject,
    'PKS1' . str_repeat('A', 20),
    0,
    \App\Model\PriceTemplate::TYPE_PERCENT,
);
$unmanagedOutcome = $classifyExisting->invoke(
    $importerObject,
    'MANUAL-CODE',
    0,
    \App\Model\PriceTemplate::TYPE_PERCENT,
);
expect(
    $managedOutcome === CommodityImporter::OUTCOME_ALREADY_MANAGED,
    'concurrent extension import must report already_managed'
);
expect(
    $unmanagedOutcome === CommodityImporter::OUTCOME_HELD_EXISTING_UNMANAGED,
    'concurrent unmanaged import must be held'
);

$recordImportOutcome = $syncReflection->getMethod('recordImportOutcome');
$applied = [
    'sync' => 0,
    'import' => 0,
    'zero' => 0,
    'held_race' => 0,
    'already_managed' => 0,
    'held_existing_unmanaged' => 0,
];
$applied = $recordImportOutcome->invoke($syncObject, $applied, CommodityImporter::OUTCOME_CREATED);
$applied = $recordImportOutcome->invoke($syncObject, $applied, $managedOutcome);
$applied = $recordImportOutcome->invoke($syncObject, $applied, $unmanagedOutcome);
expect($applied['import'] === 1, 'only a created commodity may increment the import counter');
expect($applied['already_managed'] === 1, 'already-managed race outcome was not counted separately');
expect($applied['held_existing_unmanaged'] === 1, 'held unmanaged race outcome was not counted separately');

$projection = $syncReflection->getMethod('buildUpdateValues');
$baseItem = [
    'name' => 'Remote', 'description' => '<p>Safe</p>', 'cover' => '/assets/cache/cover.png',
    'draft_status' => 0, 'seckill_status' => 0, 'seckill_start_time' => '',
    'seckill_end_time' => '', 'widget' => '[]', 'minimum' => 0, 'maximum' => 0,
    'contact_type' => 0, 'stock' => 9,
];
$disabled = $projection->invoke($syncObject, $baseItem, ['price' => '2.00', 'user_price' => '2.00', 'config' => []], null, false, false, false);
foreach (['price', 'user_price', 'config', 'name', 'description', 'cover', 'stock', 'status', 'shared_stock', 'shared_amount_sync', 'shared_config_sync', 'inventory_sync'] as $field) {
    expect(!array_key_exists($field, $disabled), "disabled sync field {$field} must stay untouched");
}
$enabled = $projection->invoke($syncObject, $baseItem, ['price' => '2.00', 'user_price' => '2.00', 'config' => []], '0.00', true, true, true);
foreach (['price', 'user_price', 'config', 'name', 'description', 'cover', 'draft_status', 'widget', 'stock', 'shared_stock'] as $field) {
    expect(array_key_exists($field, $enabled), "enabled sync field {$field} must update");
}
foreach (['seckill_status', 'seckill_start_time', 'seckill_end_time', 'minimum', 'maximum', 'contact_type'] as $field) {
    expect(!array_key_exists($field, $enabled), "operator-owned field {$field} must stay untouched during periodic sync");
}
expect(!array_key_exists('status', $enabled), 'plugin must never change administrator status');

$selectedProjection = $syncReflection->getMethod('buildSelectedValues');
$selectionCases = 0;
for ($mask = 0; $mask < 64; $mask++) {
    $selection = [];
    foreach (Options::SYNC_FIELDS as $index => $field) $selection['sync_' . $field] = (bool)($mask & (1 << $index));
    $selectedOptions = Options::fromArray($selection);
    for ($gates = 0; $gates < 8; $gates++) {
        $commodity = new \App\Model\Commodity();
        $commodity->shared_amount_sync = (int)(bool)($gates & 1);
        $commodity->shared_config_sync = (int)(bool)($gates & 2);
        $commodity->inventory_sync = (int)(bool)($gates & 4);
        $held = false;
        $arguments = [$commodity, $baseItem, ['price' => '2.00', 'user_price' => '2.00', 'config' => []],
            '0.00', $selectedOptions, &$held];
        $values = $selectedProjection->invokeArgs($syncObject, $arguments);
        foreach (['name', 'cover', 'description'] as $field) {
            expect(array_key_exists($field, $values) === ($selection['sync_' . $field] && (bool)($gates & 2)),
                "selected {$field} must intersect the native config gate");
        }
        foreach (['price', 'user_price', 'draft_premium'] as $field) {
            expect(array_key_exists($field, $values) === ($selection['sync_price'] && (bool)($gates & 1)),
                "selected {$field} must intersect the native amount gate");
        }
        foreach (['stock', 'shared_stock'] as $field) {
            expect(array_key_exists($field, $values) === ($selection['sync_inventory'] && (bool)($gates & 4)),
                "selected {$field} must intersect the native inventory gate");
        }
        foreach (['draft_status', 'widget'] as $field) {
            expect(array_key_exists($field, $values) === ($selection['sync_options'] && (bool)($gates & 2)),
                "selected {$field} must intersect the native options gate");
        }
        expect(!$held && !isset($values['status']) && !isset($values['category_id']), 'projection changed ownership or status');
        if ($values !== []) {
            expect($values['api_status'] === 1 && $values['shared_sync'] === 0, 'effective selection lost existing housekeeping');
        } else {
            expect(!isset($values['api_status']) && !isset($values['shared_sync']), 'empty selection wrote housekeeping');
        }
        $selectionCases++;
    }
}
fwrite(STDOUT, "field selection projection PASS {$selectionCases} combinations; unselected cover network calls=0\n");

fwrite(STDOUT, "local supply behavior PASS\n");
