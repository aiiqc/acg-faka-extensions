<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\Manager;

final class PathGuard
{
    private const STATE_BASE = '/var/lib/pika-local-extensions/sites';
    public const EXTENSION_ID_PATTERN = '/^[A-Z][A-Za-z0-9]{1,63}$/D';
    public const THEME_ID_PATTERN = '/^[A-Z][A-Za-z0-9]{1,63}$/D';

    public static function extensionId(string $id): string
    {
        if (preg_match(self::EXTENSION_ID_PATTERN, $id) !== 1) {
            throw new \RuntimeException('Invalid local extension identifier.');
        }

        return $id;
    }

    public static function themeId(string $id): string
    {
        if (preg_match(self::THEME_ID_PATTERN, $id) !== 1) {
            throw new \RuntimeException('Invalid local theme identifier.');
        }

        return $id;
    }

    public static function regularFileWithin(string $root, string $candidate): string
    {
        $realRoot = realpath($root);
        $realFile = realpath($candidate);

        if ($realRoot === false || $realFile === false || !is_dir($realRoot) || !is_file($realFile)) {
            throw new \RuntimeException('Trusted local extension file is missing.');
        }

        if (is_link($candidate) || !str_starts_with($realFile, $realRoot . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Trusted local extension path escaped its allowed root.');
        }

        return $realFile;
    }

    public static function immutableFileWithin(string $root, string $candidate): string
    {
        $realRoot = realpath($root);
        $realFile = self::regularFileWithin($root, $candidate);
        if ($realRoot === false || is_link($root)) {
            throw new \RuntimeException('Trusted local extension root is unsafe.');
        }

        $stat = lstat($realFile);
        if (!is_array($stat)
            || ((int)($stat['mode'] ?? 0) & 0170000) !== 0100000
            || ((int)($stat['mode'] ?? 0) & 0777) !== 0644
            || (int)($stat['uid'] ?? -1) !== 0
            || (int)($stat['gid'] ?? -1) !== 0
            || (int)($stat['nlink'] ?? 0) !== 1) {
            throw new \RuntimeException('Trusted local extension file identity is unsafe.');
        }

        $cursor = dirname($realFile);
        while (true) {
            $permissions = fileperms($cursor);
            if (is_link($cursor) || !is_dir($cursor)
                || fileowner($cursor) !== 0 || filegroup($cursor) !== 0
                || $permissions === false || ($permissions & 0777) !== 0755) {
                throw new \RuntimeException('Trusted local extension ancestry is unsafe.');
            }
            if ($cursor === $realRoot) {
                break;
            }
            $parent = dirname($cursor);
            if ($parent === $cursor || !str_starts_with($parent . '/', $realRoot . '/')) {
                throw new \RuntimeException('Trusted local extension ancestry escaped its root.');
            }
            $cursor = $parent;
        }
        return $realFile;
    }

    public static function assertNotGroupOrWorldWritable(string $path): void
    {
        $permissions = fileperms($path);
        if ($permissions === false || ($permissions & 0o022) !== 0) {
            throw new \RuntimeException('Trusted local extension metadata has unsafe permissions.');
        }
    }

    public static function siteRoot(): string
    {
        if (!defined('BASE_PATH')) {
            throw new \RuntimeException('Acg-Faka BASE_PATH is unavailable.');
        }

        $root = realpath((string)constant('BASE_PATH'));
        if ($root === false || !is_dir($root)) {
            throw new \RuntimeException('Acg-Faka site root is unavailable.');
        }

        return rtrim($root, DIRECTORY_SEPARATOR);
    }

    public static function stateRoot(): string
    {
        $base = self::stateBase();
        $controlRoot = dirname($base);
        self::assertRootDirectory($controlRoot, 0o755);
        self::assertRootDirectory($base, 0o755);
        $realBase = realpath($base);
        if ($realBase === false || $realBase !== $base || !is_dir($realBase) || is_link($base)) {
            throw new \RuntimeException('Local extension state base is unavailable.');
        }

        $siteDirectory = $realBase . DIRECTORY_SEPARATOR . hash('sha256', self::siteRoot());
        $runtime = $siteDirectory . DIRECTORY_SEPARATOR . 'runtime';
        $realRuntime = realpath($runtime);
        if (
            $realRuntime === false
            || !is_dir($realRuntime)
            || is_link($siteDirectory)
            || is_link($runtime)
            || $realRuntime !== $runtime
        ) {
            throw new \RuntimeException('Local extension state directory is unavailable or unsafe.');
        }

        self::assertNotGroupOrWorldWritable($realBase);
        self::assertRootDirectory($siteDirectory, 0o755);
        $permissions = fileperms($realRuntime);
        $owner = fileowner($realRuntime);
        if ($permissions === false
            || ($permissions & 0o777) !== 0o750
            || $owner === false
            || $owner === 0
            || !is_writable($realRuntime)) {
            throw new \RuntimeException('Local extension runtime permissions are unsafe.');
        }

        return $realRuntime;
    }

    public static function stateDirectory(string $relative, int $mode = 0o750): string
    {
        if (
            $relative === ''
            || preg_match('#^[A-Za-z0-9._/-]+$#D', $relative) !== 1
            || !in_array($mode, [0o700, 0o750], true)
        ) {
            throw new \RuntimeException('Invalid local extension state directory.');
        }
        $parts = array_values(array_filter(explode('/', trim($relative, '/')), 'strlen'));
        if ($parts === [] || in_array('.', $parts, true) || in_array('..', $parts, true)) {
            throw new \RuntimeException('Invalid local extension state directory.');
        }

        $root = self::stateRoot();
        $current = $root;
        foreach ($parts as $part) {
            $current .= DIRECTORY_SEPARATOR . $part;
            if (is_link($current)) {
                throw new \RuntimeException('Local extension state directory contains a symbolic link.');
            }
            if (!is_dir($current) && !mkdir($current, $mode) && !is_dir($current)) {
                throw new \RuntimeException('Unable to create local extension state directory.');
            }
            if (!is_dir($current) || is_link($current)) {
                throw new \RuntimeException('Local extension state directory is unsafe.');
            }
        }

        $real = realpath($current);
        if ($real === false || !str_starts_with($real . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Local extension state directory escaped its allowed root.');
        }
        if (!chmod($real, $mode)) {
            throw new \RuntimeException('Unable to set local extension state directory permissions.');
        }
        clearstatcache(true, $real);
        if (fileowner($real) !== self::runtimeOwner()
            || (fileperms($real) & 0o777) !== $mode
            || !is_writable($real)) {
            throw new \RuntimeException('Local extension state directory permissions are unsafe.');
        }
        return $real;
    }

    public static function runtimeOwner(): int
    {
        $owner = fileowner(self::stateRoot());
        if ($owner === false || $owner === 0) {
            throw new \RuntimeException('Local extension runtime owner is unsafe.');
        }
        return $owner;
    }

    private static function stateBase(): string
    {
        return self::STATE_BASE;
    }

    private static function assertRootDirectory(string $path, int $mode): void
    {
        $permissions = fileperms($path);
        if (realpath($path) !== $path
            || !is_dir($path)
            || is_link($path)
            || fileowner($path) !== 0
            || filegroup($path) !== 0
            || $permissions === false
            || ($permissions & 0o777) !== $mode) {
            throw new \RuntimeException('Local extension state control directory is unsafe.');
        }
    }
}
