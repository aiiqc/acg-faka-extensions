<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaSupplySync\Service;

use App\Model\Shared;
use RuntimeException;

final class SourceIdentity
{
    private const FIELDS = [
        'id',
        'type',
        'name',
        'domain',
        'app_id',
        'app_key',
        'currency',
        'currency_rate',
    ];

    public static function fingerprint(Shared $source): string
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $values[$field] = (string)($source->{$field} ?? '');
        }
        return hash('sha256', (string)json_encode(
            $values,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    public static function lockAndVerify(Shared $source): Shared
    {
        $locked = Shared::query()
            ->whereKey((int)$source->id)
            ->lockForUpdate()
            ->first(self::FIELDS);
        if (!$locked) {
            throw new RuntimeException('共享店铺已经失效');
        }
        if (!hash_equals(self::fingerprint($source), self::fingerprint($locked))) {
            throw new RuntimeException('共享店铺配置在同步期间发生变化，已拒绝写入');
        }
        return $locked;
    }
}
