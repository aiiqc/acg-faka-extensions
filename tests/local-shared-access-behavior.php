<?php
declare(strict_types=1);

// Run only in the existing network-disabled, root-started official fixture.
// Real Request, SharedValidation, controllers, Kernel and the unchanged bridge
// are exercised over synthetic SQLite and a loopback-only PHP HTTP server.
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Schema\Blueprint;
use Pika\LocalExtensions\Manager\ConfigStore;
use Pika\LocalExtensions\Manager\ManagerService;
use Pika\LocalExtensions\Manager\PathGuard;
use Pika\LocalExtensions\Manager\Runtime;
use Pika\LocalExtensions\Manager\SharedAccessGuard;
use Pika\LocalExtensions\Manager\StateStore;

function accessExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    $GLOBALS['access_checks'] = ($GLOBALS['access_checks'] ?? 0) + 1;
}

function accessCopy(string $from, string $to): void
{
    mkdir($to, 0755, true); chmod($to, 0755);
    foreach (scandir($from) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $source = $from . '/' . $entry; $target = $to . '/' . $entry;
        accessExpect(!is_link($source), 'fixture source must not contain symlinks');
        if (is_dir($source)) accessCopy($source, $target);
        else { copy($source, $target); chmod($target, 0644); }
    }
}

function accessWrite(string $path, string $contents, int $mode = 0644): void
{
    accessExpect(file_put_contents($path, $contents) === strlen($contents), 'fixture write failed');
    chmod($path, $mode);
}

final class AccessNativeDecision
{
    public static mixed $result = null;
    public static bool $throws = false;
    public function before(): mixed
    {
        if (self::$throws) throw new RuntimeException('FIXTURE_NATIVE_EXCEPTION');
        return self::$result;
    }
}

// This router models only the web server's rewrite into GET s. It does not
// choose classes/actions or implement authorization. The actual Kernel does.
if (PHP_SAPI === 'cli-server') {
    ini_set('display_errors', '0'); ini_set('log_errors', '0');
    $fixture = realpath((string)getenv('PIKA_ACCESS_HTTP_FIXTURE'));
    if ($fixture === false || !str_starts_with($fixture, sys_get_temp_dir() . '/pika-shared-access-')
        || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
        || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(500); echo 'FIXTURE_BOUNDARY'; return;
    }
    if (!array_key_exists('s', $_GET)) {
        $_GET['s'] = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
    }
    $_REQUEST = array_merge($_GET, $_POST);
    require $fixture . '/kernel/Kernel.php';
    return;
}

$webUid = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$webGid = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
$official = realpath((string)getenv('ACG_FAKA_OFFICIAL_ROOT'));
accessExpect(is_int($webUid) && is_int($webGid) && posix_geteuid() === 0 && $official !== false,
    'root, WEB_UID WEB_GID and pinned official fixture required');
$release = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/pika-shared-access-' . bin2hex(random_bytes(6));
mkdir($fixture, 0755); chmod($fixture, 0755);
define('BASE_PATH', $fixture . '/');

// Copy only source/configuration required by the fixed native route. Never
// include or decode official kernel/Plugin.php or any real plugin state.
mkdir($fixture . '/kernel', 0755);
foreach (['Kernel.php', 'Helper.php'] as $file) {
    copy($official . '/kernel/' . $file, $fixture . '/kernel/' . $file);
}
accessCopy($official . '/kernel/Waf/Rule', $fixture . '/kernel/Waf/Rule');
mkdir($fixture . '/kernel/Install', 0755);
accessWrite($fixture . '/kernel/Install/Lock', 'isolated-shared-access-fixture');
$bridge = file_get_contents($release . '/bridge/3.7.9/local-extensions.patch');
$patchText = '';
foreach (['Helper', 'Kernel'] as $file) {
    accessExpect(is_string($bridge) && preg_match(
        '~^diff --git a/kernel/' . $file . '\.php b/kernel/' . $file . '\.php\n.*?(?=^diff --git |\z)~ms',
        $bridge, $section) === 1, 'existing native bridge section missing');
    $patchText .= $section[0];
}
$patch = proc_open(['patch', '--batch', '--forward', '--fuzz=0', '-p1'],
    [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes, $fixture);
accessExpect(is_resource($patch), 'standard bridge patch process unavailable');
fwrite($pipes[0], $patchText); fclose($pipes[0]);
stream_get_contents($pipes[1]); fclose($pipes[1]);
stream_get_contents($pipes[2]); fclose($pipes[2]);
accessExpect(proc_close($patch) === 0, 'fixed official Kernel/Helper did not accept existing bridge');
foreach (['Kernel.php', 'Helper.php'] as $file) chmod($fixture . '/kernel/' . $file, 0644);
accessExpect(!file_exists($fixture . '/kernel/Plugin.php'), 'fixture must not load native store binary');

accessCopy($release . '/manager/site/local-extensions', $fixture . '/local-extensions');
accessCopy($release . '/extensions/PikaSharedAccess', $fixture . '/local-extensions/extensions/PikaSharedAccess');
$manifest = json_decode(file_get_contents($fixture . '/local-extensions/extensions/PikaSharedAccess/local-extension.json'), true, flags: JSON_THROW_ON_ERROR);
accessExpect($manifest['hooks'] === [] && $manifest['version'] === '0.1.0', 'access extension unexpectedly registers bypassable hooks');
$fixtureExtension = $fixture . '/local-extensions/extensions/PikaFixture';
mkdir($fixtureExtension, 0755);
$fixtureManifest = $manifest;
$fixtureManifest['id'] = 'PikaFixture'; $fixtureManifest['name'] = 'Isolated hook fixture';
$fixtureManifest['namespace'] = 'Pika\\LocalExtensions\\PikaFixture\\';
$fixtureManifest['settings'] = [];
$fixtureManifest['hooks'] = [['point'=>49, 'class'=>'Pika\\LocalExtensions\\PikaFixture\\Decision', 'method'=>'before', 'priority'=>1]];
accessWrite($fixtureExtension . '/local-extension.json', json_encode($fixtureManifest, JSON_THROW_ON_ERROR));
accessWrite($fixtureExtension . '/bootstrap.php', <<<'PHP'
<?php
namespace Pika\LocalExtensions\PikaFixture;
final class Decision {
    public static mixed $result = null;
    public static bool $throws = false;
    public function before(): mixed {
        if (self::$throws) throw new \RuntimeException('FIXTURE_LOCAL_EXCEPTION');
        return self::$result;
    }
}
PHP);
$entries = [];
foreach (['PikaSharedAccess', 'PikaFixture'] as $id) {
    $entries[] = ['id'=>$id, 'manifest'=>'extensions/' . $id . '/local-extension.json',
        'manifest_sha256'=>hash_file('sha256', $fixture . '/local-extensions/extensions/' . $id . '/local-extension.json')];
}
accessWrite($fixture . '/local-extensions/registry.json', json_encode(['schema'=>1, 'extensions'=>$entries, 'themes'=>[]], JSON_THROW_ON_ERROR));
mkdir($fixture . '/app/Plugin/FixtureAccess', 0755, true);
mkdir($fixture . '/config', 0755); mkdir($fixture . '/vendor', 0755);
copy($official . '/config/dependencies.php', $fixture . '/config/dependencies.php');
chmod($fixture . '/config/dependencies.php', 0644);
mkdir($fixture . '/runtime', 0750); chmod($fixture . '/runtime', 0750);
chown($fixture . '/runtime', $webUid); chgrp($fixture . '/runtime', $webGid);
$database = $fixture . '/runtime/native.sqlite';
touch($database); chmod($database, 0600); chown($database, $webUid); chgrp($database, $webGid);
accessWrite($fixture . '/config/database.php', '<?php return ' . var_export([
    'driver'=>'sqlite', 'database'=>$database, 'prefix'=>'',
    'username'=>'synthetic', 'password'=>'synthetic-only',
], true) . ';');
accessWrite($fixture . '/config/store.php', '<?php return ' . var_export([
    'server'=>0, 'app_key'=>'0123456789abcdef',
], true) . ';');
accessWrite($fixture . '/config/app.php', '<?php return ["version"=>"3.7.9"];');
$snapshot = ["\0complete"=>'1', 'shop_name'=>'isolated-access-shop', 'request_log_enabled'=>'0',
    'request_log'=>'1', 'csp_mode'=>'off', 'link_domain_filter'=>'0'];
// Kernel still loads the genuine Composer autoloader. Only synthetic site
// configuration is primed; no authentication or controller classes are replaced.
accessWrite($fixture . '/vendor/autoload.php', '<?php $loader = require ' . var_export($official . '/vendor/autoload.php', true)
    . '; App\\Util\\Context::set("_DB_CONFIG_SNAPSHOT", ' . var_export($snapshot, true) . '); return $loader;');
$stateBase = '/var/lib/pika-local-extensions';
if (!is_dir($stateBase . '/sites')) mkdir($stateBase . '/sites', 0755, true);
chmod($stateBase, 0755); chmod($stateBase . '/sites', 0755);
$state = $stateBase . '/sites/' . hash('sha256', realpath($fixture));
mkdir($state . '/runtime', 0750, true); chmod($state, 0755); chmod($state . '/runtime', 0750);
chown($state . '/runtime', $webUid); chgrp($state . '/runtime', $webGid);
require $fixture . '/vendor/autoload.php';
require $fixture . '/local-extensions/bootstrap.php';
require $fixture . '/kernel/Helper.php';
require $fixtureExtension . '/bootstrap.php';
$db = new DB();
$db->addConnection(['driver'=>'sqlite', 'database'=>$database, 'prefix'=>'']);
$db->setAsGlobal(); $db->bootEloquent();
$schema = DB::connection()->getSchemaBuilder();
$schema->create('user', static function (Blueprint $table): void {
    $table->increments('id'); $table->string('app_key');
    $table->decimal('balance', 12, 2)->default(0); $table->decimal('recharge', 12, 2)->default(0);
});
$schema->create('user_group', static function (Blueprint $table): void {
    $table->increments('id'); $table->string('name'); $table->decimal('recharge', 12, 2)->default(0);
    $table->text('discount_config')->nullable();
});
$schema->create('config', static function (Blueprint $table): void {
    $table->increments('id'); $table->string('key'); $table->text('value');
});
$schema->create('category', static function (Blueprint $table): void {
    $table->increments('id'); $table->string('name'); $table->integer('sort')->default(0);
    $table->integer('owner')->default(0); $table->integer('status')->default(1);
    $table->integer('hide')->default(0); $table->integer('pid')->default(0);
    $table->string('icon')->default('');
});
$schema->create('commodity', static function (Blueprint $table): void {
    $table->increments('id'); $table->unsignedInteger('category_id'); $table->string('code'); $table->string('name');
    $table->integer('status')->default(1); $table->integer('api_status')->default(1); $table->integer('hide')->default(0);
    $table->integer('delivery_way')->default(1); $table->integer('stock')->default(5); $table->integer('shared_id')->default(0);
    $table->text('shared_stock')->nullable(); $table->text('level_price')->nullable();
    $table->text('leave_message')->nullable(); $table->text('delivery_message')->nullable();
});
$schema->create('card', static function (Blueprint $table): void {
    $table->increments('id'); $table->integer('commodity_id'); $table->integer('status')->default(0); $table->text('secret');
});
$schema->create('order', static function (Blueprint $table): void {
    $table->increments('id'); $table->integer('owner'); $table->string('trade_no'); $table->text('secret');
    $table->integer('status')->default(1); $table->text('widget')->nullable();
});
$schema->create('fixture_action', static function (Blueprint $table): void {
    $table->increments('id'); $table->string('action');
});
$keys = [101=>bin2hex(random_bytes(24)), 102=>bin2hex(random_bytes(24))];
foreach ($keys as $id=>$key) DB::table('user')->insert(['id'=>$id, 'app_key'=>$key, 'balance'=>100, 'recharge'=>0]);
DB::table('category')->insert(['id'=>1, 'name'=>'synthetic-category']);
DB::table('commodity')->insert(['id'=>1, 'category_id'=>1, 'code'=>'fixture-code', 'name'=>'synthetic-item']);
DB::table('card')->insert(['id'=>1, 'commodity_id'=>1, 'secret'=>'SYNTHETIC_CARD_ONLY']);
DB::table('order')->insert(['id'=>1, 'owner'=>101, 'trade_no'=>'123456789012345678', 'secret'=>'SYNTHETIC_ORDER_ONLY']);
accessExpect(posix_setgid($webGid) && posix_setuid($webUid), 'could not drop fixture identity');

function accessRequest(array $post, string $peer = '192.0.2.1'): Kernel\Context\Request
{
    $_POST = $post; $_GET = ['s'=>'/shared/authentication/connect']; $_REQUEST = $post; $_COOKIE = []; $_FILES = [];
    $_SERVER = ['REQUEST_METHOD'=>'POST', 'HTTP_HOST'=>'fixture.example.invalid',
        'HTTP_USER_AGENT'=>'isolated-access-fixture', 'REMOTE_ADDR'=>$peer];
    App\Util\Context::set(App\Consts\Shared::SESSION, null);
    $request = new Kernel\Context\Request();
    Kernel\Util\Context::set(Kernel\Context\Interface\Request::class, $request);
    return $request;
}

function accessSigned(int $id, array $extra = []): array
{
    $post = ['app_id'=>(string)$id] + $extra;
    $post['sign'] = App\Util\Str::generateSignature($post, $GLOBALS['keys'][$id]);
    return $post;
}

function accessValidate(Kernel\Context\Request $request): void
{
    $validator = new App\Interceptor\SharedValidation();
    (new ReflectionProperty($validator, 'request'))->setValue($validator, $request);
    $validator->handle(Kernel\Annotation\Interceptor::TYPE_API);
}

function accessRejected(callable $attempt, string $label): void
{
    $rejected = false;
    try { $attempt(); }
    catch (Kernel\Exception\JSONException $exception) {
        $rejected = $exception->getCode() === 403 && $exception->getMessage() === '共享接口未获准访问';
    }
    accessExpect($rejected, 'guard did not fail closed: ' . $label);
}

function accessPolicy(string $clients): void
{
    ConfigStore::save('PikaSharedAccess', ['allowed_clients'=>$clients]);
}

function accessState(): array
{
    $state = [];
    foreach (['user', 'category', 'commodity', 'card', 'order', 'fixture_action'] as $table) {
        $state[$table] = hash('sha256', DB::table($table)->orderBy('id')->get()->toJson());
    }
    return $state;
}

$commodity = new App\Controller\Shared\Commodity();
$authentication = new App\Controller\Shared\Authentication();
$action = 'connect';
accessExpect(!StateStore::isEnabled('PikaSharedAccess'), 'new access extension must start disabled');
accessRequest([]);
SharedAccessGuard::before($authentication, $action);
accessExpect(!file_exists(PathGuard::stateDirectory('config') . '/PikaSharedAccess.json'), 'disabled guard unexpectedly created policy');
accessPolicy('101@192.0.2.1,102@192.0.2.2,101@2001:db8::1');
$configPath = PathGuard::stateDirectory('config') . '/PikaSharedAccess.json';
accessExpect((fileperms($configPath) & 0777) === 0600 && fileowner($configPath) === $webUid,
    'policy not stored with the existing private config identity');
StateStore::setEnabled('PikaSharedAccess', true);
foreach ([[101,'192.0.2.1'], [102,'192.0.2.2'], [101,'2001:0db8:0:0:0:0:0:1'], [101,'::ffff:192.0.2.1']] as [$id,$peer]) {
    accessValidate(accessRequest(accessSigned($id), $peer));
    SharedAccessGuard::before($authentication, $action);
}
foreach ([[101,'192.0.2.2'], [102,'192.0.2.1'], [101,'192.0.2.3']] as [$id,$peer]) {
    accessValidate(accessRequest(accessSigned($id), $peer));
    accessRejected(static fn()=>SharedAccessGuard::before($authentication, 'connect'), 'paired account and peer');
}
accessValidate(accessRequest(accessSigned(102), '192.0.2.1'));
$_POST['app_id'] = '101'; $_REQUEST['app_id'] = '101';
accessRejected(static fn()=>SharedAccessGuard::before($authentication, 'connect'), 'POST identity cannot replace authenticated Context');
accessValidate(accessRequest(accessSigned(101), '192.0.2.99'));
$_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.1'; $_SERVER['HTTP_X_REAL_IP'] = '192.0.2.1';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '192.0.2.1'; $_SERVER['HTTP_FORWARDED'] = 'for=192.0.2.1';
accessRejected(static fn()=>SharedAccessGuard::before($authentication, 'connect'), 'forwarding headers are not an authority');
foreach ([null, (object)['id'=>101], ['id'=>101]] as $identity) {
    accessRequest(accessSigned(101)); App\Util\Context::set(App\Consts\Shared::SESSION, $identity);
    accessRejected(static fn()=>SharedAccessGuard::before($authentication, 'connect'), 'missing or forged authentication object');
}
foreach (['', '192.0.2.1:443', '192.0.2.1,192.0.2.2', 'fixture.example.invalid'] as $peer) {
    accessValidate(accessRequest(accessSigned(101), $peer));
    accessRejected(static fn()=>SharedAccessGuard::before($authentication, 'connect'), 'invalid REMOTE_ADDR');
}
accessValidate(accessRequest(accessSigned(101))); unset($_SERVER['REMOTE_ADDR']);
accessRejected(static fn()=>SharedAccessGuard::before($authentication, 'connect'), 'missing REMOTE_ADDR');

// Policy validation is exercised through ConfigStore, not just the parser.
$validBytes = file_get_contents($configPath);
$beforeSameSave = accessState();
accessPolicy('101@192.0.2.1,102@192.0.2.2,101@2001:db8::1');
accessExpect(file_get_contents($configPath) === $validBytes && accessState() === $beforeSameSave,
    'same-value policy save changed configuration content or business data');
$invalidRules = ['0@192.0.2.1', '01@192.0.2.1', '-1@192.0.2.1', '1.0@192.0.2.1', '1e2@192.0.2.1',
    '9223372036854775808@192.0.2.1', '101', '@192.0.2.1', '101@', '101@*', '101@host.example.invalid',
    '101@192.0.2.0/24', '101@[2001:db8::1]', '101@fe80::1%eth0', '101@192.0.2.1,',
    '101@192.0.2.1,,102@192.0.2.2', '101@192.0.2.1,101@192.0.2.1',
    '101@192.0.2.1,101@::ffff:192.0.2.1', '101@2001:db8::1,101@2001:0db8:0:0:0:0:0:1',
    str_repeat('x', 4097), implode(',', array_map(static fn(int $id): string=>$id . '@192.0.2.1', range(1,101)))];
foreach ($invalidRules as $rule) {
    $failed = false;
    try { accessPolicy($rule); } catch (RuntimeException) { $failed = true; }
    accessExpect($failed && file_get_contents($configPath) === $validBytes, 'invalid policy altered last valid configuration');
}
SharedAccessGuard::validateSettings(['allowed_clients'=>implode(',', array_map(static fn(int $id): string=>$id . '@192.0.2.1', range(1,100)))]);
accessPolicy(''); accessValidate(accessRequest(accessSigned(101)));
accessRejected(static fn()=>SharedAccessGuard::before($authentication, 'connect'), 'enabled empty policy');
$restore = json_encode(['schema'=>1, 'values'=>['allowed_clients'=>'101@192.0.2.1']], JSON_THROW_ON_ERROR);
$badConfigs = ['{', '{"schema":2,"values":{}}', '{"schema":1,"values":{"allowed_clients":[]}}',
    '{"schema":1,"values":{"allowed_clients":"101@host.example.invalid"}}'];
foreach ($badConfigs as $badConfig) {
    accessWrite($configPath, $badConfig, 0600);
    accessValidate(accessRequest(accessSigned(101)));
    accessRejected(static fn()=>SharedAccessGuard::before($authentication, 'connect'), 'damaged or invalid stored policy');
    SharedAccessGuard::before(new App\Controller\Shared\Plugin(), 'face');
    SharedAccessGuard::before(new stdClass(), 'connect');
    StateStore::setEnabled('PikaSharedAccess', false);
    SharedAccessGuard::before($authentication, 'connect');
    StateStore::setEnabled('PikaSharedAccess', true);
}
accessWrite($configPath, '{', 0600);
$listing = array_column(ManagerService::listing(), null, 'id');
accessExpect($listing['PikaSharedAccess']['config_error'] === true
    && $listing['PikaSharedAccess']['values'] === [] && $listing['PikaSharedAccess']['enabled'] === true,
    'damaged policy must leave an honest usable manager toggle');
StateStore::setEnabled('PikaSharedAccess', false);
accessExpect(!StateStore::isEnabled('PikaSharedAccess', true), 'manager cannot disable damaged policy');
accessWrite($configPath, $restore, 0600); StateStore::setEnabled('PikaSharedAccess', true);
unlink($configPath);
accessValidate(accessRequest(accessSigned(101)));
accessRejected(static fn()=>SharedAccessGuard::before($authentication, 'connect'), 'enabled missing policy');
accessWrite($configPath, $restore, 0600);
chmod($configPath, 0644); clearstatcache(true, $configPath);
accessValidate(accessRequest(accessSigned(101)));
accessRejected(static fn()=>SharedAccessGuard::before($authentication, 'connect'), 'unsafe policy file mode');
chmod($configPath, 0600); clearstatcache(true, $configPath);

// The official hook wrapper, local Dispatcher and Runtime are all real. A
// sentinel stands for the call immediately after BEFORE, exactly like Kernel.
function accessSentinel(object $controller, string $action): void
{
    hook(App\Consts\Hook::CONTROLLER_CALL_BEFORE, $controller, $action);
    DB::table('fixture_action')->insert(['action'=>$action]);
}
Kernel\Util\Context::set(Kernel\Consts\Base::STORE_STATUS, true);
Kernel\Util\Context::set(Kernel\Consts\Base::IS_INSTALL, true);
Kernel\Util\Plugin::$container = ['hook'=>[49=>[['namespace'=>AccessNativeDecision::class,
    'method'=>'before', 'pluginName'=>'FixtureAccess']]]];
$before = accessState();
foreach ([null, false, true, new Kernel\Plugin\Entity\Stock(1)] as $result) {
    AccessNativeDecision::$result = $result;
    accessValidate(accessRequest(accessSigned(102)));
    foreach ([$commodity, $authentication] as $controller) {
        accessRejected(static fn()=>accessSentinel($controller, 'items'), 'native hook short circuit');
    }
}
AccessNativeDecision::$result = null;
StateStore::setEnabled('PikaFixture', true);
foreach ([false, true, new Kernel\Plugin\Entity\Stock(1)] as $result) {
    Pika\LocalExtensions\PikaFixture\Decision::$result = $result;
    accessValidate(accessRequest(accessSigned(102)));
    accessRejected(static fn()=>accessSentinel($commodity, 'trade'), 'local hook short circuit');
}
Pika\LocalExtensions\PikaFixture\Decision::$result = null;
foreach (['native', 'local'] as $source) {
    AccessNativeDecision::$throws = $source === 'native';
    Pika\LocalExtensions\PikaFixture\Decision::$throws = $source === 'local';
    accessValidate(accessRequest(accessSigned(102)));
    $threw = false;
    try { accessSentinel($commodity, 'trade'); }
    catch (RuntimeException $exception) { $threw = $exception->getMessage() === 'FIXTURE_' . strtoupper($source) . '_EXCEPTION'; }
    accessExpect($threw, 'dispatcher exception swallowed before action');
}
AccessNativeDecision::$throws = false; Pika\LocalExtensions\PikaFixture\Decision::$throws = false;
StateStore::setEnabled('PikaFixture', false);
Kernel\Util\Plugin::$container = ['hook'=>[]];
accessExpect(accessState() === $before, 'rejected or exceptional BEFORE executed the action sentinel');
accessValidate(accessRequest(accessSigned(101)));
accessSentinel($authentication, 'connect');
accessExpect(DB::table('fixture_action')->count() === 1, 'allowed sentinel did not execute');
// Class, rather than requested URI or action spelling, owns the policy scope.
foreach ([new App\Controller\Shared\Plugin(), new App\Controller\User\Index(), new stdClass()] as $controller) {
    accessRequest([]); SharedAccessGuard::before($controller, 'connect');
}

function accessHttp(string $address, string $route, array $post, array $headers = [], bool $json = true): array
{
    $curl = curl_init('http://' . $address . $route);
    curl_setopt_array($curl, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>http_build_query($post),
        CURLOPT_HTTPHEADER=>array_merge(['Content-Type: application/x-www-form-urlencoded'], $headers),
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_MAXREDIRS=>0,
        CURLOPT_CONNECTTIMEOUT=>2, CURLOPT_TIMEOUT=>5, CURLOPT_PROXY=>'']);
    $body = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE); $error = curl_errno($curl); curl_close($curl);
    accessExpect($error === 0 && is_string($body) && $status === 200, 'native HTTP request failed: ' . $route);
    if (!$json) return ['raw'=>$body];
    $decoded = json_decode($body, true);
    accessExpect(is_array($decoded), 'native Kernel did not return its JSON envelope: ' . $route);
    return $decoded;
}

$listener = stream_socket_server('tcp://127.0.0.1:0', $socketError, $socketMessage);
accessExpect(is_resource($listener), 'could not reserve loopback HTTP port');
$address = stream_socket_get_name($listener, false); fclose($listener);
accessExpect(is_string($address) && preg_match('/^127\.0\.0\.1:[0-9]+$/D', $address) === 1, 'HTTP server escaped loopback');
$environment = getenv(); $environment['PIKA_ACCESS_HTTP_FIXTURE'] = $fixture;
unset($environment['PHP_CLI_SERVER_WORKERS']);
$server = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', '-S', $address, __FILE__],
    [0=>['file','/dev/null','r'], 1=>['file','/dev/null','w'], 2=>['file','/dev/null','w']], $pipes, $fixture, $environment);
accessExpect(is_resource($server), 'could not start existing loopback PHP server');
try {
    $ready = false;
    for ($attempt=0; $attempt<50; $attempt++) {
        $probe = @stream_socket_client('tcp://' . $address, $socketError, $socketMessage, 0.1);
        if (is_resource($probe)) { fclose($probe); $ready = true; break; }
        accessExpect(proc_get_status($server)['running'], 'native HTTP fixture exited before readiness');
        usleep(20000);
    }
    accessExpect($ready, 'native HTTP fixture not ready');
    $before = accessState();
    StateStore::setEnabled('PikaSharedAccess', false);
    $baselineConnect = accessHttp($address, '/shared/authentication/connect', accessSigned(101));
    $baselineItems = accessHttp($address, '/shared/commodity/items', accessSigned(101));
    accessExpect(($baselineConnect['code'] ?? null) === 200 && ($baselineConnect['data']['shopName'] ?? null) === 'isolated-access-shop',
        'disabled extension changed native signed connect');
    accessExpect(($baselineItems['code'] ?? null) === 200 && ($baselineItems['data'][0]['children'][0]['code'] ?? null) === 'fixture-code',
        'disabled extension changed native signed catalog');
    accessPolicy('101@127.0.0.1,102@192.0.2.2'); StateStore::setEnabled('PikaSharedAccess', true);
    accessExpect(accessHttp($address, '/shared/authentication/connect', accessSigned(101)) === $baselineConnect
        && accessHttp($address, '/shared/commodity/items', accessSigned(101)) === $baselineItems,
        'allowed native connection or catalog differs from disabled baseline');
    $routes = ['/shared/commodity/items', '/index.php?s=' . rawurlencode('/shared/commodity/items'),
        '/index.php?s=' . rawurlencode('/Shared/Commodity/ITEMS'),
        '/index.php?s=' . rawurlencode('/shared\\Commodity/items'), '/shared/commodity/items.json',
        '/shared/commodity/items/', '/index.php?s=' . rawurlencode('/shared/Authentication/CONNECT.json')];
    foreach ($routes as $route) {
        $result = accessHttp($address, $route, accessSigned(102));
        accessExpect(($result['code'] ?? null) === 403 && ($result['msg'] ?? null) === '共享接口未获准访问'
            && !isset($result['data']), 'real Kernel equivalent route bypassed guard: ' . $route);
    }
    $actions = [];
    foreach ((new ReflectionClass(App\Controller\Shared\Commodity::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() === App\Controller\Shared\Commodity::class) $actions[] = $method->getName();
    }
    sort($actions);
    accessExpect($actions === ['draft','draftCard','inventory','inventoryState','item','items','query','stock','trade','valuation'],
        'fixed native action coverage changed; review the new action explicitly');
    foreach ($actions as $method) {
        $result = accessHttp($address, '/shared/commodity/' . $method, accessSigned(102, [
            'code'=>'fixture-code', 'shared_code'=>'fixture-code', 'num'=>'1', 'card_id'=>'1',
            'tradeNo'=>'123456789012345678', 'contact'=>'fixture@example.invalid',
        ]));
        accessExpect(($result['code'] ?? null) === 403 && ($result['msg'] ?? null) === '共享接口未获准访问'
            && !array_key_exists('data', $result), 'protected native action ran: ' . $method);
        accessExpect(accessState() === $before, 'denied native action changed synthetic business tables');
    }
    $forged = accessHttp($address, '/shared/authentication/connect', accessSigned(102),
        ['X-Forwarded-For: 192.0.2.2', 'X-Real-IP: 192.0.2.2', 'CF-Connecting-IP: 192.0.2.2']);
    accessExpect(($forged['code'] ?? null) === 403, 'HTTP forwarding headers bypassed bound peer');
    foreach ([[[], '商户ID不存在'], [['app_id'=>'101','sign'=>'invalid'], '密钥错误'],
        [accessSigned(101) + ['unsigned'=>'tampered'], '密钥错误']] as [$post, $expectedMessage]) {
        $result = accessHttp($address, '/shared/authentication/connect', $post);
        accessExpect(($result['code'] ?? null) === 0 && ($result['msg'] ?? null) === $expectedMessage
            && !array_key_exists('data', $result), 'native signature gate bypassed or masked by new guard');
    }
    $waf = accessHttp($address, '/shared/authentication/connect', accessSigned(101, ['fixture_probe'=>'sleep(1)']));
    accessExpect(($waf['code'] ?? null) === 0
        && ($waf['msg'] ?? null) === 'The current session is not secure. Please refresh the web page and try again.'
        && !array_key_exists('data', $waf), 'native WAF gate bypassed or masked by new guard');
    accessWrite($configPath, '{', 0600);
    $corrupt = accessHttp($address, '/shared/authentication/connect', accessSigned(101));
    accessExpect(($corrupt['code'] ?? null) === 403, 'real Kernel allowed damaged enabled policy');
    $pluginFace = accessHttp($address, '/shared/plugin/face', [], [], false);
    $face = App\Util\Aes::decrypt($pluginFace['raw'], '0123456789abcdef', '0123456789abcdef');
    accessExpect(($face['face'] ?? null) === 'isolated-shared-access-fixture',
        'enabled corrupt policy affected native Shared Plugin face');
    StateStore::setEnabled('PikaSharedAccess', false);
    accessExpect(accessHttp($address, '/shared/authentication/connect', accessSigned(101)) === $baselineConnect,
        'disabled extension read corrupt rules in native Kernel');
    accessExpect(accessState() === $before, 'HTTP matrix wrote synthetic balances, products, cards or orders');
} finally {
    if (proc_get_status($server)['running']) proc_terminate($server);
    $stopped = false;
    for ($attempt=0; $attempt<50; $attempt++) {
        if (!proc_get_status($server)['running']) { $stopped = true; break; }
        usleep(20000);
    }
    if (!$stopped) {
        proc_terminate($server, 9);
        for ($attempt=0; $attempt<50; $attempt++) {
            if (!proc_get_status($server)['running']) { $stopped = true; break; }
            usleep(20000);
        }
    }
    accessExpect($stopped, 'loopback fixture remained running after bounded stop');
    proc_close($server);
}
echo json_encode(['status'=>'PASS', 'checks'=>$GLOBALS['access_checks'],
    'scope'=>'native SharedValidation/Runtime/Kernel; synthetic SQLite; loopback HTTP; no production or upstream',
    'commodity_actions'=>count($actions)], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
