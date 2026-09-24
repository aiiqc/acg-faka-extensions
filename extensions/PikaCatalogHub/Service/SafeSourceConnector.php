<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaCatalogHub\Service;

use App\Model\Shared;
use App\Util\Currency;
use App\Util\Date;
use App\Util\Schema;
use App\Util\SharedCurrency;
use App\Util\Str;
use Illuminate\Database\Capsule\Manager as DB;
use Pika\LocalExtensions\PikaSupplySync\Service\RunBudget;
use Pika\LocalExtensions\PikaSupplySync\Service\SafeHttpClient;
use Pika\LocalExtensions\PikaSupplySync\Service\SourceIdentity;
use Pika\LocalExtensions\PikaSupplySync\Service\SourceLock;
use Pika\LocalExtensions\PikaSupplySync\Service\SourcePolicy;
use Pika\LocalExtensions\PikaSupplySync\Service\StateStore as SupplyStateStore;
use RuntimeException;

final class SafeSourceConnector
{
    private const MAX_SOURCES = 16;

    /** @var (callable(array,string,string,array,string,int,int,int):array)|null */
    private $transport;

    /** @var (callable(string):array<int,string>)|null */
    private $resolver;

    private SourceAliasService $aliases;

    public function __construct(
        ?callable $transport = null,
        ?callable $resolver = null,
        private ?SourceConnectLock $lock = null,
        ?SourceAliasService $aliases = null,
    ) {
        $this->transport = $transport;
        $this->resolver = $resolver;
        $this->lock ??= new SourceConnectLock();
        $this->aliases = $aliases ?? new SourceAliasService();
    }

    /** @param array<string,mixed> $input @return array{source_id:int} */
    public function connect(array $input): array
    {
        $this->onlyKeys($input, ['type', 'domain', 'app_id', 'app_key', 'currency', 'currency_rate']);
        $type = $this->protocol($input['type'] ?? null);
        $policy = new SourcePolicy($this->resolver);
        $domain = $this->domain($input['domain'] ?? null, $policy);
        $appId = $this->appId($input['app_id'] ?? null);
        $appKey = $this->appKey($input['app_key'] ?? null);
        $currency = $this->currency($input['currency'] ?? null);
        $currencyRate = $this->currencyRate($input['currency_rate'] ?? null);
        $factor = SharedCurrency::resolveFactor(
            $currency,
            $currencyRate,
            Currency::code(),
            Currency::rate(),
        );
        if ($factor === null || $factor === '0') {
            throw new RuntimeException('该货币组合需要填写有效结算汇率。');
        }

        $identity = $this->remoteIdentity($type, $domain, $appId, $appKey, $policy);
        $sourceId = $this->save(
            $type,
            $domain,
            $appId,
            $appKey,
            $currency,
            $currencyRate,
            $identity,
        );
        $appKey = '';
        return ['source_id' => $sourceId];
    }

    /**
     * Edits one existing source without ever returning its stored secret.
     * An empty app_key explicitly preserves the current value.
     *
     * @param array<string,mixed> $input
     * @return array{source:array{id:int,name:string,alias:string,type:int,domain:string,app_id:string,currency:string,currency_rate:string}}
     */
    public function update(int $sourceId, array $input): array
    {
        $this->sourceId($sourceId);
        $this->onlyKeys($input, ['alias', 'type', 'domain', 'app_id', 'app_key', 'currency', 'currency_rate']);
        $alias = $this->aliases->normalize($sourceId, $input['alias'] ?? null);
        $type = $this->protocol($input['type'] ?? null);
        $rawDomain = $input['domain'] ?? null;
        $domain = $this->canonicalStoredOrigin($rawDomain);
        if ($domain === '') {
            throw new RuntimeException('店铺地址格式不正确。');
        }
        $appId = $this->appId($input['app_id'] ?? null);
        $rawAppKey = $input['app_key'] ?? null;
        if (!is_string($rawAppKey)) {
            throw new RuntimeException('商户密钥格式不正确。');
        }
        $currency = $this->currency($input['currency'] ?? null);
        $currencyRate = $this->currencyRate($input['currency_rate'] ?? null);

        return $this->withSourceLocks($sourceId, function () use (
            $sourceId,
            $alias,
            $type,
            $rawDomain,
            $domain,
            $appId,
            $rawAppKey,
            $currency,
            $currencyRate,
        ): array {
            $this->assertSourceIdle($sourceId);
            $source = Shared::query()->find($sourceId);
            if (!$source || (int)($source->id ?? 0) !== $sourceId) {
                throw new RuntimeException('共享店铺不存在。');
            }
            $bindingChanged = $this->bindingChanged($source, $type, $domain, $appId);
            if ($bindingChanged) {
                throw new RuntimeException('协议、店铺地址和商户 ID 不支持原地改绑，请按备份维护流程处理。');
            }
            $aliasChanged = $this->aliases->isChange($sourceId, $alias);
            $sourceSettingsChanged = $this->sourceSettingsChanged($source, $currency, $currencyRate);
            if (!$aliasChanged && $rawAppKey === '' && !$sourceSettingsChanged) {
                $this->assertValidCurrencyFactor($currency, $currencyRate);
                $public = $this->publicSource($source);
                $public['alias'] = $alias;
                return ['source' => $public];
            }
            if ($aliasChanged) {
                if ($rawAppKey !== '' || $sourceSettingsChanged) {
                    throw new RuntimeException('货源名称与密钥、货币或汇率的修改不能在一次请求中合并，请分两次保存。');
                }
                $public = $this->publicSource($source);
                $this->aliases->rename($sourceId, $alias);
                $public['alias'] = $alias;
                return ['source' => $public];
            }

            $this->aliases->assertNoPendingRename();

            $appKey = $rawAppKey === ''
                ? $this->appKey((string)($source->app_key ?? ''))
                : $this->appKey($rawAppKey);
            $this->assertValidCurrencyFactor($currency, $currencyRate);
            $policy = new SourcePolicy($this->resolver);
            $domain = $this->domain($rawDomain, $policy);
            $identity = $this->remoteIdentity($type, $domain, $appId, $appKey, $policy);
            $syncContextChanged = $this->syncContextChanged(
                $source,
                $appKey,
                $identity['name'],
                $currency,
                $currencyRate,
            );
            if ($syncContextChanged) {
                // Reset the bounded cursor before changing any value that can
                // alter remote identity or locally calculated prices.
                // If the later DB transaction fails, a harmless full re-read is
                // safer than retaining a cursor from a different sync context.
                (new SupplyStateStore())->resetProgress($sourceId);
            }
            $updated = DB::transaction(function () use (
                $source,
                $sourceId,
                $type,
                $domain,
                $appId,
                $appKey,
                $currency,
                $currencyRate,
                $identity,
            ): Shared {
                $locked = SourceIdentity::lockAndVerify($source);
                $duplicates = Shared::query()
                    ->where('id', '!=', $sourceId)
                    ->lockForUpdate()
                    ->get(['id', 'domain']);
                foreach ($duplicates as $duplicate) {
                    $existing = $this->canonicalStoredOrigin($duplicate->domain ?? null);
                    if ($existing !== '' && hash_equals($existing, $domain)) {
                        throw new RuntimeException('该店铺地址已经存在。');
                    }
                }

                $locked->type = $type;
                $locked->domain = $domain;
                $locked->app_id = $appId;
                $locked->app_key = $appKey;
                $locked->name = $identity['name'];
                $locked->balance = $identity['balance'];
                $locked->currency = $currency;
                $locked->currency_rate = $currencyRate;
                if (!$locked->save()) {
                    throw new RuntimeException('共享店铺修改失败。');
                }
                return $locked;
            }, 1);
            $appKey = '';
            $public = $this->publicSource($updated);
            $public['alias'] = $alias;
            return ['source' => $public];
        });
    }

    /** @return array{name:string,balance:float} */
    private function remoteIdentity(
        int $type,
        string $domain,
        string $appId,
        string $appKey,
        SourcePolicy $policy,
    ): array {
        $budget = new RunBudget();
        $budget->beginSource(1);
        try {
            $client = new SafeHttpClient($policy, $this->transport, $budget);
            if ($type === 1) {
                $response = $client->postJson(
                    $domain . '/plugin/open-api/connect',
                    [
                        'Accept: application/json',
                        'Api-Id: ' . $appId,
                        'Api-Signature: ' . Str::generateSignature([], $appKey),
                    ],
                    [],
                );
            } else {
                $form = ['app_id' => $appId, 'app_key' => $appKey];
                $form['sign'] = Str::generateSignature($form, $appKey);
                $response = $client->postJson(
                    $domain . ($type === 2
                        ? '/plugin/SharedStock/api/connect'
                        : '/shared/authentication/connect'),
                    ['Accept: application/json'],
                    $form,
                );
            }
            if ($this->containsSecret($response, $appKey)) {
                throw new RuntimeException('远端响应包含提交的商户密钥。');
            }
            return $this->identity($response, $type);
        } finally {
            $budget->endSource();
        }
    }

    /** @param array<string,mixed>|array<int,mixed> $response @return array{name:string,balance:float} */
    private function identity(array $response, int $type): array
    {
        $code = $response['code'] ?? null;
        $data = $response['data'] ?? null;
        if (!in_array($code, [200, '200'], true) || !is_array($data) || array_is_list($data)) {
            throw new RuntimeException('远端连接响应格式不正确。');
        }
        $rawName = $data[$type === 1 ? 'username' : 'shopName'] ?? null;
        $rawBalance = $data['balance'] ?? null;
        if (!is_scalar($rawName) || !is_numeric($rawBalance)) {
            throw new RuntimeException('远端连接身份格式不正确。');
        }
        $name = trim(strip_tags((string)$rawName));
        if (!mb_check_encoding($name, 'UTF-8')
            || $name === ''
            || mb_strlen($name, 'UTF-8') > 128
            || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
            throw new RuntimeException('远端店铺名称格式不正确。');
        }
        $balance = (float)$rawBalance;
        if (!is_finite($balance) || $balance < 0 || $balance > 999999999999.99) {
            throw new RuntimeException('远端店铺余额格式不正确。');
        }
        return ['name' => $name, 'balance' => round($balance, 2)];
    }

    /** @param array{name:string,balance:float} $identity */
    private function save(
        int $type,
        string $domain,
        string $appId,
        string $appKey,
        string $currency,
        string $currencyRate,
        array $identity,
    ): int {
        Schema::ensureSharedCurrency();
        $lock = $this->lock;
        if ($lock === null) {
            throw new RuntimeException('货源接入锁不可用。');
        }
        $lock->acquire();
        $primaryFailure = null;
        try {
            return DB::transaction(function () use (
                $type,
                $domain,
                $appId,
                $appKey,
                $currency,
                $currencyRate,
                $identity,
            ): int {
                $rows = Shared::query()
                    ->orderBy('id')
                    ->limit(self::MAX_SOURCES + 1)
                    ->lockForUpdate()
                    ->get(['id', 'type', 'domain', 'app_id', 'app_key', 'currency', 'currency_rate']);
                foreach ($rows as $row) {
                    $existing = $this->canonicalStoredOrigin($row->domain ?? null);
                    if ($existing !== '' && hash_equals($existing, $domain)) {
                        $same = (int)($row->type ?? -1) === $type
                            && is_string($row->app_id ?? null)
                            && hash_equals((string)$row->app_id, $appId)
                            && is_string($row->app_key ?? null)
                            && hash_equals((string)$row->app_key, $appKey)
                            && strtoupper((string)($row->currency ?? 'CNY')) === $currency
                            && sprintf('%.6f', (float)($row->currency_rate ?? 0)) === $currencyRate;
                        if (!$same) {
                            throw new RuntimeException('该店铺地址已经存在。');
                        }
                        $existingId = (int)($row->id ?? 0);
                        if ($existingId < 1 || $existingId > 0x7fffffff) {
                            throw new RuntimeException('共享店铺记录格式不正确。');
                        }
                        return $existingId;
                    }
                }
                if (count($rows) >= self::MAX_SOURCES) {
                    throw new RuntimeException('智能货源中心最多管理 16 个共享店铺。');
                }

                $source = new Shared();
                $source->type = $type;
                $source->domain = $domain;
                $source->app_id = $appId;
                $source->app_key = $appKey;
                $source->name = $identity['name'];
                $source->balance = $identity['balance'];
                $source->currency = $currency;
                $source->currency_rate = $currencyRate;
                $source->create_time = Date::current();
                if (!$source->save()) {
                    throw new RuntimeException('共享店铺保存失败。');
                }
                $id = (int)($source->id ?? 0);
                if ($id < 1 || $id > 0x7fffffff) {
                    throw new RuntimeException('共享店铺保存结果不正确。');
                }
                return $id;
            }, 1);
        } catch (\Throwable $exception) {
            $primaryFailure = $exception;
            throw $exception;
        } finally {
            try {
                $lock->release();
            } catch (\Throwable $releaseFailure) {
                if ($primaryFailure === null) {
                    throw $releaseFailure;
                }
            }
        }
    }

    /** @param array<string,mixed>|array<int,mixed> $response */
    private function containsSecret(array $response, string $secret): bool
    {
        $stack = [$response];
        while ($stack !== []) {
            $value = array_pop($stack);
            foreach ($value as $key => $child) {
                if (is_string($key) && $this->secretMatch($key, $secret)) {
                    return true;
                }
                if (is_array($child)) {
                    $stack[] = $child;
                } elseif (is_string($child) && $this->secretMatch($child, $secret)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return mixed */
    private function withSourceLocks(int $sourceId, callable $operation): mixed
    {
        $connectLock = $this->lock;
        if ($connectLock === null) {
            throw new RuntimeException('货源接入锁不可用。');
        }
        $connectLock->acquire();
        $sourceLock = new SourceLock();
        $sourceLocked = false;
        $primaryFailure = null;
        try {
            $sourceLocked = $sourceLock->acquire($sourceId);
            if (!$sourceLocked) {
                throw new RuntimeException('该货源正在同步或入库，请稍后重试。');
            }
            return $operation();
        } catch (\Throwable $exception) {
            $primaryFailure = $exception;
            throw $exception;
        } finally {
            if ($sourceLocked) {
                $sourceLock->release();
            }
            try {
                $connectLock->release();
            } catch (\Throwable $releaseFailure) {
                if ($primaryFailure === null) {
                    throw $releaseFailure;
                }
            }
        }
    }

    private function assertSourceIdle(int $sourceId): void
    {
        $jobs = new JobStore();
        foreach ($jobs->list() as $job) {
            if ((int)($job['source_id'] ?? 0) === $sourceId
                && !$jobs->isTerminal((string)($job['state'] ?? ''))) {
                throw new RuntimeException('该货源已有未完成的后台任务。');
            }
        }
    }

    private function bindingChanged(Shared $source, int $type, string $domain, string $appId): bool
    {
        $storedDomain = $this->canonicalStoredOrigin($source->domain ?? null);
        return (int)($source->type ?? -1) !== $type
            || $storedDomain === ''
            || !hash_equals($storedDomain, $domain)
            || !is_string($source->app_id ?? null)
            || !hash_equals((string)$source->app_id, $appId);
    }

    private function syncContextChanged(
        Shared $source,
        string $appKey,
        string $name,
        string $currency,
        string $currencyRate,
    ): bool {
        return !is_string($source->app_key ?? null)
            || !hash_equals((string)$source->app_key, $appKey)
            || !is_string($source->name ?? null)
            || !hash_equals((string)$source->name, $name)
            || strtoupper((string)($source->currency ?? 'CNY')) !== $currency
            || sprintf('%.6f', (float)($source->currency_rate ?? 0)) !== $currencyRate;
    }

    private function sourceSettingsChanged(Shared $source, string $currency, string $currencyRate): bool
    {
        return strtoupper((string)($source->currency ?? 'CNY')) !== $currency
            || sprintf('%.6f', (float)($source->currency_rate ?? 0)) !== $currencyRate;
    }

    private function assertValidCurrencyFactor(string $currency, string $currencyRate): void
    {
        $factor = SharedCurrency::resolveFactor(
            $currency,
            $currencyRate,
            Currency::code(),
            Currency::rate(),
        );
        if ($factor === null || $factor === '0') {
            throw new RuntimeException('该货币组合需要填写有效结算汇率。');
        }
    }

    /** @return array{id:int,name:string,type:int,domain:string,app_id:string,currency:string,currency_rate:string} */
    private function publicSource(Shared $source): array
    {
        $id = (int)($source->id ?? 0);
        $this->sourceId($id);
        $domain = $this->canonicalStoredOrigin($source->domain ?? null);
        if ($domain === '') {
            throw new RuntimeException('共享店铺地址格式不正确。');
        }
        $name = $source->name ?? null;
        if (!is_string($name)
            || !mb_check_encoding($name, 'UTF-8')
            || trim($name) === ''
            || mb_strlen(trim($name), 'UTF-8') > 128
            || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
            throw new RuntimeException('共享店铺名称格式不正确。');
        }
        $appId = $this->appId($source->app_id ?? null);
        $currency = $this->currency((string)($source->currency ?? 'CNY'));
        $rate = (float)($source->currency_rate ?? 0);
        if (!is_finite($rate) || $rate < 0 || $rate > 999999999) {
            throw new RuntimeException('共享店铺结算汇率格式不正确。');
        }
        return [
            'id' => $id,
            'name' => trim($name),
            'type' => (int)($source->type ?? 0),
            'domain' => $domain,
            'app_id' => $appId,
            'currency' => $currency,
            'currency_rate' => sprintf('%.6f', $rate),
        ];
    }

    private function sourceId(int $sourceId): void
    {
        if ($sourceId < 1 || $sourceId > 0x7fffffff) {
            throw new RuntimeException('共享店铺 ID 不正确。');
        }
    }

    private function secretMatch(string $value, string $secret): bool
    {
        return str_contains($value, $secret);
    }

    private function protocol(mixed $value): int
    {
        if (!is_string($value) || preg_match('/^[012]$/D', $value) !== 1) {
            throw new RuntimeException('共享协议不正确。');
        }
        return (int)$value;
    }

    private function domain(mixed $value, SourcePolicy $policy): string
    {
        if (!is_string($value) || strlen($value) > 128) {
            throw new RuntimeException('店铺地址格式不正确。');
        }
        $endpoint = $policy->resolve($value, true, false);
        $host = $endpoint['host'];
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $host = '[' . $host . ']';
        }
        return 'https://' . $host;
    }

    private function canonicalStoredOrigin(mixed $value): string
    {
        if (!is_string($value)
            || $value === ''
            || strlen($value) > 128
            || preg_match('/[\x00-\x20\x7F\\\\]/', $value) === 1) {
            return '';
        }
        $parts = parse_url($value);
        if (!is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || !empty($parts['user'])
            || !empty($parts['pass'])
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)
            || (isset($parts['port']) && (int)$parts['port'] !== 443)
            || !in_array((string)($parts['path'] ?? ''), ['', '/'], true)) {
            return '';
        }
        $rawHost = (string)($parts['host'] ?? '');
        if ($rawHost === '') {
            return '';
        }
        if (str_starts_with($rawHost, '[') && str_ends_with($rawHost, ']')) {
            $host = SourcePolicy::normalizeIp(substr($rawHost, 1, -1));
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                return 'https://[' . $host . ']';
            }
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                ? 'https://' . $host
                : '';
        }
        $host = strtolower(rtrim($rawHost, '.'));
        if ($host === ''
            || (filter_var($host, FILTER_VALIDATE_IP) === false
                && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false)) {
            return '';
        }
        return 'https://' . $host;
    }

    private function appId(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9._:@-]{1,32}$/D', $value) !== 1) {
            throw new RuntimeException('商户 ID 格式不正确。');
        }
        return $value;
    }

    private function appKey(mixed $value): string
    {
        if (!is_string($value)
            || preg_match('/^[^\s\x00-\x1F\x7F]{8,64}$/uD', $value) !== 1) {
            throw new RuntimeException('商户密钥格式不正确。');
        }
        return $value;
    }

    private function currency(mixed $value): string
    {
        if (!is_string($value)) {
            throw new RuntimeException('对方货币格式不正确。');
        }
        $currency = strtoupper(trim($value));
        if (preg_match('/^[A-Z0-9]{1,8}$/D', $currency) !== 1) {
            throw new RuntimeException('对方货币格式不正确。');
        }
        return $currency;
    }

    private function currencyRate(mixed $value): string
    {
        if (!is_string($value)) {
            throw new RuntimeException('结算汇率格式不正确。');
        }
        $value = trim($value);
        if ($value === '') {
            return '0.000000';
        }
        if (preg_match('/^\d{1,9}(?:\.\d{1,6})?$/D', $value) !== 1) {
            throw new RuntimeException('结算汇率格式不正确。');
        }
        $rate = (float)$value;
        if (!is_finite($rate) || $rate > 999999999) {
            throw new RuntimeException('结算汇率超出有效范围。');
        }
        if ($rate === 0.0) {
            return '0.000000';
        }
        if ($rate < 0.000001) {
            throw new RuntimeException('结算汇率超出有效范围。');
        }
        return sprintf('%.6f', $rate);
    }

    /** @param array<string,mixed> $input @param list<string> $allowed */
    private function onlyKeys(array $input, array $allowed): void
    {
        foreach (array_keys($input) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new RuntimeException('货源接入请求包含未授权字段。');
            }
        }
    }
}
