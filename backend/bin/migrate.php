<?php

declare(strict_types=1);

use Orbit\Database;

require_once dirname(__DIR__) . '/src/Core/functions.php';
require_once dirname(__DIR__) . '/src/Core/Database.php';

$configFile = dirname(__DIR__) . '/config/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "Missing backend/config/config.php\n");
    exit(1);
}

/** @var array $config */
$config = require $configFile;
$fresh = in_array('--fresh', $argv, true);
if ($fresh && (($config['env'] ?? '') !== 'local')) {
    fwrite(STDERR, "Refusing --fresh when env is not local.\n");
    exit(1);
}

$db = $config['db'];
$server = Database::connect($db, false);
if ($fresh) {
    $server->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '', $db['name']) . '`');
    echo "Dropped database {$db['name']}.\n";
}

$sqlPath = dirname(__DIR__, 2) . '/database/schema.sql';
$sql = file_get_contents($sqlPath);
if ($sql === false) {
    fwrite(STDERR, "Could not read schema.sql\n");
    exit(1);
}

$sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
$parts = preg_split('/;\s*(?:\R|$)/', $sql) ?: [];
foreach ($parts as $statement) {
    $statement = trim($statement);
    if ($statement === '') {
        continue;
    }
    $server->exec($statement);
}

echo "ORBIT schema is installed.\n";
