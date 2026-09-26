<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\Manager;

final class ManagerService
{
    /** @return list<array<string,mixed>> */
    public static function listing(): array
    {
        $list = [];
        foreach (Registry::extensions() as $extension) {
            $configError = false;
            try {
                $config = ConfigStore::publicView($extension['id']);
            } catch (\Throwable $exception) {
                if ($extension['id'] !== SharedAccessGuard::ID) {
                    throw $exception;
                }
                // A damaged admission policy must not hide the administrator's
                // existing disable control or the other extension cards.
                $config = ['values' => [], 'password_configured' => []];
                $configError = true;
            }
            $list[] = [
                'id' => $extension['id'],
                'name' => $extension['name'],
                'version' => $extension['version'],
                'description' => $extension['description'],
                'enabled' => StateStore::isEnabled($extension['id']),
                'settings' => $extension['settings'],
                'values' => $config['values'],
                'password_configured' => $config['password_configured'],
                ...($configError ? ['config_error' => true] : []),
                ...($extension['id'] === 'PikaSupplySync' ? ['sync_status' => SupplySyncStatus::snapshot()] : []),
            ];
        }
        usort($list, static fn(array $left, array $right): int => strcmp($left['id'], $right['id']));
        return $list;
    }
}
