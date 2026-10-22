<?php

declare(strict_types=1);

// Autoloader PSR-4 minimal agar agent tidak butuh Composer di komputer user.
spl_autoload_register(static function (string $class): void {
    $prefix = 'NetWatch\\Agent\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
