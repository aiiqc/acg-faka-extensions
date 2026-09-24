<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaSupplySync\Service;

use RuntimeException;

/** Only bounded enums and numbers may cross the supplier diagnostic boundary. */
final class UpstreamFailure extends RuntimeException
{
    private const CATEGORIES = [
        'none',
        'transport', 'http_retryable', 'http_rejected', 'credentials', 'business',
        'content_type', 'json', 'schema', 'response_size', 'budget', 'unknown',
        'item_unavailable', 'item_invalid',
    ];

    public readonly array $diagnostics;

    public function __construct(string $category, array $diagnostics = [])
    {
        $this->diagnostics = self::sanitize(['category' => $category] + $diagnostics);
        parent::__construct('远端 HTTPS 请求失败');
    }

    /** Decode errors only; bounded future codes remain observations, not syntax claims. */
    public static function jsonErrorKind(int $code): ?string
    {
        if ($code < 1 || $code > 255 || in_array($code, [6, 7, 8, 11], true)) {
            // RECURSION, INF_OR_NAN, UNSUPPORTED_TYPE and NON_BACKED_ENUM are encode-only.
            return null;
        }
        return match ($code) {
            1 => 'depth',
            5 => 'utf8',
            2, 3, 4, 9, 10 => 'syntax',
            default => 'unknown',
        };
    }

    /** Only the six bounded observations may cross the response-shape boundary. */
    public static function sanitizeResponseStructure(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $safe = [];
        foreach (['business_code', 'data_type', 'data_count', 'first_children_type',
            'first_children_count', 'first_item_type'] as $key) {
            if (!array_key_exists($key, $value)) {
                continue;
            }
            $observation = $value[$key];
            if (str_ends_with($key, '_type')) {
                if (in_array($observation, ['missing', 'null', 'boolean', 'number', 'string',
                    'list', 'object', 'empty_array_or_object'], true)) {
                    $safe[$key] = $observation;
                }
            } elseif (is_int($observation)
                && $observation >= ($key === 'business_code' ? -999999 : 0)
                && $observation <= ($key === 'business_code' ? 999999 : 10000)) {
                $safe[$key] = $observation;
            }
        }
        return $safe === [] ? null : $safe;
    }

    /** New observations omit invalid/uncollected values; legacy five-field defaults stay compatible. */
    public static function sanitize(array $values): array
    {
        $category = $values['category'] ?? null;
        $safe = ['category' => in_array($category, self::CATEGORIES, true) ? $category : 'unknown'];
        foreach (['http_status' => 599, 'curl_code' => 999, 'elapsed_ms' => 480000, 'attempts' => 3] as $key => $max) {
            $value = $values[$key] ?? 0;
            // Invalid/out-of-range measurements mean unknown, never a fabricated boundary value.
            $safe[$key] = is_int($value) && $value >= 0 && $value <= $max ? $value : 0;
        }
        $safe['http_status'] = $safe['http_status'] >= 100 ? $safe['http_status'] : 0;
        if (array_key_exists('mime_category', $values)) {
            $safe['mime_category'] = in_array($values['mime_category'], [
                'application_json', 'text_json', 'json_suffix', 'text_html', 'text_plain',
                'missing', 'empty', 'other', 'malformed', 'conflicting', 'unknown',
            ], true) ? $values['mime_category'] : 'unknown';
        }
        if (array_key_exists('mime_count', $values)) {
            $safe['mime_count'] = is_int($values['mime_count']) && $values['mime_count'] >= 0
                && $values['mime_count'] <= 65535 ? $values['mime_count'] : null;
        }
        if (array_key_exists('json_valid', $values)) {
            $safe['json_valid'] = is_bool($values['json_valid']) ? $values['json_valid'] : null;
        }
        if (array_key_exists('mime_compatibility', $values)) {
            $safe['mime_compatibility'] = $values['mime_compatibility'] === true;
        }
        if (array_key_exists('json_error_code', $values) && array_key_exists('json_error', $values)
            && is_int($values['json_error_code'])) {
            $kind = self::jsonErrorKind($values['json_error_code']);
            if ($kind !== null && $values['json_error'] === $kind) {
                $safe['json_error_code'] = $values['json_error_code'];
                $safe['json_error'] = $kind;
            }
        }
        $structure = self::sanitizeResponseStructure($values['response_structure'] ?? null);
        if ($structure !== null) {
            $safe['response_structure'] = $structure;
        }
        if (in_array($values['stage'] ?? null, ['catalog', 'detail', 'image'], true)) {
            $safe['stage'] = $values['stage'];
        }
        $remaining = self::sanitizeRemaining($values['remaining_budget'] ?? null);
        if ($remaining !== []) $safe['remaining_budget'] = $remaining;
        $history = $values['attempt_history'] ?? null;
        if (is_array($history) && array_is_list($history) && count($history) <= 3) {
            $safe['attempt_history'] = [];
            foreach ($history as $attempt) {
                if (!is_array($attempt)) continue;
                $entry = self::measurements($attempt, ['http_status' => 599, 'curl_code' => 999,
                    'elapsed_ms' => 480000, 'connect_timeout_ms' => 5000, 'request_timeout_ms' => 90000]);
                if (isset($entry['http_status']) && $entry['http_status'] > 0 && $entry['http_status'] < 100) {
                    unset($entry['http_status']);
                }
                if (in_array($attempt['category'] ?? null, self::CATEGORIES, true)) {
                    $entry['category'] = $attempt['category'];
                }
                $before = self::sanitizeRemaining($attempt['remaining_before'] ?? null);
                if ($before !== []) $entry['remaining_before'] = $before;
                if ($entry !== []) $safe['attempt_history'][] = $entry;
            }
        }
        return $safe;
    }

    public static function sanitizeRemaining(mixed $value): array
    {
        return is_array($value) ? self::measurements($value, ['round_ms' => 300000, 'source_ms' => 120000]) : [];
    }

    public static function sanitizeObservation(array $value): array
    {
        $safe = self::sanitize($value);
        // New observations do not inherit the legacy error contract's zero defaults.
        foreach (['category', 'http_status', 'curl_code', 'elapsed_ms', 'attempts',
            'mime_category', 'mime_count', 'json_valid', 'mime_compatibility'] as $field) {
            if (!array_key_exists($field, $value) || $value[$field] !== $safe[$field]) unset($safe[$field]);
        }
        if (($safe['attempts'] ?? null) === 0) {
            unset($safe['http_status'], $safe['curl_code']);
            if (!isset($safe['stage'])) unset($safe['elapsed_ms']);
        }
        return $safe;
    }

    /** Three fixed stage slots, never a per-product/request list. */
    public static function sanitizeRequests(mixed $value): array
    {
        $safe = [];
        if (!is_array($value)) return $safe;
        foreach (['catalog', 'detail', 'image'] as $stage) {
            $summary = $value[$stage] ?? null;
            if (!is_array($summary)) continue;
            $entry = self::measurements($summary, ['count' => 10000]);
            foreach (['last', 'last_failure'] as $key) {
                $request = $summary[$key] ?? null;
                if (!is_array($request) || ($request['stage'] ?? null) !== $stage) continue;
                $entry[$key] = self::sanitizeObservation($request);
            }
            if ($entry !== []) $safe[$stage] = $entry;
        }
        return $safe;
    }

    private static function measurements(array $values, array $limits): array
    {
        $safe = [];
        foreach ($limits as $key => $max) {
            $value = $values[$key] ?? null;
            if (is_int($value) && $value >= 0 && $value <= $max) $safe[$key] = $value;
        }
        return $safe;
    }
}
