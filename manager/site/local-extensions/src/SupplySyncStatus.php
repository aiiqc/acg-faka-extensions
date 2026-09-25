<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\Manager;

/** Read-only, bounded presentation of existing SupplySync records, never a runner. */
final class SupplySyncStatus
{
    private const MAX_SOURCES = 100;
    private const MAX_LOG_BYTES = 2097152 + 65536;
    private const MAX_READ_BYTES = 8388608;
    private int $remainingBytes = self::MAX_READ_BYTES;

    public static function snapshot(): array
    {
        try {
            return (new self())->read();
        } catch (\Throwable) {
            // Do not expose paths, source identities, raw log lines or exceptions.
            return ['availability' => 'unavailable', 'scheduler' => 'unverified',
                'running' => 'unverified', 'sources' => [], 'incomplete' => true];
        }
    }

    private function read(): array
    {
        $result = ['availability' => 'available', 'scheduler' => 'unverified',
            'running' => 'unverified', 'sources' => [], 'incomplete' => false];
        $directory = PathGuard::stateRoot() . '/extensions/PikaSupplySync';
        if (!file_exists($directory) && !is_link($directory)) {
            return $result;
        }
        if (!is_dir($directory) || is_link($directory) || realpath($directory) !== $directory) {
            throw new \RuntimeException('Unsafe synchronization history directory.');
        }
        $sources = [];
        $log = $this->readFile($directory . '/sync.log', self::MAX_LOG_BYTES);
        if ($log !== null) {
            $lines = explode("\n", $log);
            // A truncated last line is not a completed record.
            if (array_pop($lines) !== '') {
                $result['incomplete'] = true;
            }
            foreach (array_reverse($lines) as $line) {
                if (preg_match('/\A(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00) (.{1,32768})\z/D', $line, $match) !== 1) {
                    $result['incomplete'] = true;
                    continue;
                }
                try {
                    $entry = json_decode($match[2], true, 16, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $result['incomplete'] = true;
                    continue;
                }
                $id = is_array($entry) ? ($entry['source_id'] ?? null) : null;
                if (!is_int($id) || $id < 1 || $id > 4294967295) {
                    $result['incomplete'] = true;
                    continue;
                }
                $record = self::record($entry, $match[1], 'UTC', 'log');
                if ($record === null) {
                    $result['incomplete'] = true;
                    continue;
                }
                if (!isset($sources[$id]) && count($sources) >= self::MAX_SOURCES) {
                    $result['incomplete'] = true;
                    continue;
                }
                $sources[$id] ??= self::source($id);
                $kind = $record['kind'];
                $sources[$id][$kind] ??= $record;
            }
        }
        // Source files retain the last actual batch even after log truncation.
        // They do not record timezone/mode, and are explicitly labelled legacy history.
        $visited = 0;
        foreach (new \DirectoryIterator($directory) as $file) {
            if (++$visited > 512) {
                $result['incomplete'] = true;
                break;
            }
            if (preg_match('/\Asource-([1-9]\d{0,9})\.json\z/D', $file->getFilename(), $match) !== 1) {
                continue;
            }
            $id = (int)$match[1];
            if ($id > 4294967295) {
                continue;
            }
            if (!isset($sources[$id]) && count($sources) >= self::MAX_SOURCES) {
                $result['incomplete'] = true;
                continue;
            }
            try {
                $bytes = $this->readFile($file->getPathname(), 1048576);
                $state = $bytes === null ? null : json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($state)) throw new \RuntimeException('Invalid source history.');
                if (($state['last_run'] ?? '') === '' && ($state['last_result'] ?? []) === []) continue;
                $time = $state['last_run'] ?? null;
                if (!is_string($time) || preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/D', $time) !== 1
                    || !is_array($state['last_result'] ?? null)) {
                    throw new \RuntimeException('Invalid source history.');
                }
                $record = self::record($state['last_result'] + ['dry_run' => false], $time, 'unrecorded', 'state');
                if ($record === null) throw new \RuntimeException('Invalid source result.');
                $sources[$id] ??= self::source($id);
                $sources[$id]['saved_batch'] = $record;
            } catch (\Throwable) {
                $result['incomplete'] = true;
            }
        }
        ksort($sources, SORT_NUMERIC);
        $result['sources'] = array_values($sources);
        return $result;
    }

    private static function source(int $id): array
    {
        return ['source_id' => $id, 'actual' => null, 'preview' => null, 'unknown' => null, 'saved_batch' => null];
    }

    /** Strict projection: never forward raw error messages, hashes, URLs or arbitrary keys. */
    private static function record(array $entry, string $time, string $zone, string $origin): ?array
    {
        if (!in_array($entry['status'] ?? null, ['ok', 'partial', 'error', 'locked', 'held_empty_catalog'], true)) {
            return null;
        }
        $dryRun = $entry['dry_run'] ?? null;
        $counts = [];
        foreach (['sync', 'import', 'zero', 'held_race', 'already_managed', 'held_existing_unmanaged'] as $key) {
            $value = $entry['applied'][$key] ?? null;
            if ($value !== null && (!is_int($value) || $value < 0 || $value > 10000)) return null;
            $counts[$key] = $value;
        }
        $unknown = [
            'catalog_unknown' => array_key_exists('catalog_unknown', $entry) ? $entry['catalog_unknown'] : 0,
            'planned_held_unknown' => is_array($entry['planned'] ?? null) && array_key_exists('held_unknown', $entry['planned'])
                ? $entry['planned']['held_unknown'] : 0,
            'applied_held_unknown' => is_array($entry['applied'] ?? null) && array_key_exists('held_unknown', $entry['applied'])
                ? $entry['applied']['held_unknown'] : 0,
        ];
        foreach ($unknown as $value) {
            if (!is_int($value) || $value < 0 || $value > 10000) return null;
        }
        $counts['held_unknown'] = $unknown['applied_held_unknown'];
        $failed = $entry['failed'] ?? null;
        if ($failed !== null && (!is_int($failed) || $failed < 0 || $failed > 10000)) return null;
        $held = $entry['selection_held'] ?? null;
        if ($held !== null && (!is_int($held) || $held < 0 || $held > 10000)) return null;
        $planned = null;
        if (is_array($entry['planned'] ?? null)) {
            $planned = $unknown['planned_held_unknown'];
            foreach (['sync', 'import', 'zero', 'hold_zero'] as $key) {
                $count = $entry['planned'][$key] ?? null;
                if (!is_int($count) || $count < 0 || $count > 10000) return null;
                $planned += $count;
            }
        }
        return ['kind' => $dryRun === true ? 'preview' : ($dryRun === false ? 'actual' : 'unknown'),
            'recorded_at' => $time, 'timezone' => $zone, 'origin' => $origin,
            'mode' => in_array($entry['mode'] ?? null, ['basic', 'full'], true) ? $entry['mode'] : null,
            'status' => $entry['status'], 'planned' => $planned, 'applied' => $counts,
            'catalog_unknown' => $unknown['catalog_unknown'],
            'planned_held_unknown' => $unknown['planned_held_unknown'],
            'failed' => $failed, 'selection_held' => $held,
            'catalog_diagnostic' => $entry['status'] === 'error'
                ? self::catalogDiagnostic($entry['catalog_diagnostic'] ?? null) : null,
            'mass_zero_fuse' => ($entry['mass_zero_fuse'] ?? null) === true];
    }

    /** Manager history remains readable with Supply disabled and without its autoloader. */
    private static function catalogDiagnostic(mixed $value): ?array
    {
        if (!is_array($value)) return null;
        $category = $value['category'] ?? null;
        $safe = ['category' => in_array($category, ['transport', 'http_retryable', 'http_rejected',
            'credentials', 'business', 'content_type', 'json', 'schema', 'response_size', 'budget', 'unknown'], true)
            ? $category : 'unknown'];
        foreach (['http_status' => 599, 'curl_code' => 999, 'elapsed_ms' => 480000, 'attempts' => 3] as $key => $max) {
            $number = $value[$key] ?? null;
            $safe[$key] = is_int($number) && $number >= 0 && $number <= $max ? $number : 0;
        }
        if ($safe['http_status'] < 100) $safe['http_status'] = 0;
        return $safe;
    }

    private function readFile(string $path, int $limit): ?string
    {
        clearstatcache(true, $path);
        $before = @lstat($path);
        if ($before === false) return null;
        if (is_link($path) || ($before['mode'] & 0170000) !== 0100000
            || $before['uid'] !== PathGuard::runtimeOwner() || ($before['mode'] & 0777) !== 0600
            || $before['nlink'] !== 1 || $before['size'] > min($limit, $this->remainingBytes)) {
            throw new \RuntimeException('Unsafe or oversized synchronization history.');
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) throw new \RuntimeException('History unavailable.');
        try {
            if (!flock($handle, LOCK_SH | LOCK_NB)) throw new \RuntimeException('History busy.');
            $opened = fstat($handle);
            if ($opened === false || $opened['dev'] !== $before['dev'] || $opened['ino'] !== $before['ino']
                || ($opened['mode'] & 0170000) !== 0100000 || ($opened['mode'] & 0777) !== 0600
                || $opened['uid'] !== PathGuard::runtimeOwner() || $opened['nlink'] !== 1
                || $opened['size'] > min($limit, $this->remainingBytes)) {
                throw new \RuntimeException('History changed while opening.');
            }
            $bytes = stream_get_contents($handle, min($limit, $this->remainingBytes) + 1);
            if (!is_string($bytes) || strlen($bytes) > min($limit, $this->remainingBytes)) {
                throw new \RuntimeException('History read exceeded its limit.');
            }
            $this->remainingBytes -= strlen($bytes);
            return $bytes;
        } finally {
            fclose($handle);
        }
    }
}
