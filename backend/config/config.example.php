<?php

declare(strict_types=1);

/**
 * Copy this file to config.php and replace every secret before use.
 * config.php is not committed.
 */
return [
    'env' => 'local',
    'debug' => true,
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'orbit',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'name' => 'ORBIT',
        'key' => 'change-me-to-a-64-character-random-hex-string-please-0000',
        'access_ttl' => 900,
        'refresh_ttl' => 2592000,
        'public_url' => 'http://localhost/orbit-unorthodox',
    ],
    'google' => [
        'client_id' => '',
    ],
    'fcm' => [
        'project_id' => '',
        'service_account' => '',
    ],
    'mail' => [
        'enabled' => false,
        'from' => 'noreply@localhost',
    ],
    'cors_origins' => [],
    'trust_proxy' => false,
];
