<?php

declare(strict_types=1);

use Orbit\App;
use Orbit\AuditLog;

$options = getopt('', ['email:', 'password:', 'name:']);
$email = strtolower(trim((string) ($options['email'] ?? '')));
$password = (string) ($options['password'] ?? '');
$name = trim((string) ($options['name'] ?? 'Platform Admin'));

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
    fwrite(STDERR, "Usage: php backend/bin/create-admin.php --email=admin@orbit.local --password=ChangeMe123 --name=\"Platform Admin\"\n");
    exit(1);
}

/** @var App $app */
$app = require dirname(__DIR__) . '/bootstrap.php';
$pdo = $app->pdo();

$existing = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$existing->execute([$email]);
$userId = $existing->fetchColumn();
if ($userId) {
    $pdo->prepare('UPDATE users SET password_hash = ?, full_name = ?, status = "active", deleted_at = NULL, updated_at = UTC_TIMESTAMP() WHERE id = ?')
        ->execute([password_hash($password, PASSWORD_DEFAULT), $name, $userId]);
    $userId = (int) $userId;
} else {
    $pdo->prepare('INSERT INTO users (email, password_hash, full_name, status, created_at, updated_at) VALUES (?, ?, ?, "active", UTC_TIMESTAMP(), UTC_TIMESTAMP())')
        ->execute([$email, password_hash($password, PASSWORD_DEFAULT), $name]);
    $userId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO user_profiles (user_id) VALUES (?)')->execute([$userId]);
    $pdo->prepare('INSERT INTO notification_preferences (user_id) VALUES (?)')->execute([$userId]);
}

$pdo->prepare('INSERT IGNORE INTO platform_admins (user_id, role, created_at) VALUES (?, "super", UTC_TIMESTAMP())')->execute([$userId]);
$app->get(AuditLog::class)->write('platform', null, $userId, 'admin.created', 'A platform administrator was provisioned.', 'user', (string) $userId);
fwrite(STDOUT, "Platform admin ready: {$email} (user {$userId})\n");
