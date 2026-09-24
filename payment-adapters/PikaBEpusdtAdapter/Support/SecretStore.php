<?php
declare(strict_types=1);

namespace App\Pay\PikaBEpusdtAdapter\Support;

use Kernel\Exception\JSONException;

final class SecretStore
{
    private const STATE_ROOT = '/var/lib/pika-local-extensions';
    private const MAX_TOKEN_BYTES = 256;
    private const MAX_NAMESPACE_BYTES = 12;

    /** @return array{token:string,namespace:string} */
    public static function load(): array
    {
        $directory = self::directory();
        $user = self::effectiveUser();
        $group = self::effectiveGroup();
        self::assertProcessGroups($group);
        self::assertDirectory(self::STATE_ROOT, 0, 0, 0755);
        self::assertDirectory(self::STATE_ROOT . '/sites', 0, 0, 0755);
        self::assertDirectory(dirname($directory), 0, 0, 0755);
        self::assertDirectory(dirname($directory) . '/runtime', $user, $group, 0750);
        self::assertDirectory($directory, 0, $group, 0750);

        $token = self::readFile($directory . '/bepusdt-token', $group, self::MAX_TOKEN_BYTES);
        $namespace = self::readFile(
            $directory . '/bepusdt-namespace',
            $group,
            self::MAX_NAMESPACE_BYTES,
        );
        if (strlen($token) < 16
            || preg_match('/^[\x21-\x7e]{16,256}$/D', $token) !== 1
            || preg_match('/^[a-z0-9]{4,12}$/D', $namespace) !== 1) {
            throw new JSONException(Gateway::ERR_SECRET);
        }
        return ['token' => $token, 'namespace' => $namespace];
    }

    public static function directory(): string
    {
        if (!defined('BASE_PATH')) {
            throw new JSONException(Gateway::ERR_SECRET);
        }
        $root = realpath((string)constant('BASE_PATH'));
        if (!is_string($root) || $root === '' || !str_starts_with($root, '/')) {
            throw new JSONException(Gateway::ERR_SECRET);
        }
        $canonical = rtrim($root, '/');
        if ($canonical === '') {
            throw new JSONException(Gateway::ERR_SECRET);
        }
        return self::STATE_ROOT . '/sites/' . hash('sha256', $canonical) . '/secrets';
    }

    private static function effectiveGroup(): int
    {
        if (!function_exists('posix_getegid')) {
            throw new JSONException(Gateway::ERR_SECRET);
        }
        $group = posix_getegid();
        if (!is_int($group) || $group < 0) {
            throw new JSONException(Gateway::ERR_SECRET);
        }
        return $group;
    }

    private static function effectiveUser(): int
    {
        if (!function_exists('posix_geteuid')) {
            throw new JSONException(Gateway::ERR_SECRET);
        }
        $user = posix_geteuid();
        if (!is_int($user) || $user < 0) {
            throw new JSONException(Gateway::ERR_SECRET);
        }
        return $user;
    }

    private static function assertProcessGroups(int $primary): void
    {
        if (!function_exists('posix_getgroups')) {
            throw new JSONException(Gateway::ERR_SECRET);
        }
        $groups = posix_getgroups();
        if (!is_array($groups)) {
            throw new JSONException(Gateway::ERR_SECRET);
        }
        foreach ($groups as $group) {
            if (!is_int($group) || $group !== $primary) {
                throw new JSONException(Gateway::ERR_SECRET);
            }
        }
    }

    private static function assertDirectory(string $path, int $uid, int $gid, int $mode): void
    {
        clearstatcache(true, $path);
        if (is_link($path) || !is_dir($path)) {
            throw new JSONException(Gateway::ERR_SECRET);
        }
        $stat = lstat($path);
        if (!is_array($stat)
            || !isset($stat['mode'], $stat['uid'], $stat['gid'])
            || ((int)$stat['mode'] & 0170000) !== 0040000
            || ((int)$stat['mode'] & 0777) !== $mode
            || (int)$stat['uid'] !== $uid
            || (int)$stat['gid'] !== $gid) {
            throw new JSONException(Gateway::ERR_SECRET);
        }
    }

    private static function readFile(string $path, int $gid, int $limit): string
    {
        clearstatcache(true, $path);
        if (is_link($path) || !is_file($path)) {
            throw new JSONException(Gateway::ERR_SECRET);
        }
        $before = lstat($path);
        if (!is_array($before)) {
            throw new JSONException(Gateway::ERR_SECRET);
        }
        self::assertFileStat($before, $gid, $limit);

        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new JSONException(Gateway::ERR_SECRET);
        }
        try {
            $opened = fstat($handle);
            if (!is_array($opened)) {
                throw new JSONException(Gateway::ERR_SECRET);
            }
            self::assertFileStat($opened, $gid, $limit);
            if ((int)$opened['dev'] !== (int)$before['dev']
                || (int)$opened['ino'] !== (int)$before['ino']) {
                throw new JSONException(Gateway::ERR_SECRET);
            }
            $value = stream_get_contents($handle, $limit + 1);
            if (!is_string($value) || $value === '' || strlen($value) > $limit) {
                throw new JSONException(Gateway::ERR_SECRET);
            }
            $after = lstat($path);
            if (!is_array($after)
                || (int)$after['dev'] !== (int)$before['dev']
                || (int)$after['ino'] !== (int)$before['ino']) {
                throw new JSONException(Gateway::ERR_SECRET);
            }
            return $value;
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string|int,mixed> $stat */
    private static function assertFileStat(array $stat, int $gid, int $limit): void
    {
        foreach (['mode', 'uid', 'gid', 'nlink', 'size', 'dev', 'ino'] as $key) {
            if (!isset($stat[$key])) {
                throw new JSONException(Gateway::ERR_SECRET);
            }
        }
        if (((int)$stat['mode'] & 0170000) !== 0100000
            || ((int)$stat['mode'] & 0777) !== 0640
            || (int)$stat['uid'] !== 0
            || (int)$stat['gid'] !== $gid
            || (int)$stat['nlink'] !== 1
            || (int)$stat['size'] < 1
            || (int)$stat['size'] > $limit) {
            throw new JSONException(Gateway::ERR_SECRET);
        }
    }
}
