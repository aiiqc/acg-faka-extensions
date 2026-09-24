<?php
declare(strict_types=1);

$prefix = 'Pika\\LocalExtensions\\PikaOrderReturnWait\\';
$baseDirectory = __DIR__ . DIRECTORY_SEPARATOR;

return spl_autoload_register(static function (string $class) use ($prefix, $baseDirectory): void {
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    if ($relative === '' || preg_match('/^[A-Za-z][A-Za-z0-9_]*(?:\\\\[A-Za-z][A-Za-z0-9_]*)*$/D', $relative) !== 1) {
        return;
    }

    $path = $baseDirectory . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
    if (is_file($path) && !is_link($path)) {
        require_once $path;
    }
});
