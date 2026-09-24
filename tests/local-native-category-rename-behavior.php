<?php
declare(strict_types=1);

// The routes use pinned official controllers, Request and model behavior.
// SQLite, synthetic identities and a private tmpfs replace external state.
use App\Model\Category;
use App\Model\Shared;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Schema\Blueprint;
use Pika\LocalExtensions\Manager\Runtime;
use Pika\LocalExtensions\PikaCatalogHub\Service\ConfigRepository;
use Pika\LocalExtensions\PikaCatalogHub\Service\SourceAliasService;
use Pika\LocalExtensions\PikaSupplySync\Service\PlannedCategoryMapper;

function nativeExpect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function lang(string $message): string { return $message; }
function nativeCopy(string $from, string $to): void {
    mkdir($to, 0755, true);
    chmod($to, 0755);
    foreach (scandir($from) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if (is_dir($from . '/' . $entry)) nativeCopy($from . '/' . $entry, $to . '/' . $entry);
        else { copy($from . '/' . $entry, $to . '/' . $entry); chmod($to . '/' . $entry, 0644); }
    }
}
final class NativeTreeOfficialDecision {
    public static mixed $result = null;
    public function after(mixed $controller, mixed $action, mixed &$result): mixed { return self::$result; }
}

// The same file is the loopback-only router for the real HTTP fixture below.
// This deliberately does not boot Kernel, installation, live plugins or jobs.
// PHP parses the actual form body; official Request/SharedValidation/items and
// the installed Helper/Runtime/Dispatcher perform the authenticated export.
if (PHP_SAPI === 'cli-server') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '0');
    set_error_handler(static function (int $severity): bool {
        if (!(error_reporting() & $severity)) return false;
        throw new ErrorException('FIXTURE_RUNTIME_WARNING', 0, $severity);
    }, E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE);
    header('Content-Type: application/json; charset=utf-8');
    $httpStage = 'bootstrap';
    try {
        $httpFixture = realpath((string)getenv('PIKA_NATIVE_HTTP_FIXTURE'));
        $httpOfficial = realpath((string)getenv('ACG_FAKA_OFFICIAL_ROOT'));
        nativeExpect($httpFixture !== false && $httpOfficial !== false
            && str_starts_with($httpFixture, sys_get_temp_dir() . '/pika-native-category-')
            && ($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1'
            && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
            && ($_SERVER['REQUEST_URI'] ?? '') === '/shared/commodity/items', 'invalid HTTP fixture boundary');
        define('BASE_PATH', $httpFixture . '/');
        require $httpOfficial . '/vendor/autoload.php';
        require dirname(__DIR__) . '/manager/site/local-extensions/bootstrap.php';
        require $httpFixture . '/kernel/Helper.php';
        foreach (['PikaSupplySync', 'PikaCatalogHub'] as $id) {
            require $httpFixture . '/local-extensions/extensions/' . $id . '/bootstrap.php';
        }
        $httpDb = new DB();
        $httpDb->addConnection(['driver'=>'sqlite', 'database'=>$httpFixture . '/runtime/native.sqlite', 'prefix'=>'']);
        $httpDb->setAsGlobal(); $httpDb->bootEloquent();
        $_GET['s'] = '/shared/commodity/items';
        $request = new Kernel\Context\Request();
        Kernel\Util\Context::set(Kernel\Context\Interface\Request::class, $request);
        Kernel\Util\Context::set(Kernel\Consts\Base::STORE_STATUS, true);
        Kernel\Util\Context::set(Kernel\Consts\Base::IS_INSTALL, true);
        Kernel\Util\Plugin::$container = ['hook'=>[]];
        $httpStage = 'native_validation';
        nativeTreeValidate($request);
        $controller = new App\Controller\Shared\Commodity(); $action = 'items';
        hook(App\Consts\Hook::CONTROLLER_CALL_BEFORE, $controller, $action);
        $httpStage = 'native_items';
        $result = $controller->items();
        $httpStage = 'after_hook';
        hook(App\Consts\Hook::CONTROLLER_CALL_AFTER, $controller, $action, $result);
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (Kernel\Exception\JSONException $exception) {
        // Keep the same business-envelope convention without exposing arbitrary
        // native messages, exception data or fixture credentials in test output.
        echo json_encode(['code'=>$exception->getCode(), 'msg'=>$exception->getMessage() === 'PIKA_TREE_UNAVAILABLE'
            ? 'PIKA_TREE_UNAVAILABLE' : 'FIXTURE_NATIVE_REJECTED'], JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        http_response_code(500);
        echo json_encode(['code'=>0, 'msg'=>'FIXTURE_HTTP_INTERNAL', 'stage'=>$httpStage], JSON_THROW_ON_ERROR);
    }
    return;
}

$webUid = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$webGid = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
$official = realpath((string)getenv('ACG_FAKA_OFFICIAL_ROOT'));
nativeExpect(is_int($webUid) && is_int($webGid) && posix_geteuid() === 0 && $official !== false, 'root, WEB_UID WEB_GID and official fixture required');
$fixture = sys_get_temp_dir() . '/pika-native-category-' . bin2hex(random_bytes(6));
mkdir($fixture, 0755);
define('BASE_PATH', $fixture . '/');
$release = dirname(__DIR__);
// Apply the existing release bridge to a private official Helper copy. Loading
// this file declares helpers only; it does not boot Kernel or execute a route.
mkdir($fixture . '/kernel', 0755);
copy($official . '/kernel/Helper.php', $fixture . '/kernel/Helper.php');
$bridge = file_get_contents($release . '/bridge/3.7.0/local-extensions.patch');
nativeExpect(is_string($bridge) && preg_match(
    '~^diff --git a/kernel/Helper\.php b/kernel/Helper\.php\n.*?(?=^diff --git |\z)~ms', $bridge, $helperPatch) === 1,
    'existing official Helper bridge section unavailable');
$patch = proc_open(['patch', '--batch', '--forward', '--fuzz=0', '-p1'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $patchPipes, $fixture);
nativeExpect(is_resource($patch), 'could not open standard patch for official Helper fixture');
fwrite($patchPipes[0], $helperPatch[0]); fclose($patchPipes[0]);
stream_get_contents($patchPipes[1]); fclose($patchPipes[1]);
stream_get_contents($patchPipes[2]); fclose($patchPipes[2]);
nativeExpect(proc_close($patch) === 0, 'fixed official Helper did not accept the existing bridge');
chmod($fixture . '/kernel/Helper.php', 0644);
mkdir($fixture . '/app/Plugin/FixtureTree', 0755, true);
$entries = [];
foreach (['PikaCatalogHub', 'PikaSupplySync'] as $id) {
    $target = $fixture . '/local-extensions/extensions/' . $id;
    nativeCopy($release . '/extensions/' . $id, $target);
    if ($id === 'PikaCatalogHub') {
        // An additional fixture-only hook proves local dispatcher short-circuit
        // values cannot skip the mandatory guard either.
        file_put_contents($target . '/Hook/FixtureDecision.php', '<?php namespace Pika\\LocalExtensions\\PikaCatalogHub\\Hook; final class FixtureDecision { public static mixed $result = false; public function before(): mixed { return self::$result; } }');
        chmod($target . '/Hook/FixtureDecision.php', 0644);
        $manifest = json_decode(file_get_contents($target . '/local-extension.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest['hooks'][] = ['point' => 49, 'class' => 'Pika\\LocalExtensions\\PikaCatalogHub\\Hook\\FixtureDecision', 'method' => 'before', 'priority' => 1];
        file_put_contents($target . '/local-extension.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    }
    $entries[] = ['id' => $id, 'manifest' => 'extensions/' . $id . '/local-extension.json',
        'manifest_sha256' => hash_file('sha256', $target . '/local-extension.json')];
}
chmod($fixture . '/local-extensions', 0755);
chmod($fixture . '/local-extensions/extensions', 0755);
file_put_contents($fixture . '/local-extensions/registry.json', json_encode(['schema' => 1, 'extensions' => $entries, 'themes' => []], JSON_THROW_ON_ERROR));
chmod($fixture . '/local-extensions/registry.json', 0644);
mkdir($fixture . '/runtime', 0750);
chown($fixture . '/runtime', $webUid); chgrp($fixture . '/runtime', $webGid);
$base = '/var/lib/pika-local-extensions';
if (!is_dir($base . '/sites')) mkdir($base . '/sites', 0755, true);
chmod($base, 0755); chmod($base . '/sites', 0755);
$state = $base . '/sites/' . hash('sha256', realpath($fixture));
mkdir($state . '/runtime', 0750, true);
chmod($state, 0755); chmod($state . '/runtime', 0750);
chown($state . '/runtime', $webUid); chgrp($state . '/runtime', $webGid);
require $official . '/vendor/autoload.php';
require $release . '/manager/site/local-extensions/bootstrap.php';
nativeExpect(!function_exists('hook'), 'fixture must load the real bridged Helper without replacing an existing hook');
require $fixture . '/kernel/Helper.php';
nativeExpect(function_exists('hook'), 'bridged official Helper did not declare hook');
foreach (['PikaSupplySync', 'PikaCatalogHub'] as $id) require $fixture . '/local-extensions/extensions/' . $id . '/bootstrap.php';
$db = new DB();
$database = $fixture . '/runtime/native.sqlite';
touch($database); chmod($database, 0600); chown($database, $webUid); chgrp($database, $webGid);
$db->addConnection(['driver' => 'sqlite', 'database' => $database, 'prefix' => '']);
$db->setAsGlobal(); $db->bootEloquent();
$schema = DB::connection()->getSchemaBuilder();
$schema->create('category', static function (Blueprint $table): void {
    $table->increments('id'); $table->string('name'); $table->integer('sort')->default(0);
    $table->string('create_time'); $table->unsignedInteger('owner')->default(0); $table->string('icon')->default('');
    $table->unsignedInteger('status')->default(1); $table->unsignedInteger('hide')->default(0); $table->unsignedInteger('pid')->nullable();
    $table->text('user_level_config')->nullable();
});
$schema->create('shared', static function (Blueprint $table): void { $table->increments('id'); });
$schema->create('commodity', static function (Blueprint $table): void {
    $table->increments('id'); $table->unsignedInteger('category_id');
    $table->string('code'); $table->string('name'); $table->integer('stock')->nullable();
    $table->unsignedInteger('status')->default(1); $table->unsignedInteger('api_status')->default(1);
    $table->unsignedInteger('hide')->default(0); $table->unsignedInteger('delivery_way')->default(1);
    $table->unsignedInteger('shared_id')->default(0); $table->text('shared_stock')->nullable();
    $table->text('level_price')->nullable(); $table->text('leave_message')->nullable();
    $table->text('delivery_message')->nullable();
});
$schema->create('card', static function (Blueprint $table): void {
    $table->increments('id'); $table->unsignedInteger('commodity_id'); $table->unsignedInteger('status')->default(0);
});
$schema->create('user', static function (Blueprint $table): void {
    $table->increments('id'); $table->string('app_key'); $table->decimal('recharge', 12, 2)->default(0);
});
$schema->create('user_group', static function (Blueprint $table): void {
    $table->increments('id'); $table->string('name'); $table->decimal('recharge', 12, 2)->default(0);
    $table->text('discount_config')->nullable();
});
$schema->create('manage_log', static function (Blueprint $table): void {
    $table->increments('id');
    foreach (['email', 'nickname', 'content', 'create_time', 'create_ip', 'ua'] as $field) $table->string($field);
    $table->unsignedInteger('risk');
});
DB::table('shared')->insert(['id' => 1]);
nativeExpect(posix_setgid($webGid) && posix_setuid($webUid), 'could not drop fixture identity');
$source = Shared::query()->findOrFail(1);
$config = new ConfigRepository(); $config->upsertAlias(1, 'native-old');
$mapper = new PlannedCategoryMapper();
$leaf = $mapper->resolve($source, 'native-old', ['group' => 'native-group', 'family' => ''], 'original-name', str_repeat('a', 64));
$sourceCategory = (int)$leaf->pid;
$aliasService = new SourceAliasService($config);
$manage = new App\Model\Manage();
$manage->id = 1; $manage->type = 0; $manage->email = 'fixture@example.invalid';
$manage->nickname = 'fixture'; $manage->last_login_ip = '127.0.0.1';
App\Util\Context::set(App\Consts\Manage::SESSION, $manage);

function nativeRequest(array $post, string $route = '/admin/api/category/save', string $method = 'POST'): Kernel\Context\Request {
    $_POST = $post; $_GET = ['s' => $route]; $_REQUEST = $post;
    $_COOKIE = []; $_FILES = [];
    $_SERVER = ['REQUEST_METHOD' => $method, 'HTTP_HOST' => 'fixture.example.invalid',
        'HTTP_REFERER' => 'http://fixture.example.invalid/admin/category/index',
        'HTTP_USER_AGENT' => 'native-category-fixture', 'REMOTE_ADDR' => '127.0.0.1'];
    $request = new Kernel\Context\Request();
    Kernel\Util\Context::set(Kernel\Context\Interface\Request::class, $request);
    return $request;
}
function nativeController(): App\Controller\Admin\Api\Category {
    $controller = new App\Controller\Admin\Api\Category();
    $property = new ReflectionProperty($controller, 'query');
    $property->setValue($controller, new App\Service\Bind\Query());
    return $controller;
}
function nativeSave(array $post, mixed $officialResult = null): array {
    $request = nativeRequest($post); $controller = nativeController(); $action = 'save';
    Runtime::hook(App\Consts\Hook::CONTROLLER_CALL_BEFORE, $officialResult, $controller, $action);
    return $controller->save($request);
}
function nativeTreeValidate(Kernel\Context\Request $request): void {
    $validator = new App\Interceptor\SharedValidation();
    (new ReflectionProperty($validator, 'request'))->setValue($validator, $request);
    $validator->handle(Kernel\Annotation\Interceptor::TYPE_API);
}
function nativeTreeDispatch(mixed &$result): mixed {
    $controller = new App\Controller\Shared\Commodity(); $action = 'items';
    return hook(App\Consts\Hook::CONTROLLER_CALL_AFTER, $controller, $action, $result);
}
function nativeTreeSigned(array $post, string $key): array {
    $post['sign'] = App\Util\Str::generateSignature($post, $key);
    return $post;
}
function nativeTreeRejected(array $post, array $native, bool $validate = true, string $method = 'POST'): void {
    $request = nativeRequest($post, '/shared/commodity/items', $method);
    if ($validate) nativeTreeValidate($request);
    $result = $native;
    try { nativeTreeDispatch($result); throw new RuntimeException('invalid tree request was accepted'); }
    catch (Kernel\Exception\JSONException $exception) {
        nativeExpect($exception->getMessage() === 'PIKA_TREE_UNAVAILABLE', 'tree failure did not escape Helper as a fixed JSON error');
    }
    nativeExpect($result === $native, 'rejected tree request partially replaced native response');
}
function nativeTreeCapacityFixture(array $nodes, array $leafIds, string $prefix): array {
    $rows = []; $byId = [];
    foreach ($nodes as $node) {
        $byId[$node['id']] = $node;
        $rows[] = $node + ['owner'=>0, 'status'=>1, 'hide'=>0, 'create_time'=>'2026-09-17 00:00:00'];
    }
    foreach (array_chunk($rows, 100) as $chunk) DB::table('category')->insert($chunk);
    $groups = [];
    foreach ($leafIds as $id) {
        $groups[] = $byId[$id] + ['children'=>[
            ['code'=>$prefix . '-item-' . $id, 'category_id'=>$id, 'name'=>$prefix . '-item', 'stock'=>1],
        ]];
    }
    return ['code'=>200, 'data'=>$groups];
}

function nativeTreeHttpPost(string $address, array $post): array {
    static $requests = 0;
    nativeExpect(++$requests <= 19, 'HTTP fixture exceeded its synthetic request budget');
    $curl = curl_init('http://' . $address . '/shared/commodity/items');
    nativeExpect($curl !== false, 'HTTP fixture curl unavailable');
    $body = '';
    curl_setopt_array($curl, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>http_build_query($post, '', '&', PHP_QUERY_RFC3986),
        CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROXY=>'', CURLOPT_CONNECTTIMEOUT_MS=>1000, CURLOPT_TIMEOUT_MS=>5000,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTP, CURLOPT_WRITEFUNCTION=>static function ($curl, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 1048576) return 0;
            $body .= $chunk;
            return strlen($chunk);
        }]);
    $ok = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
    $error = curl_errno($curl);
    curl_close($curl);
    nativeExpect($ok !== false && $error === 0 && is_string($type)
        && str_starts_with($type, 'application/json'), 'HTTP fixture did not return a bounded JSON response');
    $result = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    nativeExpect(is_array($result), 'HTTP fixture returned a non-object envelope');
    $safeStage = in_array($result['stage'] ?? null, ['bootstrap', 'native_validation', 'native_items', 'after_hook'], true)
        ? $result['stage'] : 'unclassified';
    nativeExpect($status === 200, 'HTTP fixture failed: status=' . $status . '; stage=' . $safeStage);
    return $result;
}

function nativeIconGatewayFixture(array $snapshot, string $key): void {
    $source = new Shared();
    $source->domain = 'https://source.example.invalid';
    $source->app_id = '101'; $source->app_key = $key; $source->type = 0;
    $policy = new Pika\LocalExtensions\PikaSupplySync\Service\SourcePolicy(static fn(): array => ['8.8.8.8']);
    foreach (['icons', 'old-schema', 'string-schema', 'wrong-capability', 'items', 'missing-node',
        'wrong-id', 'business-failure'] as $case) {
        $data = $snapshot; $calls = 0;
        switch ($case) {
            case 'old-schema': $data['schema'] = 2; break;
            case 'string-schema': $data['schema'] = '3'; break;
            case 'wrong-capability': $data['capability'] = 'pika_category_tree'; break;
            case 'items': $data['items'] = []; break;
            case 'missing-node': array_pop($data['categories']); break;
            case 'wrong-id': $data['categories'][1]['id'] = 10004; break;
        }
        $envelope = $case === 'business-failure' ? ['code'=>0, 'msg'=>'PIKA_TREE_UNAVAILABLE'] : ['code'=>200, 'data'=>$data];
        $http = new Pika\LocalExtensions\PikaSupplySync\Service\SafeHttpClient($policy,
            static function ($endpoint, $address, $method, $headers, $body) use (&$calls, $envelope, $key): array {
                $calls++;
                parse_str($body, $form);
                nativeExpect($method === 'POST' && parse_url($endpoint['url'], PHP_URL_PATH) === '/shared/commodity/items'
                    && array_keys($form) === ['app_id', 'pika_category_tree', 'pika_category_ids', 'sign']
                    && $form['pika_category_tree'] === '3' && $form['pika_category_ids'] === '10001,10003'
                    && hash_equals(App\Util\Str::generateSignature($form, $key), $form['sign']),
                    'icon gateway did not send one native-signed canonical identity request without the secret');
                return ['status'=>200, 'body'=>json_encode($envelope, JSON_THROW_ON_ERROR),
                    'content_type'=>'application/json', 'connected_ip'=>$address];
            });
        $gateway = new Pika\LocalExtensions\PikaSupplySync\Service\SharedGateway($http, $policy);
        $rejected = false;
        try {
            $nodes = $gateway->categoryIcons($source, [10003, 10001]);
        } catch (RuntimeException) {
            $rejected = true;
        }
        nativeExpect($case === 'icons' ? (!$rejected && array_keys($nodes) === [10001, 10003]) : $rejected,
            'icon gateway accepted or rejected the wrong response contract: ' . $case);
        nativeExpect($calls === 1, 'icon gateway used a second directory request or fallback');
        try {
            $gateway->categoryIcons($source, [10001, 10001]);
            throw new LogicException('icon gateway accepted duplicate request identities');
        } catch (RuntimeException) {}
        nativeExpect($calls === 1, 'invalid icon identities reached HTTP transport');
    }
}

function nativeIconCapabilityFixture(array $snapshot, string $key): void {
    $source = new Shared();
    $source->domain = 'https://source.example.invalid';
    $source->app_id = '101'; $source->app_key = $key; $source->type = 0;
    $otherSource = clone $source; $otherSource->domain = 'https://other.example.invalid';
    $policy = new Pika\LocalExtensions\PikaSupplySync\Service\SourcePolicy(static fn(): array => ['8.8.8.8']);
    $plain = ['code'=>200, 'data'=>$snapshot];
    $capable = $plain + ['pika_category_icons'=>1];
    $next = $capable; $calls = 0;
    $http = new Pika\LocalExtensions\PikaSupplySync\Service\SafeHttpClient($policy,
        static function ($endpoint, $address, $method, $headers, $body) use (&$calls, &$next, $key): array {
            $calls++;
            parse_str($body, $form);
            nativeExpect($method === 'POST' && parse_url($endpoint['url'], PHP_URL_PATH) === '/shared/commodity/items'
                && array_keys($form) === ['app_id', 'pika_category_tree', 'sign'] && $form['pika_category_tree'] === '2'
                && hash_equals(App\Util\Str::generateSignature($form, $key), $form['sign']),
                'capability discovery changed the legacy v2 request');
            return ['status'=>200, 'body'=>json_encode($next, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                'content_type'=>'application/json', 'connected_ip'=>$address];
        });
    $gateway = new Pika\LocalExtensions\PikaSupplySync\Service\SharedGateway($http, $policy);
    nativeExpect(!$gateway->supportsCategoryIcons(), 'gateway started with a stale icon capability');
    nativeExpect($gateway->categoryTree($source) === $snapshot && $gateway->supportsCategoryIcons(),
        'valid capability declaration was not accepted after complete v2 validation');
    $next = $plain;
    nativeExpect($gateway->categoryTree($otherSource) === $snapshot && !$gateway->supportsCategoryIcons(),
        'icon capability leaked from a capable source to a legacy source');
    $decode = new ReflectionMethod($gateway, 'responseData');
    nativeExpect($decode->invoke($gateway, $capable, $key) === $snapshot,
        'legacy envelope decoding stopped ignoring the new sibling capability');
    $invalidEnvelopes = [];
    foreach ([0, true, '1', 1.0, null, []] as $invalid) {
        $invalidEnvelopes[] = $plain + ['pika_category_icons'=>$invalid];
    }
    $badSchema = $capable; $badSchema['data']['schema'] = 1;
    $badTree = $capable; $badTree['data']['categories'][0]['pid'] = 999999;
    $invalidEnvelopes[] = $badSchema; $invalidEnvelopes[] = $badTree;
    $invalidEnvelopes[] = ['code'=>0, 'msg'=>'PIKA_TREE_UNAVAILABLE', 'pika_category_icons'=>1];
    foreach ($invalidEnvelopes as $invalidEnvelope) {
        $next = $capable;
        $gateway->categoryTree($source);
        nativeExpect($gateway->supportsCategoryIcons(), 'capability reset fixture did not begin capable');
        $next = $invalidEnvelope; $before = $calls;
        $rejected = false;
        try { $gateway->categoryTree($otherSource); } catch (RuntimeException) { $rejected = true; }
        nativeExpect($rejected && !$gateway->supportsCategoryIcons() && $calls === $before + 1,
            'invalid capability or tree downgraded silently, retained stale state or retried');
    }
    foreach ([1, 2] as $unsupportedType) {
        $next = $capable; $gateway->categoryTree($source); $before = $calls;
        $unsupported = clone $source; $unsupported->type = $unsupportedType;
        $rejected = false;
        try { $gateway->categoryTree($unsupported); } catch (RuntimeException) { $rejected = true; }
        nativeExpect($rejected && !$gateway->supportsCategoryIcons() && $calls === $before,
            'unsupported source type retained capability or reached the transport');
    }
    $next = $capable; $gateway->categoryTree($source); $before = $calls;
    $unsafe = clone $source; $unsafe->domain = 'https://127.0.0.1';
    $rejected = false;
    try { $gateway->categoryTree($unsafe); } catch (RuntimeException) { $rejected = true; }
    nativeExpect($rejected && !$gateway->supportsCategoryIcons() && $calls === $before,
        'unsafe source address bypassed the policy or retained icon capability');
}

function nativeTreeHttpSuite(string $fixture, string $official, string $key, string $otherKey): void {
    $listener = stream_socket_server('tcp://127.0.0.1:0', $socketError, $socketMessage);
    nativeExpect(is_resource($listener), 'HTTP fixture could not reserve a loopback port');
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    nativeExpect(is_string($address) && preg_match('/^127\.0\.0\.1:[0-9]+$/D', $address) === 1,
        'HTTP fixture bound outside loopback');
    $environment = getenv();
    $environment['PIKA_NATIVE_HTTP_FIXTURE'] = $fixture;
    $environment['ACG_FAKA_OFFICIAL_ROOT'] = $official;
    unset($environment['PHP_CLI_SERVER_WORKERS']);
    $server = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', '-S', $address, __FILE__],
        [0=>['file', '/dev/null', 'r'], 1=>['file', '/dev/null', 'w'], 2=>['file', '/dev/null', 'w']],
        $pipes, $fixture, $environment);
    nativeExpect(is_resource($server), 'HTTP fixture could not start the existing PHP CLI server');
    try {
        $ready = false;
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $probe = @stream_socket_client('tcp://' . $address, $socketError, $socketMessage, 0.1);
            if (is_resource($probe)) { fclose($probe); $ready = true; break; }
            nativeExpect(proc_get_status($server)['running'], 'HTTP fixture server exited before readiness');
            usleep(20000);
        }
        nativeExpect($ready, 'HTTP fixture server did not become ready');
        $before = nativeTreeHttpState();
        $plain = nativeTreeHttpPost($address, nativeTreeSigned(['app_id'=>'101'], $key));
        nativeExpect(($plain['code'] ?? null) === 200 && is_array($plain['data'] ?? null)
            && array_is_list($plain['data']), 'official items HTTP did not produce its native list');
        $nativeItems = [];
        foreach ($plain['data'] as $group) {
            nativeExpect(array_diff(['id', 'pid', 'name', 'sort', 'children'], array_keys($group)) === [],
                'real official group omitted a required tree field');
            foreach ($group['children'] as $item) {
                nativeExpect(array_diff(['code', 'category_id', 'name', 'stock'], array_keys($item)) === [],
                    'real official commodity omitted a required tree field');
                $nativeItems[$item['code']] = $item;
            }
        }
        $expectedCodes = ['fixture-http-normal', 'fixture-http-cf', 'fixture-http-cc',
            'fixture-http-hidden-allowed', 'fixture-http-local-auto', 'fixture-http-shared-auto'];
        $actualCodes = array_keys($nativeItems); sort($expectedCodes); sort($actualCodes);
        nativeExpect($actualCodes === $expectedCodes && $nativeItems['fixture-http-local-auto']['stock'] === 2
            && $nativeItems['fixture-http-shared-auto']['stock'] === 5,
            'real official visibility or native stock calculation changed');
        nativeExpect($nativeItems['fixture-http-cf']['name'] === "fixture\u{200B}name"
            && $nativeItems['fixture-http-cc']['name'] === "fixture\x07name",
            'HTTP fixture did not carry real synthetic Cf/Cc names through official items');
        $postV1 = nativeTreeSigned(['app_id'=>'101', 'pika_category_tree'=>'1'], $key);
        $postV2 = nativeTreeSigned(['app_id'=>'101', 'pika_category_tree'=>'2'], $key);
        $postIcons = nativeTreeSigned(['app_id'=>'101', 'pika_category_tree'=>'3', 'pika_category_ids'=>'10001,10003'], $key);
        $v1Rejected = nativeTreeHttpPost($address, $postV1);
        nativeExpect(($v1Rejected['code'] ?? null) === 0 && ($v1Rejected['msg'] ?? null) === 'PIKA_TREE_UNAVAILABLE'
            && !array_key_exists('data', $v1Rejected), 'v1 HTTP accepted names outside its unchanged strict contract');
        $v2 = nativeTreeHttpPost($address, $postV2);
        nativeExpect(($v2['code'] ?? null) === 200 && ($v2['data']['schema'] ?? null) === 2,
            'v2 HTTP export did not accept the real official catalog');
        nativeExpect(($v2['pika_category_icons'] ?? null) === 1
            && !array_key_exists('pika_category_icons', $v2['data']), 'v2 icon capability changed the legacy data schema');
        nativeIconCapabilityFixture($v2['data'], $key);
        $v2Codes = array_column($v2['data']['items'], 'code'); sort($v2Codes);
        nativeExpect($v2Codes === $expectedCodes, 'v2 HTTP lost or duplicated an authorized item');
        foreach ($v2['data']['items'] as $item) {
            nativeExpect(array_keys($item) === ['code', 'category_id', 'stock'],
                'v2 HTTP transmitted or fabricated a product name');
            nativeExpect((int)$nativeItems[$item['code']]['category_id'] === $item['category_id']
                && (int)$nativeItems[$item['code']]['stock'] === $item['stock'],
                'compact catalog changed a native authorized item category or stock');
        }
        $icons = nativeTreeHttpPost($address, $postIcons);
        nativeExpect(($icons['code'] ?? null) === 200
            && array_keys($icons['data'] ?? []) === ['schema', 'capability', 'categories']
            && ($icons['data']['schema'] ?? null) === 3
            && ($icons['data']['capability'] ?? null) === 'pika_category_icons',
            'icon HTTP response changed its bounded metadata envelope');
        $iconNodes = Pika\LocalExtensions\PikaSupplySync\Service\UpstreamCategoryTree::iconNodes($icons['data'], [10001, 10003]);
        nativeExpect(array_keys($iconNodes) === [10001, 10003]
            && $iconNodes[10001]['icon'] === '/assets/fixture-root.png'
            && $iconNodes[10003]['icon'] === 'https://images.example.invalid/fixture-leaf.png',
            'icon HTTP omitted ancestor or leaf icons from the authorized database rows');
        nativeIconGatewayFixture($icons['data'], $key);
        foreach ($v2['data']['categories'] as $category) {
            nativeExpect(array_keys($category) === ['id', 'pid', 'name', 'sort'], 'v2 HTTP leaked new icon metadata');
        }
        $unauthorizedIcons = nativeTreeHttpPost($address,
            nativeTreeSigned(['app_id'=>'101', 'pika_category_tree'=>'3', 'pika_category_ids'=>'10001,10004'], $key));
        nativeExpect(($unauthorizedIcons['code'] ?? null) === 0
            && ($unauthorizedIcons['msg'] ?? null) === 'PIKA_TREE_UNAVAILABLE'
            && !array_key_exists('data', $unauthorizedIcons), 'icon HTTP disclosed an unselected or unauthorized sibling');
        $tamperedIds = $postIcons; $tamperedIds['pika_category_ids'] = '10001,10002';
        $tamperedIcons = nativeTreeHttpPost($address, $tamperedIds);
        nativeExpect(($tamperedIcons['code'] ?? null) !== 200 && !array_key_exists('data', $tamperedIcons),
            'icon HTTP identities were not covered by the native signature');
        $wireV2 = json_encode($v2, JSON_THROW_ON_ERROR);
        foreach (["fixture\u{200B}name", "fixture\x07name"] as $forbiddenName) {
            nativeExpect(!str_contains($wireV2, substr(json_encode($forbiddenName, JSON_THROW_ON_ERROR), 1, -1)),
                'v2 HTTP leaked an omitted synthetic product name elsewhere in its payload');
        }
        $unprivileged = nativeTreeHttpPost($address,
            nativeTreeSigned(['app_id'=>'102', 'pika_category_tree'=>'2'], $otherKey));
        nativeExpect(($unprivileged['code'] ?? null) === 200 && count($unprivileged['data']['items'] ?? []) === 5
            && !in_array('fixture-http-hidden-allowed', array_column($unprivileged['data']['items'], 'code'), true),
            'v2 HTTP bypassed the real merchant group visibility filter');
        $tampered = $postV1; $tampered['pika_category_tree'] = '2';
        $tamperedResult = nativeTreeHttpPost($address, $tampered);
        nativeExpect(($tamperedResult['code'] ?? null) !== 200 && !array_key_exists('data', $tamperedResult),
            'HTTP signed tree version was mutable');
        $unknown = nativeTreeHttpPost($address, nativeTreeSigned(['app_id'=>'101', 'pika_category_tree'=>'4'], $key));
        nativeExpect(($unknown['code'] ?? null) === 0 && ($unknown['msg'] ?? null) === 'PIKA_TREE_UNAVAILABLE',
            'HTTP accepted an unknown signed tree version');
        nativeExpect(nativeTreeHttpState() === $before, 'official HTTP export wrote fixture database or runtime state');

        // Only the synthetic source names change here. The server still reads
        // official models and does not receive a hand-built native catalog.
        DB::table('commodity')->whereIn('code', ['fixture-http-cf', 'fixture-http-cc'])->update(['name'=>'fixture-valid-name']);
        $validBefore = nativeTreeHttpState();
        $validV1 = nativeTreeHttpPost($address, $postV1);
        $validV2 = nativeTreeHttpPost($address, $postV2);
        $tree = new Pika\LocalExtensions\PikaSupplySync\Service\UpstreamCategoryTree();
        nativeExpect(($validV1['code'] ?? null) === 200 && ($validV1['data']['schema'] ?? null) === 1
            && ($validV2['code'] ?? null) === 200 && ($validV2['data']['schema'] ?? null) === 2,
            'HTTP normal catalog failed one supported tree version');
        nativeExpect(!array_key_exists('pika_category_icons', $validV1), 'v1 advertised an unrequested capability');
        nativeExpect($tree->suggest($tree->flatten($validV1['data']))['plan_hash']
            === $tree->suggest($tree->flatten($validV2['data']))['plan_hash'],
            'HTTP normal v1/v2 catalogs generated different mirror plans');
        nativeExpect(nativeTreeHttpState() === $validBefore, 'normal HTTP tree exports wrote fixture state');
        DB::table('category')->where('id', 10003)->update(['icon'=>'http://images.example.invalid/unsafe.png']);
        try {
            $invalidIconBefore = nativeTreeHttpState();
            $invalidIcons = nativeTreeHttpPost($address, $postIcons);
            nativeExpect(($invalidIcons['code'] ?? null) === 0 && ($invalidIcons['msg'] ?? null) === 'PIKA_TREE_UNAVAILABLE'
                && !array_key_exists('data', $invalidIcons), 'unsafe icon HTTP metadata was accepted');
            nativeExpect(nativeTreeHttpPost($address, $postV1) === $validV1
                && nativeTreeHttpPost($address, $postV2) === $validV2,
                'unrequested icon validation changed a legacy tree response');
            nativeExpect(nativeTreeHttpState() === $invalidIconBefore, 'icon HTTP export wrote fixture state');
        } finally {
            DB::table('category')->where('id', 10003)->update(['icon'=>'https://images.example.invalid/fixture-leaf.png']);
        }
        foreach ([
            ['table'=>'commodity', 'where'=>['code'=>'fixture-http-normal'], 'invalid'=>['stock'=>-1], 'restore'=>['stock'=>2]],
            ['table'=>'category', 'where'=>['id'=>10002], 'invalid'=>['name'=>"fixture\u{200B}category"], 'restore'=>['name'=>'货源A']],
            ['table'=>'category', 'where'=>['id'=>10002], 'invalid'=>['pid'=>999999], 'restore'=>['pid'=>10001]],
            ['table'=>'category', 'where'=>['id'=>10002], 'invalid'=>['owner'=>9], 'restore'=>['owner'=>0]],
            ['table'=>'category', 'where'=>['id'=>10002], 'invalid'=>['hide'=>1], 'restore'=>['hide'=>0]],
        ] as $case) {
            $query = DB::table($case['table'])->where($case['where']);
            $query->update($case['invalid']);
            try {
                $invalidBefore = nativeTreeHttpState();
                $rejected = nativeTreeHttpPost($address, $postV2);
                nativeExpect(($rejected['code'] ?? null) === 0 && ($rejected['msg'] ?? null) === 'PIKA_TREE_UNAVAILABLE'
                    && !array_key_exists('data', $rejected), 'v2 HTTP weakened an existing stock or category gate');
                nativeExpect(nativeTreeHttpState() === $invalidBefore, 'rejected v2 HTTP export wrote fixture state');
            } finally {
                $query->update($case['restore']);
            }
        }
        nativeExpect(nativeTreeHttpState() === $validBefore, 'synthetic invalid-case restoration was incomplete');
    } finally {
        if (proc_get_status($server)['running']) {
            nativeExpect(proc_terminate($server), 'HTTP fixture server refused termination');
        }
        $stopped = false;
        for ($attempt = 0; $attempt < 50; $attempt++) {
            if (!proc_get_status($server)['running']) { $stopped = true; break; }
            usleep(20000);
        }
        if (!$stopped) {
            proc_terminate($server, 9);
            for ($attempt = 0; $attempt < 50; $attempt++) {
                if (!proc_get_status($server)['running']) { $stopped = true; break; }
                usleep(20000);
            }
        }
        nativeExpect($stopped, 'HTTP fixture server remained running after bounded shutdown');
        proc_close($server);
    }
}

function nativeTreeHttpState(): array {
    $tables = [];
    foreach (['category', 'commodity', 'card', 'user', 'user_group', 'manage_log'] as $table) {
        $tables[$table] = hash('sha256', DB::table($table)->orderBy('id')->get()->toJson());
    }
    $runtime = '/var/lib/pika-local-extensions/sites/' . hash('sha256', realpath(BASE_PATH)) . '/runtime';
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($runtime, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile()) $files[substr($file->getPathname(), strlen($runtime))] = hash_file('sha256', $file->getPathname());
    }
    ksort($files);
    return ['tables'=>$tables, 'runtime'=>$files];
}

// These extensions are installed but disabled: mapping protection is durable.
foreach ([null, false, new Kernel\Plugin\Entity\Stock(1)] as $officialResult) {
    $before = Category::query()->orderBy('id')->get()->toJson();
    try { nativeSave(['id' => $sourceCategory, 'name' => 'bypass-name', 'sort' => '11'], $officialResult); throw new RuntimeException('native guard accepted name edit'); }
    catch (Kernel\Exception\JSONException $exception) { nativeExpect(str_contains($exception->getMessage(), '修改货源显示名'), 'unexpected native guard rejection'); }
    nativeExpect(Category::query()->orderBy('id')->get()->toJson() === $before, 'blocked native route wrote Category');
}
Pika\LocalExtensions\Manager\StateStore::setEnabled('PikaCatalogHub', true);
foreach ([false, new Kernel\Plugin\Entity\Stock(1)] as $localResult) {
    Pika\LocalExtensions\PikaCatalogHub\Hook\FixtureDecision::$result = $localResult;
    $before = Category::query()->orderBy('id')->get()->toJson();
    try { nativeSave(['id' => $sourceCategory, 'name' => 'local-bypass']); throw new RuntimeException('local hook bypassed native guard'); }
    catch (Kernel\Exception\JSONException $exception) { nativeExpect(str_contains($exception->getMessage(), '修改货源显示名'), 'unexpected local-hook guard rejection'); }
    nativeExpect(Category::query()->orderBy('id')->get()->toJson() === $before, 'blocked local-hook route wrote Category');
}
Pika\LocalExtensions\Manager\StateStore::setEnabled('PikaCatalogHub', false);
$request = nativeRequest(['id' => $sourceCategory, 'name' => 'native-old', 'sort' => '7', 'icon' => '<script>alert(1)</script>/safe.png']);
$safePost = $request->post(flags: Kernel\Waf\Filter::NORMAL);
nativeExpect($safePost['icon'] !== $request->unsafePost()['icon'], 'fixture did not exercise original Request XSS filtering');
$controller = nativeController(); $action = 'save';
Runtime::hook(App\Consts\Hook::CONTROLLER_CALL_BEFORE, null, $controller, $action);
$guardedPost = $request->post(flags: Kernel\Waf\Filter::NORMAL);
nativeExpect(!array_key_exists('name', $guardedPost) && $guardedPost['icon'] === $safePost['icon'], 'guard restored unsafe non-name fields');
// Simulate a dedicated rename after the stale form was accepted by BEFORE.
$aliasService->rename(1, 'native-new');
nativeExpect($controller->save($request)['code'] === 200, 'original Category controller failed partial save');
nativeExpect(Category::query()->findOrFail($sourceCategory)->name === 'native-new'
    && Category::query()->findOrFail($sourceCategory)->sort === 7, 'stale original form overwrote rename or lost sort');
nativeExpect(nativeSave(['id' => $sourceCategory, 'hide' => '1'])['code'] === 200, 'native inline field save was blocked');
nativeExpect(nativeSave(['id' => $leaf->id, 'name' => 'ordinary-child-edit'])['code'] === 200, 'ordinary child edit was blocked');
nativeExpect($leaf->fresh()->name === 'ordinary-child-edit' && Category::query()->findOrFail($sourceCategory)->name === 'native-new', 'child edit linked unexpectedly');
nativeExpect(App\Model\ManageLog::query()->count() === 3, 'official route logging did not run exactly for successful native saves');

// The legacy hook unit cases below still supply hand-built, merchant-filtered
// arrays. The separate HTTP cases execute real official items() over these
// synthetic tables, so those unit cases no longer stand in for the native path.
$treeKey = bin2hex(random_bytes(32)); $otherTreeKey = bin2hex(random_bytes(32));
$privateMarker = 'fixture-only-' . bin2hex(random_bytes(16));
DB::table('user_group')->insert([
    ['id'=>7, 'name'=>'fixture-authorized', 'recharge'=>100, 'discount_config'=>null],
    ['id'=>8, 'name'=>'fixture-unprivileged', 'recharge'=>0, 'discount_config'=>null],
]);
DB::table('user')->insert([
    ['id'=>101, 'app_key'=>$treeKey, 'recharge'=>150],
    ['id'=>102, 'app_key'=>$otherTreeKey, 'recharge'=>0],
]);
$treeRows = [
    ['id'=>10001, 'pid'=>null, 'name'=>'Telegram', 'sort'=>1, 'owner'=>0, 'status'=>1, 'hide'=>0, 'icon'=>'/assets/fixture-root.png', 'create_time'=>'2026-09-17 00:00:00'],
    ['id'=>10002, 'pid'=>10001, 'name'=>'货源A', 'sort'=>2, 'owner'=>0, 'status'=>1, 'hide'=>0, 'icon'=>'', 'create_time'=>'2026-09-17 00:00:00'],
    ['id'=>10003, 'pid'=>10002, 'name'=>'子分类', 'sort'=>3, 'owner'=>0, 'status'=>1, 'hide'=>0, 'icon'=>'https://images.example.invalid/fixture-leaf.png', 'create_time'=>'2026-09-17 00:00:00'],
    ['id'=>10004, 'pid'=>10001, 'name'=>'无可共享商品兄弟', 'sort'=>4, 'owner'=>0, 'status'=>1, 'hide'=>0, 'icon'=>'/assets/private-sibling.png', 'create_time'=>'2026-09-17 00:00:00'],
];
DB::table('category')->insert($treeRows);
DB::table('category')->insert(['id'=>10005, 'pid'=>10001, 'name'=>'fixture-inactive-category',
    'sort'=>5, 'owner'=>0, 'status'=>0, 'hide'=>0, 'create_time'=>'2026-09-18 00:00:00']);
$httpRows = [
    ['code'=>'fixture-http-normal', 'name'=>'fixture-normal-name', 'category_id'=>10003, 'stock'=>2],
    ['code'=>'fixture-http-cf', 'name'=>"fixture\u{200B}name", 'category_id'=>10003, 'stock'=>3],
    ['code'=>'fixture-http-cc', 'name'=>"fixture\x07name", 'category_id'=>10003, 'stock'=>4],
    ['code'=>'fixture-http-hidden-allowed', 'name'=>'fixture-visible-group', 'category_id'=>10003, 'stock'=>1, 'hide'=>1,
        'level_price'=>json_encode(['7'=>['amount'=>1, 'config'=>'', 'show'=>1]], JSON_THROW_ON_ERROR)],
    ['code'=>'fixture-http-hidden-denied', 'name'=>'fixture-hidden', 'category_id'=>10004, 'stock'=>1, 'hide'=>1],
    ['code'=>'fixture-http-api-off', 'name'=>'fixture-api-off', 'category_id'=>10003, 'stock'=>1, 'api_status'=>0],
    ['code'=>'fixture-http-status-off', 'name'=>'fixture-status-off', 'category_id'=>10003, 'stock'=>1, 'status'=>0],
    ['code'=>'fixture-http-category-off', 'name'=>'fixture-category-off', 'category_id'=>10005, 'stock'=>1],
    ['code'=>'fixture-http-local-auto', 'name'=>'fixture-local-auto', 'category_id'=>10003, 'stock'=>99, 'delivery_way'=>0],
    ['code'=>'fixture-http-shared-auto', 'name'=>'fixture-shared-auto', 'category_id'=>10003, 'stock'=>2,
        'delivery_way'=>0, 'shared_id'=>1, 'shared_stock'=>json_encode(['fixture-slot'=>5], JSON_THROW_ON_ERROR)],
];
foreach ($httpRows as $row) DB::table('commodity')->insert($row);
$httpCardItem = (int)DB::table('commodity')->where('code', 'fixture-http-local-auto')->value('id');
DB::table('card')->insert([
    ['commodity_id'=>$httpCardItem, 'status'=>0], ['commodity_id'=>$httpCardItem, 'status'=>0],
    ['commodity_id'=>$httpCardItem, 'status'=>1],
]);
Pika\LocalExtensions\Manager\StateStore::setEnabled('PikaCatalogHub', true);
nativeTreeHttpSuite($fixture, $official, $treeKey, $otherTreeKey);
$nativeTree = ['code'=>200, 'msg'=>'native-ok', 'native_extra'=>$privateMarker, 'data'=>[
    ['id'=>10003, 'pid'=>10002, 'name'=>'子分类', 'sort'=>3, 'owner'=>0, 'status'=>1,
        'user_level_config'=>$privateMarker, 'config'=>$privateMarker, 'children'=>[
            ['code'=>'fixture-visible-item', 'category_id'=>10003, 'name'=>'合成可共享商品', 'stock'=>2,
                'config'=>$privateMarker, 'shared_id'=>987654, 'app_key'=>$privateMarker, 'secret_like'=>$privateMarker],
        ]],
    // The pinned 3.7.0 catalog can leave this empty after hidden-product filtering.
    ['id'=>10004, 'pid'=>10001, 'name'=>$privateMarker, 'sort'=>4, 'children'=>[], 'config'=>$privateMarker],
]];
$treePost = nativeTreeSigned(['app_id'=>'101', 'pika_category_tree'=>'1',
    'app_key'=>'<script>fixture()</script>' . bin2hex(random_bytes(8))], $treeKey);
$treeStateBefore = Category::query()->orderBy('id')->get()->toJson();
Pika\LocalExtensions\Manager\StateStore::setEnabled('PikaCatalogHub', true);
$previousPluginContainer = Kernel\Util\Plugin::$container;
$previousPluginName = Kernel\Util\Plugin::$currentPluginName;
$previousStoreStatus = Kernel\Util\Context::get(Kernel\Consts\Base::STORE_STATUS);
$previousInstallStatus = Kernel\Util\Context::get(Kernel\Consts\Base::IS_INSTALL);
Kernel\Util\Context::set(Kernel\Consts\Base::STORE_STATUS, true);
Kernel\Util\Context::set(Kernel\Consts\Base::IS_INSTALL, true);
Kernel\Util\Plugin::$container = ['hook'=>[App\Consts\Hook::CONTROLLER_CALL_AFTER=>[
    ['pluginName'=>'FixtureTree', 'namespace'=>NativeTreeOfficialDecision::class, 'method'=>'after'],
]]];

$plainPost = nativeTreeSigned(['app_id'=>'101'], $treeKey);
nativeTreeValidate(nativeRequest($plainPost, '/shared/commodity/items'));
$plainResult = $nativeTree; nativeTreeDispatch($plainResult);
nativeExpect($plainResult === $nativeTree, 'missing opt-in flag changed the native result');
$treeRequest = nativeRequest($treePost, '/shared/commodity/items');
$filteredTreePost = $treeRequest->post(flags: Kernel\Waf\Filter::NORMAL);
nativeExpect($filteredTreePost['app_key'] !== $treeRequest->unsafePost()['app_key'],
    'tree fixture did not distinguish filtered POST from native signature input');
nativeTreeValidate($treeRequest);
$projected = $nativeTree;
nativeExpect(nativeTreeDispatch($projected) === null, 'void tree hook changed the official hook return convention');
nativeExpect(array_keys($projected) === ['code', 'data']
    && array_keys($projected['data']) === ['schema', 'capability', 'categories', 'items']
    && $projected['data']['capability'] === 'pika_category_tree', 'tree hook did not replace the referenced result');
nativeExpect(array_column($projected['data']['categories'], 'id') === [10001, 10002, 10003]
    && array_column($projected['data']['categories'], 'name') === ['Telegram', '货源A', '子分类'],
    'tree hook omitted ancestors, inserted an alias, or disclosed an empty sibling');
foreach ($projected['data']['categories'] as $category) {
    nativeExpect(array_keys($category) === ['id', 'pid', 'name', 'sort'], 'tree exposed category fields outside its allowlist');
}
nativeExpect(count($projected['data']['items']) === 1
    && array_keys($projected['data']['items'][0]) === ['code', 'category_id', 'name', 'stock']
    && !str_contains(json_encode($projected, JSON_THROW_ON_ERROR), $privateMarker),
    'tree exposed native product/config/credential fields');
$flatTree = (new Pika\LocalExtensions\PikaSupplySync\Service\UpstreamCategoryTree())->flatten($projected['data']);
nativeExpect(array_column($flatTree['fixture-visible-item']['target']['path'], 'id') === [10001, 10002, 10003],
    'client did not preserve the exact referenced response path');
nativeExpect(Category::query()->orderBy('id')->get()->toJson() === $treeStateBefore
    && App\Model\ManageLog::query()->count() === 3, 'read-only tree export changed category or audit state');
$withoutLegacyKey = nativeTreeSigned(['app_id'=>'101', 'pika_category_tree'=>'1'], $treeKey);
nativeTreeValidate(nativeRequest($withoutLegacyKey, '/shared/commodity/items'));
$withoutLegacyKeyResult = $nativeTree; nativeTreeDispatch($withoutLegacyKeyResult);
nativeExpect($withoutLegacyKeyResult === $projected, 'omitting the optional legacy app_key field changed the authorized tree');

$iconPost = nativeTreeSigned(['app_id'=>'101', 'pika_category_tree'=>'3', 'pika_category_ids'=>'10001,10002,10003'], $treeKey);
nativeTreeValidate(nativeRequest($iconPost, '/shared/commodity/items'));
$iconProjected = $nativeTree; nativeTreeDispatch($iconProjected);
$iconNodeMap = Pika\LocalExtensions\PikaSupplySync\Service\UpstreamCategoryTree::iconNodes($iconProjected['data'], [10001, 10002, 10003]);
nativeExpect($iconNodeMap[10002]['icon'] === '' && $iconNodeMap[10003]['icon'] === 'https://images.example.invalid/fixture-leaf.png'
    && !array_key_exists('items', $iconProjected['data']), 'icon hook depended on omitted native leaf metadata or returned products');
// A database-null icon is an explicit absence; an omitted loader column is not.
// These exporter-only cases add no HTTP call and do not alter the SQLite rows.
$iconLoaderRows = array_column($treeRows, null, 'id');
foreach (['missing-column', 'database-null'] as $iconLoaderCase) {
    $loaderRows = $iconLoaderRows;
    if ($iconLoaderCase === 'missing-column') unset($loaderRows[10003]['icon']);
    else $loaderRows[10003]['icon'] = null;
    $exporter = new Pika\LocalExtensions\PikaCatalogHub\Service\SharedCategoryTree(null,
        static fn(int $id): ?array => $loaderRows[$id] ?? null);
    $rejected = false;
    try { $loaderIcons = $exporter->exportIcons($nativeTree['data'], [10003]); }
    catch (RuntimeException) { $rejected = true; }
    nativeExpect($iconLoaderCase === 'missing-column' ? $rejected
        : (!$rejected && $loaderIcons['categories'][0]['icon'] === ''),
        'icon exporter confused a missing column with an explicit database null');
}
foreach (['', '0', '01', '10001,10001', '10003,10001', '10001, 10003', '2147483648',
    implode(',', range(1, 101)), ['10001']] as $invalidIds) {
    nativeTreeRejected(nativeTreeSigned(['app_id'=>'101', 'pika_category_tree'=>'3', 'pika_category_ids'=>$invalidIds], $treeKey), $nativeTree);
}
nativeTreeRejected(nativeTreeSigned(['app_id'=>'101', 'pika_category_tree'=>'3'], $treeKey), $nativeTree);
foreach (['1', '2'] as $oldFlag) {
    nativeTreeRejected(nativeTreeSigned(['app_id'=>'101', 'pika_category_tree'=>$oldFlag, 'pika_category_ids'=>'10001'], $treeKey), $nativeTree);
}
nativeTreeRejected(nativeTreeSigned(['app_id'=>'101', 'pika_category_tree'=>'3', 'pika_category_ids'=>'10001,10004'], $treeKey), $nativeTree);

foreach (['0', '4', 1, ['1']] as $invalidFlag) {
    nativeTreeRejected(nativeTreeSigned(['app_id'=>'101', 'pika_category_tree'=>$invalidFlag], $treeKey), $nativeTree);
}
nativeTreeRejected(nativeTreeSigned(['app_id'=>'101', 'pika_category_tree'=>'1', 'extra'=>'unsupported'], $treeKey), $nativeTree);
nativeTreeRejected($treePost, $nativeTree, true, 'GET');
$wrongSignature = $treePost; $wrongSignature['sign'] = str_repeat('0', 32);
$wrongSignatureRequest = nativeRequest($wrongSignature, '/shared/commodity/items');
try { nativeTreeValidate($wrongSignatureRequest); throw new RuntimeException('native merchant validation accepted a wrong signature'); }
catch (Kernel\Exception\JSONException) {}
// Even a stale native context cannot bypass the opt-in hook's strict check.
nativeTreeRejected($wrongSignature, $nativeTree, false);
$magicShapedSignature = $treePost; $magicShapedSignature['sign'] = '0e' . str_repeat('0', 30);
nativeTreeRejected($magicShapedSignature, $nativeTree, false);
$unsignedFlag = nativeTreeSigned(['app_id'=>'101'], $treeKey); $unsignedFlag['pika_category_tree'] = '1';
nativeTreeRejected($unsignedFlag, $nativeTree, false);
$arrayIdentity = $treePost; $arrayIdentity['app_id'] = ['101'];
nativeTreeRejected($arrayIdentity, $nativeTree, false);
App\Util\Context::set(App\Consts\Shared::SESSION, null);
nativeTreeRejected($treePost, $nativeTree, false);
nativeTreeValidate(nativeRequest($treePost, '/shared/commodity/items'));

// In-memory type-coercion counterexample for the pinned 3.7.0 != predicate;
// this does not claim a Boolean is a normal form-encoded HTTP value.
$loosePost = $treePost; $loosePost['sign'] = true;
$looseRequest = nativeRequest($loosePost, '/shared/commodity/items');
$strictNative = str_contains(file_get_contents($official . '/app/Interceptor/SharedValidation.php'), 'hash_equals');
if ($strictNative) {
    try { nativeTreeValidate($looseRequest); throw new RuntimeException('strict native validation accepted a Boolean signature'); }
    catch (Kernel\Exception\JSONException) {}
} else {
    nativeTreeValidate($looseRequest);
    nativeExpect(App\Util\Context::get(App\Consts\Shared::SESSION) instanceof App\Model\User,
        'pinned loose comparator did not exercise its Boolean coercion path');
}
nativeTreeRejected($loosePost, $nativeTree, false);

// Hidden ancestors require this merchant's native category group show rule.
Category::query()->where('id', 10002)->update(['hide'=>1, 'user_level_config'=>json_encode(['7'=>['show'=>1]], JSON_THROW_ON_ERROR)]);
nativeTreeValidate(nativeRequest($treePost, '/shared/commodity/items'));
$groupAllowed = $nativeTree; nativeTreeDispatch($groupAllowed);
nativeExpect(array_column($groupAllowed['data']['categories'], 'id') === [10001, 10002, 10003],
    'native group show=1 did not authorize the necessary hidden ancestor');
nativeTreeValidate(nativeRequest($iconPost, '/shared/commodity/items'));
$groupIcons = $nativeTree; nativeTreeDispatch($groupIcons);
nativeExpect(array_column($groupIcons['data']['categories'], 'id') === [10001, 10002, 10003],
    'native group show=1 did not authorize selected ancestor icons');
$unprivilegedIconPost = nativeTreeSigned(['app_id'=>'102', 'pika_category_tree'=>'3', 'pika_category_ids'=>'10001,10002,10003'], $otherTreeKey);
nativeTreeRejected($unprivilegedIconPost, $nativeTree);
$unprivilegedPost = nativeTreeSigned(['app_id'=>'102', 'pika_category_tree'=>'1'], $otherTreeKey);
nativeTreeRejected($unprivilegedPost, $nativeTree);
nativeTreeRejected(nativeTreeSigned(['app_id'=>'102', 'pika_category_tree'=>'2'], $otherTreeKey), $nativeTree);
Category::query()->where('id', 10002)->update(['user_level_config'=>null]);
nativeTreeRejected($treePost, $nativeTree);
nativeTreeRejected(nativeTreeSigned(['app_id'=>'101', 'pika_category_tree'=>'2'], $treeKey), $nativeTree);
Category::query()->where('id', 10002)->update(['hide'=>0]);
foreach ([['status'=>0], ['owner'=>9], ['pid'=>10003]] as $invalidAncestor) {
    Category::query()->where('id', 10001)->update($invalidAncestor);
    nativeTreeRejected($treePost, $nativeTree);
    nativeTreeRejected(nativeTreeSigned(['app_id'=>'101', 'pika_category_tree'=>'2'], $treeKey), $nativeTree);
    Category::query()->where('id', 10001)->update(['status'=>1, 'owner'=>0, 'pid'=>null]);
}
Category::query()->where('id', 10001)->delete();
nativeTreeRejected($treePost, $nativeTree);
DB::table('category')->insert($treeRows[0]);
$conflictingNative = $nativeTree;
$conflictingNative['data'][] = array_replace($nativeTree['data'][0], ['name'=>'冲突同ID']);
nativeTreeRejected($treePost, $conflictingNative);
$missingParentField = ['code'=>200, 'data'=>[
    ['id'=>10001, 'name'=>'Telegram', 'sort'=>1, 'children'=>[
        ['code'=>'fixture-root-item', 'category_id'=>10001, 'name'=>'合成根商品', 'stock'=>1],
    ]],
]];
nativeTreeRejected($treePost, $missingParentField);
nativeTreeRejected($treePost, ['code'=>500, 'data'=>[]]);
nativeTreeRejected($treePost, ['code'=>200, 'data'=>'invalid']);

// Capacity fixtures insert synthetic categories only. Each actual export below
// must remain read-only and receives products from its native result, not SQL.
$exportCapacityTree = static function (array $native, string $label) use ($treePost): array {
    $before = Category::query()->orderBy('id')->get()->toJson();
    $logsBefore = App\Model\ManageLog::query()->count();
    nativeTreeValidate(nativeRequest($treePost, '/shared/commodity/items'));
    $result = $native;
    nativeExpect(nativeTreeDispatch($result) === null, $label . ': export changed the official hook return convention');
    nativeExpect(($result['data']['capability'] ?? null) === 'pika_category_tree', $label . ': export did not return a tree');
    nativeExpect(Category::query()->orderBy('id')->get()->toJson() === $before
        && App\Model\ManageLog::query()->count() === $logsBefore, $label . ': export wrote category or audit state');
    return $result['data'];
};
$capacityNodes = [
    ['id'=>20001, 'pid'=>0, 'name'=>'fixture-capacity-root', 'sort'=>0],
    ['id'=>20002, 'pid'=>20001, 'name'=>'fixture-capacity-branch-a', 'sort'=>0],
    ['id'=>20003, 'pid'=>20001, 'name'=>'fixture-capacity-branch-b', 'sort'=>1],
];
for ($index = 0; $index < 56; $index++) {
    $capacityNodes[] = ['id'=>20004 + $index, 'pid'=>20002 + ($index % 2),
        'name'=>'fixture-capacity-parent-' . $index, 'sort'=>$index];
}
$capacityLeaves = [];
for ($index = 0; $index < 155; $index++) {
    $id = 20060 + $index;
    $capacityNodes[] = ['id'=>$id, 'pid'=>20004 + ($index % 56),
        'name'=>'fixture-capacity-leaf-' . $index, 'sort'=>$index];
    $capacityLeaves[] = $id;
}
$capacityNative = nativeTreeCapacityFixture($capacityNodes, $capacityLeaves, 'fixture-capacity');
nativeExpect(count($capacityNodes) === 214 && count($capacityNative['data']) === 155,
    'capacity fixture must retain 155 native product groups plus 59 pure ancestors');
// This export is the red regression on 142: C=214 used to share the N=200 cap.
$capacityExport = $exportCapacityTree($capacityNative, '214-node closure');
nativeExpect(count($capacityExport['categories']) === 214 && count($capacityExport['items']) === 155
    && array_column($capacityExport['categories'], 'id') === array_column($capacityNodes, 'id'),
    '214-node export lost or duplicated shared ancestors');
$capacityClient = new Pika\LocalExtensions\PikaSupplySync\Service\UpstreamCategoryTree();
$capacityFlat = $capacityClient->flatten($capacityExport);
foreach ($capacityFlat as $row) nativeExpect(count($row['target']['path']) === 4, '214-node fixture exceeded depth four');
nativeExpect($capacityClient->suggest($capacityFlat)['counts']['categories'] === 155,
    'client counted pure ancestors as product categories');

$chainNodes = []; $chainLeaves = [];
for ($chain = 0; $chain < 32; $chain++) {
    for ($level = 0; $level < 64; $level++) {
        $id = 30001 + $chain * 64 + $level;
        $chainNodes[] = ['id'=>$id, 'pid'=>$level === 0 ? 0 : $id - 1,
            'name'=>'fixture-boundary-node-' . $id, 'sort'=>0];
    }
    $chainLeaves[] = $id;
}
$chainNative = nativeTreeCapacityFixture($chainNodes, $chainLeaves, 'fixture-boundary');
nativeExpect(count($chainNodes) === 2048 && count($chainNative['data']) === 32,
    'tree boundary fixture must stay below the native-group and product-category limits');
$chainExport = $exportCapacityTree($chainNative, '2048-node closure');
nativeExpect(count($chainExport['categories']) === 2048 && count($chainExport['items']) === 32,
    'exactly 2048 tree nodes were not exported');
$chainFlat = $capacityClient->flatten($chainExport);
foreach ($chainFlat as $row) nativeExpect(count($row['target']['path']) === 64, 'tree boundary fixture exceeded depth 64');
nativeExpect($capacityClient->suggest($chainFlat)['counts']['categories'] === 32,
    'exactly 2048 exported nodes were not accepted by the client');
$extraNode = ['id'=>32049, 'pid'=>30064, 'name'=>'fixture-boundary-node-32049', 'sort'=>0];
$extraNative = nativeTreeCapacityFixture([$extraNode], [32049], 'fixture-boundary-extra');
$chainOverflowNative = $chainNative;
$chainOverflowNative['data'][0] = $extraNative['data'][0];
nativeExpect(count($chainOverflowNative['data']) === 32 && $extraNode['pid'] === $chainLeaves[0],
    '2049-node overflow must only extend one existing chain to depth 65');
$overflowState = Category::query()->orderBy('id')->get()->toJson();
nativeTreeRejected($treePost, $chainOverflowNative);
nativeExpect(Category::query()->orderBy('id')->get()->toJson() === $overflowState
    && App\Model\ManageLog::query()->count() === 3, 'rejected 2049-node export wrote state');

$productNodes = []; $productLeaves = [];
for ($index = 0; $index < 200; $index++) {
    $id = 40001 + $index;
    $productNodes[] = ['id'=>$id, 'pid'=>0, 'name'=>'fixture-product-category-' . $index, 'sort'=>$index];
    $productLeaves[] = $id;
}
$productNative = nativeTreeCapacityFixture($productNodes, $productLeaves, 'fixture-product-limit');
$productNative['data'][0]['children'][] = array_replace($productNative['data'][0]['children'][0],
    ['code'=>'fixture-product-second-item-same-category']);
$productExport = $exportCapacityTree($productNative, '200 native groups');
nativeExpect(count($productExport['categories']) === 200 && count($productExport['items']) === 201,
    'exactly 200 native groups must remain accepted even with more than 200 items');
$emptyNode = ['id'=>40201, 'pid'=>0, 'name'=>'fixture-empty-category', 'sort'=>0];
nativeTreeCapacityFixture([$emptyNode], [], 'fixture-empty');
$nativeGroupOverflow = $productNative;
$nativeGroupOverflow['data'][] = $emptyNode + ['children'=>[]];
nativeExpect(count($nativeGroupOverflow['data']) === 201
    && count(array_filter($nativeGroupOverflow['data'], static fn(array $group): bool => $group['children'] !== [])) === 200,
    'native-group overflow must keep only 200 nonempty product categories');
$nativeOverflowState = Category::query()->orderBy('id')->get()->toJson();
nativeTreeRejected($treePost, $nativeGroupOverflow);
nativeExpect(Category::query()->orderBy('id')->get()->toJson() === $nativeOverflowState
    && App\Model\ManageLog::query()->count() === 3, 'rejected 201-native-group export wrote state');

foreach ([false, true, new Kernel\Plugin\Entity\Stock(1)] as $officialDecision) {
    NativeTreeOfficialDecision::$result = $officialDecision;
    nativeTreeValidate(nativeRequest($treePost, '/shared/commodity/items'));
    $shortCircuited = $nativeTree;
    nativeExpect(nativeTreeDispatch($shortCircuited) === $officialDecision && $shortCircuited === $nativeTree,
        'official bool/Stock short-circuit unexpectedly ran the local tree hook');
    try {
        (new Pika\LocalExtensions\PikaSupplySync\Service\UpstreamCategoryTree())->flatten($shortCircuited['data']);
        throw new LogicException('client accepted an unprojected native list as a supported tree');
    } catch (RuntimeException $exception) {
        nativeExpect($exception->getMessage() === 'PIKA_TREE_UNSUPPORTED', 'client native-list rejection lost its safe error');
    }
}
NativeTreeOfficialDecision::$result = null;
Pika\LocalExtensions\Manager\StateStore::setEnabled('PikaCatalogHub', false);
nativeTreeValidate(nativeRequest($treePost, '/shared/commodity/items'));
$disabledTree = $nativeTree; nativeTreeDispatch($disabledTree);
nativeExpect($disabledTree === $nativeTree, 'disabled extension modified the native shared result');
try {
    (new Pika\LocalExtensions\PikaSupplySync\Service\UpstreamCategoryTree())->flatten($disabledTree['data']);
    throw new LogicException('client accepted a disabled extension native response as a tree');
} catch (RuntimeException $exception) {
    nativeExpect($exception->getMessage() === 'PIKA_TREE_UNSUPPORTED', 'disabled tree rejection lost its safe error');
}
Kernel\Util\Plugin::$container = $previousPluginContainer;
Kernel\Util\Plugin::$currentPluginName = $previousPluginName;
Kernel\Util\Context::set(Kernel\Consts\Base::STORE_STATUS, $previousStoreStatus);
Kernel\Util\Context::set(Kernel\Consts\Base::IS_INSTALL, $previousInstallStatus);
App\Util\Context::set(App\Consts\Shared::SESSION, null);

$kernel = file_get_contents($official . '/kernel/Kernel.php');
nativeExpect(str_contains($kernel, 'hook(\\App\\Consts\\Hook::CONTROLLER_CALL_BEFORE, $controllerInstance, $action);')
    && str_contains($kernel, '$result = call_user_func_array([$controllerInstance, $action], $parameters);')
    && str_contains($kernel, 'hook(\\App\\Consts\\Hook::CONTROLLER_CALL_AFTER, $controllerInstance, $action, $result);'),
    'official controller hook route contract changed');
if (($argv[3] ?? '') === '--hooks') {
    file_put_contents($state . '/runtime/csrf.key', str_repeat('fixture-only-not-a-secret-', 2));
    chmod($state . '/runtime/csrf.key', 0600);
    App\Util\Context::set(App\Consts\Manage::SESSION_RECORD, (object)['id' => 1]);
    Kernel\Util\Context::set(Kernel\Consts\Base::ROUTE, '/admin/category/index');
    $hook = new Pika\LocalExtensions\PikaCatalogHub\Hook\NativeCategoryRename();
    fwrite(STDOUT, json_encode(['form' => $hook->form(), 'toolbar' => $hook->toolbar()], JSON_THROW_ON_ERROR));
} else {
    fwrite(STDOUT, "shared tree loopback HTTP / official SharedValidation and Commodity::items / bridged AFTER Hook v1-v2 behavior: PASS\n");
    fwrite(STDOUT, "selected category icons loopback HTTP / native signature / authorized closure and legacy isolation: PASS\n");
    fwrite(STDOUT, "shared tree official Helper/SharedValidation/Runtime/Dispatcher reference and rejection behavior: PASS\n");
    fwrite(STDOUT, "local native category rename official Request/Controller/Query behavior: PASS\n");
}
