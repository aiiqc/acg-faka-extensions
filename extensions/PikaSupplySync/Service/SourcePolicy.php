<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaSupplySync\Service;

use App\Model\Shared;
use RuntimeException;

final class SourcePolicy
{
    private const BLOCKED_CIDRS = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '::/96',
        '::1/128',
        '::ffff:0:0/96',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '100::/64',
        '2001::/23',
        '2001:db8::/32',
        '2002::/16',
        '3fff::/20',
        'fc00::/7',
        'fec0::/10',
        'fe80::/10',
        'ff00::/8',
        '5f00::/16',
        '100:0:0:1::/64',
    ];

    /** @var \Closure(string): array<int,string> */
    private \Closure $resolver;

    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver === null
            ? static fn(string $host): array => self::resolveHost($host)
            : \Closure::fromCallable($resolver);
    }

    public function assertSafe(Shared $source): void
    {
        $this->resolve((string)$source->domain, true, false);
    }

    /** @return array{url:string,host:string,addresses:array<int,string>} */
    public function resolve(string $url, bool $rootOnly = false, bool $allowQuery = false): array
    {
        if (
            $url === ''
            || strlen($url) > 2048
            || preg_match('/[\x00-\x20\x7F\\\\]/', $url)
            || filter_var($url, FILTER_VALIDATE_URL) === false
        ) {
            throw new RuntimeException('远端地址格式不正确');
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new RuntimeException('远端地址格式不正确');
        }
        $path = (string)($parts['path'] ?? '');
        if (
            (string)($parts['scheme'] ?? '') !== 'https'
            || !empty($parts['user'])
            || !empty($parts['pass'])
            || array_key_exists('fragment', $parts)
            || (!$allowQuery && array_key_exists('query', $parts))
            || (isset($parts['port']) && (int)$parts['port'] !== 443)
            || ($rootOnly && !in_array($path, ['', '/'], true))
            || (!$rootOnly && $path !== '' && !str_starts_with($path, '/'))
        ) {
            throw new RuntimeException($rootOnly
                ? '共享店铺只允许无凭据、无参数的 HTTPS 标准 443 根地址'
                : '远端请求只允许无凭据的 HTTPS 标准 443 地址');
        }

        $rawHost = (string)($parts['host'] ?? '');
        $host = $rawHost;
        $canonicalUrlHost = $rawHost;
        if (str_starts_with($rawHost, '[') && str_ends_with($rawHost, ']')) {
            $host = self::normalizeIp(substr($rawHost, 1, -1));
            $canonicalUrlHost = '[' . $host . ']';
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new RuntimeException('远端主机名格式不正确');
            }
        } else {
            $host = strtolower(rtrim($rawHost, '.'));
            $canonicalUrlHost = $host;
        }
        if ($host === '') {
            throw new RuntimeException('远端地址缺少主机名');
        }
        if (!hash_equals($canonicalUrlHost, $rawHost)) {
            throw new RuntimeException('远端主机名必须使用规范小写形式且不能带尾点');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            self::assertPublicIp($host);
            $addresses = [self::normalizeIp($host)];
        } else {
            if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                throw new RuntimeException('远端主机名格式不正确');
            }
            $addresses = ($this->resolver)($host);
            if (!is_array($addresses) || $addresses === [] || count($addresses) > 32) {
                throw new RuntimeException('远端主机名没有可验证的公共 DNS 结果');
            }
            $unique = [];
            foreach ($addresses as $address) {
                if (!is_string($address)) {
                    throw new RuntimeException('远端 DNS 结果格式不正确');
                }
                self::assertPublicIp($address);
                $address = self::normalizeIp($address);
                $unique[$address] = $address;
            }
            $addresses = array_values($unique);
            sort($addresses, SORT_STRING);
        }

        return ['url' => $url, 'host' => $host, 'addresses' => $addresses];
    }

    public static function assertPublicIp(string $address): void
    {
        $address = self::normalizeIp($address);
        if (filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false) {
            throw new RuntimeException('远端地址解析到了私网、保留或链路本地地址');
        }
        foreach (self::BLOCKED_CIDRS as $cidr) {
            if (self::inCidr($address, $cidr)) {
                throw new RuntimeException('远端地址解析到了非公共网络地址');
            }
        }
    }

    public static function normalizeIp(string $address): string
    {
        $address = strtolower(trim($address, " \t\n\r\0\x0B[]"));
        if (str_starts_with($address, '::ffff:')) {
            $mapped = substr($address, 7);
            if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $mapped;
            }
        }
        return $address;
    }

    /** @return string[] */
    private static function resolveHost(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records)) {
            return [];
        }
        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address) && $address !== '') {
                $addresses[$address] = $address;
            }
        }
        return array_values($addresses);
    }

    private static function inCidr(string $address, string $cidr): bool
    {
        [$network, $bitsText] = explode('/', $cidr, 2);
        $ip = @inet_pton($address);
        $base = @inet_pton($network);
        if ($ip === false || $base === false || strlen($ip) !== strlen($base)) {
            return false;
        }
        $bits = (int)$bitsText;
        $bytes = intdiv($bits, 8);
        $remaining = $bits % 8;
        if ($bytes > 0 && substr($ip, 0, $bytes) !== substr($base, 0, $bytes)) {
            return false;
        }
        if ($remaining === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remaining)) & 0xFF;
        return (ord($ip[$bytes]) & $mask) === (ord($base[$bytes]) & $mask);
    }
}
