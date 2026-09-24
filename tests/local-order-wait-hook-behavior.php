<?php
declare(strict_types=1);

namespace Kernel\Consts {
    final class Base
    {
        public const ROUTE = 'route';
    }
}

namespace Kernel\Util {
    final class Context
    {
        public static string $route = '';

        public static function get(string $key): string
        {
            return self::$route;
        }
    }
}

namespace Pika\LocalExtensions\Manager {
    final class ConfigStore
    {
        public static function get(string $id): array
        {
            if ($id !== 'PikaOrderReturnWait') {
                throw new \RuntimeException('unexpected extension id');
            }
            return ['wait_seconds' => 30, 'refresh_seconds' => 3];
        }
    }
}

namespace {
    use Kernel\Util\Context;
    use Pika\LocalExtensions\PikaOrderReturnWait\Hook\OrderReturnWait;

    $extensionRoot = dirname(__DIR__) . '/extensions/PikaOrderReturnWait';
    require $extensionRoot . '/bootstrap.php';
    $_GET = [
        'tradeNo' => '202608301234567890',
        'password' => '<img src=x onerror=alert(1)>',
    ];
    $hook = new OrderReturnWait();

    Context::$route = '/user/index/query';
    $guest = $hook->guestOrderPage();
    if (!str_contains($guest, 'data-mode="guest"') || $hook->memberOrderPage() !== '') {
        fwrite(STDERR, "FAIL: guest hook route isolation failed\n");
        exit(1);
    }
    if (str_contains($guest, 'password') || str_contains($guest, 'onerror')) {
        fwrite(STDERR, "FAIL: query password leaked into guest hook output\n");
        exit(1);
    }

    Context::$route = '/user/personal/purchaseRecord';
    $member = $hook->memberOrderPage();
    if (!str_contains($member, 'data-mode="member"') || $hook->guestOrderPage() !== '') {
        fwrite(STDERR, "FAIL: member hook route isolation failed\n");
        exit(1);
    }

    fwrite(STDOUT, "PASS local order wait hook behavior\n");
}
