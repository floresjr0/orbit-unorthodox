<?php

declare(strict_types=1);

use Orbit\App;
use Orbit\RecurrenceService;
use Orbit\ReminderService;

$lockPath = dirname(__DIR__) . '/storage/cache/cron.lock';
$lock = fopen($lockPath, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "Cron already running.\n");
    exit(0);
}

/** @var App $app */
$app = require dirname(__DIR__) . '/bootstrap.php';

try {
    $spawned = $app->get(RecurrenceService::class)->spawnDue();
    $reminders = $app->get(ReminderService::class)->sendDue();
    $app->pdo()->exec('DELETE FROM refresh_tokens WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 1 DAY OR (revoked_at IS NOT NULL AND revoked_at < UTC_TIMESTAMP() - INTERVAL 30 DAY)');
    $app->pdo()->exec('DELETE FROM password_resets WHERE expires_at < UTC_TIMESTAMP() OR used_at IS NOT NULL');
    $app->get(Orbit\NotificationService::class)->flushPushes();
    \Orbit\app_log('cron', 'Cron finished', ['spawned' => $spawned, 'reminders' => $reminders]);
    fwrite(STDOUT, "Spawned {$spawned} task(s). Sent {$reminders} reminder(s).\n");
} catch (Throwable $e) {
    \Orbit\app_log('cron', 'Cron failed', ['error' => $e->getMessage()]);
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
