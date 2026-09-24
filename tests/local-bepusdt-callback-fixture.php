<?php
declare(strict_types=1);

// Isolated child of local-bepusdt-adapter-behavior.php. Only Signature,
// SecretStore, OrderId and the managed controller are production code here.
// Models, the official initializer/service, Decimal, DI and DB are test doubles.
namespace Kernel\Exception {
    class JSONException extends \RuntimeException {}
}

namespace Kernel\Consts {
    interface Base { public const ROUTE = 'URL_LOCAL_ROUTE'; }
}

namespace Kernel\Annotation {
    #[\Attribute(\Attribute::TARGET_CLASS)]
    final class Interceptor
    {
        public const TYPE_API = 1;
        public function __construct(mixed $interceptors, mixed $type = null) {}
    }
}

namespace Kernel\Context\Interface {
    interface Request { public function raw(): string; }
}

namespace Kernel\Util {
    final class Context
    {
        private static array $values = [];
        public static function get(string $key): mixed { return self::$values[$key] ?? null; }
        public static function set(string $key, mixed $value): void { self::$values[$key] = $value; }
    }

    final class Decimal
    {
        public function __construct(private string|float|int $amount, private int $scale = 2) {}
        public function getAmount(?int $scale = 2): string
        {
            // The fixture uses bounded nonnegative decimal strings only. This
            // shim does not establish equivalence to the official bcmath code.
            if (!preg_match('/^([0-9]+)(?:\.([0-9]+))?$/D', (string)$this->amount, $parts)) {
                throw new \InvalidArgumentException('unsupported fixture amount');
            }
            return (ltrim($parts[1], '0') ?: '0') . '.'
                . substr(str_pad($parts[2] ?? '', $scale ?? $this->scale, '0'), 0, $scale ?? $this->scale);
        }
    }
}

namespace App\Consts {
    interface Pay { public const DAFA = 'FROM_PAY_DATA'; }
}

namespace App\Pay {
    interface Signature { public function verification(array $data, array $config): bool; }
}

namespace App\Interceptor { final class Waf {} }

namespace App\Model {
    final class Pay
    {
        public function __construct(public int $id, public string $handle, public int $pay_config_id) {}
    }

    class CallbackRecord
    {
        public int $id;
        public string $trade_no;
        public int $pay_id;
        public int $status;
        public ?string $pay_time;
        public ?string $gateway_amount;
        public string $amount;
        public ?Pay $pay;

        public function __construct(array $row)
        {
            foreach ($row as $key => $value) {
                $this->$key = $key === 'pay' && is_array($value) ? new Pay(...$value) : $value;
            }
        }

        public static function with(array $relations): \CallbackFixtureQuery
        {
            return new \CallbackFixtureQuery(static::class);
        }

        public function fresh(array $relations): ?static
        {
            $row = \CallbackFixtureState::$record;
            switch (\CallbackFixtureState::$scenario) {
                case 'fresh_missing': return null;
                case 'fresh_channel': $row['pay']['handle'] = 'OtherAdapter'; break;
                case 'fresh_profile': $row['pay']['pay_config_id']++; break;
                case 'fresh_pay_id': $row['pay_id']++; break;
            }
            return new static($row);
        }
    }
    final class Order extends CallbackRecord {}
    final class UserRecharge extends CallbackRecord {}
}

namespace App\Util {
    final class CallbackIpWhitelist
    {
        public static function enforce(): void
        {
            \CallbackFixtureState::$whitelistCalls++;
            if (\CallbackFixtureState::$scenario === 'ip_rejected') {
                throw new \RuntimeException('fixture IP denied');
            }
        }
    }
    final class PayProfile
    {
        public static function config(\App\Model\Pay $pay): array
        {
            if ($pay->handle !== 'PikaBEpusdtAdapter' || $pay->pay_config_id !== 1) {
                throw new \RuntimeException('fixture profile mismatch');
            }
            return ['fixture' => true];
        }
    }
}

namespace App\Service {
    class Order
    {
        public function callbackInitialize(\App\Model\Pay $pay, array $map, ?array $config = null): array
        {
            \CallbackFixtureState::$initializeCalls++;
            \Kernel\Util\Context::set(\App\Consts\Pay::DAFA, $map);
            $signature = new \App\Pay\PikaBEpusdtAdapter\Impl\Signature();
            if (!$signature->verification($map, $config ?? [])) {
                throw new \RuntimeException('fixture signature rejected');
            }
            $verified = \Kernel\Util\Context::get(\App\Consts\Pay::DAFA);
            return ['trade_no' => $verified['order_id'], 'amount' => $verified['amount'], 'success' => 'ok'];
        }

        public function callback(string $tradeNo, array $map): string
        {
            \CallbackFixtureState::$callbackCalls++;
            if ((static::class === self::class) !== (\CallbackFixtureState::$flow === 'order')) {
                throw new \LogicException('fixture used the wrong native business service');
            }
            if (\CallbackFixtureState::$scenario === 'business_throw') {
                // A duplicate-looking error must never be interpreted as ACK.
                throw new \RuntimeException('订单已支付 ok success');
            }
            if (\CallbackFixtureState::$scenario === 'bad_success') {
                return 'success';
            }
            $row = \CallbackFixtureState::$record;
            $verified = $this->callbackInitialize(new \App\Model\Pay(...$row['pay']), $map, ['fixture' => true]);
            if ($row['status'] !== 0 || $tradeNo !== $verified['trade_no']) {
                throw new \RuntimeException('fixture native duplicate or identity rejection');
            }
            if (\CallbackFixtureState::$scenario === 'commit_pending') {
                \CallbackFixtureState::$transactionLevel = 1;
                \CallbackFixtureState::$pdoTransaction = true;
                return 'ok';
            }
            \CallbackFixtureState::$record['status'] = 1;
            \CallbackFixtureState::$record['pay_time'] = '2026-09-16 00:00:00';
            \CallbackFixtureState::$ledger++;
            return 'ok';
        }
    }
    final class Recharge extends Order {}
}

namespace Kernel\Container {
    final class Di
    {
        public static function inst(): self { return new self(); }
        public function make(string $class): object { return new $class(); }
    }
}

namespace Illuminate\Database\Capsule {
    final class Manager
    {
        public static function connection(): \CallbackFixtureConnection { return new \CallbackFixtureConnection(); }
    }
}

namespace {
    final class CallbackFixtureState
    {
        public static string $flow;
        public static string $scenario;
        public static array $record;
        public static int $ledger = 0;
        public static int $callbackCalls = 0;
        public static int $initializeCalls = 0;
        public static int $whitelistCalls = 0;
        public static int $transactionLevel = 0;
        public static bool $pdoTransaction = false;
    }
    final class CallbackFixtureConnection
    {
        public function transactionLevel(): int { return CallbackFixtureState::$transactionLevel; }
        public function getPdo(): self { return $this; }
        public function inTransaction(): bool { return CallbackFixtureState::$pdoTransaction; }
    }
    final class CallbackFixtureQuery
    {
        private string $tradeNo = '';
        public function __construct(private string $class) {}
        public function where(string $key, mixed $value): self
        {
            if ($key !== 'trade_no') throw new \LogicException('unexpected fixture query');
            $this->tradeNo = $value;
            return $this;
        }
        public function first(): ?\App\Model\CallbackRecord
        {
            $expected = CallbackFixtureState::$flow === 'order' ? \App\Model\Order::class : \App\Model\UserRecharge::class;
            if ($this->class !== $expected) throw new \LogicException('fixture used the wrong order table');
            if (CallbackFixtureState::$scenario === 'unknown_order'
                || $this->tradeNo !== CallbackFixtureState::$record['trade_no']) return null;
            $class = $this->class;
            return new $class(CallbackFixtureState::$record);
        }
    }
    final class CallbackFixtureRequest implements \Kernel\Context\Interface\Request
    {
        public function __construct(private string $raw) {}
        public function raw(): string { return $this->raw; }
    }

    if (getenv('PIKA_BEPUSDT_TEST_CONTAINER') !== '1' || !function_exists('posix_geteuid') || posix_geteuid() !== 0
        || !isset($argv[1]) || !is_dir($argv[1])) {
        fwrite(STDERR, "FAIL: callback fixture requires its parent isolated root test\n");
        exit(1);
    }
    define('BASE_PATH', $argv[1]);
    $input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
    $flow = $input['flow'];
    if (!in_array($flow, ['order', 'recharge'], true)) throw new \LogicException('invalid fixture flow');
    CallbackFixtureState::$flow = $flow;
    CallbackFixtureState::$scenario = $input['scenario'];
    CallbackFixtureState::$record = $input['record'];
    CallbackFixtureState::$ledger = $input['ledger'];
    $scenario = CallbackFixtureState::$scenario;
    if ($scenario === 'wrong_channel') CallbackFixtureState::$record['pay']['handle'] = 'OtherAdapter';
    if ($scenario === 'unknown_status') CallbackFixtureState::$record['status'] = 2;
    if ($scenario === 'paid_no_time') CallbackFixtureState::$record['pay_time'] = null;
    if ($scenario === 'outer_transaction') CallbackFixtureState::$transactionLevel = 1;
    if ($scenario === 'outer_pdo') CallbackFixtureState::$pdoTransaction = true;

    $source = dirname(__DIR__) . '/payment-adapters/PikaBEpusdtAdapter';
    foreach (['Support/Gateway.php', 'Support/UrlPolicy.php', 'Support/OrderId.php', 'Support/SecretStore.php', 'Impl/Signature.php'] as $file) {
        require $source . '/' . $file;
    }
    require dirname(__DIR__) . '/manager/site/app/Controller/User/Api/PikaBEpusdt.php';

    $tradeNo = CallbackFixtureState::$record['trade_no'];
    $route = '/user/api/pikaBEpusdt/' . $flow . '.' . $tradeNo;
    $_SERVER['REQUEST_METHOD'] = $scenario === 'wrong_method' ? 'GET' : 'POST';
    $_SERVER['CONTENT_TYPE'] = $scenario === 'wrong_content_type' ? 'text/plain' : 'application/json';
    $_SERVER['REQUEST_URI'] = $scenario === 'wrong_uri' ? $route . '/extra' : $route;
    $_GET['_PARAMETER'] = $scenario === 'extra_parameter' ? [$tradeNo, 'extra'] : [$tradeNo];
    \Kernel\Util\Context::set(\Kernel\Consts\Base::ROUTE, $route);
    \Kernel\Util\Context::set(\Kernel\Context\Interface\Request::class,
        new CallbackFixtureRequest(json_encode($input['map'], JSON_THROW_ON_ERROR)));
    if ($scenario === 'stale_context') {
        \Kernel\Util\Context::set(\App\Consts\Pay::DAFA,
            ['order_id' => $tradeNo, 'amount' => '10.00', 'status' => 2]);
    }

    ob_start();
    register_shutdown_function(static function (): void {
        $body = ob_get_clean();
        $error = error_get_last();
        fwrite(STDOUT, json_encode([
            'http_status' => http_response_code(), 'body' => $body,
            'callback_calls' => CallbackFixtureState::$callbackCalls,
            'initialize_calls' => CallbackFixtureState::$initializeCalls,
            'whitelist_calls' => CallbackFixtureState::$whitelistCalls,
            'ledger' => CallbackFixtureState::$ledger, 'record' => CallbackFixtureState::$record,
            'fatal' => $error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true),
        ], JSON_THROW_ON_ERROR));
    });
    $controller = new \App\Controller\User\Api\PikaBEpusdt();
    $controller->$flow();
}
