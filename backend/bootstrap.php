<?php

declare(strict_types=1);

use Orbit\App;
use Orbit\Database;

require_once __DIR__ . '/src/Core/functions.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Orbit\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $name = substr($class, strlen($prefix));
    foreach (['Core', 'Http', 'Services'] as $dir) {
        $path = __DIR__ . '/src/' . $dir . '/' . $name . '.php';
        if (is_file($path)) {
            require $path;
            return;
        }
    }
});

date_default_timezone_set('UTC');
ini_set('display_errors', '0');

foreach (['logs', 'cache', 'uploads/avatars', 'uploads/teams', 'uploads/attachments', 'releases'] as $dir) {
    $path = Orbit\storage_path($dir);
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
}

$configFile = __DIR__ . '/config/config.php';
if (!is_file($configFile)) {
    throw new RuntimeException('Missing backend/config/config.php. Copy config.example.php and set the database credentials and app key.');
}

/** @var array $config */
$config = require $configFile;
$pdo = Database::connect($config['db']);

return new App($config, $pdo);
