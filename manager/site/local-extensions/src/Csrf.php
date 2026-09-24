<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\Manager;

use App\Consts\Manage as ManageConst;
use App\Util\Context;

final class Csrf
{
    private const WINDOW_SECONDS = 1800;

    public static function issue(): string
    {
        $bucket = (int)floor(time() / self::WINDOW_SECONDS);
        return $bucket . '.' . self::signature($bucket);
    }

    public static function verify(mixed $token): bool
    {
        if (!is_string($token) || preg_match('/^(\d{1,12})\.([a-f0-9]{64})$/D', $token, $matches) !== 1) {
            return false;
        }
        $bucket = (int)$matches[1];
        $current = (int)floor(time() / self::WINDOW_SECONDS);
        if ($bucket !== $current && $bucket !== $current - 1) {
            return false;
        }
        return hash_equals(self::signature($bucket), $matches[2]);
    }

    /**
     * Only an authenticated, same-origin renewal endpoint may use this proof.
     * An expired signature proves the original page's login, never authorizes
     * a mutation. The replacement keeps the ordinary current/previous window.
     */
    public static function renew(mixed $token): ?string
    {
        if (!is_string($token) || preg_match('/^(\d{1,12})\.([a-f0-9]{64})$/D', $token, $matches) !== 1) {
            return null;
        }
        $bucket = (int)$matches[1];
        if ($bucket > (int)floor(time() / self::WINDOW_SECONDS)
            || !hash_equals(self::signature($bucket), $matches[2])) {
            return null;
        }
        return self::issue();
    }

    /** A non-authorizing, login-scoped namespace for non-secret browser drafts. */
    public static function draftScope(): string
    {
        [$manageId, $sessionId] = self::sessionIdentity();
        return hash_hmac('sha256', "catalog-draft-v1|{$manageId}|{$sessionId}", self::secret());
    }

    private static function signature(int $bucket): string
    {
        [$manageId, $sessionId] = self::sessionIdentity();
        return hash_hmac('sha256', "local-extensions-v1|{$manageId}|{$sessionId}|{$bucket}", self::secret());
    }

    /** @return array{int,int} */
    private static function sessionIdentity(): array
    {
        $manage = Context::get(ManageConst::SESSION);
        $session = Context::get(ManageConst::SESSION_RECORD);
        $manageId = is_object($manage) ? (int)($manage->id ?? 0) : 0;
        $sessionId = is_object($session) ? (int)($session->id ?? 0) : 0;
        if ($manageId < 1 || $sessionId < 1) {
            throw new \RuntimeException('Local extension CSRF session is unavailable.');
        }
        return [$manageId, $sessionId];
    }

    private static function secret(): string
    {
        $path = PathGuard::stateRoot() . '/csrf.key';
        if (!is_file($path) || is_link($path)) {
            throw new \RuntimeException('Local extension CSRF key is unavailable.');
        }
        $permissions = fileperms($path);
        if ($permissions === false || ($permissions & 0o077) !== 0) {
            throw new \RuntimeException('Local extension CSRF key permissions are unsafe.');
        }
        $secret = file_get_contents($path);
        if (!is_string($secret)) {
            throw new \RuntimeException('Unable to read local extension CSRF key.');
        }
        $secret = trim($secret);
        if (strlen($secret) < 32 || strlen($secret) > 256) {
            throw new \RuntimeException('Local extension CSRF key is invalid.');
        }
        return $secret;
    }
}
