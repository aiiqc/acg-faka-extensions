<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaSupplySync\Service;

final class Redactor
{
    /** @param string[] $secrets */
    public static function text(string $value, array $secrets = []): string
    {
        foreach ($secrets as $secret) {
            if ($secret !== '' && strlen($secret) >= 8) {
                $value = str_replace($secret, '[REDACTED]', $value);
            }
        }
        $value = (string)preg_replace(
            '/(?i)(app[_-]?key|secret|token|password)\s*[:=]\s*[^\s,;]+/',
            '$1=[REDACTED]',
            $value
        );
        $value = (string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
        return mb_substr(trim(strip_tags($value)), 0, 200, 'UTF-8');
    }
}
