<?php
declare(strict_types=1);

$prefix = 'Pika\\LocalExtensions\\PikaSupplySync\\';
$root = __DIR__ . DIRECTORY_SEPARATOR;

spl_autoload_register(static function (string $class) use ($prefix, $root): void {
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    if ($relative === '' || preg_match('/^[A-Za-z0-9_\\\\]+$/D', $relative) !== 1) {
        return;
    }
    $path = $root . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
    if (is_file($path) && !is_link($path)) {
        require_once $path;
    }
});

return true;
