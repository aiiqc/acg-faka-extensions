<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\Manager;

use App\Consts\Shared;
use App\Model\User;
use App\Util\Context;
use Kernel\Exception\JSONException;

/** Restricts authenticated native store sharing, not general site traffic. */
final class SharedAccessGuard
{
    public const ID = 'PikaSharedAccess';

    public static function before(mixed $controller, mixed $action): void
    {
        // Kernel has already resolved the class and action and run its native
        // interceptors. Protect every action of these two classes, including
        // equivalent route spellings; never classify a request by its URI.
        if (!is_object($controller) || !in_array(strtolower(get_class($controller)), [
            'app\\controller\\shared\\commodity',
            'app\\controller\\shared\\authentication',
        ], true)) {
            return;
        }

        try {
            if (!isset(Registry::extensions()[self::ID]) || !StateStore::isEnabled(self::ID, true)) {
                return;
            }
            $clients = self::clients(ConfigStore::get(self::ID));
            // Only SharedValidation's authenticated model is authoritative.
            // A submitted app_id, forwarding header or hook return is not.
            $user = Context::get(Shared::SESSION);
            if (!$user instanceof User) {
                throw new \RuntimeException('Authenticated shared user is unavailable.');
            }
            $id = self::accountId($user->id);
            $address = self::address($_SERVER['REMOTE_ADDR'] ?? null);
            if (!in_array($address, $clients[$id] ?? [], true)) {
                throw new \RuntimeException('Shared account and source are not allowed.');
            }
        } catch (\Throwable) {
            // Kernel catches this exception and never calls the controller.
            // Do not expose policy contents, account identifiers or IPs.
            throw new JSONException('共享接口未获准访问', 403);
        }
    }

    /** Validate before ConfigStore atomically publishes an administrator edit. */
    public static function validateSettings(array $values): void
    {
        self::clients($values);
    }

    /** @return array<int,list<string>> Account IDs map to packed IP addresses. */
    private static function clients(array $values): array
    {
        if (array_diff(array_keys($values), ['allowed_clients']) !== []
            || !is_string($values['allowed_clients'] ?? null)
            || strlen($values['allowed_clients']) > 4096) {
            throw new \RuntimeException('Shared access settings are invalid.');
        }
        $raw = trim($values['allowed_clients']);
        if ($raw === '') {
            return [];
        }
        $entries = explode(',', $raw);
        if (count($entries) > 100) {
            throw new \RuntimeException('Shared access pair limit exceeded.');
        }
        $clients = [];
        foreach ($entries as $entry) {
            $pair = explode('@', trim($entry));
            if (count($pair) !== 2) {
                throw new \RuntimeException('Shared access requires account@IP pairs.');
            }
            $id = self::accountId(trim($pair[0]));
            $address = self::address(trim($pair[1]));
            if (in_array($address, $clients[$id] ?? [], true)) {
                throw new \RuntimeException('Shared access contains a duplicate pair.');
            }
            $clients[$id][] = $address;
        }
        return $clients;
    }

    private static function accountId(mixed $value): int
    {
        if ((!is_int($value) && !is_string($value))
            || preg_match('/^[1-9][0-9]{0,18}$/D', (string)$value) !== 1) {
            throw new \RuntimeException('Shared account ID is invalid.');
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($id)) {
            throw new \RuntimeException('Shared account ID is outside its allowed bounds.');
        }
        return $id;
    }

    private static function address(mixed $value): string
    {
        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_IP) === false) {
            throw new \RuntimeException('Shared source must be a literal IP address.');
        }
        $packed = inet_pton($value);
        if (!is_string($packed)) {
            throw new \RuntimeException('Shared source IP is invalid.');
        }
        // Treat IPv4-mapped IPv6 and the same IPv4 peer as one address.
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            return substr($packed, 12);
        }
        return $packed;
    }
}
