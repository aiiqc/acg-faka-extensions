import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import {fileURLToPath} from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const extension = path.join(root, 'extensions/PikaSupplySync');
const read = relative => fs.readFileSync(path.join(extension, relative), 'utf8');

function sourcesBelow(directory) {
    return fs.readdirSync(directory, {withFileTypes: true}).flatMap(entry => {
        const file = path.join(directory, entry.name);
        return entry.isDirectory() ? sourcesBelow(file) : [file];
    });
}

test('declares a LocalExtensions package and no official plugin lifecycle', () => {
    const manifest = JSON.parse(read('local-extension.json'));
    assert.equal(manifest.schema, 1);
    assert.equal(manifest.id, 'PikaSupplySync');
    assert.equal(manifest.name, '货源同步');
    assert.match(manifest.description, /[\u4e00-\u9fff]/);
    for (const field of ['name', 'cover', 'description', 'price', 'inventory', 'options']) {
        const setting = manifest.settings.find(item => item.key === `sync_${field}`);
        assert.equal(setting.type, 'checkbox');
        assert.equal(Object.hasOwn(setting, 'default'), false, 'legacy selection must remain absent');
    }
    assert.equal(manifest.version, '1.1.20');
    assert.equal(manifest.namespace, 'Pika\\LocalExtensions\\PikaSupplySync\\');
    assert.equal(manifest.bootstrap, 'bootstrap.php');
    assert.deepEqual(manifest.hooks, []);
    assert.equal(manifest.settings.find(item => item.key === 'premium_percent').default, 0);

    const php = sourcesBelow(extension)
        .filter(file => file.endsWith('.php'))
        .map(file => fs.readFileSync(file, 'utf8'))
        .join('\n');
    assert.doesNotMatch(php, /namespace App\\Plugin\\PikaSupplySync/);
    assert.doesNotMatch(php, /Kernel\\Annotation\\Plugin|_plugin_start|PLUGIN_CONFIG|['"]STATUS['"]/);
    assert.doesNotMatch(php, /\\App\\Service\\Shared|PluginRuntime/);
    assert.equal(fs.existsSync(path.join(extension, 'Hook/Lifecycle.php')), false);

    const cli = read('bin/sync.php');
    assert.match(cli, /dirname\(\$extensionRoot, 3\)/);
    assert.match(cli, /\/local-extensions\/bootstrap\.php/);
    assert.match(cli, /\$config\s*=\s*array_merge\(\$defaults,\s*\$saved\)/);
    const targetReader = cli.slice(cli.indexOf('$targetOptionsReader ='), cli.indexOf('$policy = new SourcePolicy'));
    assert.match(targetReader, /ManagerState::isEnabled\(EXTENSION_ID, true\)/);
    assert.match(targetReader, /SyncService::targetedOptions\(/);
    assert.doesNotMatch(targetReader, /\$overrides/);
    assert.doesNotMatch(cli, /\/local-extensions\/manager\/bootstrap\.php/);
});

test('CLI rejects unknown, misspelled, duplicate and valueless options before loading the site', t => {
    const probe = spawnSync('docker', ['version'], {encoding: 'utf8'});
    if (probe.error?.code === 'ENOENT') {
        t.skip('Docker is unavailable on this host');
        return;
    }
    const cli = '/release/extensions/PikaSupplySync/bin/sync.php';
    const run = args => spawnSync('docker', [
        'run', '--rm', '--network', 'none', '--pull', 'never', '-v', `${root}:/release:ro`, '--entrypoint', 'php',
        process.env.PHP_FIXTURE_IMAGE || 'php:8.3-cli', cli, ...args,
    ], {encoding: 'utf8'});
    for (const [args, expected] of [
        [['--dryrun', '--source=1'], /未知参数：--dryrun/],
        [['--dry-run=true'], /开关不接受参数值：--dry-run/],
        [['--source=1', '--source=2'], /参数重复：--source/],
        [['--batch'], /参数缺少值：--batch/],
        [['positional'], /不支持的位置参数或短参数/],
        [['--only-code-hashes=aaaaaaaaaaaa'], /需要明确唯一/],
        [['--source=1,2', '--only-code-hashes=aaaaaaaaaaaa'], /需要明确唯一/],
        [['--source=1', '--only-code-hashes='], /参数缺少值/],
        [['--source=1', '--only-code-hashes=AAAAAAAAAAAA'], /需要明确唯一/],
        [['--source=1', '--only-code-hashes=aaaaaaaaaaaa,aaaaaaaaaaaa'], /需要明确唯一/],
        [['--source=1', '--only-code-hashes=aaaaaaaaaaaa,bbbbbbbbbbbb,cccccccccccc'], /需要明确唯一/],
    ]) {
        const result = run(args);
        assert.notEqual(result.status, 0, `unsafe CLI arguments unexpectedly succeeded: ${args.join(' ')}`);
        assert.match(result.stderr, expected);
        assert.doesNotMatch(result.stderr, /Acg-Faka 站点根目录不正确/);
    }
    const help = run(['--help']);
    assert.equal(help.status, 0, help.stderr);
    assert.match(help.stdout, /Usage: php bin\/sync\.php/);
    assert.match(help.stdout, /--only-code-hashes/);
    for (const hashes of ['aaaaaaaaaaaa', 'aaaaaaaaaaaa,bbbbbbbbbbbb']) {
        const parsed = run(['--source=1', `--only-code-hashes=${hashes}`, '--root=/does-not-exist']);
        assert.equal(parsed.status, 1);
        assert.match(parsed.stderr, /Acg-Faka 站点根目录不正确/);
        assert.doesNotMatch(parsed.stderr, /未知参数|需要明确唯一/);
    }
});

test('owns a pinned HTTPS client with bounded parsing', () => {
    const http = read('Service/SafeHttpClient.php');
    const policy = read('Service/SourcePolicy.php');
    const postJsonMethod = http.slice(
        http.indexOf('public function postJson'),
        http.indexOf('public static function diagnosticUnavailable'),
    );
    const imageMethod = http.slice(
        http.indexOf('public function getImage'),
        http.indexOf('private function request'),
    );
    assert.match(http, /CURLOPT_SSL_VERIFYPEER\s*=>\s*true/);
    assert.match(http, /CURLOPT_SSL_VERIFYHOST\s*=>\s*2/);
    assert.match(http, /CURLOPT_FOLLOWLOCATION\s*=>\s*false/);
    assert.match(http, /CURLOPT_RESOLVE/);
    assert.match(http, /CURLOPT_URL\s*=>\s*\$endpoint\['url'\]/);
    assert.match(http, /\$resolveHost/);
    assert.match(http, /CURLINFO_PRIMARY_IP/);
    assert.match(http, /hash_equals\(SourcePolicy::normalizeIp\(\$expectedIp\)/);
    assert.match(http, /CONNECT_TIMEOUT_MS\s*=\s*5000/);
    assert.match(http, /REQUEST_TIMEOUT_MS\s*=\s*90000/);
    assert.match(http, /DEFAULT_READ_IDLE_SECONDS\s*=\s*30/);
    assert.match(http, /CATALOG_READ_IDLE_SECONDS\s*=\s*60/);
    assert.match(http, /jsonReadIdleSeconds\(string \$url\): int/);
    assert.match(http, /'\/shared\/commodity\/items'/);
    assert.match(http, /'\/plugin\/SharedStock\/api\/items'/);
    assert.match(http, /'\/plugin\/open-api\/items'/);
    assert.match(http, /CURLOPT_LOW_SPEED_TIME\s*=>\s*max\([\s\S]+min\(\$readIdleSeconds,/);
    assert.match(postJsonMethod, /\$this->jsonReadIdleSeconds\(\$url\),/);
    assert.doesNotMatch(postJsonMethod, /DEFAULT_READ_IDLE_SECONDS|CATALOG_READ_IDLE_SECONDS/);
    assert.match(imageMethod, /self::DEFAULT_READ_IDLE_SECONDS,/);
    assert.doesNotMatch(imageMethod, /CATALOG_READ_IDLE_SECONDS|jsonReadIdleSeconds/);
    assert.match(http, /MAX_JSON_BYTES\s*=\s*16777216/);
    assert.match(http, /MAX_JSON_DEPTH\s*=\s*32/);
    assert.match(http, /MAX_JSON_NODES\s*=\s*500000/);
    assert.match(http, /MAX_ATTEMPTS\s*=\s*3/);
    assert.match(http, /RETRY_BACKOFF_MS\s*=\s*\[500, 1000\]/);
    assert.match(http, /RETRYABLE_HTTP_STATUSES\s*=\s*\[408, 429, 502, 503, 504\]/);
    assert.match(http, /catch \(RetryableTransportFailure \$exception\)/);
    assert.match(http, /function backoff\(int \$attempt, int \$retryAfterMs\): void/);
    assert.doesNotMatch(http, /catch \(\\Throwable \$exception\) \{\s*unset\(\$exception\);\s*\}/);
    assert.doesNotMatch(http, /getMessage|getTrace|json_encode\(\$exception/i);
    assert.match(http, /CURLOPT_PROXY\s*=>\s*''/);
    assert.match(policy, /FILTER_FLAG_NO_PRIV_RANGE\s*\|\s*FILTER_FLAG_NO_RES_RANGE/);
    assert.match(policy, /100\.64\.0\.0\/10/);
    assert.match(policy, /2001::\/23/);
    assert.match(policy, /fec0::\/10/);
    assert.match(policy, /5f00::\/16/);
    assert.match(policy, /100:0:0:1::\/64/);
    assert.match(policy, /远端主机名必须使用规范小写形式且不能带尾点/);
    assert.match(policy, /hash_equals\(\$canonicalUrlHost, \$rawHost\)/);
    assert.match(policy, /共享店铺只允许无凭据、无参数的 HTTPS 标准 443 根地址/);
});

test('keeps standalone detail diagnosis one-shot, bounded, and free of model bootstrap', () => {
    const http = read('Service/SafeHttpClient.php');
    const gateway = read('Service/SharedGateway.php');
    const entry = fs.readFileSync(path.join(root, 'scripts/diagnose-supply-item.php'), 'utf8');
    const diagnostic = http.slice(
        http.indexOf('public static function diagnosticUnavailable'),
        http.indexOf('public function getImage'),
    );
    const request = http.slice(http.indexOf('private function request('), http.indexOf('private function curl('));
    const curl = http.slice(http.indexOf('private function curl('), http.indexOf('private function backoff('));
    const gatewayDiagnostic = gateway.slice(
        gateway.indexOf('public function diagnoseItem('),
        gateway.indexOf('private function legacyTreeItem('),
    );

    assert.match(http, /public static function diagnosticUnavailable\(\): array/);
    assert.match(http, /public function diagnosePostJson\(string \$url, array \$headers, array \$form, int \$sourceType\): array/);
    assert.match(http, /private function recordDiagnosticHeader\(string \$header\): void/);
    assert.match(diagnostic, /'schema_version'\s*=>\s*1/);
    assert.match(diagnostic, /'diagnostic_only'\s*=>\s*true/);
    assert.match(diagnostic, /\[0\s*=>\s*'\/shared\/commodity\/item',\s*1\s*=>\s*'\/plugin\/open-api\/item',\s*2\s*=>\s*'\/plugin\/SharedStock\/api\/item'\]/);
    assert.match(diagnostic, /parse_url\(\$url, PHP_URL_PATH\)\s*!==\s*\$detailPaths\[\$sourceType\]/);
    assert.match(diagnostic, /json_decode\(\$response\['body'\], false, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR\)/);
    assert.match(diagnostic, /if \(\$report\['within_json_limits'\]\)\s*\{\s*\$this->describeDiagnosticShape/);
    assert.doesNotMatch(diagnostic, /\$report\[['"](?:body|headers|url|form|request|response)['"]\]/);

    assert.match(request, /bool \$diagnostic\s*=\s*false/);
    assert.match(request, /\$maxAttempts\s*=\s*\$diagnostic\s*\?\s*1\s*:\s*self::MAX_ATTEMPTS/);
    assert.match(curl, /if \(\$diagnostic\)\s*\{\s*\$this->recordDiagnosticHeader\(\$header\)/);
    assert.match(curl, /CURLOPT_SSL_VERIFYPEER\s*=>\s*true/);
    assert.match(curl, /CURLOPT_SSL_VERIFYHOST\s*=>\s*2/);
    assert.match(curl, /CURLOPT_FOLLOWLOCATION\s*=>\s*false/);
    assert.match(curl, /CURLOPT_RESOLVE/);
    assert.match(curl, /CURLOPT_PROXY\s*=>\s*''/);

    assert.match(gateway, /public function diagnoseItem\(array \$source, string \$code\): array/);
    assert.match(gatewayDiagnostic, /count\(\$source\)\s*!==\s*4/);
    assert.match(gatewayDiagnostic, /!is_string\(\$source\['domain'\]/);
    assert.match(gatewayDiagnostic, /!is_string\(\$source\['app_id'\]/);
    assert.match(gatewayDiagnostic, /!is_string\(\$source\['app_key'\]/);
    assert.match(gatewayDiagnostic, /!is_int\(\$source\['type'\]/);
    assert.match(gatewayDiagnostic, /!in_array\(\$source\['type'\], \[0, 1, 2\], true\)/);
    assert.match(gatewayDiagnostic, /->diagnosePostJson\(/);
    assert.doesNotMatch(gatewayDiagnostic, /App\\Model|Shared::|new Shared|kernel\/|bootstrap|DB::/);

    assert.match(entry, /function pikaSupplyItemDiagnosticUnavailable\(\): array/);
    assert.match(entry, /function pikaDiagnoseSupplyItem\(string \$coreRoot, array \$source, string \$code\): array/);
    const entryFunction = entry.slice(entry.indexOf('function pikaDiagnoseSupplyItem('));
    const consume = entryFunction.indexOf('$consumed = true;');
    assert.ok(consume >= 0 && consume < entryFunction.indexOf("ini_get($setting)"),
        'one-process diagnostic must be consumed before any preflight');
    assert.doesNotMatch(entry, /STDIN|php:\/\/stdin|\$_SERVER\[['"]argv['"]\]|fwrite\(|\bexit\s*\(/);
    assert.doesNotMatch(entry.slice(0, entry.indexOf('function pikaDiagnoseSupplyItem(')), /\brequire(?:_once)?\b|\binclude(?:_once)?\b/);
});

test('tolerates MIME only on exact detail routes without broadly resuming response errors', () => {
    const http = read('Service/SafeHttpClient.php');
    const importer = read('Service/CommodityImporter.php');
    const gateway = read('Service/SharedGateway.php');
    const failure = read('Service/CommodityImportFailure.php');
    const upstream = read('Service/UpstreamFailure.php');
    const post = http.slice(http.indexOf('public function postJson('), http.indexOf('public static function diagnosticUnavailable('));
    const request = http.slice(http.indexOf('private function request('), http.indexOf('private function curl('));
    assert.match(post, /\$detail = in_array\(parse_url\(\$url, PHP_URL_PATH\), \[\s*'\/shared\/commodity\/item', '\/plugin\/open-api\/item', '\/plugin\/SharedStock\/api\/item',\s*\], true\)/);
    assert.match(post, /if \(!\$detail && !in_array\(\$contentType, \['application\/json', 'text\/json'\], true\)\)/);
    assert.match(post, /json_decode\(\$response\['body'\], true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR\)/);
    assert.match(post, /\$this->assertNodeLimit\(\$decoded\)/);
    assert.doesNotMatch(post, /strip_tags|preg_replace|JSON_INVALID_UTF8|json_decode\([^;]+\?\?/);
    assert.match(request, /if \(\$response\['status'\] !== 200 && !\$diagnostic\)/);
    assert.match(request, /\$maxAttempts = \$diagnostic \? 1 : self::MAX_ATTEMPTS/);
    assert.doesNotMatch(request, /in_array\(\$category, \[[^\]]*content_type/);
    for (const field of ['mime_category', 'mime_count', 'json_valid', 'mime_compatibility']) {
        assert.match(upstream, new RegExp(`array_key_exists\\('${field}', \\$values\\)`));
    }
    assert.match(upstream, /\$values\['mime_count'\] <= 65535/);
    assert.match(gateway, /\$this->http->clearDetailDiagnostics\(\)/);
    const getter = importer.slice(importer.indexOf('public function detailDiagnostics('), importer.indexOf('public function __construct('));
    assert.match(getter, /return \$this->detailDiagnostics;/);
    assert.doesNotMatch(getter, /gateway|http|query|stage/);
    assert.match(importer, /public function import\([^)]*\): string\s*\{\s*\$this->detailDiagnostics = null;/);
    assert.match(importer, /public function importPlanned\([\s\S]*?\): string\s*\{\s*\$this->detailDiagnostics = null;/);
    assert.match(importer, /finally\s*\{\s*\$this->detailDiagnostics \?\?= \$this->gateway->detailDiagnostics\(\);/);
    assert.match(importer, /'content_type', 'schema', 'response_size' => CommodityImportFailure::DETAIL_RESPONSE_INVALID/);
    assert.match(importer, /'json' => \$diagnostics\['http_status'\] === 200 && \$diagnostics\['curl_code'\] === 0/);
    assert.match(importer, /\? CommodityImportFailure::DETAIL_JSON_INVALID : CommodityImportFailure::DETAIL_RESPONSE_INVALID/);
    const resumable = failure.slice(failure.indexOf('public static function isResumableDetailCode('), failure.indexOf('public function __construct('));
    assert.doesNotMatch(resumable, /DETAIL_JSON_INVALID|DETAIL_RESPONSE_INVALID|DETAIL_BUSINESS_REJECTED|DETAIL_NORMALIZATION_FAILED/);
});

test('uses 60-second idle only for exact catalog JSON paths', t => {
    const probe = spawnSync('docker', ['version'], {encoding: 'utf8'});
    if (probe.error?.code === 'ENOENT') {
        t.skip('Docker is unavailable on this host');
        return;
    }
    const script = String.raw`
require '/release/extensions/PikaSupplySync/Service/SafeHttpClient.php';
$class = new ReflectionClass(Pika\LocalExtensions\PikaSupplySync\Service\SafeHttpClient::class);
$client = $class->newInstanceWithoutConstructor();
$method = $class->getMethod('jsonReadIdleSeconds');
$method->setAccessible(true);
$cases = [
    'https://example.com/shared/commodity/items' => 60,
    'https://example.com/plugin/SharedStock/api/items' => 60,
    'https://example.com/plugin/open-api/items' => 60,
    'https://example.com/shared/commodity/item' => 30,
    'https://example.com/plugin/SharedStock/api/item' => 30,
    'https://example.com/plugin/open-api/item' => 30,
    'https://example.com/shared/authentication/connect' => 30,
    'https://example.com/plugin/SharedStock/api/connect' => 30,
    'https://example.com/plugin/open-api/connect' => 30,
    'https://example.com/other/items' => 30,
    'https://example.com/shared/commodity/items/' => 30,
    'https://example.com/SHARED/commodity/items' => 30,
];
foreach ($cases as $url => $expected) {
    $actual = $method->invoke($client, $url);
    if ($actual !== $expected) {
        fwrite(STDERR, sprintf("idle mismatch expected=%d actual=%d url=%s\\n", $expected, $actual, $url));
        exit(1);
    }
}
echo "catalog idle path matrix PASS\\n";
`;
    const result = spawnSync('docker', [
        'run', '--rm', '--network', 'none', '--pull', 'never', '-v', `${root}:/release:ro`, 'php:8.3-cli',
        'php', '-d', 'display_errors=1', '-r', script,
    ], {encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    assert.match(result.stdout, /catalog idle path matrix PASS/);
});

test('uses the safe client for every catalog and item request', () => {
    const gateway = read('Service/SharedGateway.php');
    const sync = read('Service/SyncService.php');
    assert.match(gateway, /private SafeHttpClient \$http/);
    assert.match(gateway, /\/shared\/commodity\/items/);
    assert.match(gateway, /\/shared\/commodity\/item/);
    assert.match(gateway, /\/plugin\/open-api\/items/);
    assert.match(gateway, /\/plugin\/SharedStock\/api\/items/);
    assert.match(gateway, /Str::generateSignature/);
    assert.match(sync, /\$this->gateway->items\(\$source\)/);
    assert.match(sync, /\$this->gateway->item\(\$source, \$code\)/);
});

test('rechecks source identity inside every write transaction', () => {
    const identity = read('Service/SourceIdentity.php');
    const importer = read('Service/CommodityImporter.php');
    const importFailure = read('Service/CommodityImportFailure.php');
    const categories = read('Service/CategoryMapper.php');
    const sync = read('Service/SyncService.php');
    const lock = read('Service/SourceLock.php');
    const planned = read('Service/PlannedCategoryMapper.php');
    const localPath = read('Service/LocalPath.php');
    assert.match(identity, /'domain'.*'app_id'.*'app_key'/s);
    assert.match(identity, /lockForUpdate\(\)/);
    assert.match(identity, /hash_equals/);
    assert.match(importer, /DB::transaction[\s\S]+SourceIdentity::lockAndVerify/);
    const importPlanned = importer.slice(importer.indexOf('public function importPlanned('),
        importer.indexOf('private function prepare('));
    const prepareIndex = importPlanned.indexOf('$prepared = $this->prepare(');
    assert.ok(prepareIndex > 0);
    const existingPath = importPlanned.slice(0, prepareIndex);
    const newItemPath = importPlanned.slice(prepareIndex);
    assert.match(existingPath, /->exists\(\)/);
    assert.match(existingPath, /\$this->sourcePolicy->assertSafe\(\$source\)/);
    assert.match(existingPath, /withResolvedCategory\([\s\S]+\$this->persistExisting\(/);
    assert.match(existingPath, /throw \$missing/);
    assert.match(existingPath, /if \(\$failure !== \$missing\)\s*\{\s*throw \$failure/);
    assert.match(existingPath, /if \(\$outcome !== null\)\s*\{\s*return \$outcome/);
    assert.doesNotMatch(existingPath, /\$this->prepare\(|\$this->gateway->|->normalize\(/);
    assert.match(newItemPath, /\$this->prepare\([\s\S]+withResolvedCategory\([\s\S]+\$this->persist\(/);
    for (const safeCode of [
        'ITEM_SOURCE_POLICY_FAILED',
        'ITEM_DETAIL_FETCH_FAILED',
        'ITEM_DETAIL_NORMALIZATION_FAILED',
        'ITEM_STOCK_VALIDATION_FAILED',
        'ITEM_PRICE_ADJUSTMENT_FAILED',
        'ITEM_CONFIG_EXTRACTION_FAILED',
        'ITEM_CATEGORY_TRANSACTION_FAILED',
        'ITEM_PERSISTENCE_FAILED',
    ]) {
        assert.match(importFailure, new RegExp(`['"]${safeCode}['"]`));
    }
    assert.match(importFailure, /class CommodityImportFailure extends RuntimeException/);
    assert.match(importer, /catch \(CommodityImportFailure \$failure\)[\s\S]+throw \$failure/);
    assert.doesNotMatch(importer + importFailure, /getMessage|getTrace|json_encode\(\$exception/i);
    assert.match(importer, /\$catalogItem\['category'\][\s\S]+\$planHash/);
    assert.match(importer, /OUTCOME_ALREADY_MANAGED[\s\S]+\$existing->category_id\s*=\s*\$categoryId/);
    assert.match(importer, /Category::query\(\)->whereKey\(\$categoryId\)->lockForUpdate\(\)/);
    assert.match(categories, /DB::transaction[\s\S]+SourceIdentity::lockAndVerify/);
    assert.ok((sync.match(/SourceIdentity::lockAndVerify\(\$source\)/g) ?? []).length >= 2);
    assert.match(lock, /runtime\/local-extensions\/extensions\/PikaSupplySync/);
    assert.match(localPath, /PathGuard::stateRoot\(\)/);
    assert.match(localPath, /\['runtime', 'local-extensions'\]/);
    assert.match(localPath, /in_array\('\.', \$parts, true\)/);
    assert.match(lock, /source-' \. \$sourceId \. '\.run\.lock'/);
    assert.match(lock, /return @fopen\(\$path, 'r\+b'\)/);
    assert.match(lock, /return @fopen\(\$path, 'x\+b'\)/);
    assert.match(lock, /umask\(0o177\)/);
    assert.match(lock, /finally[\s\S]+umask\(\$previousUmask\)/);
    assert.match(lock, /assertSafeExistingPath\(\$path\)/);
    assert.ok((lock.match(/assertSafeHandle\(\$handle, \$path\)/g) ?? []).length >= 2);
    assert.match(lock, /fstat\(\$handle\)/);
    assert.match(lock, /lstat\(\$path\)/);
    assert.match(lock, /PathGuard::runtimeOwner\(\)/);
    assert.match(lock, /\['nlink'\]/);
    assert.match(lock, /\['dev'\]/);
    assert.match(lock, /\['ino'\]/);
    assert.match(lock, /if \(!flock\(\$handle, LOCK_EX \| LOCK_NB\)\)[\s\S]+return false;/);
    assert.doesNotMatch(lock, /fopen\(\$path, ['"]c['"]\)/);
    assert.doesNotMatch(lock, /chmod\(\$path/);
    assert.doesNotMatch(lock, /fchmod\(/);
    assert.doesNotMatch(lock, /shared-store-sync/);

    assert.match(planned, /@fopen\(\$path, 'r\+b'\)/);
    assert.match(planned, /@fopen\(\$path, 'x\+b'\)/);
    assert.match(planned, /umask\(0177\)/);
    assert.match(planned, /finally[\s\S]+umask\(\$previousUmask\)/);
    assert.match(planned, /assertSafeExistingLockPath\(\$path, \$pathMetadata\)/);
    assert.ok((planned.match(/assertHandle\(\$lock, \$lockPath, '映射锁'\)/g) ?? []).length >= 2);
    assert.match(planned, /fstat\(\$handle\)/);
    assert.match(planned, /lstat\(\$path\)/);
    assert.match(planned, /PathGuard::runtimeOwner\(\)/);
    assert.match(planned, /MAX_NODES\s*=\s*2048/);
    assert.match(planned, /assertProjectedCapacity\(/);
    const transaction = planned.slice(planned.indexOf('public function withResolvedCategory('));
    const capacityGate = transaction.indexOf('$this->assertProjectedCapacity(');
    const firstCreation = transaction.indexOf('$leaf = $this->resolveNode(');
    assert.ok(capacityGate >= 0 && firstCreation > capacityGate,
        'complete plan capacity must be checked before the shared parent-first node creation loop');
    assert.match(planned, /source-category/);
    assert.match(planned, /\['nlink'\]/);
    assert.match(planned, /\['dev'\]/);
    assert.match(planned, /\['ino'\]/);
    assert.doesNotMatch(planned, /fopen\(\$lockPath, ['"]c\+b['"]\)/);
    assert.doesNotMatch(planned, /chmod\(\$lockPath/);
    assert.doesNotMatch(planned, /fchmod\(/);
});

test('isolates only explicit prewrite item failures without widening schema or dependency failures', () => {
    const failure = read('Service/CommodityImportFailure.php');
    const importer = read('Service/CommodityImporter.php');
    const gateway = read('Service/SharedGateway.php');
    const item = read('Service/RemoteItem.php');
    const itemFailure = read('Service/RemoteItemDataInvalid.php');
    const allowlist = failure.match(/function isIsolatableItemCode\([^)]*\): bool\s*\{([\s\S]*?)\n    \}/)?.[1];
    assert.ok(allowlist, 'explicit item isolation predicate is missing');
    assert.deepEqual([...allowlist.matchAll(/self::([A-Z_]+)/g)].map(match => match[1]), [
        'DETAIL_TRANSPORT_FAILED', 'DETAIL_HTTP_RETRYABLE', 'DETAIL_ITEM_UNAVAILABLE', 'ITEM_DATA_INVALID', 'DETAIL_JSON_INVALID',
    ]);
    assert.match(failure, /DETAIL_ITEM_UNAVAILABLE = 'ITEM_DETAIL_UNAVAILABLE'/);
    assert.match(failure, /ITEM_DATA_INVALID = 'ITEM_REMOTE_DATA_INVALID'/);
    assert.match(itemFailure, /final class RemoteItemDataInvalid extends RuntimeException/);
    assert.match(importer, /\$exception instanceof RemoteItemDataInvalid[\s\S]*?DETAIL_FETCH_FAILED, CommodityImportFailure::DETAIL_NORMALIZATION_FAILED/);
    assert.match(importer, /'item_unavailable' => CommodityImportFailure::DETAIL_ITEM_UNAVAILABLE/);
    assert.match(importer, /'item_invalid' => CommodityImportFailure::ITEM_DATA_INVALID/);
    assert.match(gateway, /if \(is_array\(\$tree\[0\]\['children'\]\[0\] \?\? null\)\)\s*\{\s*return \$tree\[0\]\['children'\]\[0\]/);
    assert.match(gateway, /array_is_list\(\$tree\)/);
    assert.match(gateway, /array_is_list\(\$category\['children'\]\)/);
    assert.match(item, /throw new RuntimeException\('远端商品详情编号与请求不一致'\)/);
    assert.match(item, /throw new RuntimeException\('净化后的远端商品说明超过 1MB'\)/);
    const configMethod = item.slice(item.indexOf('private function config('), item.indexOf('private function widget('));
    const configValidators = item.slice(item.indexOf('private function validateConfigText('), item.indexOf('private function normalizeWidgetRegex('));
    assert.doesNotMatch(configMethod + configValidators, /RemoteItemDataInvalid/);
    assert.doesNotMatch(item, /catch\s*\(\\?Throwable/);
    assert.doesNotMatch(item + itemFailure + importer, /getMessage|getTrace/);
    assert.ok(sourcesBelow(extension).includes(path.join(extension, 'Service/RemoteItemDataInvalid.php')));
});

test('stages the typed item failure with the existing extension payload walker', () => {
    const script = String.raw`
        mkdir('/tmp/supply-payload');
        passthru('php /release/scripts/stage-payload.php --repo-root /release --stage-root /tmp/supply-payload --mode update', $status);
        if ($status !== 0) { exit($status); }
        $file = '/Service/RemoteItemDataInvalid.php';
        $source = '/release/extensions/PikaSupplySync' . $file;
        $staged = '/tmp/supply-payload/local-extensions/extensions/PikaSupplySync' . $file;
        if (!is_file($staged) || hash_file('sha256', $source) !== hash_file('sha256', $staged)) { exit(1); }
        echo "typed item failure payload PASS\n";
    `;
    const result = spawnSync('docker', [
        'run', '--rm', '--read-only', '--network', 'none', '--pull', 'never',
        '--tmpfs', '/tmp:rw,nosuid,nodev,size=64m', '-v', `${root}:/release:ro`, 'php:8.3-cli',
        'php', '-r', script,
    ], {encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    assert.match(result.stdout, /typed item failure payload PASS/);
});

test('manages only its own bounded imports and preserves downstream sharing', () => {
    const sync = read('Service/SyncService.php');
    const importer = read('Service/CommodityImporter.php');
    assert.match(sync, /MAX_LOCAL_ITEMS\s*=\s*10000/);
    assert.match(sync, /where\('owner', 0\)[\s\S]+where\('shared_id'/);
    assert.match(sync, /\^PKS1\[A-F0-9\]\{20\}\$/);
    assert.doesNotMatch(sync, /update\(\['shared_sync' => 0\]\)/);
    assert.match(importer, /'PKS1' \. strtoupper\(bin2hex\(random_bytes\(10\)\)\)/);
    assert.doesNotMatch(importer, /目录与详情库存不一致/);
    assert.match(importer, /远端商品库存格式不正确/);
    assert.match(importer, /\$commodity->stock\s*=\s*\$item\['stock'\]/);
    assert.match(importer, /\$commodity->status\s*=\s*1/);
    assert.match(importer, /\$commodity->owner\s*=\s*0/);
    assert.match(importer, /\$commodity->api_status\s*=\s*1/);
    assert.match(importer, /\$commodity->shared_sync\s*=\s*0/);
    for (const field of [
        'seckill_status',
        'seckill_start_time',
        'seckill_end_time',
        'minimum',
        'maximum',
        'contact_type',
    ]) {
        assert.match(importer, new RegExp(`\\$commodity->${field}\\s*=`), `initial import must set ${field}`);
        assert.doesNotMatch(sync, new RegExp(`\\$updates\\['${field}'\\]\\s*=`), `periodic sync must preserve ${field}`);
    }
});

test('localizes public HTTPS images and accepts official slash-delimited widget regex', () => {
    const image = read('Service/ImageCache.php');
    const item = read('Service/RemoteItem.php');
    assert.match(image, /\$this->http->getImage\(\$url\)/);
    assert.match(image, /getimagesizefromstring/);
    assert.match(image, /assets\/cache\/pika-supply-sync/);
    assert.match(image, /MAX_CACHE_BYTES\s*=\s*268435456/);
    assert.match(image, /cacheLock\(\)/);
    assert.match(item, /return \$this->images->localize\(\$source, \$cover, \$refresh\)/);
    assert.doesNotMatch(item, /img\[src/);
    assert.match(item, /str_starts_with\(\$regex, '\/'\)/);
    assert.match(item, /MAX_QUANTIFIERS\s*=\s*1/);
    assert.match(item, /quantifiers\(\$regex\)/);
    assert.match(item, /MAX_FIXED_REPETITION = 256/);
    assert.match(item, /assertWidgetRegexCompiles\(\$regex\)/);
    assert.match(item, /hasUnescapedEndAnchor\(\$regex\)/);
    assert.match(item, /if \(!str_starts_with\(\$regex, '\^'\)\)/);
    assert.match(item, /\$regex \.[=] '\$'/);
    assert.ok((item.match(/assertWidgetRegexCompiles\(\$regex\)/g) ?? []).length >= 2);
    assert.match(item, /consumeText\(strlen\(\$encoded\)\)/);
    assert.match(item, /consumeText\(max\(strlen\(\$html\), strlen\(\$clean\)\)\)/);
    assert.match(item, /净化后的远端商品说明超过 1MB/);
    assert.match(item, /return \$regex/);
    assert.ok(
        item.includes("preg_match('/^\\p{L}[\\p{L}\\p{N}_]{0,31}$/uD', $name)"),
        'safe Unicode widget identifiers must be accepted without broadening punctuation',
    );
    assert.doesNotMatch(item, /\^\[A-Za-z\]\[A-Za-z0-9_\]/);
    assert.doesNotMatch(item, /\$name\s*=\s*\$this->plain\(\$widget\['name'\]/);
    assert.doesNotMatch(item, /\$type\s*=\s*\$this->plain\(\$widget\['type'\]/);
    assert.match(item, /!is_string\(\$name\)/);
    assert.match(item, /!mb_check_encoding\(\$name, 'UTF-8'\)/);
    const budget = read('Service/RunBudget.php');
    assert.match(budget, /MAX_IMAGE_DOWNLOADS\s*=\s*100/);
    assert.match(budget, /MAX_IMAGE_BYTES\s*=\s*52428800/);
    assert.match(budget, /MAX_TEXT_BYTES\s*=\s*10485760/);
});

test('keeps full/basic, stock priority and zero percent default behavior', () => {
    const options = read('Service/Options.php');
    const planner = read('Service/CatalogPlanner.php');
    const config = read('Config/Config.php');
    assert.match(options, /MODE_BASIC\s*=\s*'basic'/);
    assert.match(options, /MODE_FULL\s*=\s*'full'/);
    assert.match(config, /'premium_percent'\s*=>\s*'0'/);
    assert.match(options, /\['premium_percent'\]\s*\?\?\s*0/);
    assert.match(planner, /\$priority\[\]\s*=\s*\$code/);
    assert.match(planner, /floor\(\$options->batchLimit \* 0\.75\)/);
    assert.match(planner, /next_priority_cursor/);
    assert.match(planner, /private function actionType\(/);
    assert.match(planner, /return \(\$remote === null \? \$fuse : \$explicitZeroFuse\) && \(int\)\$row\['stock'\] > 0\s*\? 'hold_zero' : 'zero'/);
    assert.doesNotMatch(planner, /autoOffline|restore|offline/);
    assert.match(planner, /\$options->mode === Options::MODE_FULL/);

    const sync = read('Service/SyncService.php');
    assert.match(sync, /\$amountSync = \(int\)\$commodity->shared_amount_sync === 1/);
    assert.match(sync, /\$configSync = \(int\)\$commodity->shared_config_sync === 1/);
    assert.doesNotMatch(sync, /'shared_amount_sync'\s*=>\s*1/);
    assert.doesNotMatch(sync, /'shared_config_sync'\s*=>\s*1/);
    assert.doesNotMatch(sync, /'inventory_sync'\s*=>\s*1/);
    assert.doesNotMatch(sync, /\$commodity->status\s*=/);
    assert.match(sync, /\$updates\['description'\]\s*=\s*\$item\['description'\]/);
    assert.match(sync, /\$updates\['cover'\]\s*=\s*\$item\['cover'\]/);
    assert.match(sync, /catalogHubManagesSource\(\$sourceId\)/);
    assert.match(sync, /category-map\.json/);
    assert.match(sync, /jobs\/jobs\.json/);
    assert.match(sync, /hasExactKeys\(\$state, \['schema', 'jobs'\]\)/);
    assert.match(sync, /hasExactKeys\(\$state, \['schema', 'jobs', 'snapshot_gc'\]\)/);
    assert.match(
        sync,
        /hasExactKeys\(\s*\$snapshotGc,\s*\['task_id', 'source_fingerprint', 'plan_hash', 'sha256'\],?\s*\)/,
    );
    assert.match(sync, /array_key_exists\(\$snapshotGc\['task_id'\], \$state\['jobs'\]\)/);
    assert.match(sync, /\(\$planHash === null\) !== \(\$snapshotSha256 === null\)/);
    assert.match(sync, /PikaSupplySync \u540c\u6b65\u6a21\u5f0f\u6539\u4e3a basic/);
    assert.ok(
        sync.indexOf('catalogHubManagesSource($sourceId)') < sync.indexOf('$this->gateway->items($source)'),
        'CatalogHub full-mode guard must run before contacting the upstream catalog',
    );

    const manifest = JSON.parse(read('local-extension.json'));
    const mode = manifest.settings.find((setting) => setting.key === 'mode');
    assert.match(mode.options.find((option) => option.value === 'full').label, /\u4e0d\u9002\u7528\u4e8e\u667a\u80fd\u8d27\u6e90中心/);
    assert.equal(manifest.settings.find((setting) => setting.key === 'premium_percent').default, 0);
});
