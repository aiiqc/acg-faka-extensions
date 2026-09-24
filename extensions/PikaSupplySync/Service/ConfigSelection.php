<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaSupplySync\Service;

/** Project only the native price trees and the V4 fulfillment mapping. */
final class ConfigSelection
{
    private const PRICE_DEPTH = [
        'category' => 1,
        'wholesale' => 1,
        'sku' => 2,
        'category_wholesale' => 2,
        'category_cost' => 1,
        'sku_cost' => 2,
    ];

    /**
     * Inputs are parsed native INI; remote prices have already been normalized
     * and adjusted. This returns config only, never stock/shared_stock fields.
     * Unknown sections stay local by default. Explicit full ownership can follow
     * the normalized remote tree only with both selections and valid known trees.
     * Callers enforce source ownership and retain other field projections.
     *
     * @return array{config:array,held:bool}
     */
    public static function project(array $local, array $remoteAdjusted, bool $price, bool $options,
        bool $followUpstreamConfig = false): array
    {
        if (!$price && !$options) {
            return ['config' => $local, 'held' => false];
        }
        if (!self::valid($remoteAdjusted) || ((!$price || !$options) && !self::valid($local))
            || ($followUpstreamConfig && $price && $options && !self::validFullSnapshot($remoteAdjusted))) {
            return ['config' => $local, 'held' => true];
        }

        $held = self::hasUnknownSections($local) || self::hasUnknownSections($remoteAdjusted);
        $config = $local;
        if ($price && $options) {
            $knownSections = self::PRICE_DEPTH + ['shared_mapping' => 1];
            // The validation above is shared by both policies; opaque values are
            // already normalized by the caller and must not receive arithmetic.
            if ($followUpstreamConfig) {
                $config = $remoteAdjusted;
                $held = false;
            } else {
                $held = array_diff_key($local, $knownSections)
                    !== array_diff_key($remoteAdjusted, $knownSections);
            }
            foreach ($knownSections as $section => $unused) {
                if (array_key_exists($section, $remoteAdjusted)) {
                    $config[$section] = $remoteAdjusted[$section];
                } else {
                    unset($config[$section]);
                }
            }
            return ['config' => $config, 'held' => $held];
        }

        if ($options) {
            // Deletions are also held: deleting a price-bearing option or tier
            // would remove protected local pricing. No partial option set is
            // installed when any exact key/fulfillment identity cannot match.
            foreach (self::PRICE_DEPTH as $section => $depth) {
                if (array_key_exists($section, $local) !== array_key_exists($section, $remoteAdjusted)
                    || !self::sameKeys($local[$section] ?? [], $remoteAdjusted[$section] ?? [], $depth)) {
                    return ['config' => $local, 'held' => true];
                }
            }
            if (!self::sameMapping($local, $remoteAdjusted)) {
                return ['config' => $local, 'held' => true];
            }
            foreach (self::PRICE_DEPTH as $section => $depth) {
                if (array_key_exists($section, $remoteAdjusted)) {
                    $config[$section] = self::retainAmounts($local[$section], $remoteAdjusted[$section], $depth);
                }
            }
            if (array_key_exists('shared_mapping', $remoteAdjusted)) {
                $config['shared_mapping'] = $remoteAdjusted['shared_mapping'];
            }
            return ['config' => $config, 'held' => $held];
        }

        if (!self::sameMapping($local, $remoteAdjusted)) {
            $held = true;
        }
        foreach (self::PRICE_DEPTH as $section => $depth) {
            if (array_key_exists($section, $local) !== array_key_exists($section, $remoteAdjusted)) {
                $held = true;
                continue;
            }
            if (!array_key_exists($section, $local)) {
                continue;
            }
            if (!self::sameKeys($local[$section], $remoteAdjusted[$section], $depth)) {
                $held = true;
            }
            foreach ($local[$section] as $key => $value) {
                if (!array_key_exists($key, $remoteAdjusted[$section])
                    || (in_array($section, ['category', 'category_cost', 'category_wholesale'], true)
                        && !self::sameCategoryIdentity($key, $local, $remoteAdjusted))) {
                    $held = true;
                    continue;
                }
                if ($depth === 1) {
                    $config[$section][$key] = $remoteAdjusted[$section][$key];
                    continue;
                }
                foreach ($value as $option => $amount) {
                    if (array_key_exists($option, $remoteAdjusted[$section][$key])) {
                        $config[$section][$key][$option] = $remoteAdjusted[$section][$key][$option];
                    } else {
                        $held = true;
                    }
                }
            }
        }
        return ['config' => $config, 'held' => $held];
    }

    /** Validate before INI serialization can silently omit scalar top-level sections. */
    public static function validFullSnapshot(array $config): bool
    {
        foreach ($config as $section) {
            if (!is_array($section)) return false;
        }
        return self::valid($config);
    }

    private static function valid(array $config): bool
    {
        foreach (self::PRICE_DEPTH as $section => $depth) {
            // Native valuation ignores non-positive SKU surcharges and costs;
            // preserve finite negative sentinels only in those two trees.
            $allowNegative = in_array($section, ['sku', 'sku_cost'], true);
            if (array_key_exists($section, $config) && !self::validAmounts($config[$section], $depth, $allowNegative)) {
                return false;
            }
        }
        if (array_key_exists('shared_mapping', $config)) {
            if (!is_array($config['shared_mapping'])) {
                return false;
            }
            foreach ($config['shared_mapping'] as $name => $identity) {
                if ((!is_string($identity) && !is_int($identity)) || (string)$identity === ''
                    || !array_key_exists($name, $config['category'] ?? [])) {
                    return false;
                }
            }
            if (!self::sameKeys($config['shared_mapping'], $config['category'] ?? [], 1)) {
                return false;
            }
        }
        return true;
    }

    private static function validAmounts(mixed $values, int $depth, bool $allowNegative): bool
    {
        if (!is_array($values)) {
            return false;
        }
        foreach ($values as $amount) {
            if ($depth > 1) {
                if (!self::validAmounts($amount, $depth - 1, $allowNegative)) {
                    return false;
                }
            } elseif ((!is_string($amount) && !is_int($amount) && !is_float($amount))
                || !is_numeric($amount) || !is_finite((float)$amount) || (!$allowNegative && (float)$amount < 0)) {
                return false;
            }
        }
        return true;
    }

    private static function hasUnknownSections(array $config): bool
    {
        foreach ($config as $section => $unused) {
            if (!array_key_exists($section, self::PRICE_DEPTH) && $section !== 'shared_mapping') {
                return true;
            }
        }
        return false;
    }

    private static function sameKeys(array $local, array $remote, int $depth): bool
    {
        if (count($local) !== count($remote)) {
            return false;
        }
        foreach ($local as $key => $value) {
            if (!array_key_exists($key, $remote)
                || ($depth > 1 && !self::sameKeys($value, $remote[$key], $depth - 1))) {
                return false;
            }
        }
        return true;
    }

    private static function sameMapping(array $local, array $remote): bool
    {
        if (array_key_exists('shared_mapping', $local) !== array_key_exists('shared_mapping', $remote)
            || !self::sameKeys($local['shared_mapping'] ?? [], $remote['shared_mapping'] ?? [], 1)) {
            return false;
        }
        foreach ($local['shared_mapping'] ?? [] as $name => $identity) {
            if ($identity !== $remote['shared_mapping'][$name]) {
                return false;
            }
        }
        return true;
    }

    private static function sameCategoryIdentity(string|int $name, array $local, array $remote): bool
    {
        $localMapped = array_key_exists('shared_mapping', $local);
        $remoteMapped = array_key_exists('shared_mapping', $remote);
        if (!$localMapped && !$remoteMapped) {
            return true;
        }
        return $localMapped && $remoteMapped
            && array_key_exists($name, $local['shared_mapping'])
            && array_key_exists($name, $remote['shared_mapping'])
            && $local['shared_mapping'][$name] === $remote['shared_mapping'][$name];
    }

    private static function retainAmounts(array $local, array $remote, int $depth): array
    {
        $result = [];
        foreach ($remote as $key => $value) {
            $result[$key] = $depth > 1
                ? self::retainAmounts($local[$key], $value, $depth - 1)
                : $local[$key];
        }
        return $result;
    }
}
