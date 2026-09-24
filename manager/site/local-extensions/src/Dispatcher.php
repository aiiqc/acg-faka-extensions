<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\Manager;

use Kernel\Container\Di;
use Kernel\Plugin\Entity\Stock;

final class Dispatcher
{
    /** @var array<string,true> */
    private static array $bootstrapped = [];

    public static function dispatch(int $point, mixed $officialResult, mixed &...$args): mixed
    {
        if ($officialResult instanceof Stock || is_bool($officialResult)) {
            return $officialResult;
        }

        $result = $officialResult;
        // Match Acg-Faka's public hook() wrapper exactly: null, an empty
        // string and an empty array all mean "no hook result". Keeping that
        // distinction prevents an enabled manager with no matching local hook
        // from changing the official return value.
        $hasResult = $officialResult !== null && $officialResult !== '' && $officialResult !== [];
        foreach (self::callbacks($point) as $callback) {
            $extensionId = $callback['extension_id'];
            if ($extensionId !== '__manager__') {
                self::bootstrap($extensionId);
            }
            $class = $callback['class'];
            if (!class_exists($class)) {
                throw new \RuntimeException('Trusted local extension hook class is unavailable.');
            }
            $instance = new $class();
            Di::inst()->inject($instance);
            if (!is_callable([$instance, $callback['method']])) {
                throw new \RuntimeException('Trusted local extension hook method is unavailable.');
            }
            $localResult = call_user_func_array([$instance, $callback['method']], $args);
            if ($localResult instanceof Stock || is_bool($localResult)) {
                return $localResult;
            }
            if ($localResult === null) {
                continue;
            }
            [$result, $hasResult] = self::collect($result, $hasResult, $localResult);
        }

        return $hasResult ? $result : null;
    }

    public static function count(int $point): int
    {
        return count(self::callbacks($point));
    }

    /** @return list<array{extension_id:string,class:string,method:string,priority:int,order:int}> */
    private static function callbacks(int $point): array
    {
        $callbacks = [];
        if (defined('App\\Consts\\Hook::ADMIN_VIEW_MENU') && $point === \App\Consts\Hook::ADMIN_VIEW_MENU) {
            $callbacks[] = [
                'extension_id' => '__manager__',
                'class' => AdminMenu::class,
                'method' => 'render',
                'priority' => -1000,
                'order' => 0,
            ];
        }
        foreach (Registry::extensions() as $extension) {
            if (!StateStore::isEnabled($extension['id'])) {
                continue;
            }
            foreach ($extension['hooks'] as $hook) {
                if ($hook['point'] !== $point) {
                    continue;
                }
                $callbacks[] = [
                    'extension_id' => $extension['id'],
                    'class' => $hook['class'],
                    'method' => $hook['method'],
                    'priority' => $hook['priority'],
                    'order' => $hook['order'],
                ];
            }
        }
        usort($callbacks, static function (array $left, array $right): int {
            return [$left['priority'], $left['extension_id'], $left['order'], $left['class'], $left['method']]
                <=> [$right['priority'], $right['extension_id'], $right['order'], $right['class'], $right['method']];
        });
        return $callbacks;
    }

    private static function bootstrap(string $extensionId): void
    {
        if (isset(self::$bootstrapped[$extensionId])) {
            return;
        }
        $extension = Registry::extension($extensionId);
        $bootstrap = PathGuard::immutableFileWithin(
            $extension['root'],
            $extension['root'] . '/' . $extension['bootstrap']
        );
        $return = require_once $bootstrap;
        if ($return !== 1 && $return !== true && $return !== null) {
            throw new \RuntimeException('Trusted local extension bootstrap returned an invalid result.');
        }
        self::$bootstrapped[$extensionId] = true;
    }

    /** @return array{mixed,bool} */
    private static function collect(mixed $current, bool $hasCurrent, mixed $next): array
    {
        if (is_string($next) && (!$hasCurrent || is_string($current))) {
            return [($hasCurrent ? $current : '') . $next, true];
        }

        if (!$hasCurrent) {
            return [[$next], true];
        }
        if (is_array($current)) {
            $current[] = $next;
            return [$current, true];
        }
        return [[$current, $next], true];
    }
}
