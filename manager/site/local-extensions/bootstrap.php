<?php
declare(strict_types=1);

use Pika\LocalExtensions\Manager\Runtime;

$managerRoot = __DIR__ . '/src';

foreach ([
    'PathGuard.php',
    'ManifestValidator.php',
    'Registry.php',
    'AtomicJson.php',
    'StateStore.php',
    'ConfigStore.php',
    'Csrf.php',
    'RequestGuard.php',
    'AdminMenu.php',
    'SupplySyncStatus.php',
    'ManagerService.php',
    'Dispatcher.php',
    'NativeCategoryGuard.php',
    'Runtime.php',
] as $managerFile) {
    require_once $managerRoot . '/' . $managerFile;
}

Runtime::boot();
