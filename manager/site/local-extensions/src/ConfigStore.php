<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\Manager;

final class ConfigStore
{
    /** @return array<string,mixed> */
    public static function get(string $id): array
    {
        $extension = Registry::extension($id);
        $config = AtomicJson::read(self::path($id), ['schema' => 1, 'values' => []]);
        self::validateEnvelope($config);
        return self::withDefaults($extension['settings'], $config['values']);
    }

    /** @return array{values:array<string,mixed>,password_configured:array<string,bool>} */
    public static function publicView(string $id): array
    {
        $extension = Registry::extension($id);
        $values = self::get($id);
        $passwordConfigured = [];
        foreach ($extension['settings'] as $setting) {
            if ($setting['type'] === 'password') {
                $passwordConfigured[$setting['key']] = isset($values[$setting['key']]) && $values[$setting['key']] !== '';
                $values[$setting['key']] = '';
            }
        }
        return ['values' => $values, 'password_configured' => $passwordConfigured];
    }

    /** @param array<string,mixed> $input */
    public static function save(string $id, array $input): void
    {
        $extension = Registry::extension($id);
        $settings = $extension['settings'];
        $allowed = array_column($settings, null, 'key');
        if (array_diff(array_keys($input), array_keys($allowed)) !== []) {
            throw new \RuntimeException('Unsupported local extension setting.');
        }
        $syncKeys = $id === 'PikaSupplySync'
            ? ['sync_name', 'sync_cover', 'sync_description', 'sync_price', 'sync_inventory', 'sync_options'] : [];
        $submittedSyncKeys = array_intersect($syncKeys, array_keys($input));
        if ($submittedSyncKeys !== [] && count($submittedSyncKeys) !== count($syncKeys)) {
            throw new \RuntimeException('请完整保存六项同步选择');
        }
        foreach ($submittedSyncKeys as $key) self::normalizeValue($allowed[$key], $input[$key]);

        AtomicJson::update(self::path($id), ['schema' => 1, 'values' => []], static function (array $envelope) use ($id, $syncKeys, $settings, $input): array {
            self::validateEnvelope($envelope);
            $current = self::withDefaults($settings, $envelope['values']);
            $next = [];
            foreach ($settings as $setting) {
                $key = $setting['key'];
                $submitted = array_key_exists($key, $input);
                if (in_array($key, $syncKeys, true) && !$submitted && !array_key_exists($key, $current)) {
                    continue;
                }
                $raw = $submitted ? $input[$key] : ($current[$key] ?? ($setting['default'] ?? null));
                if ($setting['type'] === 'password' && (!$submitted || $raw === '')) {
                    $raw = $current[$key] ?? '';
                }
                $next[$key] = self::normalizeValue($setting, $raw);
            }
            if ($id === 'PikaSupplySync') {
                $selected = count(array_intersect($syncKeys, array_keys($next)));
                if ($selected !== 0 && $selected !== count($syncKeys)) {
                    throw new \RuntimeException('请完整保存六项同步选择');
                }
                if (($next['follow_upstream_config'] ?? false) === true) {
                    if ($selected !== count($syncKeys)) {
                        throw new \RuntimeException('完整跟随配置需要完整保存六项同步选择');
                    }
                    $sourceIds = self::followSourceIds($next['follow_upstream_config_source_ids']);
                    if (($current['follow_upstream_config'] ?? false) === true && !array_key_exists('follow_upstream_config', $input)
                        && array_diff($sourceIds, self::followSourceIds($current['follow_upstream_config_source_ids'])) !== []) {
                        throw new \RuntimeException('扩大完整跟随货源范围时请明确确认完整跟随配置');
                    }
                }
            }
            return ['schema' => 1, 'values' => $next];
        });
    }

    /** @param list<array<string,mixed>> $settings @param array<string,mixed> $values */
    private static function withDefaults(array $settings, array $values): array
    {
        $result = [];
        foreach ($settings as $setting) {
            $key = $setting['key'];
            if (array_key_exists($key, $values)) {
                $result[$key] = self::normalizeValue($setting, $values[$key]);
            } elseif (array_key_exists('default', $setting)) {
                $result[$key] = self::normalizeValue($setting, $setting['default']);
            }
        }
        return $result;
    }

    private static function normalizeValue(array $setting, mixed $raw): mixed
    {
        return match ($setting['type']) {
            'text', 'password' => self::textValue($setting, $raw),
            'number' => self::numberValue($setting, $raw),
            'select' => self::selectValue($setting, $raw),
            'checkbox' => self::checkboxValue($raw),
            default => throw new \RuntimeException('Unsupported local extension setting type.'),
        };
    }

    private static function textValue(array $setting, mixed $raw): string
    {
        if (!is_string($raw) && !is_int($raw) && !is_float($raw)) {
            throw new \RuntimeException('Local extension text setting is invalid.');
        }
        $value = (string)$raw;
        $length = strlen($value);
        if ($length < $setting['min'] || $length > $setting['max']
            || ($setting['required'] && $value === '')
            || preg_match('/[\x00]/', $value)) {
            throw new \RuntimeException('Local extension text setting is outside its allowed bounds.');
        }
        return $value;
    }

    private static function numberValue(array $setting, mixed $raw): int|float
    {
        if (!is_numeric($raw) || !is_finite((float)$raw)) {
            throw new \RuntimeException('Local extension number setting is invalid.');
        }
        $value = str_contains((string)$raw, '.') ? (float)$raw : (int)$raw;
        if ($value < $setting['min'] || $value > $setting['max']) {
            throw new \RuntimeException('Local extension number setting is outside its allowed bounds.');
        }
        return $value;
    }

    private static function selectValue(array $setting, mixed $raw): string
    {
        if (!is_scalar($raw)) {
            throw new \RuntimeException('Local extension select setting is invalid.');
        }
        $value = (string)$raw;
        foreach ($setting['options'] as $option) {
            if ($value === $option['value']) {
                return $value;
            }
        }
        throw new \RuntimeException('Local extension select setting is not in its allowlist.');
    }

    private static function checkboxValue(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        if (in_array($raw, [1, '1', 'true', 'on'], true)) {
            return true;
        }
        if (in_array($raw, [0, '0', 'false', 'off', '', null], true)) {
            return false;
        }
        throw new \RuntimeException('Local extension checkbox setting is invalid.');
    }

    /** @return list<int> */
    private static function followSourceIds(string $value): array
    {
        $candidates = trim($value) === '' ? [] : explode(',', $value);
        if ($candidates === [] || count($candidates) > 100) {
            throw new \RuntimeException('完整跟随配置需要明确填写 1 至 100 个共享店铺 ID');
        }
        $ids = [];
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '' || !ctype_digit($candidate) || (int)$candidate < 1) {
                throw new \RuntimeException('完整跟随共享店铺 ID 只能包含英文逗号分隔的正整数');
            }
            $ids[(int)$candidate] = (int)$candidate;
        }
        ksort($ids, SORT_NUMERIC);
        return array_values($ids);
    }

    private static function validateEnvelope(array $envelope): void
    {
        if (($envelope['schema'] ?? null) !== 1 || !is_array($envelope['values'] ?? null)
            || array_diff(array_keys($envelope), ['schema', 'values']) !== []) {
            throw new \RuntimeException('Local extension config schema is invalid.');
        }
    }

    private static function path(string $id): string
    {
        return PathGuard::stateDirectory('config') . '/' . PathGuard::extensionId($id) . '.json';
    }
}
