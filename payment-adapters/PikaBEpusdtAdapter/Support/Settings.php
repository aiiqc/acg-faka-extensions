<?php
declare(strict_types=1);

namespace App\Pay\PikaBEpusdtAdapter\Support;

use Kernel\Exception\JSONException;

final class Settings
{
    private const FIATS = ['CNY', 'USD', 'EUR', 'JPY', 'GBP'];

    private function __construct(
        public readonly string $gatewayOrigin,
        public readonly string $checkoutOrigin,
        public readonly string $merchantOrigin,
        public readonly string $fiat,
    ) {
    }

    /** @param array<string,mixed> $config */
    public static function from(array $config): self
    {
        $gateway = $config['gateway_origin'] ?? null;
        $checkout = $config['checkout_origin'] ?? null;
        $merchant = $config['merchant_origin'] ?? null;
        $fiat = $config['fiat'] ?? 'CNY';
        if (!is_string($gateway) || !is_string($checkout)
            || !is_string($merchant) || !is_string($fiat)) {
            throw new JSONException(Gateway::ERR_CONFIG);
        }
        if (!in_array($fiat, self::FIATS, true)) {
            throw new JSONException(Gateway::ERR_CONFIG);
        }

        $gatewayOrigin = UrlPolicy::gatewayOrigin($gateway);
        $checkoutOrigin = UrlPolicy::checkoutOrigin($checkout);
        $merchantOrigin = UrlPolicy::merchantOrigin($merchant);
        if (!hash_equals($merchantOrigin, $checkoutOrigin)) {
            throw new JSONException(Gateway::ERR_CONFIG);
        }

        return new self($gatewayOrigin, $checkoutOrigin, $merchantOrigin, $fiat);
    }
}
