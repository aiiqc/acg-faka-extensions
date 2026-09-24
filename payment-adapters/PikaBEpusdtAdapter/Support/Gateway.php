<?php
declare(strict_types=1);

namespace App\Pay\PikaBEpusdtAdapter\Support;

use GuzzleHttp\Client;
use Kernel\Util\Context;

final class Gateway
{
    public const CREATE_TRANSACTION_PATH = '/api/v1/order/create-transaction';
    public const MAX_BODY = 65536;
    public const CTX_CLIENT = 'pika.bepusdt.client';

    public const ERR_CONFIG = 'BEpusdt 配置不可用';
    public const ERR_SECRET = 'BEpusdt 凭据不可用';
    public const ERR_ORDER = 'BEpusdt 订单号格式不可用';
    public const ERR_REQUEST = 'BEpusdt 暂时无法建立交易';
    public const ERR_RESPONSE = 'BEpusdt 返回了无效响应';

    /** @return array<string,mixed> */
    public static function clientOptions(Settings $settings): array
    {
        return [
            'base_uri' => $settings->gatewayOrigin . '/',
            'verify' => true,
            'connect_timeout' => 2,
            'timeout' => 10,
            'read_timeout' => 10,
            'allow_redirects' => false,
            'http_errors' => false,
            'proxy' => '',
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'PikaBEpusdtAdapter/0.1',
            ],
        ];
    }

    public static function client(Settings $settings): Client
    {
        $override = Context::get(self::CTX_CLIENT);
        if ($override instanceof Client) {
            return $override;
        }
        return new Client(self::clientOptions($settings));
    }
}
