<?php
declare(strict_types=1);

namespace App\Pay\PikaBEpusdtAdapter\Support;

use Kernel\Exception\JSONException;

final class OrderId
{
    private const PREFIX = 'pka1_';
    private const REAL_PATTERN = '/^[0-9]{18}$/D';
    private const TEST_PATTERN = '/^TEST_[A-Za-z0-9_-]{1,27}$/D';
    private const NAMESPACE_PATTERN = '/^[a-z0-9]{4,12}$/D';
    private const LEGACY_TRADE_PATTERN = '/^[0-9A-Za-z]{18}$/D';
    private const UUID_TRADE_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

    public static function wrap(string $local, string $namespace, string $callbackUrl): string
    {
        self::assertNamespace($namespace);
        if (preg_match(self::REAL_PATTERN, $local) !== 1) {
            if (preg_match(self::TEST_PATTERN, $local) !== 1
                || !UrlPolicy::isTestCallback($callbackUrl, $local)) {
                throw new JSONException(Gateway::ERR_ORDER);
            }
        }
        return self::PREFIX . $namespace . '_' . $local;
    }

    public static function unwrap(string $upstream, string $namespace): ?string
    {
        self::assertNamespace($namespace);
        $prefix = self::PREFIX . $namespace . '_';
        if (!str_starts_with($upstream, $prefix)) {
            return null;
        }
        $local = substr($upstream, strlen($prefix));
        if (preg_match(self::REAL_PATTERN, $local) === 1
            || preg_match(self::TEST_PATTERN, $local) === 1) {
            return $local;
        }
        return null;
    }

    public static function isExplicitTest(string $local): bool
    {
        return preg_match(self::TEST_PATTERN, $local) === 1;
    }

    public static function isTestRequestUri(string $uri, string $local): bool
    {
        $path = parse_url($uri, PHP_URL_PATH);
        return is_string($path)
            && hash_equals('/user/api/order/callbackTest.' . $local, $path);
    }

    public static function isLegacyTradeId(string $tradeId): bool
    {
        return preg_match(self::LEGACY_TRADE_PATTERN, $tradeId) === 1;
    }

    public static function isUuidTradeId(string $tradeId): bool
    {
        return preg_match(self::UUID_TRADE_PATTERN, $tradeId) === 1;
    }

    private static function assertNamespace(string $namespace): void
    {
        if (preg_match(self::NAMESPACE_PATTERN, $namespace) !== 1) {
            throw new JSONException(Gateway::ERR_SECRET);
        }
    }
}
