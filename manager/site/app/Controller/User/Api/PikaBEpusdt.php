<?php
declare(strict_types=1);

namespace App\Controller\User\Api;

use App\Interceptor\Waf;
use App\Model\Order;
use App\Model\UserRecharge;
use App\Service\Order as OrderService;
use App\Service\Recharge as RechargeService;
use App\Util\CallbackIpWhitelist;
use App\Util\PayProfile;
use Illuminate\Database\Capsule\Manager as DB;
use Kernel\Annotation\Interceptor;
use Kernel\Consts\Base;
use Kernel\Container\Di;
use Kernel\Context\Interface\Request;
use Kernel\Util\Context;
use Kernel\Util\Decimal;

/** Only the BE adapter's new, real-order notifications use this endpoint. */
#[Interceptor(Waf::class, Interceptor::TYPE_API)]
final class PikaBEpusdt
{
    private const MAX_BODY = 65536;

    public function __construct()
    {
        // Kernel constructs the controller before interceptors and injection.
        // Never let a later validation exception inherit the default HTTP 200.
        http_response_code(500);
    }

    public function order(): never
    {
        $this->callback('order');
    }

    public function recharge(): never
    {
        $this->callback('recharge');
    }

    private function callback(string $flow): never
    {
        try {
            if (headers_sent() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
                || strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0])) !== 'application/json') {
                throw new \RuntimeException('Invalid callback request.');
            }
            $parameters = $_GET['_PARAMETER'] ?? null;
            if (!is_array($parameters) || array_keys($parameters) !== [0]
                || !is_string($parameters[0]) || preg_match('/^[0-9]{18}$/D', $parameters[0]) !== 1) {
                throw new \RuntimeException('Invalid callback route.');
            }
            $tradeNo = $parameters[0];
            $route = '/user/api/pikaBEpusdt/' . $flow . '.' . $tradeNo;
            if (($_SERVER['REQUEST_URI'] ?? '') !== $route || Context::get(Base::ROUTE) !== $route) {
                throw new \RuntimeException('Invalid callback route.');
            }
            CallbackIpWhitelist::enforce();
            $request = Context::get(Request::class);
            if (!$request instanceof Request) {
                throw new \RuntimeException('Callback request is unavailable.');
            }
            $raw = $request->raw();
            if (!is_string($raw) || $raw === '' || strlen($raw) > self::MAX_BODY) {
                throw new \RuntimeException('Invalid callback body.');
            }
            $decoded = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
            if (!$decoded instanceof \stdClass) {
                throw new \RuntimeException('Invalid callback body.');
            }
            $map = get_object_vars($decoded);

            // An outer transaction would turn the native commit into a
            // savepoint, so it must not be mistaken for durable completion.
            $connection = DB::connection();
            self::assertCommitted($connection);
            $order = $flow === 'order'
                ? Order::with(['pay'])->where('trade_no', $tradeNo)->first()
                : UserRecharge::with(['pay'])->where('trade_no', $tradeNo)->first();
            self::assertAdapterOrder($order, $tradeNo);
            $payId = (int)$order->pay_id;
            $profileId = (int)$order->pay->pay_config_id;

            $orderService = Di::inst()->make(OrderService::class);
            // This is the same initializer used by both native callbacks.
            // It verifies this request afresh, including the adapter signature,
            // paid status and namespace; it does not fulfill or credit orders.
            $verified = $orderService->callbackInitialize($order->pay, $map, PayProfile::config($order->pay));
            if (($verified['success'] ?? null) !== 'ok'
                || !is_string($verified['trade_no'] ?? null)
                || !hash_equals($tradeNo, $verified['trade_no'])) {
                throw new \RuntimeException('Callback identity was rejected.');
            }

            // Refresh after authentication: a concurrent native callback may
            // have committed while verification was running. Never reuse a
            // previous request's payment context or infer success from errors.
            $order = $order->fresh(['pay']);
            self::assertAdapterOrder($order, $tradeNo);
            if ((int)$order->pay_id !== $payId || (int)$order->pay->pay_config_id !== $profileId) {
                throw new \RuntimeException('Callback binding changed.');
            }
            $paidAmount = $verified['amount'] ?? null;
            if (!is_scalar($paidAmount) || !is_numeric((string)$paidAmount)) {
                throw new \RuntimeException('Callback amount was rejected.');
            }
            $expected = $order->gateway_amount !== null ? (string)$order->gateway_amount : (string)$order->amount;
            if (!hash_equals((new Decimal($expected, 2))->getAmount(), (new Decimal((string)$paidAmount, 2))->getAmount())) {
                throw new \RuntimeException('Callback amount was rejected.');
            }

            if ((int)$order->status === 1) {
                // Lost ACK: only an authenticated, amount-matched committed
                // paid record can be acknowledged without calling fulfillment.
                if (!is_string($order->pay_time) || $order->pay_time === '') {
                    throw new \RuntimeException('Paid completion is unavailable.');
                }
            } elseif ((int)$order->status === 0) {
                $service = $flow === 'order' ? $orderService : Di::inst()->make(RechargeService::class);
                if ($service->callback($tradeNo, $map) !== 'ok') {
                    throw new \RuntimeException('Native callback was not acknowledged.');
                }
            } else {
                throw new \RuntimeException('Callback state was rejected.');
            }
            self::assertCommitted($connection);
        } catch (\Throwable) {
            $this->respond(500, 'fail');
        }

        $this->respond(200, 'ok');
    }

    private static function assertAdapterOrder(mixed $order, string $tradeNo): void
    {
        if (!$order || !$order->pay || (string)$order->pay->handle !== 'PikaBEpusdtAdapter'
            || !hash_equals($tradeNo, (string)$order->trade_no)) {
            throw new \RuntimeException('Callback binding was rejected.');
        }
    }

    private static function assertCommitted(object $connection): void
    {
        if ($connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
            throw new \RuntimeException('Callback transaction is not committed.');
        }
    }

    private function respond(int $status, string $body): never
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        // This endpoint owns its response; payment-service hooks have already
        // run, but global controller response hooks must not rewrite the ACK.
        exit($body);
    }
}
