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
            $config = ConfigStore::publicView($extension['id']);
            $list[] = [
                'id' => $extension['id'],
                'name' => $extension['name'],
                'version' => $extension['version'],
                'description' => $extension['description'],
                'enabled' => StateStore::isEnabled($extension['id']),
                'settings' => $extension['settings'],
                'values' => $config['values'],
                'password_configured' => $config['password_configured'],
                ...($extension['id'] === 'PikaSupplySync' ? ['sync_status' => SupplySyncStatus::snapshot()] : []),
            ];
        }
        usort($list, static fn(array $left, array $right): int => strcmp($left['id'], $right['id']));
        return $list;
    }
}
