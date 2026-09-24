<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaOrderReturnWait\Service;

final class WaitPage
{
    public const GUEST_ROUTE = '/user/index/query';
    public const MEMBER_ROUTE = '/user/personal/purchaserecord';

    private const DEFAULT_WAIT_SECONDS = 15;
    private const DEFAULT_REFRESH_SECONDS = 2;
    private const MIN_WAIT_SECONDS = 5;
    private const MAX_WAIT_SECONDS = 120;
    private const MIN_REFRESH_SECONDS = 1;
    private const MAX_REFRESH_SECONDS = 10;

    /**
     * @return array{waitSeconds: int, refreshSeconds: int}
     */
    public static function normalizeConfig(array $config): array
    {
        $waitSeconds = self::clamp(
            self::integer($config['wait_seconds'] ?? null, self::DEFAULT_WAIT_SECONDS),
            self::MIN_WAIT_SECONDS,
            self::MAX_WAIT_SECONDS
        );
        $refreshSeconds = self::clamp(
            self::integer($config['refresh_seconds'] ?? null, self::DEFAULT_REFRESH_SECONDS),
            self::MIN_REFRESH_SECONDS,
            self::MAX_REFRESH_SECONDS
        );

        return [
            'waitSeconds' => $waitSeconds,
            'refreshSeconds' => min($refreshSeconds, $waitSeconds),
        ];
    }

    public static function render(string $route, mixed $tradeNo, array $config): string
    {
        $mode = self::mode($route);
        $tradeNo = self::tradeNo($tradeNo);
        if ($mode === null || $tradeNo === null) {
            return '';
        }

        $settings = self::normalizeConfig($config);
        $queryPath = $mode === 'guest'
            ? self::GUEST_ROUTE
            : '/user/personal/purchaseRecord';
        $queryUrl = $queryPath . '?tradeNo=' . rawurlencode($tradeNo);
        $base = '/assets/local-extensions/PikaOrderReturnWait/';
        $version = rawurlencode(self::version());

        return sprintf(
            '<link rel="stylesheet" href="%1$sorder-return-wait.css?v=%2$s">'
            . '<div class="pika-order-wait" data-pika-order-wait hidden'
            . ' data-mode="%3$s" data-trade-no="%4$s" data-wait-seconds="%5$d"'
            . ' data-refresh-seconds="%6$d" data-query-url="%7$s">'
            . '<div class="pika-order-wait__card" role="status" aria-live="polite" aria-atomic="true">'
            . '<span class="pika-order-wait__spinner" aria-hidden="true"></span>'
            . '<h2 class="pika-order-wait__title">正在确认付款并获取商品</h2>'
            . '<p class="pika-order-wait__message" data-pika-order-wait-message>请稍候，页面会自动刷新。</p>'
            . '<div class="pika-order-wait__actions">'
            . '<a class="pika-order-wait__link" data-pika-order-wait-query data-pjax="false" href="%7$s">查询订单</a>'
            . '<button class="pika-order-wait__cancel" data-pika-order-wait-cancel type="button">取消自动刷新</button>'
            . '</div></div></div>'
            . '<script src="%1$sorder-return-wait.js?v=%2$s"></script>',
            $base,
            $version,
            self::escape($mode),
            self::escape($tradeNo),
            $settings['waitSeconds'],
            $settings['refreshSeconds'],
            self::escape($queryUrl)
        );
    }

    private static function mode(string $route): ?string
    {
        return match (strtolower(rtrim($route, '/'))) {
            self::GUEST_ROUTE => 'guest',
            self::MEMBER_ROUTE => 'member',
            default => null,
        };
    }

    private static function version(): string
    {
        static $version;
        if (is_string($version)) {
            return $version;
        }
        $manifestPath = dirname(__DIR__) . '/local-extension.json';
        $bytes = is_file($manifestPath) && !is_link($manifestPath)
            ? file_get_contents($manifestPath)
            : false;
        try {
            $manifest = is_string($bytes)
                ? json_decode($bytes, true, 16, JSON_THROW_ON_ERROR)
                : null;
        } catch (\JsonException) {
            $manifest = null;
        }
        $candidate = is_array($manifest) ? ($manifest['version'] ?? null) : null;
        if (!is_string($candidate)
            || preg_match('/^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?$/D', $candidate) !== 1) {
            throw new \RuntimeException('PikaOrderReturnWait manifest version is invalid.');
        }
        return $version = $candidate;
    }

    private static function tradeNo(mixed $tradeNo): ?string
    {
        if (!is_string($tradeNo) && !is_int($tradeNo)) {
            return null;
        }

        $tradeNo = trim((string)$tradeNo);
        return preg_match('/^\d{18}$/D', $tradeNo) === 1 ? $tradeNo : null;
    }

    private static function integer(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/D', trim($value)) === 1) {
            return (int)$value;
        }

        return $default;
    }

    private static function clamp(int $value, int $minimum, int $maximum): int
    {
        return max($minimum, min($maximum, $value));
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
