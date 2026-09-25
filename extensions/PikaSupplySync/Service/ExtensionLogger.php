<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaSupplySync\Service;

use RuntimeException;

final class ExtensionLogger
{
    private const MAX_BYTES = 2097152;

    public function write(array $result): void
    {
        $safe = $this->safeResult($result);
        $line = json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (!is_string($line) || strlen($line) > 32768) {
            throw new RuntimeException('同步日志记录超过大小上限');
        }
        $path = $this->path();
        // Keep one-off acceptance out of the manager's ordinary rotation history.
        if (($safe['targeted'] ?? false) === true) $path = dirname($path) . '/targeted-sync.log';
        if (is_link($path)) {
            throw new RuntimeException('同步日志不能是符号链接');
        }
        $handle = fopen($path, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            is_resource($handle) && fclose($handle);
            throw new RuntimeException('无法写入同步日志');
        }
        try {
            chmod($path, 0600);
            $size = fstat($handle)['size'] ?? 0;
            if (!is_int($size) || $size < 0 || $size > self::MAX_BYTES) {
                ftruncate($handle, 0);
                rewind($handle);
            } else {
                fseek($handle, 0, SEEK_END);
            }
            if (fwrite($handle, gmdate('c') . ' ' . $line . PHP_EOL) === false || !fflush($handle)) {
                throw new RuntimeException('同步日志写入失败');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function path(): string
    {
        $directory = LocalPath::directory(
            'runtime/local-extensions/extensions/PikaSupplySync',
            0700
        );
        return $directory . '/sync.log';
    }

    /** Project at the write boundary; no raw text, arbitrary keys or nested payloads. */
    private function safeResult(array $result): array
    {
        $safe = [];
        foreach (['status' => ['ok', 'partial', 'error', 'locked', 'held_empty_catalog'],
            'mode' => ['basic', 'full'], 'budget_scope' => ['source', 'round'],
            'phase' => ['preflight', 'catalog', 'planning', 'actions']] as $key => $allowed) {
            if (in_array($result[$key] ?? null, $allowed, true)) $safe[$key] = $result[$key];
        }
        foreach (['dry_run', 'targeted', 'selection_empty', 'mass_zero_fuse'] as $key) {
            if (is_bool($result[$key] ?? null)) $safe[$key] = $result[$key];
        }
        foreach (['source_id' => 4294967295, 'catalog_total' => 10000, 'catalog_unknown' => 10000, 'local_total' => 10000,
            'failed' => 10000, 'cover_failed' => 10000, 'selection_held' => 10000] as $key => $max) {
            $value = $result[$key] ?? null;
            if (is_int($value) && $value >= ($key === 'source_id' ? 1 : 0) && $value <= $max) $safe[$key] = $value;
        }
        foreach (['planned' => ['sync', 'import', 'zero', 'hold_zero', 'held_unknown'],
            'applied' => ['sync', 'import', 'zero', 'held_race', 'already_managed', 'held_existing_unmanaged', 'held_unknown']] as $key => $keys) {
            if (!is_array($result[$key] ?? null)) continue;
            $safe[$key] = [];
            foreach ($keys as $field) {
                $value = $result[$key][$field] ?? null;
                if (is_int($value) && $value >= 0 && $value <= 10000) $safe[$key][$field] = $value;
            }
        }
        $ratio = $result['mass_zero_ratio'] ?? null;
        if ((is_int($ratio) || is_float($ratio)) && is_finite((float)$ratio) && $ratio >= 0 && $ratio <= 100) {
            $safe['mass_zero_ratio'] = $ratio;
        }
        $hash = $result['next_cursor_hash'] ?? null;
        if (is_string($hash) && ($hash === '' || preg_match('/^[a-f0-9]{12}$/D', $hash))) $safe['next_cursor_hash'] = $hash;
        foreach (['target_code_hashes', 'verified_code_hashes'] as $key) {
            if (!is_array($result[$key] ?? null) || !array_is_list($result[$key]) || count($result[$key]) > 100) continue;
            $safe[$key] = array_values(array_filter($result[$key], static fn($value): bool =>
                is_string($value) && preg_match('/^[a-f0-9]{12}$/D', $value) === 1));
        }
        foreach (['catalog_diagnostic', 'failure_diagnostic'] as $key) {
            if (!is_array($result[$key] ?? null)) continue;
            $safe[$key] = $key === 'catalog_diagnostic' ? UpstreamFailure::sanitize($result[$key])
                : UpstreamFailure::sanitizeObservation($result[$key]);
            if ($key === 'catalog_diagnostic') $safe[$key] = array_intersect_key($safe[$key],
                array_flip(['category', 'http_status', 'curl_code', 'elapsed_ms', 'attempts']));
        }
        $remaining = UpstreamFailure::sanitizeRemaining($result['remaining_budget'] ?? null);
        if ($remaining !== []) $safe['remaining_budget'] = $remaining;
        $requests = UpstreamFailure::sanitizeRequests($result['request_diagnostics'] ?? null);
        if ($requests !== []) $safe['request_diagnostics'] = $requests;
        return $safe;
    }
}
