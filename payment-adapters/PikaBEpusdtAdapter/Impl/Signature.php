<?php
declare(strict_types=1);

namespace App\Pay\PikaBEpusdtAdapter\Impl;

use App\Consts\Pay as PayConsts;
use App\Pay\PikaBEpusdtAdapter\Support\OrderId;
use App\Pay\PikaBEpusdtAdapter\Support\SecretStore;
use Kernel\Util\Context;

final class Signature implements \App\Pay\Signature
{
    /** @param array<string,mixed> $data */
    public static function generateSignature(array $data, string $token): string
    {
        unset($data['signature']);
        ksort($data, SORT_STRING);
        $parts = [];
        foreach ($data as $key => $value) {
            if (!is_string($key) || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $key) !== 1) {
                throw new \InvalidArgumentException('invalid signature field');
            }
            if ($value === null || $value === '') {
                continue;
            }
            if (is_bool($value)) {
                $parts[] = $key . '=' . ($value ? 'true' : 'false');
                continue;
            }
            if (is_int($value)) {
                $parts[] = $key . '=' . (string)$value;
                continue;
            }
            if (is_float($value)) {
                if (!is_finite($value)) {
                    throw new \InvalidArgumentException('invalid signature field');
                }
                // An integral float such as 42.0 is signed as `42`, matching
                // both PHP scalar formatting and BEpusdt's Go verifier.
                $encoded = (string)$value;
                if ($encoded === '' || strpbrk($encoded, 'eE') !== false) {
                    throw new \InvalidArgumentException('invalid signature field');
                }
                $parts[] = $key . '=' . $encoded;
                continue;
            }
            if (is_string($value)) {
                $parts[] = $key . '=' . $value;
                continue;
            }
            throw new \InvalidArgumentException('invalid signature field');
        }
        return md5(implode('&', $parts) . $token);
    }

    /** @param array<string,mixed> $data @param array<string,mixed> $config */
    public function verification(array $data, array $config): bool
    {
        unset($config);
        $provided = $data['signature'] ?? null;
        if (!is_string($provided) || preg_match('/^[0-9a-f]{32}$/D', $provided) !== 1) {
            return false;
        }
        unset($data['signature']);
        $secret = SecretStore::load();
        try {
            $expected = self::generateSignature($data, $secret['token']);
        } catch (\Throwable) {
            return false;
        }
        if (!hash_equals($expected, $provided)) {
            return false;
        }

        $status = $data['status'] ?? null;
        if (!((is_int($status) && $status === 2) || (is_string($status) && $status === '2'))) {
            return false;
        }

        $upstreamOrderId = $data['order_id'] ?? null;
        if (!is_string($upstreamOrderId)) {
            return false;
        }
        $localOrderId = OrderId::unwrap($upstreamOrderId, $secret['namespace']);
        if ($localOrderId === null) {
            return false;
        }
        if (OrderId::isExplicitTest($localOrderId)
            && !OrderId::isTestRequestUri((string)($_SERVER['REQUEST_URI'] ?? ''), $localOrderId)) {
            return false;
        }

        $data['order_id'] = $localOrderId;
        Context::set(PayConsts::DAFA, $data);
        return true;
    }
}
