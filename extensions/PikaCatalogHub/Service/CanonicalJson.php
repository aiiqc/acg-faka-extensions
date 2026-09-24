<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaCatalogHub\Service;

use RuntimeException;

final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        try {
            return json_encode(
                self::normalize($value),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new RuntimeException('智能货源中心无法生成规范 JSON。', 0, $exception);
        }
    }

    private static function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            if (is_float($value) && !is_finite($value)) {
                throw new RuntimeException('智能货源中心规范数据包含非有限数字。');
            }
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }

        $normalized = [];
        $keys = array_keys($value);
        foreach ($keys as $key) {
            if (!is_string($key) && !is_int($key)) {
                throw new RuntimeException('智能货源中心规范对象键格式不正确。');
            }
        }
        sort($keys, SORT_STRING);
        foreach ($keys as $key) {
            $normalized[(string)$key] = self::normalize($value[$key]);
        }
        return $normalized;
    }
}
