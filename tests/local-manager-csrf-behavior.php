<?php
declare(strict_types=1);

namespace App\Consts {
    final class Manage { public const SESSION = 'manage'; public const SESSION_RECORD = 'manage_session'; }
}
namespace App\Util {
    final class Context {
        public static array $values = [];
        public static function get(string $key): mixed { return self::$values[$key] ?? null; }
    }
}
namespace Kernel\Exception {
    final class JSONException extends \Exception {}
}
namespace Kernel\Context\Interface {
    interface Request {
        public function method(): string;
        public function unsafePost(?string $key = null): mixed;
        public function header(?string $key = null): mixed;
    }
}
namespace Pika\LocalExtensions\Manager {
    function time(): int { return $GLOBALS['csrf_fixture_time']; }
    final class PathGuard {
        public static string $root;
        public static function stateRoot(): string { return self::$root; }
    }
}
namespace {
    use App\Util\Context;
    use Kernel\Context\Interface\Request;
    use Kernel\Exception\JSONException;
    use Pika\LocalExtensions\Manager\Csrf;
    use Pika\LocalExtensions\Manager\PathGuard;
    use Pika\LocalExtensions\Manager\RequestGuard;

    final class CsrfRequest implements Request {
        public function __construct(private array $headers, private mixed $token = null, private string $verb = 'POST') {}
        public function method(): string { return $this->verb; }
        public function unsafePost(?string $key = null): mixed { return $key === 'csrf_token' ? $this->token : null; }
        public function header(?string $key = null): mixed { return $this->headers[$key] ?? null; }
    }
    function check(bool $condition, string $message): void {
        if (!$condition) throw new RuntimeException($message);
    }
    function rejected(callable $operation, string $label): void {
        try { $operation(); } catch (JSONException) { return; }
        throw new RuntimeException($label . ' was accepted');
    }

    $directory = sys_get_temp_dir() . '/pika-csrf-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    PathGuard::$root = $directory;
    file_put_contents($directory . '/csrf.key', str_repeat('synthetic-csrf-fixture-', 3));
    chmod($directory . '/csrf.key', 0600);
    require dirname(__DIR__) . '/manager/site/local-extensions/src/Csrf.php';
    require dirname(__DIR__) . '/manager/site/local-extensions/src/RequestGuard.php';
    try {
        Context::$values = ['manage' => (object)['id' => 7, 'type' => 0], 'manage_session' => (object)['id' => 9]];
        $admin = Context::$values['manage'];
        $GLOBALS['csrf_fixture_time'] = 1800 * 1000 + 10;
        $token = Csrf::issue();
        check(Csrf::verify($token), 'current token rejected');
        check(Csrf::renew($token) === $token, 'valid same-session renewal changed its bucket');
        $GLOBALS['csrf_fixture_time'] += 1800;
        check(Csrf::verify($token), 'previous bucket rejected');
        $GLOBALS['csrf_fixture_time'] += 1800;
        check(!Csrf::verify($token), 'expired token authorized a mutation');
        $fresh = Csrf::renew($token);
        check(is_string($fresh) && Csrf::verify($fresh), 'expired same-session token could not renew');
        check(!Csrf::verify($token), 'renewal extended the old mutation token lifetime');
        foreach ([null, [], '', $token . '0', '1.' . str_repeat('0', 64)] as $invalid) {
            check(Csrf::renew($invalid) === null, 'invalid token renewed');
        }
        Context::$values['manage_session'] = (object)['id' => 10];
        check(Csrf::renew($token) === null, 'another login renewed an old page token');
        Context::$values['manage_session'] = (object)['id' => 9];
        Context::$values['manage'] = (object)['id' => 8, 'type' => 0];
        check(Csrf::renew($token) === null, 'another administrator renewed an old page token');
        Context::$values['manage'] = $admin;
        unset(Context::$values['manage_session']);
        try {
            Csrf::renew($token);
            throw new RuntimeException('missing login context renewed a token');
        } catch (RuntimeException $exception) {
            check($exception->getMessage() === 'Local extension CSRF session is unavailable.', 'missing login context was not rejected');
        }
        Context::$values['manage_session'] = (object)['id' => 9];
        $GLOBALS['csrf_fixture_time'] = 1800 * 999;
        check(Csrf::renew($token) === null, 'future token renewed');
        $GLOBALS['csrf_fixture_time'] = 1800 * 1002 + 10;

        $_SERVER = ['HTTPS' => 'on', 'REQUEST_SCHEME' => 'http'];
        $headers = ['Origin' => 'https://fixture.example', 'Host' => 'fixture.example', 'SecFetchSite' => 'same-origin'];
        RequestGuard::csrfRenewal(new CsrfRequest($headers, $token), $admin);
        $_SERVER['HTTPS'] = '1';
        RequestGuard::csrfRenewal(new CsrfRequest($headers, $token), $admin);
        $_SERVER = ['REQUEST_SCHEME' => 'https'];
        RequestGuard::csrfRenewal(new CsrfRequest($headers, $token), $admin);
        foreach ([
            ['https://fixture.example:443', 'fixture.example'],
            ['https://fixture.example:18443', 'fixture.example:18443'],
            ['https://[::1]:18443', '[::1]:18443'],
        ] as [$origin, $host]) {
            RequestGuard::csrfRenewal(new CsrfRequest(array_replace($headers, ['Origin' => $origin, 'Host' => $host]), $token), $admin);
        }
        foreach ([null, '', 'null', [], 'http://fixture.example', 'https://other.example',
            'https://fixture.example:18443', 'https://user@fixture.example', 'https://fixture.example/',
            'https://fixture.example?x=1', 'https://fixture.example#x', 'https://fixture.example https://other.example',
            'https://fixture.example,https://other.example', "https://fixture.example\n", 'https://fixture.example:99999',
            'https://[bad]', 'https://-invalid.example', 'https://fixture..example',
        ] as $origin) {
            rejected(fn() => RequestGuard::csrfRenewal(new CsrfRequest(array_replace($headers, ['Origin' => $origin]), $token), $admin), 'unsafe origin');
        }
        foreach (['cross-site', 'same-site', 'none', [], ''] as $site) {
            rejected(fn() => RequestGuard::csrfRenewal(new CsrfRequest(array_replace($headers, ['SecFetchSite' => $site]), $token), $admin), 'unsafe fetch metadata');
        }
        $withoutMetadata = $headers;
        unset($withoutMetadata['SecFetchSite']);
        RequestGuard::csrfRenewal(new CsrfRequest($withoutMetadata, $token), $admin);
        foreach (['fixture.example/path', 'user@fixture.example', '', null, []] as $host) {
            rejected(fn() => RequestGuard::csrfRenewal(new CsrfRequest(array_replace($headers, ['Host' => $host]), $token), $admin), 'unsafe host');
        }
        rejected(fn() => RequestGuard::csrfRenewal(new CsrfRequest($headers, $token, 'GET'), $admin), 'GET renewal');
        rejected(fn() => RequestGuard::csrfRenewal(new CsrfRequest($headers, $token), (object)['type' => 1]), 'non-admin renewal');
        $_SERVER = ['HTTP_X_FORWARDED_PROTO' => 'https'];
        rejected(fn() => RequestGuard::csrfRenewal(new CsrfRequest($headers, $token), $admin), 'forwarded-only scheme');
        $_SERVER = ['REQUEST_SCHEME' => 'http', 'HTTP_X_FORWARDED_PROTO' => 'https'];
        rejected(fn() => RequestGuard::csrfRenewal(new CsrfRequest($headers, $token), $admin), 'forwarded scheme override');
        rejected(fn() => RequestGuard::mutation(new CsrfRequest($headers, $token), $admin), 'expired mutation');
        RequestGuard::mutation(new CsrfRequest($headers, $fresh), $admin);
        file_put_contents($directory . '/csrf.key', str_repeat('rotated-synthetic-fixture-', 3));
        check(Csrf::renew($token) === null, 'a replaced key renewed the old token');
        echo "local-manager-csrf-behavior: PASS\n";
    } finally {
        unlink($directory . '/csrf.key');
        rmdir($directory);
    }
}
