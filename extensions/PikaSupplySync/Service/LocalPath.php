<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaSupplySync\Service;

use Pika\LocalExtensions\Manager\PathGuard;
use RuntimeException;

final class LocalPath
{
    public static function directory(string $relative, int $mode): string
    {
        if (
            !defined('BASE_PATH')
            || preg_match('#^[A-Za-z0-9._/-]+$#D', $relative) !== 1
            || !in_array($mode, [0700, 0755], true)
        ) {
            throw new RuntimeException('扩展目录参数不正确');
        }
        $parts = array_values(array_filter(explode('/', trim($relative, '/')), 'strlen'));
        if ($parts === [] || in_array('.', $parts, true) || in_array('..', $parts, true)) {
            throw new RuntimeException('扩展目录参数不正确');
        }
        $runtimePrefix = ['runtime', 'local-extensions'];
        $isRuntime = array_slice($parts, 0, 2) === $runtimePrefix;
        if ($isRuntime) {
            if (!class_exists(PathGuard::class)) {
                throw new RuntimeException('LocalExtensions 运行目录管理器不可用');
            }
            $root = PathGuard::stateRoot();
            $parts = array_slice($parts, 2);
        } else {
            $root = realpath((string)BASE_PATH);
            if ($root === false || !is_dir($root)) {
                throw new RuntimeException('项目运行目录不可用');
            }
        }
        if ($parts === []) {
            return $root;
        }
        $current = $root;
        foreach ($parts as $part) {
            $current .= DIRECTORY_SEPARATOR . $part;
            if (is_link($current)) {
                throw new RuntimeException('扩展目录不能包含符号链接');
            }
            if (!is_dir($current) && !mkdir($current, $mode) && !is_dir($current)) {
                throw new RuntimeException('无法创建扩展目录');
            }
            if (!is_dir($current) || is_link($current)) {
                throw new RuntimeException('扩展目录类型不正确');
            }
        }
        $real = realpath($current);
        if ($real === false || !str_starts_with($real . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('扩展目录越出允许的根目录');
        }
        if (!chmod($real, $mode)) {
            throw new RuntimeException('无法设置扩展目录权限');
        }
        return $real;
    }
}
