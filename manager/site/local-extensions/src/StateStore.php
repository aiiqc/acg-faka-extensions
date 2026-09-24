<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\Manager;

final class StateStore
{
    private const DEFAULT_STATE = ['schema' => 1, 'extensions' => []];
    /** @var array<string,mixed>|null */
    private static ?array $cache = null;

    public static function isEnabled(string $id, bool $fresh = false): bool
    {
        Registry::extension($id);
        $state = self::read($fresh);
        return ($state['extensions'][$id]['enabled'] ?? false) === true;
    }

    public static function setEnabled(string $id, bool $enabled): void
    {
        Registry::extension($id);
        self::$cache = AtomicJson::update(self::path(), self::DEFAULT_STATE, static function (array $state) use ($id, $enabled): array {
            self::validate($state);
            $state['extensions'][$id] = [
                'enabled' => $enabled,
                'updated_at' => gmdate('c'),
            ];
            ksort($state['extensions'], SORT_STRING);
            return $state;
        });
    }

    /** @return array<string,mixed> */
    private static function read(bool $fresh = false): array
    {
        if (!$fresh && self::$cache !== null) {
            return self::$cache;
        }
        $state = AtomicJson::read(self::path(), self::DEFAULT_STATE);
        self::validate($state);
        self::$cache = $state;
        return self::$cache;
    }

    private static function validate(array $state): void
    {
        if (($state['schema'] ?? null) !== 1 || !is_array($state['extensions'] ?? null)
            || array_diff(array_keys($state), ['schema', 'extensions']) !== []) {
            throw new \RuntimeException('Local extension state schema is invalid.');
        }
        foreach ($state['extensions'] as $id => $entry) {
            PathGuard::extensionId((string)$id);
            if (!is_array($entry) || !is_bool($entry['enabled'] ?? null)
                || !is_string($entry['updated_at'] ?? null)
                || array_diff(array_keys($entry), ['enabled', 'updated_at']) !== []) {
                throw new \RuntimeException('Local extension state entry is invalid.');
            }
        }
    }

    private static function path(): string
    {
        return PathGuard::stateRoot() . '/state.json';
    }
}
