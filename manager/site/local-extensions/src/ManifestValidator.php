<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\Manager;

final class ManifestValidator
{
    private const MAX_SETTINGS = 64;
    private const MAX_HOOKS = 128;

    /** @return array<string,mixed> */
    public static function plugin(array $manifest, string $registryId): array
    {
        self::onlyKeys($manifest, [
            'schema', 'id', 'name', 'version', 'description', 'type', 'namespace',
            'bootstrap', 'hooks', 'settings', 'assets',
        ]);

        $id = PathGuard::extensionId(self::shortString($manifest['id'] ?? null, 64));
        if ($id !== $registryId || ($manifest['schema'] ?? null) !== 1 || ($manifest['type'] ?? null) !== 'plugin') {
            throw new \RuntimeException('Local extension manifest identity mismatch.');
        }

        $namespace = self::shortString($manifest['namespace'] ?? null, 160);
        $expectedNamespace = 'Pika\\LocalExtensions\\' . $id . '\\';
        if ($namespace !== $expectedNamespace) {
            throw new \RuntimeException('Local extension namespace is not canonical.');
        }

        $bootstrap = self::shortString($manifest['bootstrap'] ?? null, 80);
        if ($bootstrap !== 'bootstrap.php') {
            throw new \RuntimeException('Local extension bootstrap must be bootstrap.php.');
        }

        $version = self::shortString($manifest['version'] ?? null, 32);
        if (preg_match('/^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?$/D', $version) !== 1) {
            throw new \RuntimeException('Local extension version is invalid.');
        }

        $hooks = $manifest['hooks'] ?? [];
        if (!is_array($hooks) || count($hooks) > self::MAX_HOOKS) {
            throw new \RuntimeException('Local extension hook list is invalid.');
        }

        $normalizedHooks = [];
        foreach (array_values($hooks) as $order => $hook) {
            if (!is_array($hook)) {
                throw new \RuntimeException('Local extension hook entry is invalid.');
            }
            self::onlyKeys($hook, ['point', 'class', 'method', 'priority']);
            $point = filter_var($hook['point'] ?? null, FILTER_VALIDATE_INT);
            $priority = filter_var($hook['priority'] ?? 100, FILTER_VALIDATE_INT);
            $class = self::shortString($hook['class'] ?? null, 200);
            $method = self::shortString($hook['method'] ?? null, 80);
            if ($point === false || $point < 1 || $point > 0x7fffffff
                || $priority === false || $priority < -1000 || $priority > 1000
                || !str_starts_with($class, $namespace)
                || preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/D', $class) !== 1
                || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $method) !== 1) {
                throw new \RuntimeException('Local extension hook contract is invalid.');
            }
            $normalizedHooks[] = [
                'point' => $point,
                'class' => $class,
                'method' => $method,
                'priority' => $priority,
                'order' => $order,
            ];
        }

        $settings = self::settings($manifest['settings'] ?? []);
        self::assets($manifest['assets'] ?? [], $id);

        return [
            'schema' => 1,
            'id' => $id,
            'name' => self::shortString($manifest['name'] ?? null, 100),
            'version' => $version,
            'description' => self::shortString($manifest['description'] ?? '', 500, true),
            'type' => 'plugin',
            'namespace' => $namespace,
            'bootstrap' => $bootstrap,
            'hooks' => $normalizedHooks,
            'settings' => $settings,
        ];
    }

    /** @return array<string,mixed> */
    public static function theme(array $manifest, string $registryId): array
    {
        self::onlyKeys($manifest, [
            'schema', 'id', 'name', 'version', 'type', 'theme_key', 'namespace',
            'entry', 'preserve', 'exclude',
        ]);

        $themeKey = PathGuard::themeId(self::shortString($manifest['theme_key'] ?? $manifest['id'] ?? null, 64));
        if ($themeKey !== $registryId || ($manifest['schema'] ?? null) !== 1 || ($manifest['type'] ?? null) !== 'theme') {
            throw new \RuntimeException('Local theme manifest identity mismatch.');
        }

        $version = self::shortString($manifest['version'] ?? null, 32);
        if (preg_match('/^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?$/D', $version) !== 1) {
            throw new \RuntimeException('Local theme version is invalid.');
        }

        foreach (['preserve', 'exclude'] as $listKey) {
            $list = $manifest[$listKey] ?? [];
            if (!is_array($list) || count($list) > 64) {
                throw new \RuntimeException('Local theme file list is invalid.');
            }
            foreach ($list as $relativePath) {
                if (!is_string($relativePath)
                    || preg_match('#^(?!/)(?!.*(?:^|/)\.\.(?:/|$))[A-Za-z0-9._/-]{1,160}$#D', $relativePath) !== 1) {
                    throw new \RuntimeException('Local theme relative path is invalid.');
                }
            }
        }

        return [
            'schema' => 1,
            'id' => $themeKey,
            'theme_key' => $themeKey,
            'name' => self::shortString($manifest['name'] ?? $themeKey, 100),
            'version' => $version,
            'type' => 'theme',
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function settings(mixed $settings): array
    {
        if (!is_array($settings) || count($settings) > self::MAX_SETTINGS) {
            throw new \RuntimeException('Local extension settings schema is invalid.');
        }

        $normalized = [];
        $keys = [];
        foreach (array_values($settings) as $setting) {
            if (!is_array($setting)) {
                throw new \RuntimeException('Local extension setting is invalid.');
            }
            self::onlyKeys($setting, ['key', 'label', 'type', 'required', 'default', 'min', 'max', 'options']);
            $key = self::shortString($setting['key'] ?? null, 64);
            $type = self::shortString($setting['type'] ?? null, 16);
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key) !== 1
                || isset($keys[$key])
                || !in_array($type, ['text', 'password', 'number', 'select', 'checkbox'], true)) {
                throw new \RuntimeException('Local extension setting key or type is invalid.');
            }
            $keys[$key] = true;

            $entry = [
                'key' => $key,
                'label' => self::shortString($setting['label'] ?? null, 80),
                'type' => $type,
                'required' => ($setting['required'] ?? false) === true,
            ];

            if ($type === 'text' || $type === 'password') {
                $min = self::boundedInt($setting['min'] ?? 0, 0, 4096);
                $max = self::boundedInt($setting['max'] ?? 4096, 1, 4096);
                if ($min > $max) {
                    throw new \RuntimeException('Local extension text setting bounds are invalid.');
                }
                $entry['min'] = $min;
                $entry['max'] = $max;
            } elseif ($type === 'number') {
                $min = self::finiteNumber($setting['min'] ?? -1000000000);
                $max = self::finiteNumber($setting['max'] ?? 1000000000);
                if ($min > $max) {
                    throw new \RuntimeException('Local extension number setting bounds are invalid.');
                }
                $entry['min'] = $min;
                $entry['max'] = $max;
            } elseif ($type === 'select') {
                $options = $setting['options'] ?? null;
                if (!is_array($options) || count($options) < 1 || count($options) > 100) {
                    throw new \RuntimeException('Local extension select options are invalid.');
                }
                $entry['options'] = [];
                foreach (array_values($options) as $option) {
                    if (!is_array($option)) {
                        throw new \RuntimeException('Local extension select option is invalid.');
                    }
                    self::onlyKeys($option, ['value', 'label']);
                    if (!is_scalar($option['value'] ?? null)) {
                        throw new \RuntimeException('Local extension select value is invalid.');
                    }
                    $entry['options'][] = [
                        'value' => self::shortString((string)$option['value'], 128, true),
                        'label' => self::shortString($option['label'] ?? null, 80),
                    ];
                }
            }

            if (array_key_exists('default', $setting)) {
                $entry['default'] = $setting['default'];
            }
            $normalized[] = $entry;
        }

        return $normalized;
    }

    private static function assets(mixed $assets, string $id): void
    {
        if (!is_array($assets) || count($assets) > 8) {
            throw new \RuntimeException('Local extension asset declaration is invalid.');
        }
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                throw new \RuntimeException('Local extension asset declaration is invalid.');
            }
            self::onlyKeys($asset, ['source', 'target']);
            if (($asset['source'] ?? null) !== 'Assets'
                || ($asset['target'] ?? null) !== 'assets/local-extensions/' . $id) {
                throw new \RuntimeException('Local extension asset target is not canonical.');
            }
        }
    }

    private static function onlyKeys(array $value, array $allowed): void
    {
        if (array_diff(array_keys($value), $allowed) !== []) {
            throw new \RuntimeException('Local extension metadata contains unsupported fields.');
        }
    }

    private static function shortString(mixed $value, int $max, bool $allowEmpty = false): string
    {
        if (!is_string($value) || (!$allowEmpty && $value === '') || strlen($value) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            throw new \RuntimeException('Local extension metadata string is invalid.');
        }
        return $value;
    }

    private static function boundedInt(mixed $value, int $min, int $max): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT);
        if ($validated === false || $validated < $min || $validated > $max) {
            throw new \RuntimeException('Local extension setting integer bound is invalid.');
        }
        return $validated;
    }

    private static function finiteNumber(mixed $value): int|float
    {
        if (!is_int($value) && !is_float($value)) {
            throw new \RuntimeException('Local extension setting numeric bound is invalid.');
        }
        if (!is_finite((float)$value)) {
            throw new \RuntimeException('Local extension setting numeric bound is invalid.');
        }
        return $value;
    }
}
