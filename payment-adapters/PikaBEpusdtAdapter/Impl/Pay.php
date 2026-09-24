<?php
declare(strict_types=1);

namespace App\Pay\PikaBEpusdtAdapter\Impl;

use App\Entity\PayEntity;
use App\Pay\Base;
use App\Pay\PikaBEpusdtAdapter\Support\Amount;
use App\Pay\PikaBEpusdtAdapter\Support\Gateway;
use App\Pay\PikaBEpusdtAdapter\Support\OrderId;
use App\Pay\PikaBEpusdtAdapter\Support\SecretStore;
use App\Pay\PikaBEpusdtAdapter\Support\Settings;
use App\Pay\PikaBEpusdtAdapter\Support\UrlPolicy;
use GuzzleHttp\Exception\GuzzleException;
use Kernel\Exception\JSONException;
use Psr\Http\Message\ResponseInterface;

final class Pay extends Base implements \App\Pay\Pay
{
    private const TRADE_TYPES = ['usdt.bep20', 'tron.trx', 'usdt.trc20'];

    public function trade(): PayEntity
    {
        $settings = Settings::from($this->config);
        $flow = UrlPolicy::assertCallbackUrl(
            $this->callbackUrl,
            $settings->merchantOrigin,
            (string)$this->tradeNo,
        );
        UrlPolicy::assertReturnUrl(
            $this->returnUrl,
            $settings->merchantOrigin,
            (string)$this->tradeNo,
            $flow,
        );

        $amount = Amount::normalize($this->amount);
        if ($amount === null || !Amount::isPositive($amount)) {
            throw new JSONException(Gateway::ERR_REQUEST);
        }
        if (!in_array($this->code, self::TRADE_TYPES, true)) {
            throw new JSONException(Gateway::ERR_REQUEST);
        }

        $secret = SecretStore::load();
        $upstreamOrderId = OrderId::wrap(
            (string)$this->tradeNo,
            $secret['namespace'],
            $this->callbackUrl,
        );
        $request = [
            'order_id' => $upstreamOrderId,
            'amount' => (float)$amount,
            'notify_url' => UrlPolicy::notifyUrl($this->callbackUrl, $settings->merchantOrigin, (string)$this->tradeNo),
            'redirect_url' => $this->returnUrl,
            'trade_type' => $this->code,
            'fiat' => $settings->fiat,
        ];
        try {
            $request['signature'] = Signature::generateSignature($request, $secret['token']);
            $response = Gateway::client($settings)->post(Gateway::CREATE_TRANSACTION_PATH, [
                'json' => $request,
            ]);
        } catch (GuzzleException) {
            throw new JSONException(Gateway::ERR_REQUEST);
        } catch (\InvalidArgumentException) {
            throw new JSONException(Gateway::ERR_REQUEST);
        }

        $data = $this->parseCreateTransactionResponse($response, $request, $settings);
        $entity = new PayEntity();
        $entity->setType(self::TYPE_REDIRECT);
        $entity->setUrl($data['checkout_url']);
        $entity->setOption([
            'bepusdt_trade_id' => $data['trade_id'],
            'bepusdt_trade_type' => $data['trade_type'],
        ]);
        return $entity;
    }

    /**
     * @param array<string,mixed> $request
     * @return array{trade_id:string,trade_type:string,checkout_url:string}
     */
    public function parseCreateTransactionResponse(
        ResponseInterface $response,
        array $request,
        Settings $settings,
    ): array {
        if ($response->getStatusCode() !== 200) {
            throw new JSONException(Gateway::ERR_RESPONSE);
        }
        $contentType = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'), 2)[0]));
        if (!in_array($contentType, ['application/json', 'text/json'], true)) {
            throw new JSONException(Gateway::ERR_RESPONSE);
        }

        $body = $response->getBody();
        $size = $body->getSize();
        if ($size !== null && $size > Gateway::MAX_BODY) {
            throw new JSONException(Gateway::ERR_RESPONSE);
        }
        $raw = $body->read(Gateway::MAX_BODY + 1);
        if (!is_string($raw) || strlen($raw) > Gateway::MAX_BODY) {
            throw new JSONException(Gateway::ERR_RESPONSE);
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new JSONException(Gateway::ERR_RESPONSE);
        }
        if (!is_array($decoded)
            || ($decoded['status_code'] ?? null) !== 200
            || !is_array($decoded['data'] ?? null)) {
            throw new JSONException(Gateway::ERR_RESPONSE);
        }
        $payload = $decoded['data'];

        $orderId = $payload['order_id'] ?? null;
        $amount = Amount::normalize($payload['amount'] ?? null);
        $expectedAmount = Amount::normalize($request['amount'] ?? null);
        $fiat = $payload['fiat'] ?? null;
        $status = $payload['status'] ?? null;
        $tradeType = $payload['trade_type'] ?? null;
        $tradeId = $payload['trade_id'] ?? null;
        $paymentUrl = $payload['payment_url'] ?? null;

        if (!is_string($orderId)
            || !hash_equals((string)($request['order_id'] ?? ''), $orderId)
            || $amount === null
            || $expectedAmount === null
            || !hash_equals($expectedAmount, $amount)
            || !is_string($fiat)
            || !hash_equals((string)($request['fiat'] ?? ''), $fiat)
            || !((is_int($status) && $status === 1) || (is_string($status) && $status === '1'))
            || !is_string($tradeType)
            || !in_array($tradeType, self::TRADE_TYPES, true)
            || !hash_equals((string)($request['trade_type'] ?? ''), $tradeType)
            || !is_string($tradeId)
            || (!OrderId::isLegacyTradeId($tradeId) && !OrderId::isUuidTradeId($tradeId))
            || !is_string($paymentUrl)) {
            throw new JSONException(Gateway::ERR_RESPONSE);
        }

        $path = UrlPolicy::paymentPath($paymentUrl, $tradeId);
        return [
            'trade_id' => $tradeId,
            'trade_type' => $tradeType,
            'checkout_url' => $settings->checkoutOrigin . $path,
        ];
    }
}
