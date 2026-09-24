<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\Manager;

use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;

final class RequestGuard
{
    public const CSRF_FAILURE_MESSAGE = '本地扩展 CSRF 校验失败，请刷新页面';

    public static function mutation(Request $request, mixed $manage): void
    {
        if ($request->method() !== 'POST') {
            throw new JSONException('仅允许 POST 请求');
        }
        if (!is_object($manage) || (int)($manage->type ?? -1) !== 0) {
            throw new JSONException('仅系统管理员可管理本地扩展');
        }
        if (!Csrf::verify($request->unsafePost('csrf_token'))) {
            throw new JSONException(self::CSRF_FAILURE_MESSAGE);
        }
    }

    public static function csrfRenewal(Request $request, mixed $manage): void
    {
        if ($request->method() !== 'POST' || !is_object($manage) || (int)($manage->type ?? -1) !== 0) {
            throw new JSONException('仅系统管理员可通过 POST 更新页面凭证');
        }
        // Official Request::url() reflects Origin, so it is not a target-origin
        // authority. Scheme comes only from server/FastCGI state, never Forwarded.
        $https = $_SERVER['HTTPS'] ?? null;
        $scheme = is_string($https) && in_array(strtolower($https), ['on', '1'], true)
            ? 'https' : ($_SERVER['REQUEST_SCHEME'] ?? null);
        $host = $request->header('Host');
        $origin = self::origin($request->header('Origin'));
        $target = is_string($scheme) && in_array($scheme, ['http', 'https'], true) && is_string($host)
            ? self::origin($scheme . '://' . $host) : null;
        $fetchSite = $request->header('SecFetchSite');
        if ($origin === null || $target === null || $origin !== $target
            || ($fetchSite !== null && $fetchSite !== 'same-origin')) {
            throw new JSONException('页面凭证更新仅允许同源请求，请刷新页面或重新登录');
        }
    }

    /** @return array{string,string,int}|null */
    private static function origin(mixed $value): ?array
    {
        if (!is_string($value)
            || preg_match('~\A(https?)://(\[[a-fA-F0-9:.]+\]|[a-zA-Z0-9.-]+)(?::([1-9][0-9]{0,4}))?\z~D', $value, $matches) !== 1) {
            return null;
        }
        $port = isset($matches[3]) ? (int)$matches[3] : ($matches[1] === 'https' ? 443 : 80);
        $host = $matches[2];
        $validHost = str_starts_with($host, '[')
            ? filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            : filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
        if ($port > 65535 || !$validHost) {
            return null;
        }
        return [$matches[1], strtolower($host), $port];
    }
}
