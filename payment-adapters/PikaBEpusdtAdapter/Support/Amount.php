<?php
declare(strict_types=1);

namespace App\Pay\PikaBEpusdtAdapter\Support;

final class Amount
{
    private const MAX_STRING = 16;

    public static function normalize(mixed $value): ?string
    {
        if (is_bool($value) || $value === null || is_array($value) || is_object($value) || is_resource($value)) {
            return null;
        }

        if (is_int($value)) {
            $raw = (string)$value;
        } elseif (is_float($value)) {
            if (!is_finite($value)) {
                return null;
            }
            $encoded = json_encode($value, JSON_PRESERVE_ZERO_FRACTION);
            if (!is_string($encoded) || strpbrk($encoded, 'eE') !== false) {
                return null;
            }
            $raw = $encoded;
        } elseif (is_string($value)) {
            $raw = $value;
        } else {
            return null;
        }

        if ($raw === '' || strlen($raw) > self::MAX_STRING || trim($raw) !== $raw) {
            return null;
        }
        if (preg_match('/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?$/D', $raw, $match) !== 1) {
            return null;
        }

        return $match[1] . '.' . str_pad($match[2] ?? '', 2, '0');
    }

    public static function isPositive(string $amount): bool
    {
        return !hash_equals('0.00', $amount);
    }
}
