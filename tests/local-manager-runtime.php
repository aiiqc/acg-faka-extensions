<?php
declare(strict_types=1);

namespace Kernel\Container {
    final class Di {
        private static ?self $instance = null;
        public static function inst(): self { return self::$instance ??= new self(); }
        public function inject(object $object): void {}
    }
}

namespace Kernel\Plugin\Entity {
    final class Stock {
        public function __construct(public string $value = 'stock') {}
    }
}

namespace App\Consts {
    final class Hook { public const ADMIN_VIEW_MENU = 3; }
    final class Manage { public const SESSION = 'manage'; public const SESSION_RECORD = 'manage_session_record'; }
}

namespace App\Util {
    final class Context {
        private static array $values = [];
        public static function set(string $key, mixed $value): void { self::$values[$key] = $value; }
        public static function get(string $key): mixed { return self::$values[$key] ?? null; }
    }
}

namespace Kernel\Consts {
    final class Base { public const ROUTE = 'route'; }
}

namespace Kernel\Util {
    final class Context {
        private static array $values = [];
        public static function set(string $key, mixed $value): void { self::$values[$key] = $value; }
        public static function get(string $key): mixed { return self::$values[$key] ?? null; }
    }
}

namespace {
    use Pika\LocalExtensions\Manager\ConfigStore;
    use Pika\LocalExtensions\Manager\Csrf;
    use Pika\LocalExtensions\Manager\Dispatcher;
    use Pika\LocalExtensions\Manager\AtomicJson;
    use Pika\LocalExtensions\Manager\PathGuard;
    use Pika\LocalExtensions\Manager\Registry;
    use Pika\LocalExtensions\Manager\Runtime;
    use Pika\LocalExtensions\Manager\StateStore;

    function check(bool $condition, string $message): void {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function write_json(string $path, array $value, int $mode = 0644): void {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($path, $json) === false) throw new RuntimeException("write failed: {$path}");
        chmod($path, $mode);
    }

    function remove_tree(string $path): void {
        if (!is_dir($path)) return;
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $target = $path . '/' . $entry;
            if (is_dir($target) && !is_link($target)) remove_tree($target); else unlink($target);
        }
        rmdir($path);
    }

    $suffix = bin2hex(random_bytes(6));
    $fixture = sys_get_temp_dir() . '/pika-local-manager-' . $suffix;
    $webUid = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $webGid = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if (!is_int($webUid) || !is_int($webGid) || !function_exists('posix_setuid') || posix_geteuid() !== 0) {
        throw new RuntimeException('runtime behavior fixture requires root plus WEB_UID WEB_GID');
    }
    mkdir($fixture . '/local-extensions/extensions/TestExtension', 0755, true);
    mkdir($fixture . '/app/View/User/Theme/Pika', 0755, true);
    define('BASE_PATH', $fixture . '/');

    $manifest = [
        'schema' => 1,
        'id' => 'TestExtension',
        'name' => 'Test Extension',
        'version' => '1.0.0',
        'description' => 'Runtime contract fixture.',
        'type' => 'plugin',
        'namespace' => 'Pika\\LocalExtensions\\TestExtension\\',
        'bootstrap' => 'bootstrap.php',
        'hooks' => [
            ['point' => 100, 'class' => 'Pika\\LocalExtensions\\TestExtension\\Handler', 'method' => 'first', 'priority' => 10],
            ['point' => 100, 'class' => 'Pika\\LocalExtensions\\TestExtension\\Handler', 'method' => 'second', 'priority' => 20],
            ['point' => 101, 'class' => 'Pika\\LocalExtensions\\TestExtension\\Handler', 'method' => 'decision', 'priority' => 10],
            ['point' => 102, 'class' => 'Pika\\LocalExtensions\\TestExtension\\Handler', 'method' => 'objectResult', 'priority' => 10],
            ['point' => 102, 'class' => 'Pika\\LocalExtensions\\TestExtension\\Handler', 'method' => 'arrayResult', 'priority' => 20],
            ['point' => 103, 'class' => 'Pika\\LocalExtensions\\TestExtension\\Handler', 'method' => 'stock', 'priority' => 10],
        ],
        'settings' => [
            ['key' => 'mode', 'label' => 'Mode', 'type' => 'select', 'default' => 'basic', 'options' => [
                ['value' => 'basic', 'label' => 'Basic'], ['value' => 'full', 'label' => 'Full'],
            ]],
            ['key' => 'premium', 'label' => 'Premium', 'type' => 'number', 'default' => 10, 'min' => 0, 'max' => 100],
            ['key' => 'secret', 'label' => 'Secret', 'type' => 'password', 'min' => 0, 'max' => 100],
        ],
    ];
    $manifestPath = $fixture . '/local-extensions/extensions/TestExtension/local-extension.json';
    write_json($manifestPath, $manifest);

    $handler = <<<'PHP'
<?php
declare(strict_types=1);
namespace Pika\LocalExtensions\TestExtension;
final class Handler {
    public function first(string &$argument): string { $argument .= ':first'; return '-first'; }
    public function second(string &$argument): string { $argument .= ':second'; return '-second'; }
    public function decision(string &$argument): bool { $argument .= ':decision'; return false; }
    public function objectResult(string &$argument): object { $argument .= ':object'; return (object)['kind' => 'object']; }
    public function arrayResult(string &$argument): array { $argument .= ':array'; return ['kind' => 'array']; }
    public function stock(string &$argument): \Kernel\Plugin\Entity\Stock { $argument .= ':stock'; return new \Kernel\Plugin\Entity\Stock('7'); }
}
PHP;
    file_put_contents($fixture . '/local-extensions/extensions/TestExtension/Handler.php', $handler);
    chmod($fixture . '/local-extensions/extensions/TestExtension/Handler.php', 0644);
    file_put_contents(
        $fixture . '/local-extensions/extensions/TestExtension/bootstrap.php',
        "<?php\ndeclare(strict_types=1);\nrequire_once __DIR__ . '/Handler.php';\nreturn true;\n"
    );
    chmod($fixture . '/local-extensions/extensions/TestExtension/bootstrap.php', 0644);

    $themePath = $fixture . '/app/View/User/Theme/Pika/theme.json';
    write_json($themePath, [
        'schema' => 1, 'id' => 'Pika', 'name' => 'Pika', 'version' => '1.0.0',
        'type' => 'theme', 'theme_key' => 'Pika', 'preserve' => ['Setting.php'],
    ]);
    write_json($fixture . '/local-extensions/registry.json', [
        'schema' => 1,
        'extensions' => [[
            'id' => 'TestExtension',
            'manifest' => 'extensions/TestExtension/local-extension.json',
            'manifest_sha256' => hash_file('sha256', $manifestPath),
        ]],
        'themes' => [[
            'id' => 'Pika',
            'manifest' => 'app/View/User/Theme/Pika/theme.json',
            'manifest_sha256' => hash_file('sha256', $themePath),
        ]],
    ]);
    \App\Util\Context::set(\App\Consts\Manage::SESSION, (object)['id' => 1, 'type' => 0]);
    \App\Util\Context::set(\App\Consts\Manage::SESSION_RECORD, (object)['id' => 9]);

    $stateBase = '/var/lib/pika-local-extensions/sites';
    if (!is_dir($stateBase) && !mkdir($stateBase, 0755, true) && !is_dir($stateBase)) {
        throw new RuntimeException('unable to create production state base fixture');
    }
    chmod('/var/lib/pika-local-extensions', 0755);
    chmod($stateBase, 0755);
    chown('/var/lib/pika-local-extensions', 0);
    chgrp('/var/lib/pika-local-extensions', 0);
    chown($stateBase, 0);
    chgrp($stateBase, 0);
    $siteStateDirectory = $stateBase . '/' . hash('sha256', realpath($fixture));
    mkdir($siteStateDirectory . '/runtime/config', 0750, true);
    chmod($siteStateDirectory, 0755);
    chmod($siteStateDirectory . '/runtime', 0750);
    chmod($siteStateDirectory . '/runtime/config', 0750);
    chown($siteStateDirectory, 0);
    chgrp($siteStateDirectory, 0);
    chown($siteStateDirectory . '/runtime', $webUid);
    chgrp($siteStateDirectory . '/runtime', $webGid);
    chown($siteStateDirectory . '/runtime/config', $webUid);
    chgrp($siteStateDirectory . '/runtime/config', $webGid);
    $stateRoot = $siteStateDirectory . '/runtime';
    file_put_contents($stateRoot . '/csrf.key', bin2hex(random_bytes(32)) . "\n");
    chmod($stateRoot . '/csrf.key', 0600);
    chown($stateRoot . '/csrf.key', $webUid);
    chgrp($stateRoot . '/csrf.key', $webGid);
    file_put_contents($stateRoot . '/unsafe-owner.json', "{}\n");
    chmod($stateRoot . '/unsafe-owner.json', 0600);
    file_put_contents($stateRoot . '/unsafe-lock.json.lock', '');
    chmod($stateRoot . '/unsafe-lock.json.lock', 0600);

    if (!posix_setgid($webGid) || !posix_setuid($webUid)) {
        throw new RuntimeException('unable to drop to isolated web identity');
    }

    $bootstrap = dirname(__DIR__) . '/manager/site/local-extensions/bootstrap.php';
    require $bootstrap;
    check(PathGuard::stateRoot() === $stateRoot, 'state root does not use canonical site hash and runtime child');
    check(PathGuard::runtimeOwner() === $webUid, 'runtime owner does not match isolated web identity');
    try {
        AtomicJson::read($stateRoot . '/unsafe-owner.json', []);
        throw new RuntimeException('root-owned sensitive JSON was accepted');
    } catch (RuntimeException $exception) {
        check($exception->getMessage() !== 'root-owned sensitive JSON was accepted', $exception->getMessage());
    }
    try {
        AtomicJson::update($stateRoot . '/unsafe-lock.json', [], static fn(array $value): array => $value);
        throw new RuntimeException('root-owned sensitive lock was accepted');
    } catch (RuntimeException $exception) {
        check($exception->getMessage() !== 'root-owned sensitive lock was accepted', $exception->getMessage());
    }
    try {
        PathGuard::stateDirectory('config/.', 0700);
        throw new RuntimeException('dot state path segment was accepted');
    } catch (RuntimeException $exception) {
        check($exception->getMessage() !== 'dot state path segment was accepted', $exception->getMessage());
    }
    check(Runtime::isBooted(), 'runtime did not boot');
    check(Registry::isTrustedTheme('Pika'), 'trusted theme was not recognized');
    check(!Registry::isTrustedTheme('../Pika'), 'invalid theme key was accepted');
    $repoRoot = dirname(__DIR__);
    $releaseSupply = json_decode(file_get_contents($repoRoot . '/extensions/PikaSupplySync/local-extension.json'), true, 32, JSON_THROW_ON_ERROR);
    $releaseCatalogHub = json_decode(file_get_contents($repoRoot . '/extensions/PikaCatalogHub/local-extension.json'), true, 32, JSON_THROW_ON_ERROR);
    $releaseWait = json_decode(file_get_contents($repoRoot . '/extensions/PikaOrderReturnWait/local-extension.json'), true, 32, JSON_THROW_ON_ERROR);
    $releaseTheme = json_decode(file_get_contents($repoRoot . '/themes/Pika/theme.json'), true, 32, JSON_THROW_ON_ERROR);
    \Pika\LocalExtensions\Manager\ManifestValidator::plugin($releaseSupply, 'PikaSupplySync');
    \Pika\LocalExtensions\Manager\ManifestValidator::plugin($releaseCatalogHub, 'PikaCatalogHub');
    \Pika\LocalExtensions\Manager\ManifestValidator::plugin($releaseWait, 'PikaOrderReturnWait');
    \Pika\LocalExtensions\Manager\ManifestValidator::theme($releaseTheme, 'Pika');
    check(!StateStore::isEnabled('TestExtension'), 'missing state must default disabled');

    StateStore::setEnabled('TestExtension', true);
    check(StateStore::isEnabled('TestExtension'), 'enabled state was not persisted');
    check((fileperms($stateRoot . '/state.json') & 0777) === 0600, 'state permissions are not 0600');
    check(!file_exists($fixture . '/runtime/local-extensions/state.json'), 'state leaked into the document root');

    $argument = 'arg';
    $combined = Dispatcher::dispatch(100, 'official', $argument);
    check($combined === 'official-first-second', 'string results were not appended after official result');
    check($argument === 'arg:first:second', 'hook references or ordering were not preserved');

    $argument = 'arg';
    $decision = Dispatcher::dispatch(101, null, $argument);
    check($decision === false && $argument === 'arg:decision', 'bool did not short circuit');

    $argument = 'arg';
    $officialCollection = [['official' => true]];
    $collection = Dispatcher::dispatch(102, $officialCollection, $argument);
    check(count($collection) === 3 && is_object($collection[1]) && is_array($collection[2]), 'array/object collection is invalid');
    check($collection[0] === $officialCollection[0], 'official collection lost priority');

    $argument = 'arg';
    $stock = Dispatcher::dispatch(103, null, $argument);
    check($stock instanceof \Kernel\Plugin\Entity\Stock && $stock->value === '7', 'Stock did not short circuit');

    $argument = 'unchanged';
    $officialDecision = Dispatcher::dispatch(100, true, $argument);
    check($officialDecision === true && $argument === 'unchanged', 'official bool did not retain absolute priority');

    StateStore::setEnabled('TestExtension', false);
    $argument = 'unchanged';
    check(Dispatcher::dispatch(999, '', $argument) === null, 'empty official string changed no-result semantics');
    check(Dispatcher::dispatch(999, [], $argument) === null, 'empty official array changed no-result semantics');
    StateStore::setEnabled('TestExtension', true);

    ConfigStore::save('TestExtension', ['mode' => 'full', 'premium' => '25', 'secret' => 'sensitive']);
    $public = ConfigStore::publicView('TestExtension');
    check($public['values']['mode'] === 'full' && $public['values']['premium'] === 25, 'config values were not normalized');
    check($public['values']['secret'] === '' && $public['password_configured']['secret'] === true, 'password was disclosed');
    ConfigStore::save('TestExtension', ['mode' => 'basic', 'premium' => 10, 'secret' => '']);
    check(ConfigStore::get('TestExtension')['secret'] === 'sensitive', 'blank password did not preserve stored secret');
    check((fileperms($stateRoot . '/config/TestExtension.json') & 0777) === 0600, 'config permissions are not 0600');
    check(!file_exists($fixture . '/runtime/local-extensions/config/TestExtension.json'), 'config leaked into the document root');

    $csrf = Csrf::issue();
    check(Csrf::verify($csrf), 'issued CSRF token did not verify');
    check(!Csrf::verify($csrf . '0'), 'tampered CSRF token verified');
    $draftScope = Csrf::draftScope();
    check(preg_match('/^[a-f0-9]{64}$/D', $draftScope) === 1, 'draft scope was not an opaque digest');
    check($draftScope === Csrf::draftScope(), 'draft scope changed across same-session reload');
    check(!Csrf::verify($draftScope), 'draft namespace authorized a mutation');
    check(!Csrf::verify(explode('.', $csrf)[0] . '.' . $draftScope), 'draft namespace was interchangeable with a CSRF signature');
    \App\Util\Context::set(\App\Consts\Manage::SESSION_RECORD, (object)['id' => 10]);
    check($draftScope !== Csrf::draftScope(), 'draft namespace was reused across logins');
    \App\Util\Context::set(\App\Consts\Manage::SESSION_RECORD, (object)['id' => 9]);
    \App\Util\Context::set(\App\Consts\Manage::SESSION, (object)['id' => 42]);
    check($draftScope !== Csrf::draftScope(), 'draft namespace was reused across administrators');

    echo "local-manager-runtime: PASS\n";
}
