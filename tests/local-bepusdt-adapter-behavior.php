<?php
declare(strict_types=1);

namespace Kernel\Exception {
    class JSONException extends \RuntimeException {}
}

namespace Kernel\Util {
    final class Context
    {
        private static array $values = [];
        public static function get(string $key): mixed { return self::$values[$key] ?? null; }
        public static function set(string $key, mixed $value): void { self::$values[$key] = $value; }
    }
}

namespace App\Consts {
    interface Pay
    {
        public const IS_SIGN = 0x1;
        public const IS_STATUS = 0x4;
        public const FIELD_STATUS_KEY = 0x2;
        public const FIELD_STATUS_VALUE = 0x3;
        public const FIELD_ORDER_KEY = 0x5;
        public const FIELD_AMOUNT_KEY = 0x6;
        public const FIELD_RESPONSE = 0x7;
        public const DAFA = 'FROM_PAY_DATA';
    }
}

namespace App\Entity {
    final class PayEntity
    {
        private int $type;
        private string $url;
        private array $option = [];
        public function setType(int $type): void { $this->type = $type; }
        public function getType(): int { return $this->type; }
        public function setUrl(string $url): void { $this->url = $url; }
        public function getUrl(): string { return $this->url; }
        public function setOption(array $option): void { $this->option = $option; }
        public function getOption(): array { return $this->option; }
    }
}

namespace App\Pay {
    abstract class Base
    {
        public float $amount;
        public string $tradeNo;
        public array $config;
        public string $callbackUrl;
        public string $returnUrl;
        public string $clientIp;
        public string $code;
        public string $handle;
    }
    interface Pay
    {
        public const TYPE_REDIRECT = 2;
        public function trade(): \App\Entity\PayEntity;
    }
    interface Signature
    {
        public function verification(array $data, array $config): bool;
    }
}

namespace Psr\Http\Message {
    interface ResponseInterface
    {
        public function getStatusCode(): int;
        public function getHeaderLine(string $name): string;
        public function getBody(): mixed;
    }
}

namespace GuzzleHttp\Exception {
    interface GuzzleException extends \Throwable {}
}

namespace GuzzleHttp {
    class Client
    {
        public static mixed $response = null;
        public static array $lastRequest = [];
        public function __construct(public array $options = []) {}
        public function post(string $path, array $options): mixed
        {
            self::$lastRequest = ['path' => $path, 'options' => $options, 'client' => $this->options];
            return self::$response;
        }
    }
}

namespace {
    use App\Consts\Pay as PayConsts;
    use App\Pay\PikaBEpusdtAdapter\Impl\Pay;
    use App\Pay\PikaBEpusdtAdapter\Impl\Signature;
    use App\Pay\PikaBEpusdtAdapter\Support\Gateway;
    use App\Pay\PikaBEpusdtAdapter\Support\OrderId;
    use App\Pay\PikaBEpusdtAdapter\Support\SecretStore;
    use App\Pay\PikaBEpusdtAdapter\Support\Settings;
    use App\Pay\PikaBEpusdtAdapter\Support\UrlPolicy;
    use GuzzleHttp\Client;
    use Kernel\Exception\JSONException;
    use Kernel\Util\Context;
    use Psr\Http\Message\ResponseInterface;

    if (getenv('PIKA_BEPUSDT_TEST_CONTAINER') !== '1' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        fwrite(STDERR, "FAIL: behavior test must run in its isolated root container\n");
        exit(1);
    }

    function expectBep(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    function throwsBep(callable $callback, string $message): void
    {
        try {
            $callback();
        } catch (JSONException) {
            return;
        }
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }

    final class FakeBody
    {
        public function __construct(private string $body, private ?int $size = null) {}
        public function getSize(): ?int { return $this->size ?? strlen($this->body); }
        public function read(int $length): string { return substr($this->body, 0, $length); }
    }

    final class FakeResponse implements ResponseInterface
    {
        public function __construct(
            private array $payload,
            private int $status = 200,
            private string $contentType = 'application/json',
            private ?string $raw = null,
            private ?int $declaredSize = null,
        ) {}
        public function getStatusCode(): int { return $this->status; }
        public function getHeaderLine(string $name): string
        {
            return strtolower($name) === 'content-type' ? $this->contentType : '';
        }
        public function getBody(): FakeBody
        {
            $raw = $this->raw ?? json_encode($this->payload, JSON_THROW_ON_ERROR);
            return new FakeBody($raw, $this->declaredSize);
        }
    }

    $siteRoot = sys_get_temp_dir() . '/pika-bepusdt-site-' . getmypid();
    mkdir($siteRoot, 0755, true);
    define('BASE_PATH', $siteRoot);
    $siteHash = hash('sha256', realpath($siteRoot));
    $stateRoot = '/var/lib/pika-local-extensions';
    mkdir($stateRoot, 0755, true);
    mkdir($stateRoot . '/sites', 0755, true);
    $siteState = $stateRoot . '/sites/' . $siteHash;
    mkdir($siteState, 0755, true);
    mkdir($siteState . '/runtime', 0750, true);
    mkdir($siteState . '/secrets', 0750, true);
    chmod($stateRoot, 0755);
    chmod($stateRoot . '/sites', 0755);
    chmod($siteState, 0755);
    chmod($siteState . '/runtime', 0750);
    chmod($siteState . '/secrets', 0750);
    $token = 'epusdt_password_xasddawqe';
    $namespace = 's0demo';
    file_put_contents($siteState . '/secrets/bepusdt-token', $token);
    file_put_contents($siteState . '/secrets/bepusdt-namespace', $namespace);
    chmod($siteState . '/secrets/bepusdt-token', 0640);
    chmod($siteState . '/secrets/bepusdt-namespace', 0640);

    $source = dirname(__DIR__) . '/payment-adapters/PikaBEpusdtAdapter';
    foreach ([
        'Support/Gateway.php', 'Support/Amount.php', 'Support/UrlPolicy.php',
        'Support/Settings.php', 'Support/OrderId.php', 'Support/SecretStore.php',
        'Impl/Signature.php', 'Impl/Pay.php',
    ] as $file) {
        require $source . '/' . $file;
    }

    $secret = SecretStore::load();
    expectBep($secret === ['token' => $token, 'namespace' => $namespace], 'root-provisioned secrets load');
    expectBep(str_ends_with(SecretStore::directory(), '/' . $siteHash . '/secrets'), 'state path binds canonical site root');

    $settings = Settings::from([
        'gateway_origin' => 'http://127.0.0.1:8080',
        'checkout_origin' => 'https://catalog.example.com',
        'merchant_origin' => 'https://catalog.example.com',
        'fiat' => 'CNY',
    ]);
    expectBep($settings->gatewayOrigin === 'http://127.0.0.1:8080', 'IPv4 loopback gateway accepted');
    expectBep(
        Settings::from([
            'gateway_origin' => 'http://[::1]:8080',
            'checkout_origin' => 'https://catalog.example.com',
            'merchant_origin' => 'https://catalog.example.com',
            'fiat' => 'USD',
        ])->gatewayOrigin === 'http://[::1]:8080',
        'IPv6 loopback gateway accepted'
    );
    throwsBep(
        fn() => Settings::from([
            'gateway_origin' => 'http://127.0.0.1:8080',
            'checkout_origin' => 'https://pay.example.com',
            'merchant_origin' => 'https://catalog.example.com',
            'fiat' => 'CNY',
        ]),
        'split checkout and merchant origins must fail in V0.1'
    );
    foreach ([
        'http://localhost:8080', 'http://0.0.0.0:8080', 'http://127.0.0.1',
        'http://127.0.0.1:0', 'http://127.0.0.1:65536', 'http://127.0.0.1:8080/path',
        'https://127.0.0.1:8080',
    ] as $origin) {
        throwsBep(fn() => UrlPolicy::gatewayOrigin($origin), "unsafe gateway accepted: {$origin}");
    }
    foreach ([
        'http://pay.example.com', 'https://PAY.example.com', 'https://pay.example.com:443',
        'https://pay.example.com/path', 'https://127.0.0.1', 'https://localhost',
        'https://pay.internal',
    ] as $origin) {
        throwsBep(fn() => UrlPolicy::checkoutOrigin($origin), "unsafe checkout accepted: {$origin}");
    }
    $orderFlow = UrlPolicy::assertCallbackUrl(
        'https://catalog.example.com/user/api/order/callback.123456789012345678',
        'https://catalog.example.com',
        '123456789012345678',
    );
    expectBep($orderFlow === 'order', 'order callback flow was not identified');
    UrlPolicy::assertReturnUrl(
        'https://catalog.example.com/user/index/query?tradeNo=123456789012345678',
        'https://catalog.example.com',
        '123456789012345678',
        $orderFlow,
    );
    $rechargeFlow = UrlPolicy::assertCallbackUrl(
        'https://catalog.example.com/user/api/rechargeNotification/callback.123456789012345678',
        'https://catalog.example.com',
        '123456789012345678',
    );
    expectBep($rechargeFlow === 'recharge', 'recharge callback flow was not identified');
    UrlPolicy::assertReturnUrl(
        'https://catalog.example.com/user/recharge/index',
        'https://catalog.example.com',
        '123456789012345678',
        $rechargeFlow,
    );
    foreach ([
        'https://attacker.example/user/api/order/callback.123456789012345678',
        'https://catalog.example.com/user/api/order/callback.000000000000000000',
        'https://catalog.example.com/user/api/order/callback.123456789012345678?x=1',
    ] as $callback) {
        throwsBep(
            fn() => UrlPolicy::assertCallbackUrl(
                $callback,
                'https://catalog.example.com',
                '123456789012345678',
            ),
            "unbound callback accepted: {$callback}"
        );
    }
    foreach ([
        'https://attacker.example/user/index/query?tradeNo=123456789012345678',
        'https://catalog.example.com/user/index/query?tradeNo=000000000000000000',
        'https://catalog.example.com/user/index/query?tradeNo=123456789012345678&next=https://attacker.example',
    ] as $returnUrl) {
        throwsBep(
            fn() => UrlPolicy::assertReturnUrl(
                $returnUrl,
                'https://catalog.example.com',
                '123456789012345678',
            ),
            "unbound return URL accepted: {$returnUrl}"
        );
    }
    foreach ([
        ['https://catalog.example.com/user/recharge/index', $orderFlow],
        ['https://catalog.example.com/user/index/query?tradeNo=123456789012345678', $rechargeFlow],
        ['https://catalog.example.com/user/recharge/index?tradeNo=123456789012345678', $rechargeFlow],
    ] as [$returnUrl, $flow]) {
        throwsBep(
            fn() => UrlPolicy::assertReturnUrl(
                $returnUrl,
                'https://catalog.example.com',
                '123456789012345678',
                $flow,
            ),
            "mismatched payment flow return URL accepted: {$returnUrl}"
        );
    }

    expectBep(
        Signature::generateSignature([
            'order_id' => '20220201030210321',
            'amount' => 42.0,
            'notify_url' => 'http://example.com/notify',
            'redirect_url' => 'http://example.com/redirect',
        ], $token) === '1cd4b52df5587cfb1968b0c0c6e156cd',
        'official signature vector matches for an integral JSON float'
    );

    $localOrder = '123456789012345678';
    $wrappedOrder = OrderId::wrap(
        $localOrder,
        $namespace,
        'https://catalog.example.com/user/api/order/callback.' . $localOrder,
    );
    expectBep($wrappedOrder === 'pka1_s0demo_' . $localOrder, 'order is namespaced');
    foreach (['order/callback' => 'order', 'rechargeNotification/callback' => 'recharge'] as $native => $flow) {
        expectBep(
            UrlPolicy::notifyUrl('https://catalog.example.com/user/api/' . $native . '.' . $localOrder,
                'https://catalog.example.com', $localOrder)
                === 'https://catalog.example.com/user/api/pikaBEpusdt/' . $flow . '.' . $localOrder,
            'real callback uses its dedicated flow endpoint'
        );
    }
    throwsBep(
        fn() => UrlPolicy::notifyUrl('https://other.example.com/user/api/order/callback.' . $localOrder,
            'https://catalog.example.com', $localOrder),
        'dedicated notify URL must not bypass the original origin validation'
    );
    throwsBep(
        fn() => UrlPolicy::notifyUrl('https://catalog.example.com/user/api/pikaBEpusdt/order.' . $localOrder,
            'https://catalog.example.com', $localOrder),
        'dedicated notify URL cannot replace the native input callback contract'
    );
    expectBep(OrderId::unwrap($wrappedOrder, $namespace) === $localOrder, 'order namespace unwraps');
    expectBep(OrderId::unwrap($wrappedOrder, 'other1') === null, 'other site namespace is rejected');
    throwsBep(
        fn() => OrderId::wrap('free-form', $namespace, 'https://catalog.example.com/callback'),
        'unscoped free-form order accepted'
    );
    $testOrder = 'TEST_probe01';
    expectBep(
        UrlPolicy::notifyUrl('https://catalog.example.com/user/api/order/callbackTest.' . $testOrder,
            'https://catalog.example.com', $testOrder)
            === 'https://catalog.example.com/user/api/order/callbackTest.' . $testOrder,
        'explicit probe keeps its original callbackTest boundary'
    );
    expectBep(
        OrderId::wrap(
            $testOrder,
            $namespace,
            'https://catalog.example.com/user/api/order/callbackTest.' . $testOrder,
        ) === 'pka1_s0demo_' . $testOrder,
        'explicit official payment probe format is supported'
    );

    $uuid = 'b3d2477c-d945-41da-96b7-f925bbd1b415';
    $requestPayload = [
        'order_id' => $wrappedOrder,
        'amount' => 10.0,
        'notify_url' => 'https://catalog.example.com/user/api/order/callback.' . $localOrder,
        'redirect_url' => 'https://catalog.example.com/user/index/query?tradeNo=' . $localOrder,
        'trade_type' => 'usdt.trc20',
        'fiat' => 'CNY',
    ];
    $responseData = [
        'status_code' => 200,
        'data' => [
            'order_id' => $wrappedOrder,
            'amount' => '10.00',
            'fiat' => 'CNY',
            'status' => 1,
            'trade_type' => 'usdt.trc20',
            'trade_id' => $uuid,
            'payment_url' => 'http://127.0.0.1:8080/pay/checkout-counter/' . $uuid,
        ],
    ];
    Client::$response = new FakeResponse($responseData);
    $pay = new Pay();
    $pay->amount = 10.0;
    $pay->tradeNo = $localOrder;
    $pay->config = [
        'gateway_origin' => 'http://127.0.0.1:8080',
        'checkout_origin' => 'https://catalog.example.com',
        'merchant_origin' => 'https://catalog.example.com',
        'fiat' => 'CNY',
    ];
    $pay->callbackUrl = $requestPayload['notify_url'];
    $pay->returnUrl = $requestPayload['redirect_url'];
    $pay->clientIp = '203.0.113.1';
    $pay->code = 'usdt.trc20';
    $pay->handle = 'PikaBEpusdtAdapter';
    $entity = $pay->trade();
    expectBep($entity->getType() === \App\Pay\Pay::TYPE_REDIRECT, 'trade returns redirect');
    expectBep(
        $entity->getUrl() === 'https://catalog.example.com/pay/checkout-counter/' . $uuid,
        'checkout URL is rebuilt from trusted origin'
    );
    expectBep(Client::$lastRequest['path'] === Gateway::CREATE_TRANSACTION_PATH, 'correct API path used');
    $sent = Client::$lastRequest['options']['json'];
    expectBep($sent['order_id'] === $wrappedOrder, 'namespaced order sent upstream');
    expectBep($sent['notify_url'] === 'https://catalog.example.com/user/api/pikaBEpusdt/order.' . $localOrder,
        'new real order uses the dedicated callback');
    expectBep(!array_key_exists('api_token', $sent), 'API token is not sent as a field');
    expectBep(Signature::generateSignature($sent, $token) === $sent['signature'], 'request signature validates');
    $clientOptions = Client::$lastRequest['client'];
    expectBep($clientOptions['allow_redirects'] === false, 'redirects disabled');
    expectBep($clientOptions['proxy'] === '', 'proxy disabled');

    Client::$response = new FakeResponse($responseData);
    $rechargePay = new Pay();
    $rechargePay->amount = 10.0;
    $rechargePay->tradeNo = $localOrder;
    $rechargePay->config = $pay->config;
    $rechargePay->callbackUrl = 'https://catalog.example.com/user/api/rechargeNotification/callback.' . $localOrder;
    $rechargePay->returnUrl = 'https://catalog.example.com/user/recharge/index';
    $rechargePay->clientIp = '203.0.113.1';
    $rechargePay->code = 'usdt.trc20';
    $rechargePay->handle = 'PikaBEpusdtAdapter';
    $rechargeEntity = $rechargePay->trade();
    expectBep($rechargeEntity->getType() === \App\Pay\Pay::TYPE_REDIRECT, 'recharge trade returns redirect');
    expectBep(
        Client::$lastRequest['options']['json']['notify_url']
            === 'https://catalog.example.com/user/api/pikaBEpusdt/recharge.' . $localOrder,
        'new real recharge uses the dedicated callback'
    );
    expectBep(
        Client::$lastRequest['options']['json']['redirect_url']
            === 'https://catalog.example.com/user/recharge/index',
        'recharge return URL was not sent unchanged'
    );

    $legacy = 'LEGACY123456789012';
    $legacyResponse = $responseData;
    $legacyResponse['data']['trade_id'] = $legacy;
    $legacyResponse['data']['payment_url'] = 'http://127.0.0.1:8080/pay/checkout/' . $legacy;
    $parsed = $pay->parseCreateTransactionResponse(
        new FakeResponse($legacyResponse),
        $requestPayload,
        $settings,
    );
    expectBep(
        $parsed['checkout_url'] === 'https://catalog.example.com/pay/checkout/' . $legacy,
        'legacy checkout response is supported'
    );

    foreach ([
        ['order_id', 'pka1_other1_' . $localOrder],
        ['amount', '10.01'],
        ['fiat', 'USD'],
        ['status', 2],
        ['trade_type', 'tron.trx'],
        ['trade_id', 'not-a-trade-id'],
        ['payment_url', 'https://evil.example/pay/checkout-counter/' . $uuid . '?x=1'],
    ] as [$field, $value]) {
        $invalid = $responseData;
        $invalid['data'][$field] = $value;
        throwsBep(
            fn() => $pay->parseCreateTransactionResponse(new FakeResponse($invalid), $requestPayload, $settings),
            "response mismatch accepted: {$field}"
        );
    }
    throwsBep(
        fn() => $pay->parseCreateTransactionResponse(
            new FakeResponse($responseData, 200, 'text/html'),
            $requestPayload,
            $settings,
        ),
        'non-JSON content type accepted'
    );
    throwsBep(
        fn() => $pay->parseCreateTransactionResponse(
            new FakeResponse([], 200, 'application/json', str_repeat('x', Gateway::MAX_BODY + 1)),
            $requestPayload,
            $settings,
        ),
        'oversized response accepted'
    );

    $callback = [
        'order_id' => $wrappedOrder,
        'amount' => '10.00',
        'status' => 2,
        'trade_id' => $uuid,
        'block_transaction_id' => str_repeat('a', 64),
    ];
    $callback['signature'] = Signature::generateSignature($callback, $token);
    $_SERVER['REQUEST_URI'] = '/user/api/order/callback.' . $localOrder;
    $verifier = new Signature();

    foreach ([1, 3, '1', '3'] as $nonPaidStatus) {
        $nonPaid = $callback;
        $nonPaid['status'] = $nonPaidStatus;
        $nonPaid['signature'] = Signature::generateSignature($nonPaid, $token);
        Context::set(PayConsts::DAFA, ['sentinel' => $nonPaidStatus]);
        expectBep(
            $verifier->verification($nonPaid, []) === false,
            "signed non-paid callback status {$nonPaidStatus} is rejected"
        );
        expectBep(
            Context::get(PayConsts::DAFA) === ['sentinel' => $nonPaidStatus],
            "non-paid callback status {$nonPaidStatus} does not reach Acg payment context"
        );
    }

    expectBep($verifier->verification($callback, []) === true, 'signed callback verifies');
    $rewritten = Context::get(PayConsts::DAFA);
    expectBep($rewritten['order_id'] === $localOrder, 'callback restores local order ID');

    $wrongNamespace = $callback;
    $wrongNamespace['order_id'] = 'pka1_other1_' . $localOrder;
    $wrongNamespace['signature'] = Signature::generateSignature($wrongNamespace, $token);
    expectBep($verifier->verification($wrongNamespace, []) === false, 'signed other-site callback rejected');
    $wrongSignature = $callback;
    $wrongSignature['signature'] = str_repeat('0', 32);
    expectBep($verifier->verification($wrongSignature, []) === false, 'incorrect callback signature rejected');

    $testCallback = $callback;
    $testCallback['order_id'] = 'pka1_s0demo_' . $testOrder;
    $testCallback['signature'] = Signature::generateSignature($testCallback, $token);
    $_SERVER['REQUEST_URI'] = '/user/api/order/callbackTest.' . $testOrder;
    expectBep($verifier->verification($testCallback, []) === true, 'explicit test callback verifies');
    $_SERVER['REQUEST_URI'] = '/user/api/order/callback.' . $testOrder;
    expectBep($verifier->verification($testCallback, []) === false, 'test ID rejected on real callback route');

    /** This child-process check uses service/DB doubles, not native HTTP or MySQL. */
    function runBepusdtCallbackFixture(string $siteRoot, array $input): array
    {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/local-bepusdt-callback-fixture.php', $siteRoot],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        expectBep(is_resource($process), 'callback fixture process starts');
        $inputBytes = json_encode($input, JSON_THROW_ON_ERROR);
        expectBep(fwrite($pipes[0], $inputBytes) === strlen($inputBytes), 'callback fixture input is complete');
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $errors = '';
        $deadline = microtime(true) + 5;
        do {
            $output .= stream_get_contents($pipes[1]);
            $errors .= stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) break;
            if (microtime(true) >= $deadline) {
                proc_terminate($process, 9);
                expectBep(false, 'callback fixture exceeds its five-second bound');
            }
            usleep(10000);
        } while (true);
        $output .= stream_get_contents($pipes[1]);
        $errors .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        expectBep($status['exitcode'] === 0 && $errors === '', 'callback fixture exits without PHP errors');
        $result = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        expectBep(is_array($result) && ($result['fatal'] ?? true) === false, 'callback fixture returns a nonfatal report');
        return $result;
    }

    $callbackRecord = [
        'id' => 1, 'trade_no' => $localOrder, 'pay_id' => 7, 'status' => 0, 'pay_time' => null,
        'gateway_amount' => '10.00', 'amount' => '8.00',
        'pay' => ['id' => 7, 'handle' => 'PikaBEpusdtAdapter', 'pay_config_id' => 1],
    ];
    $wrongAmount = $callback;
    $wrongAmount['amount'] = '11.00';
    $wrongAmount['signature'] = Signature::generateSignature($wrongAmount, $token);
    $wrongTrade = $callback;
    $wrongTrade['order_id'] = 'pka1_s0demo_999999999999999999';
    $wrongTrade['signature'] = Signature::generateSignature($wrongTrade, $token);
    $nonPaidCallback = $callback;
    $nonPaidCallback['status'] = 1;
    $nonPaidCallback['signature'] = Signature::generateSignature($nonPaidCallback, $token);
    $callbackFixtureChecks = 0;
    foreach (['order', 'recharge'] as $flow) {
        $baseInput = ['flow' => $flow, 'scenario' => 'normal', 'record' => $callbackRecord, 'ledger' => 0, 'map' => $callback];
        $normal = runBepusdtCallbackFixture($siteRoot, $baseInput);
        expectBep($normal['http_status'] === 200 && $normal['body'] === 'ok'
            && $normal['callback_calls'] === 1 && $normal['ledger'] === 1
            && $normal['record']['status'] === 1 && $normal['record']['pay_time'] !== null
            && $normal['whitelist_calls'] === 1, "{$flow} completes once before a precise success ACK");
        $callbackFixtureChecks++;

        foreach ([
            'wrong_amount' => $wrongAmount, 'wrong_signature' => $wrongSignature,
            'wrong_namespace' => $wrongNamespace, 'wrong_trade' => $wrongTrade,
            'non_paid' => $nonPaidCallback, 'stale_context' => $wrongSignature,
            'unknown_order' => $callback, 'wrong_channel' => $callback,
            'extra_parameter' => $callback, 'wrong_uri' => $callback,
            'wrong_method' => $callback, 'wrong_content_type' => $callback,
            'outer_transaction' => $callback, 'outer_pdo' => $callback,
            'unknown_status' => $callback, 'fresh_missing' => $callback,
            'fresh_channel' => $callback, 'fresh_profile' => $callback, 'fresh_pay_id' => $callback,
            'ip_rejected' => $callback, 'business_throw' => $callback,
            'bad_success' => $callback, 'commit_pending' => $callback,
        ] as $scenario => $map) {
            $result = runBepusdtCallbackFixture($siteRoot, array_replace($baseInput, ['scenario' => $scenario, 'map' => $map]));
            $expectedCalls = in_array($scenario, ['business_throw', 'bad_success', 'commit_pending'], true) ? 1 : 0;
            expectBep($result['http_status'] === 500 && $result['body'] === 'fail'
                && $result['callback_calls'] === $expectedCalls && $result['ledger'] === 0,
                "{$flow} {$scenario} is non-200 with no durable synthetic ledger increment");
            $callbackFixtureChecks++;
        }

        // Simulate the sender losing the first ACK by replaying the same signed
        // request with the record/ledger produced by the completed first call.
        $paidInput = array_replace($baseInput, ['record' => $normal['record'], 'ledger' => $normal['ledger']]);
        foreach (['lost_ack', 'paid_duplicate'] as $scenario) {
            $duplicate = runBepusdtCallbackFixture($siteRoot, array_replace($paidInput, ['scenario' => $scenario]));
            expectBep($duplicate['http_status'] === 200 && $duplicate['body'] === 'ok'
                && $duplicate['initialize_calls'] === 1 && $duplicate['callback_calls'] === 0
                && $duplicate['ledger'] === 1 && $duplicate['record'] === $normal['record'],
                "{$flow} {$scenario} authenticates anew and ACKs without a second synthetic fulfillment");
            $callbackFixtureChecks++;
        }
        foreach ([
            'paid_wrong_signature' => $wrongSignature, 'paid_wrong_amount' => $wrongAmount,
            'paid_wrong_trade' => $wrongTrade,
            'paid_wrong_namespace' => $wrongNamespace, 'paid_no_time' => $callback,
            'stale_context' => $wrongSignature,
        ] as $scenario => $map) {
            $result = runBepusdtCallbackFixture($siteRoot, array_replace($paidInput, ['scenario' => $scenario, 'map' => $map]));
            expectBep($result['http_status'] === 500 && $result['body'] === 'fail'
                && $result['callback_calls'] === 0 && $result['ledger'] === 1,
                "{$flow} {$scenario} cannot ACK a paid record without this request's valid proof");
            $callbackFixtureChecks++;
        }
        $fallbackRecord = $callbackRecord;
        $fallbackRecord['gateway_amount'] = null;
        $fallbackRecord['amount'] = '10.00';
        $fallback = runBepusdtCallbackFixture($siteRoot, array_replace($baseInput, ['record' => $fallbackRecord]));
        expectBep($fallback['http_status'] === 200 && $fallback['body'] === 'ok'
            && $fallback['callback_calls'] === 1 && $fallback['ledger'] === 1,
            "{$flow} falls back to amount only when gateway_amount is null");
        $callbackFixtureChecks++;
    }
    fwrite(STDOUT, "PASS synthetic BE callback controller contracts: {$callbackFixtureChecks}; native HTTP/MySQL/BE NOT RUN\n");

    chmod($siteState . '/secrets/bepusdt-token', 0660);
    throwsBep(fn() => SecretStore::load(), 'group-writable token accepted');

    fwrite(STDOUT, "PASS local BEpusdt adapter behavior\n");
}
