<?php

declare(strict_types=1);

namespace Orbit;

final class Database
{
    public static function connect(array $db, bool $withDatabase = true): \PDO
    {
        $charset = $db['charset'] ?? 'utf8mb4';
        $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $db['host'], (int) $db['port'], $charset);
        if ($withDatabase) {
            $dsn .= ';dbname=' . $db['name'];
        }

        $pdo = new \PDO($dsn, $db['user'], $db['pass'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");

        return $pdo;
    }
}
