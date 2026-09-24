<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\Manager;

final class Runtime
{
    private static bool $booted = false;

    public static function boot(): void
    {
        self::$booted = true;
    }

    public static function isBooted(): bool
    {
        return self::$booted;
    }

    public static function hook(int $point, mixed $officialResult, mixed &...$args): mixed
    {
        $result = Dispatcher::dispatch($point, $officialResult, ...$args);
        // The native save guard must also run when either dispatcher returns a
        // bool/Stock early. An exception already prevents the controller call.
        if ($point === 0x31) {
            NativeCategoryGuard::before($args[0] ?? null, $args[1] ?? null);
        }
        return $result;
    }

    public static function getHookNum(int $point): int
    {
        return Dispatcher::count($point);
    }

    public static function isTrustedTheme(string $themeKey): bool
    {
        return Registry::isTrustedTheme($themeKey);
    }
}
