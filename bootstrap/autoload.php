<?php

declare(strict_types=1);

$composer = dirname(__DIR__) . '/vendor/autoload.php';

if (is_file($composer)) {
    require $composer;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'App\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $path = dirname(__DIR__) . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require $path;
        }
    });
}
