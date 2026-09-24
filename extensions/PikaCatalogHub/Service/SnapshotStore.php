<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaCatalogHub\Service;

use Pika\LocalExtensions\Manager\PathGuard;
use Pika\LocalExtensions\PikaSupplySync\Service\UpstreamCategoryTree;
use RuntimeException;

final class SnapshotStore
{
    private const SCHEMA = 1;
    private const MAX_BYTES = 16777216;
    private const MAX_ITEMS = 10000;

    /**
     * @param list<array{code:mixed,category:mixed,stock:mixed,target:mixed}> $items
     * @return array{sha256:string,plan_hash:string,source_fingerprint:string,item_count:int}
     */
    public function write(
        string $taskId,
        string $sourceFingerprint,
        string $planHash,
        array $items,
        string $categoryMode = 'smart',
    ): array {
        $taskId = $this->taskId($taskId);
        $sourceFingerprint = $this->sha256($sourceFingerprint, '货源指纹');
        $planHash = $this->sha256($planHash, '分类方案哈希');
        $snapshot = [
            'schema' => self::SCHEMA,
            'task_id' => $taskId,
            'source_fingerprint' => $sourceFingerprint,
            'plan_hash' => $planHash,
            'items' => $items,
        ];
        if ($categoryMode !== 'smart') {
            $snapshot['category_mode'] = $categoryMode;
        }
        $normalized = $this->normalize($snapshot);
        $payload = CanonicalJson::encode($normalized) . "\n";
        if (strlen($payload) > self::MAX_BYTES) {
            throw new RuntimeException('后台任务目录快照超过 16MB 安全上限。');
        }
        $payloadSha256 = hash('sha256', $payload);

        $directory = $this->directory();
        $path = $directory . '/' . $taskId . '.json';
        if (is_link($path)) {
            throw new RuntimeException('后台任务目录快照路径不安全。');
        }
        if (file_exists($path)) {
            return $this->existingMetadata(
                $taskId,
                $sourceFingerprint,
                $planHash,
                $payloadSha256,
            );
        }
        $temporary = $directory . '/.' . $taskId . '.tmp-' . bin2hex(random_bytes(12));
        $handle = fopen($temporary, 'xb');
        if ($handle === false) {
            throw new RuntimeException('无法创建后台任务目录快照临时文件。');
        }

        try {
            if (!chmod($temporary, 0o600)) {
                throw new RuntimeException('无法保护后台任务目录快照临时文件。');
            }
            $this->assertHandle($handle, $temporary, '临时快照');
            $offset = 0;
            $length = strlen($payload);
            while ($offset < $length) {
                $written = fwrite($handle, substr($payload, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('无法写入后台任务目录快照。');
                }
                $offset += $written;
            }
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new RuntimeException('无法刷盘后台任务目录快照。');
            }
        } catch (\Throwable $exception) {
            @unlink($temporary);
            throw $exception;
        } finally {
            fclose($handle);
        }

        $linked = @link($temporary, $path);
        if (is_file($temporary) && !is_link($temporary)) {
            @unlink($temporary);
        }
        if (!$linked) {
            if (is_link($path) || !file_exists($path)) {
                throw new RuntimeException('无法原子发布后台任务目录快照。');
            }
            return $this->existingMetadata(
                $taskId,
                $sourceFingerprint,
                $planHash,
                $payloadSha256,
            );
        }

        clearstatcache(true, $path);
        $metadata = $this->readMetadata($taskId, $sourceFingerprint, $planHash);
        if (!hash_equals($payloadSha256, $metadata['sha256'])) {
            throw new RuntimeException('后台任务目录快照发布后校验失败。');
        }
        return $metadata;
    }

    /** @return array{sha256:string,plan_hash:string,source_fingerprint:string,item_count:int} */
    private function existingMetadata(
        string $taskId,
        string $sourceFingerprint,
        string $planHash,
        string $payloadSha256,
    ): array {
        $metadata = $this->readMetadata($taskId, $sourceFingerprint, $planHash);
        if (!hash_equals($payloadSha256, $metadata['sha256'])) {
            throw new RuntimeException('后台任务目录快照已存在且内容不一致。');
        }
        return $metadata;
    }

    /**
     * @return array{schema:int,task_id:string,source_fingerprint:string,plan_hash:string,items:list<array{code:string,category:string,stock:int,target:array{group:string,family:string}}>}
     */
    public function read(
        string $taskId,
        string $expectedSha256,
        string $expectedSourceFingerprint,
        string $expectedPlanHash,
    ): array {
        $record = $this->readRecord($taskId);
        $this->assertBinding($record, $expectedSha256, $expectedSourceFingerprint, $expectedPlanHash);
        return $record['snapshot'];
    }

    /** @return array{sha256:string,plan_hash:string,source_fingerprint:string,item_count:int} */
    public function readMetadata(
        string $taskId,
        string $expectedSourceFingerprint,
        string $expectedPlanHash,
        ?string $expectedSha256 = null,
    ): array {
        $record = $this->readRecord($taskId);
        $this->assertBinding(
            $record,
            $expectedSha256 ?? $record['sha256'],
            $expectedSourceFingerprint,
            $expectedPlanHash,
        );
        return [
            'sha256' => $record['sha256'],
            'plan_hash' => $record['snapshot']['plan_hash'],
            'source_fingerprint' => $record['snapshot']['source_fingerprint'],
            'item_count' => count($record['snapshot']['items']),
        ];
    }

    /**
     * Removes only a canonical analysis artifact whose task/source identity is
     * proven by its contents. JobService calls this only while the atomic job
     * record has no snapshot metadata, so a bound import snapshot is never a
     * deletion candidate.
     */
    public function discardUnboundAnalysis(string $taskId, string $expectedSourceFingerprint): bool
    {
        return $this->retireUnboundTerminal($taskId, $expectedSourceFingerprint, true);
    }

    public function retireUnboundTerminal(
        string $taskId,
        string $expectedSourceFingerprint,
        bool $delete,
    ): bool {
        $taskId = $this->taskId($taskId);
        $expectedSourceFingerprint = $this->sha256($expectedSourceFingerprint, '货源指纹');
        $path = $this->directory() . '/' . $taskId . '.json';
        if (is_link($path)) {
            throw new RuntimeException('后台任务目录快照路径不安全。');
        }
        if (!file_exists($path)) {
            return false;
        }

        $record = $this->readRecord($taskId);
        if (!hash_equals($expectedSourceFingerprint, $record['snapshot']['source_fingerprint'])) {
            throw new RuntimeException('后台任务目录快照货源绑定不一致。');
        }
        return $this->retireFile(
            $path,
            $delete,
            '未绑定的后台任务目录快照',
            $record['dev'],
            $record['ino'],
        );
    }

    public function retireBoundTerminal(
        string $taskId,
        string $expectedSourceFingerprint,
        string $expectedPlanHash,
        string $expectedSha256,
        bool $delete,
    ): bool {
        $taskId = $this->taskId($taskId);
        $path = $this->directory() . '/' . $taskId . '.json';
        if (is_link($path)) {
            throw new RuntimeException('后台任务目录快照路径不安全。');
        }
        if (!file_exists($path)) {
            if (!$delete) {
                throw new RuntimeException('已结束后台任务目录快照不存在。');
            }
            return false;
        }
        $record = $this->readRecord($taskId);
        $this->assertBinding($record, $expectedSha256, $expectedSourceFingerprint, $expectedPlanHash);
        return $this->retireFile(
            $path,
            $delete,
            '已结束后台任务目录快照',
            $record['dev'],
            $record['ino'],
        );
    }

    private function retireFile(
        string $path,
        bool $delete,
        string $label,
        int $expectedDevice,
        int $expectedInode,
    ): bool {
        $this->safePathMetadata($path, $label);
        if (!$delete) {
            $this->assertPathIdentity($path, $label, $expectedDevice, $expectedInode);
            return true;
        }

        $tombstone = dirname($path)
            . '/.retire-'
            . basename($path)
            . '-'
            . bin2hex(random_bytes(12));
        clearstatcache(true, $tombstone);
        if (file_exists($tombstone) || is_link($tombstone)) {
            throw new RuntimeException("无法创建{$label}退役路径。");
        }
        if (!rename($path, $tombstone)) {
            throw new RuntimeException("无法原子退役{$label}。");
        }

        try {
            $this->assertPathIdentity($tombstone, $label, $expectedDevice, $expectedInode);
        } catch (\Throwable $exception) {
            $this->restoreTombstone($tombstone, $path);
            throw new RuntimeException("{$label}退役时文件身份已变化。", 0, $exception);
        }

        if (!unlink($tombstone)) {
            $this->restoreTombstone($tombstone, $path);
            throw new RuntimeException("无法安全移除{$label}。");
        }
        clearstatcache(true, $tombstone);
        clearstatcache(true, $path);
        if (file_exists($tombstone) || is_link($tombstone)) {
            throw new RuntimeException("{$label}退役文件移除后仍然存在。");
        }
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException("{$label}原路径在退役期间被重新占用。");
        }
        return true;
    }

    private function assertPathIdentity(
        string $path,
        string $label,
        int $expectedDevice,
        int $expectedInode,
    ): void {
        $metadata = $this->safePathMetadata($path, $label);
        if ($metadata['dev'] !== $expectedDevice || $metadata['ino'] !== $expectedInode) {
            throw new RuntimeException("{$label}身份或权限不安全。");
        }
    }

    /** @return array{dev:int,ino:int,mode:int,uid:int,nlink:int,size:int} */
    private function safePathMetadata(string $path, string $label): array
    {
        clearstatcache(true, $path);
        $metadata = lstat($path);
        if (!is_array($metadata)
            || ($metadata['mode'] & 0o170000) !== 0o100000
            || ($metadata['mode'] & 0o777) !== 0o600
            || $metadata['uid'] !== PathGuard::runtimeOwner()
            || $metadata['nlink'] !== 1
            || is_link($path)) {
            throw new RuntimeException("{$label}身份或权限不安全。");
        }
        return $metadata;
    }

    /**
     * Best-effort no-clobber restoration. A hard link is used so a path that
     * reappears concurrently is never overwritten by the recovery attempt.
     */
    private function restoreTombstone(string $tombstone, string $path): void
    {
        clearstatcache(true, $tombstone);
        clearstatcache(true, $path);
        $metadata = lstat($tombstone);
        if (!is_array($metadata)
            || ($metadata['mode'] & 0o170000) !== 0o100000
            || ($metadata['mode'] & 0o777) !== 0o600
            || $metadata['uid'] !== PathGuard::runtimeOwner()
            || $metadata['nlink'] !== 1
            || is_link($tombstone)
            || file_exists($path)
            || is_link($path)
            || !@link($tombstone, $path)) {
            return;
        }

        clearstatcache(true, $tombstone);
        clearstatcache(true, $path);
        $restored = lstat($path);
        $retired = lstat($tombstone);
        if (!is_array($restored)
            || !is_array($retired)
            || $restored['dev'] !== $metadata['dev']
            || $restored['ino'] !== $metadata['ino']
            || $retired['dev'] !== $metadata['dev']
            || $retired['ino'] !== $metadata['ino']
            || $restored['nlink'] !== 2
            || $retired['nlink'] !== 2) {
            return;
        }
        if (!unlink($tombstone)) {
            return;
        }
        clearstatcache(true, $path);
        $restored = lstat($path);
        if (!is_array($restored)
            || $restored['dev'] !== $metadata['dev']
            || $restored['ino'] !== $metadata['ino']
            || $restored['nlink'] !== 1) {
            throw new RuntimeException('后台任务目录快照退役恢复后身份不一致。');
        }
    }

    /** @return array{sha256:string,snapshot:array<string,mixed>,dev:int,ino:int} */
    private function readRecord(string $taskId): array
    {
        $taskId = $this->taskId($taskId);
        $path = $this->directory() . '/' . $taskId . '.json';
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('后台任务目录快照不存在或路径不安全。');
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('无法打开后台任务目录快照。');
        }
        try {
            $metadata = $this->assertHandle($handle, $path, '目录快照');
            if ($metadata['size'] < 2 || $metadata['size'] > self::MAX_BYTES) {
                throw new RuntimeException('后台任务目录快照大小不正确。');
            }
            $contents = stream_get_contents($handle);
            if (!is_string($contents) || strlen($contents) !== $metadata['size']) {
                throw new RuntimeException('无法完整读取后台任务目录快照。');
            }
        } finally {
            fclose($handle);
        }
        try {
            $decoded = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('后台任务目录快照 JSON 损坏。', 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('后台任务目录快照根节点格式不正确。');
        }
        $snapshot = $this->normalize($decoded);
        if (!hash_equals(CanonicalJson::encode($snapshot) . "\n", $contents)) {
            throw new RuntimeException('后台任务目录快照不是规范格式。');
        }
        if (!hash_equals($taskId, $snapshot['task_id'])) {
            throw new RuntimeException('后台任务目录快照编号绑定不一致。');
        }
        return [
            'sha256' => hash('sha256', $contents),
            'snapshot' => $snapshot,
            'dev' => $metadata['dev'],
            'ino' => $metadata['ino'],
        ];
    }

    /** @param array{sha256:string,snapshot:array<string,mixed>,dev:int,ino:int} $record */
    private function assertBinding(
        array $record,
        string $expectedSha256,
        string $expectedSourceFingerprint,
        string $expectedPlanHash,
    ): void {
        $expectedSha256 = $this->sha256($expectedSha256, '快照 SHA256');
        $expectedSourceFingerprint = $this->sha256($expectedSourceFingerprint, '货源指纹');
        $expectedPlanHash = $this->sha256($expectedPlanHash, '分类方案哈希');
        if (!hash_equals($expectedSha256, $record['sha256'])
            || !hash_equals($expectedSourceFingerprint, $record['snapshot']['source_fingerprint'])
            || !hash_equals($expectedPlanHash, $record['snapshot']['plan_hash'])) {
            throw new RuntimeException('后台任务目录快照身份绑定不一致。');
        }
    }

    /** @return array{schema:int,task_id:string,source_fingerprint:string,plan_hash:string,items:list<array{code:string,category:string,stock:int,target:array{group:string,family:string}>} */
    private function normalize(array $snapshot): array
    {
        $keys = ['schema', 'task_id', 'source_fingerprint', 'plan_hash', 'items'];
        if (array_key_exists('category_mode', $snapshot)) {
            $keys[] = 'category_mode';
        }
        $this->onlyKeys($snapshot, $keys, '后台任务目录快照');
        $mode = $snapshot['category_mode'] ?? 'smart';
        if (!in_array($mode, ['smart', 'mirror'], true)
            || (array_key_exists('category_mode', $snapshot) && $snapshot['category_mode'] === null)) {
            throw new RuntimeException('后台任务目录快照分类模式不正确。');
        }
        if (($snapshot['schema'] ?? null) !== self::SCHEMA) {
            throw new RuntimeException('后台任务目录快照版本不受支持。');
        }
        $taskId = $this->taskId($snapshot['task_id'] ?? null);
        $sourceFingerprint = $this->sha256($snapshot['source_fingerprint'] ?? null, '货源指纹');
        $planHash = $this->sha256($snapshot['plan_hash'] ?? null, '分类方案哈希');
        $items = $snapshot['items'] ?? null;
        if (!is_array($items) || !array_is_list($items) || $items === [] || count($items) > self::MAX_ITEMS) {
            throw new RuntimeException('后台任务目录快照商品必须是 1-10000 项列表。');
        }
        $normalized = [];
        $seen = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new RuntimeException('后台任务目录快照商品格式不正确。');
            }
            $this->onlyKeys($item, ['code', 'category', 'stock', 'target'], '后台任务目录快照商品');
            $code = $this->code($item['code'] ?? null);
            if (isset($seen[$code])) {
                throw new RuntimeException('后台任务目录快照商品编号重复。');
            }
            $seen[$code] = true;
            $stock = $item['stock'] ?? null;
            if (!is_int($stock) || $stock < 0 || $stock > 0x7fffffff) {
                throw new RuntimeException('后台任务目录快照库存格式不正确。');
            }
            $category = $this->text($item['category'] ?? null, 128, '后台任务目录快照分类');
            $target = $this->target($item['target'] ?? null);
            if (($target['mode'] ?? 'smart') !== $mode
                || ($mode === 'mirror' && $target['path'][array_key_last($target['path'])]['name'] !== $category)) {
                throw new RuntimeException('后台任务目录快照分类模式或名称不一致。');
            }
            $normalized[] = [
                'code' => $code,
                'category' => $category,
                'stock' => $stock,
                'target' => $target,
            ];
        }
        usort($normalized, static fn(array $left, array $right): int => strcmp($left['code'], $right['code']));
        if ($mode === 'mirror') {
            $suggestion = (new UpstreamCategoryTree())->suggest($normalized);
            if (!hash_equals($planHash, $suggestion['plan_hash'])) {
                throw new RuntimeException('保留上游分类结构的方案哈希与快照内容不一致。');
            }
        }
        $result = [
            'schema' => self::SCHEMA,
            'task_id' => $taskId,
            'source_fingerprint' => $sourceFingerprint,
            'plan_hash' => $planHash,
            'items' => $normalized,
        ];
        if (array_key_exists('category_mode', $snapshot)) {
            $result['category_mode'] = $mode;
        }
        return $result;
    }

    /** @return array{group:string,family:string} */
    private function target(mixed $target): array
    {
        if (!is_array($target)) {
            throw new RuntimeException('后台任务目录快照分类目标格式不正确。');
        }
        if (($target['mode'] ?? null) === 'mirror') {
            return UpstreamCategoryTree::normalizeTarget($target);
        }
        $this->onlyKeys($target, ['group', 'family'], '后台任务目录快照分类目标');
        return [
            'group' => $this->categorySegment($target['group'] ?? null, '后台任务目录快照一级分类'),
            'family' => $this->categorySegment($target['family'] ?? null, '后台任务目录快照二级分类', true),
        ];
    }

    private function categorySegment(mixed $value, string $label, bool $allowEmpty = false): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            throw new RuntimeException("{$label}格式不正确。");
        }
        if ($value === '' && $allowEmpty) {
            return '';
        }
        if ($value === ''
            || mb_strlen($value, 'UTF-8') > 64
            || preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $value) === 1
            || preg_match('#[\\\\/]#u', $value) === 1
            || preg_match('/\A[\s\p{Z}]|[\s\p{Z}]\z/u', $value) === 1) {
            throw new RuntimeException("{$label}格式不正确。");
        }
        return $value;
    }

    /** @param resource $handle @return array{dev:int,ino:int,mode:int,uid:int,nlink:int,size:int} */
    private function assertHandle($handle, string $path, string $label): array
    {
        $metadata = fstat($handle);
        $pathMetadata = lstat($path);
        if (!is_array($metadata)
            || !is_array($pathMetadata)
            || ($metadata['mode'] & 0o170000) !== 0o100000
            || $metadata['uid'] !== PathGuard::runtimeOwner()
            || ($metadata['mode'] & 0o777) !== 0o600
            || $metadata['nlink'] !== 1
            || is_link($path)
            || $metadata['dev'] !== $pathMetadata['dev']
            || $metadata['ino'] !== $pathMetadata['ino']) {
            throw new RuntimeException("后台任务{$label}所有者、权限或文件身份不安全。");
        }
        return $metadata;
    }

    /** @param array<string,mixed> $value */
    private function onlyKeys(array $value, array $allowed, string $label): void
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        $expected = $allowed;
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new RuntimeException("{$label}包含未知字段或缺少必需字段。");
        }
    }

    private function taskId(mixed $taskId): string
    {
        if (!is_string($taskId) || preg_match('/^[a-f0-9]{48}$/D', $taskId) !== 1) {
            throw new RuntimeException('后台任务编号格式不正确。');
        }
        return $taskId;
    }

    private function sha256(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new RuntimeException("{$label}格式不正确。");
        }
        return $value;
    }

    private function code(mixed $value): string
    {
        if (!is_scalar($value)) {
            throw new RuntimeException('后台任务目录快照商品编号格式不正确。');
        }
        $value = trim((string)$value);
        if ($value === '' || strlen($value) > 64 || preg_match('/[\x00-\x20\x7F]/', $value)
            || str_contains($value, '://')) {
            throw new RuntimeException('后台任务目录快照商品编号格式不正确。');
        }
        return $value;
    }

    private function text(mixed $value, int $maxLength, string $label): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')
            || preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $value) === 1) {
            throw new RuntimeException("{$label}格式不正确。");
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > $maxLength || preg_match('#https?://#iu', $value) === 1) {
            throw new RuntimeException("{$label}格式不正确。");
        }
        return $value;
    }

    private function directory(): string
    {
        return PathGuard::stateDirectory('extensions/PikaCatalogHub/snapshots', 0o700);
    }
}
