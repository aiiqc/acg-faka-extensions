<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaSupplySync\Service;

use Pika\LocalExtensions\Manager\PathGuard;
use RuntimeException;

final class StateStore
{
    private const MAX_STATE_BYTES = 1048576;
    private const MAX_CATEGORIES = 201;
    private const MAX_ROTATION_BYTES = 128;
    private const MAX_SCHEDULE_BYTES = 1024;

    /** @param int[] $sourceIds @return int[] */
    public function orderSources(array $sourceIds): array
    {
        if ($sourceIds === []) {
            return [];
        }
        $normalized = [];
        foreach ($sourceIds as $sourceId) {
            if (!is_int($sourceId) || $sourceId < 1 || $sourceId > 4294967295) {
                throw new RuntimeException('共享店铺 ID 不正确');
            }
            $normalized[$sourceId] = $sourceId;
        }
        if (count($normalized) > 100) {
            throw new RuntimeException('共享店铺超过 100 个安全上限');
        }
        ksort($normalized, SORT_NUMERIC);
        $ordered = array_values($normalized);
        $lastSourceId = $this->readRotation();
        if ($lastSourceId < 1) {
            return $ordered;
        }
        $start = 0;
        foreach ($ordered as $index => $sourceId) {
            if ($sourceId > $lastSourceId) {
                $start = $index;
                break;
            }
            $start = count($ordered);
        }
        if ($start >= count($ordered)) {
            $start = 0;
        }
        return array_merge(array_slice($ordered, $start), array_slice($ordered, 0, $start));
    }

    public function markSourceAttempted(int $sourceId): void
    {
        if ($sourceId < 1 || $sourceId > 4294967295) {
            throw new RuntimeException('共享店铺 ID 不正确');
        }
        $path = $this->rotationPath();
        if (is_link($path)) {
            throw new RuntimeException('插件轮转状态不能是符号链接');
        }
        $lockPath = dirname($path) . '/rotation.state.lock';
        if (is_link($lockPath)) {
            throw new RuntimeException('插件轮转状态锁不能是符号链接');
        }
        $handle = fopen($lockPath, 'c');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            is_resource($handle) && fclose($handle);
            throw new RuntimeException('无法锁定插件轮转状态');
        }
        chmod($lockPath, 0600);
        $temporary = false;
        try {
            $json = json_encode(
                ['last_source_id' => $sourceId],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ) . PHP_EOL;
            $temporary = tempnam(dirname($path), '.rotation-');
            if ($temporary === false || is_link($temporary)) {
                throw new RuntimeException('无法创建插件轮转临时文件');
            }
            chmod($temporary, 0600);
            $tempHandle = fopen($temporary, 'wb');
            if ($tempHandle === false) {
                throw new RuntimeException('插件轮转状态写入失败');
            }
            try {
                $written = 0;
                while ($written < strlen($json)) {
                    $bytes = fwrite($tempHandle, substr($json, $written));
                    if ($bytes === false || $bytes === 0) {
                        throw new RuntimeException('插件轮转状态写入失败');
                    }
                    $written += $bytes;
                }
                if (!fflush($tempHandle) || (function_exists('fsync') && !fsync($tempHandle))) {
                    throw new RuntimeException('插件轮转状态刷盘失败');
                }
            } finally {
                fclose($tempHandle);
            }
            if (!rename($temporary, $path)) {
                throw new RuntimeException('插件轮转状态原子替换失败');
            }
            chmod($path, 0600);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** @return array{cursor:string,priority_cursor:string,categories:array<string,int>,catalog_hash:string,last_run:string,last_result:array} */
    public function read(int $sourceId): array
    {
        $path = $this->path($sourceId);
        if (!is_file($path)) {
            return $this->defaults();
        }
        if (is_link($path) || filesize($path) > self::MAX_STATE_BYTES) {
            throw new RuntimeException('插件同步状态大小或文件类型不正确');
        }
        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            throw new RuntimeException('无法读取插件同步状态');
        }
        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('插件同步状态格式损坏');
        }
        return $this->normalize($decoded);
    }

    public function write(int $sourceId, array $state): void
    {
        $path = $this->path($sourceId);
        $state = $this->normalize($state);
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > self::MAX_STATE_BYTES) {
            throw new RuntimeException('插件同步状态超过大小上限');
        }
        $lockPath = dirname($path) . '/source-' . $sourceId . '.state.lock';
        if (is_link($lockPath)) {
            throw new RuntimeException('插件同步状态锁不能是符号链接');
        }
        $handle = fopen($lockPath, 'c');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            is_resource($handle) && fclose($handle);
            throw new RuntimeException('无法锁定插件同步状态');
        }
        chmod($lockPath, 0600);
        $temporary = tempnam(dirname($path), '.source-' . $sourceId . '-');
        try {
            if ($temporary === false || is_link($temporary)) {
                throw new RuntimeException('无法创建插件同步临时文件');
            }
            chmod($temporary, 0600);
            $tempHandle = fopen($temporary, 'wb');
            if ($tempHandle === false) {
                throw new RuntimeException('插件同步状态写入失败');
            }
            try {
                $payload = $json . PHP_EOL;
                $written = 0;
                while ($written < strlen($payload)) {
                    $bytes = fwrite($tempHandle, substr($payload, $written));
                    if ($bytes === false || $bytes === 0) {
                        throw new RuntimeException('插件同步状态写入失败');
                    }
                    $written += $bytes;
                }
                if (!fflush($tempHandle) || (function_exists('fsync') && !fsync($tempHandle))) {
                    throw new RuntimeException('插件同步状态刷盘失败');
                }
            } finally {
                fclose($tempHandle);
            }
            if (!rename($temporary, $path)) {
                throw new RuntimeException('插件同步状态原子替换失败');
            }
            chmod($path, 0600);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function resetProgress(int $sourceId): void
    {
        $state = $this->read($sourceId);
        $defaults = $this->defaults();
        $defaults['categories'] = $state['categories'];
        $this->write($sourceId, $defaults);
        // The old reset API has no source context. Invalidate only the sidecar basis.
        $this->persistSchedule($sourceId, $this->scheduleDefaults(str_repeat('0', 64)));
    }

    /** @return array{schema:int,media_cursor:string,next_slot:int,basis_hash:string} */
    public function readSchedule(int $sourceId, array $legacyState, string $sourceFingerprint): array
    {
        $basisHash = $this->scheduleBasis($legacyState, $sourceFingerprint);
        $path = $this->schedulePath($sourceId);
        clearstatcache(true, $path);
        if (!file_exists($path) && !is_link($path)) {
            return $this->scheduleDefaults($basisHash);
        }
        $this->assertScheduleFile($path);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('无法读取插件调度状态');
        }
        try {
            $this->assertScheduleHandle($handle, $path);
            $raw = stream_get_contents($handle, self::MAX_SCHEDULE_BYTES + 1);
        } finally {
            fclose($handle);
        }
        if (!is_string($raw) || strlen($raw) > self::MAX_SCHEDULE_BYTES) {
            throw new RuntimeException('插件调度状态大小不正确');
        }
        try {
            $schedule = $this->normalizeSchedule(json_decode($raw, true, 4, JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            throw new RuntimeException('插件调度状态格式损坏');
        }
        return hash_equals($basisHash, $schedule['basis_hash'])
            ? $schedule
            : $this->scheduleDefaults($basisHash);
    }

    public function writeSchedule(
        int $sourceId,
        array $legacyState,
        string $sourceFingerprint,
        array $schedule,
    ): void {
        $schedule = $this->normalizeSchedule($schedule);
        // A caller may pass its old read basis; only the already-written legacy state binds the new one.
        $schedule['basis_hash'] = $this->scheduleBasis($legacyState, $sourceFingerprint);
        $this->persistSchedule($sourceId, $schedule);
    }

    private function scheduleBasis(array $legacyState, string $sourceFingerprint): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $sourceFingerprint) !== 1) {
            throw new RuntimeException('插件货源指纹格式不正确');
        }
        $legacyState = $this->normalize($legacyState);
        ksort($legacyState['categories'], SORT_STRING);
        return hash('sha256', json_encode(
            ['source_fingerprint' => $sourceFingerprint, 'legacy_state' => $legacyState],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    private function normalizeSchedule(mixed $schedule): array
    {
        if (!is_array($schedule)) {
            throw new RuntimeException('插件调度状态必须是对象');
        }
        $this->assertKeys($schedule, ['schema', 'media_cursor', 'next_slot', 'basis_hash']);
        if ($schedule['schema'] !== 1
            || !is_string($schedule['media_cursor'])
            || strlen($schedule['media_cursor']) > 64
            || preg_match('/[\x00-\x20\x7F]/', $schedule['media_cursor'])
            || !is_int($schedule['next_slot'])
            || $schedule['next_slot'] < 0 || $schedule['next_slot'] > 4
            || !is_string($schedule['basis_hash'])
            || preg_match('/^[a-f0-9]{64}$/D', $schedule['basis_hash']) !== 1) {
            throw new RuntimeException('插件调度状态字段不正确');
        }
        return [
            'schema' => 1,
            'media_cursor' => $schedule['media_cursor'],
            'next_slot' => $schedule['next_slot'],
            'basis_hash' => $schedule['basis_hash'],
        ];
    }

    private function scheduleDefaults(string $basisHash): array
    {
        return ['schema' => 1, 'media_cursor' => '', 'next_slot' => 0, 'basis_hash' => $basisHash];
    }

    private function schedulePath(int $sourceId): string
    {
        return dirname($this->path($sourceId)) . '/schedule-' . $sourceId . '.json';
    }

    private function persistSchedule(int $sourceId, array $schedule): void
    {
        $path = $this->schedulePath($sourceId);
        $json = json_encode($schedule, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            . PHP_EOL;
        if (strlen($json) > self::MAX_SCHEDULE_BYTES) {
            throw new RuntimeException('插件调度状态超过大小上限');
        }
        if (file_exists($path) || is_link($path)) {
            $this->assertScheduleFile($path);
        }
        $lockPath = dirname($path) . '/schedule-' . $sourceId . '.state.lock';
        clearstatcache(true, $lockPath);
        $exists = file_exists($lockPath) || is_link($lockPath);
        if ($exists) {
            $this->assertScheduleFile($lockPath);
        }
        $previousUmask = umask(0177);
        try {
            $handle = fopen($lockPath, $exists ? 'r+b' : 'x+b');
        } finally {
            umask($previousUmask);
        }
        if ($handle === false) {
            throw new RuntimeException('无法创建插件调度状态锁');
        }
        $temporary = false;
        try {
            $this->assertScheduleHandle($handle, $lockPath);
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('无法锁定插件调度状态');
            }
            $this->assertScheduleHandle($handle, $lockPath);
            if (file_exists($path) || is_link($path)) {
                $this->assertScheduleFile($path);
            }
            $temporary = tempnam(dirname($path), '.schedule-' . $sourceId . '-');
            if ($temporary === false || is_link($temporary) || !chmod($temporary, 0600)) {
                throw new RuntimeException('无法创建插件调度临时文件');
            }
            $tempHandle = fopen($temporary, 'wb');
            if ($tempHandle === false) {
                throw new RuntimeException('插件调度状态写入失败');
            }
            try {
                $this->assertScheduleHandle($tempHandle, $temporary);
                $written = 0;
                while ($written < strlen($json)) {
                    $bytes = fwrite($tempHandle, substr($json, $written));
                    if ($bytes === false || $bytes === 0) {
                        throw new RuntimeException('插件调度状态写入失败');
                    }
                    $written += $bytes;
                }
                if (!fflush($tempHandle) || (function_exists('fsync') && !fsync($tempHandle))) {
                    throw new RuntimeException('插件调度状态刷盘失败');
                }
            } finally {
                fclose($tempHandle);
            }
            if (!rename($temporary, $path)) {
                throw new RuntimeException('插件调度状态原子替换失败');
            }
            $this->assertScheduleFile($path);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function assertScheduleFile(string $path): array
    {
        clearstatcache(true, $path);
        $metadata = lstat($path);
        if (!is_array($metadata) || is_link($path)
            || ((int)$metadata['mode'] & 0170000) !== 0100000
            || ((int)$metadata['mode'] & 0777) !== 0600
            || (int)$metadata['uid'] !== PathGuard::runtimeOwner()
            || (int)$metadata['nlink'] !== 1
            || (int)$metadata['size'] > self::MAX_SCHEDULE_BYTES) {
            throw new RuntimeException('插件调度状态的大小、类型、权限或文件身份不安全');
        }
        return $metadata;
    }

    /** @param resource $handle */
    private function assertScheduleHandle($handle, string $path): void
    {
        $pathMetadata = $this->assertScheduleFile($path);
        $metadata = fstat($handle);
        if (!is_array($metadata)
            || (int)$metadata['dev'] !== (int)$pathMetadata['dev']
            || (int)$metadata['ino'] !== (int)$pathMetadata['ino']) {
            throw new RuntimeException('插件调度状态文件身份已改变');
        }
    }

    private function path(int $sourceId): string
    {
        if ($sourceId < 1 || $sourceId > 4294967295) {
            throw new RuntimeException('共享店铺 ID 不正确');
        }
        $directory = LocalPath::directory(
            'runtime/local-extensions/extensions/PikaSupplySync',
            0700
        );
        return $directory . '/source-' . $sourceId . '.json';
    }

    private function readRotation(): int
    {
        $path = $this->rotationPath();
        if (is_link($path)) {
            throw new RuntimeException('插件轮转状态不能是符号链接');
        }
        if (!is_file($path)) {
            return 0;
        }
        if (filesize($path) > self::MAX_ROTATION_BYTES) {
            throw new RuntimeException('插件轮转状态大小或文件类型不正确');
        }
        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            throw new RuntimeException('无法读取插件轮转状态');
        }
        try {
            $state = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('插件轮转状态格式损坏');
        }
        if (
            !is_array($state)
            || array_keys($state) !== ['last_source_id']
            || !is_int($state['last_source_id'])
            || $state['last_source_id'] < 1
            || $state['last_source_id'] > 4294967295
        ) {
            throw new RuntimeException('插件轮转状态字段不正确');
        }
        return $state['last_source_id'];
    }

    private function rotationPath(): string
    {
        $directory = LocalPath::directory(
            'runtime/local-extensions/extensions/PikaSupplySync',
            0700
        );
        return $directory . '/rotation.json';
    }

    private function normalize(mixed $state): array
    {
        if (!is_array($state)) {
            throw new RuntimeException('插件同步状态必须是对象');
        }
        $state += ['priority_cursor' => ''];
        $this->assertKeys($state, ['cursor', 'priority_cursor', 'categories', 'catalog_hash', 'last_run', 'last_result']);
        $cursor = $state['cursor'] ?? null;
        if (!is_string($cursor) || strlen($cursor) > 64 || preg_match('/[\x00-\x20\x7F]/', $cursor)) {
            throw new RuntimeException('插件同步游标格式不正确');
        }
        $priorityCursor = $state['priority_cursor'] ?? null;
        if (!is_string($priorityCursor) || strlen($priorityCursor) > 64 || preg_match('/[\x00-\x20\x7F]/', $priorityCursor)) {
            throw new RuntimeException('插件优先同步游标格式不正确');
        }
        $categories = $state['categories'] ?? null;
        if (!is_array($categories) || count($categories) > self::MAX_CATEGORIES) {
            throw new RuntimeException('插件分类映射格式不正确');
        }
        $cleanCategories = [];
        foreach ($categories as $key => $value) {
            if (
                !is_string($key)
                || ($key !== '__root__' && preg_match('/^category:[a-f0-9]{64}$/D', $key) !== 1)
                || !is_int($value)
                || $value < 1
                || $value > 4294967295
            ) {
                throw new RuntimeException('插件分类映射字段不正确');
            }
            $cleanCategories[$key] = $value;
        }
        $hash = $state['catalog_hash'] ?? null;
        if (!is_string($hash) || ($hash !== '' && preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1)) {
            throw new RuntimeException('插件目录哈希格式不正确');
        }
        $lastRun = $state['last_run'] ?? null;
        if (!is_string($lastRun) || ($lastRun !== '' && !$this->validDate($lastRun))) {
            throw new RuntimeException('插件最后运行时间格式不正确');
        }
        return [
            'cursor' => $cursor,
            'priority_cursor' => $priorityCursor,
            'categories' => $cleanCategories,
            'catalog_hash' => $hash,
            'last_run' => $lastRun,
            'last_result' => $this->lastResult($state['last_result'] ?? null),
        ];
    }

    private function lastResult(mixed $result): array
    {
        if ($result === []) {
            return [];
        }
        if (!is_array($result)) {
            throw new RuntimeException('插件最后结果格式不正确');
        }
        $result += ['catalog_unknown' => 0];
        if (is_array($result['planned'] ?? null)) {
            $result['planned'] += ['held_unknown' => 0];
        }
        if (is_array($result['applied'] ?? null)) {
            $result['applied'] += [
                'already_managed' => 0,
                'held_existing_unmanaged' => 0,
                'held_unknown' => 0,
            ];
        }
        $this->assertKeys($result, ['status', 'catalog_total', 'catalog_unknown', 'planned', 'applied', 'failed', 'mass_zero_fuse']);
        if (!is_string($result['status'] ?? null) || !in_array($result['status'], ['ok', 'partial'], true)) {
            throw new RuntimeException('插件最后结果状态不正确');
        }
        return [
            'status' => $result['status'],
            'catalog_total' => $this->count($result['catalog_total'] ?? null),
            'catalog_unknown' => $this->count($result['catalog_unknown']),
            'planned' => $this->counts($result['planned'] ?? null, ['sync', 'import', 'zero', 'hold_zero', 'held_unknown']),
            'applied' => $this->counts($result['applied'] ?? null, [
                'sync',
                'import',
                'zero',
                'held_race',
                'already_managed',
                'held_existing_unmanaged',
                'held_unknown',
            ]),
            'failed' => $this->count($result['failed'] ?? null),
            'mass_zero_fuse' => is_bool($result['mass_zero_fuse'] ?? null)
                ? $result['mass_zero_fuse']
                : throw new RuntimeException('插件熔断状态类型不正确'),
        ];
    }

    private function counts(mixed $counts, array $keys): array
    {
        if (!is_array($counts)) {
            throw new RuntimeException('插件计数器格式不正确');
        }
        $this->assertKeys($counts, $keys);
        $clean = [];
        foreach ($keys as $key) {
            $clean[$key] = $this->count($counts[$key] ?? null);
        }
        return $clean;
    }

    private function count(mixed $value): int
    {
        if (!is_int($value) || $value < 0 || $value > 10000) {
            throw new RuntimeException('插件计数器超出有效范围');
        }
        return $value;
    }

    private function assertKeys(array $value, array $allowed): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($allowed, SORT_STRING);
        if ($actual !== $allowed) {
            throw new RuntimeException('插件同步状态包含未知或缺失字段');
        }
    }

    private function validDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        return $date !== false && $date->format('Y-m-d H:i:s') === $value;
    }

    private function defaults(): array
    {
        return [
            'cursor' => '',
            'priority_cursor' => '',
            'categories' => [],
            'catalog_hash' => '',
            'last_run' => '',
            'last_result' => [],
        ];
    }
}
