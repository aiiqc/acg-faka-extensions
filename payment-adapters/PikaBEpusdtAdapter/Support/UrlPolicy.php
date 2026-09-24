<?php
declare(strict_types=1);

namespace App\Pay\PikaBEpusdtAdapter\Support;

use Kernel\Exception\JSONException;

final class UrlPolicy
{
    private const FLOW_ORDER = 'order';
    private const FLOW_RECHARGE = 'recharge';

    public static function gatewayOrigin(string $origin): string
    {
        if (preg_match('#^http://127\.0\.0\.1:([1-9][0-9]{0,4})$#D', $origin, $match) !== 1
            && preg_match('#^http://\[::1\]:([1-9][0-9]{0,4})$#D', $origin, $match) !== 1) {
            throw new JSONException(Gateway::ERR_CONFIG);
        }
        $port = (int)$match[1];
        if ($port < 1 || $port > 65535) {
            throw new JSONException(Gateway::ERR_CONFIG);
        }
        return $origin;
    }

    public static function checkoutOrigin(string $origin): string
    {
        return self::httpsOrigin($origin);
    }

    public static function merchantOrigin(string $origin): string
    {
        return self::httpsOrigin($origin);
    }

    public static function assertCallbackUrl(string $url, string $origin, string $tradeNo): string
    {
        $parts = self::merchantUrlParts($url, $origin);
        $path = (string)($parts['path'] ?? '');
        if (isset($parts['query'])) {
            throw new JSONException(Gateway::ERR_CONFIG);
        }

        if (OrderId::isExplicitTest($tradeNo)) {
            if (!hash_equals('/user/api/order/callbackTest.' . $tradeNo, $path)) {
                throw new JSONException(Gateway::ERR_CONFIG);
            }
            return self::FLOW_ORDER;
        }
        if (hash_equals('/user/api/order/callback.' . $tradeNo, $path)) {
            return self::FLOW_ORDER;
        }
        if (hash_equals('/user/api/rechargeNotification/callback.' . $tradeNo, $path)) {
            return self::FLOW_RECHARGE;
        }
        throw new JSONException(Gateway::ERR_CONFIG);
    }

    public static function assertReturnUrl(
        string $url,
        string $origin,
        string $tradeNo,
        string $flow = self::FLOW_ORDER,
    ): void
    {
        $parts = self::merchantUrlParts($url, $origin);
        $path = (string)($parts['path'] ?? '');

        if ($flow === self::FLOW_RECHARGE) {
            if (!hash_equals('/user/recharge/index', $path) || isset($parts['query'])) {
                throw new JSONException(Gateway::ERR_CONFIG);
            }
            return;
        }
        if ($flow !== self::FLOW_ORDER) {
            throw new JSONException(Gateway::ERR_CONFIG);
        }
        if (!in_array($path, [
            '/user/index/query',
            '/user/personal/purchaseRecord',
        ], true)
            || !isset($parts['query'])
            || !hash_equals('tradeNo=' . $tradeNo, (string)$parts['query'])) {
            throw new JSONException(Gateway::ERR_CONFIG);
        }
    }

    public static function notifyUrl(string $callbackUrl, string $origin, string $tradeNo): string
    {
        $flow = self::assertCallbackUrl($callbackUrl, $origin, $tradeNo);
        if (OrderId::isExplicitTest($tradeNo)) {
            return $callbackUrl;
        }
        if (preg_match('/^[0-9]{18}$/D', $tradeNo) !== 1) {
            throw new JSONException(Gateway::ERR_CONFIG);
        }
        return $origin . '/user/api/pikaBEpusdt/' . $flow . '.' . $tradeNo;
    }

    /** @return array<string,mixed> */
    private static function merchantUrlParts(string $url, string $origin): array
    {
        $trusted = self::httpsOrigin($origin);
        if ($url === '' || strlen($url) > 2048
            || str_contains($url, '\\')
            || preg_match('/[\x00-\x1f\x7f]/', $url) === 1
            || preg_match('/%(?:00|0a|0d|2f|40|3a|5c)/i', $url) === 1) {
            throw new JSONException(Gateway::ERR_CONFIG);
        }
        $parts = parse_url($url);
        if (!is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || isset($parts['fragment'])) {
            throw new JSONException(Gateway::ERR_CONFIG);
        }
        $actualOrigin = 'https://' . (string)($parts['host'] ?? '');
        if (!hash_equals($trusted, $actualOrigin)) {
            throw new JSONException(Gateway::ERR_CONFIG);
        }
        return $parts;
    }

    private static function httpsOrigin(string $origin): string
    {
        if ($origin === '' || strlen($origin) > 270 || $origin !== strtolower($origin)) {
            throw new JSONException(Gateway::ERR_CONFIG);
        }
        if (!str_starts_with($origin, 'https://') || preg_match('/[\x00-\x20\x7f\\\\]/', $origin) === 1) {
            throw new JSONException(Gateway::ERR_CONFIG);
        }
        $parts = parse_url($origin);
        if (!is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || isset($parts['path'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new JSONException(Gateway::ERR_CONFIG);
        }
        self::assertPublicDomain((string)($parts['host'] ?? ''));
        return $origin;
    }

    public static function paymentPath(string $url, string $tradeId): string
    {
        if ($url === '' || strlen($url) > 2048
            || str_contains($url, '\\')
            || str_contains($url, '%')
            || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new JSONException(Gateway::ERR_RESPONSE);
        }
        $parts = parse_url($url);
        if (!is_array($parts)
            || !in_array(($parts['scheme'] ?? ''), ['http', 'https'], true)
            || (string)($parts['host'] ?? '') === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new JSONException(Gateway::ERR_RESPONSE);
        }
        $path = (string)($parts['path'] ?? '');
        if (OrderId::isLegacyTradeId($tradeId)) {
            $expected = '/pay/checkout/' . $tradeId;
        } elseif (OrderId::isUuidTradeId($tradeId)) {
            $expected = '/pay/checkout-counter/' . $tradeId;
        } else {
            throw new JSONException(Gateway::ERR_RESPONSE);
        }
        if (!hash_equals($expected, $path)) {
            throw new JSONException(Gateway::ERR_RESPONSE);
        }
        return $path;
    }

    public static function isTestCallback(string $url, string $tradeNo): bool
    {
        $path = parse_url($url, PHP_URL_PATH);
        return is_string($path)
            && hash_equals('/user/api/order/callbackTest.' . $tradeNo, $path);
    }

    private static function assertPublicDomain(string $host): void
    {
        if ($host === '' || $host !== strtolower($host) || str_contains($host, '%')) {
            throw new JSONException(Gateway::ERR_CONFIG);
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/D', $host) !== 1) {
            throw new JSONException(Gateway::ERR_CONFIG);
        }
        $labels = explode('.', $host);
        $suffix = $labels[count($labels) - 1];
        if (count($labels) < 2 || in_array($suffix, [
            'local', 'localhost', 'internal', 'intranet', 'lan', 'home',
            'corp', 'private', 'localdomain',
        ], true)) {
            throw new JSONException(Gateway::ERR_CONFIG);
        }
    }
}
