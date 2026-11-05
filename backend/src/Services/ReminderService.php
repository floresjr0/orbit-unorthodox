<?php

declare(strict_types=1);

namespace Orbit;

final class ReminderService
{
    public function __construct(private readonly App $app)
    {
    }

    public function sendDue(): int
    {
        $stmt = $this->app->pdo()->query(
            'SELECT t.id, t.team_id, t.title, t.deadline, t.status, teams.leader_id
             FROM tasks t
             JOIN teams ON teams.id = t.team_id
             WHERE t.deleted_at IS NULL AND t.is_template = 0 AND t.status <> "done"
               AND t.deadline IS NOT NULL
               AND teams.deleted_at IS NULL AND teams.status = "active"
               AND t.deadline BETWEEN UTC_TIMESTAMP() - INTERVAL 8 DAY AND UTC_TIMESTAMP() + INTERVAL 24 HOUR'
        );
        $sent = 0;
        $now = time();
        foreach ($stmt->fetchAll() as $task) {
            $deadline = strtotime($task['deadline'] . ' UTC');
            if ($deadline === false) {
                continue;
            }
            $delta = $deadline - $now;
            $recipients = $this->recipients((int) $task['id'], (int) $task['leader_id']);
            foreach ($recipients as $userId) {
                $prefs = $this->app->get(NotificationService::class)->preferences($userId);
                $stamp = (string) $deadline;
                if ($delta <= 86400 && $delta > 3600 && !empty($prefs['reminder_24h'])) {
                    $sent += $this->send($userId, $task, 'Deadline in 24 hours', 'rem24:' . $task['id'] . ':' . $stamp);
                }
                if ($delta <= 3600 && $delta > 0 && !empty($prefs['reminder_1h'])) {
                    $sent += $this->send($userId, $task, 'Deadline in 1 hour', 'rem1:' . $task['id'] . ':' . $stamp);
                }
                if ($delta <= 0 && $delta > -900 && !empty($prefs['reminder_deadline'])) {
                    $sent += $this->send($userId, $task, 'Task deadline is now', 'rem0:' . $task['id'] . ':' . $stamp);
                }
                if ($delta <= -3600 && !empty($prefs['overdue_reminders'])) {
                    $count = $this->app->pdo()->prepare(
                        'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND dedupe_key LIKE ?'
                    );
                    $like = 'overdue:' . $task['id'] . ':' . $stamp . ':%:' . $userId;
                    $count->execute([$userId, $like]);
                    if ((int) $count->fetchColumn() < 5) {
                        $sent += $this->send(
                            $userId,
                            $task,
                            'Task is overdue',
                            'overdue:' . $task['id'] . ':' . $stamp . ':' . gmdate('Y-m-d'),
                            'overdue'
                        );
                    }
                }
            }
        }

        return $sent;
    }

    private function recipients(int $taskId, int $leaderId): array
    {
        $stmt = $this->app->pdo()->prepare(
            'SELECT ta.user_id FROM task_assignees ta
             JOIN team_members tm ON tm.user_id = ta.user_id AND tm.team_id = (SELECT team_id FROM tasks WHERE id = ta.task_id)
             WHERE ta.task_id = ? AND tm.status = "active"'
        );
        $stmt->execute([$taskId]);
        $ids = array_map('intval', array_column($stmt->fetchAll(), 'user_id'));
        $ids[] = $leaderId;

        return array_values(array_unique(array_filter($ids)));
    }

    private function send(int $userId, array $task, string $title, string $dedupe, string $type = 'deadline_reminder'): int
    {
        $before = $this->app->pdo()->prepare('SELECT id FROM notifications WHERE user_id = ? AND dedupe_key = ?');
        $before->execute([$userId, $dedupe . ':' . $userId]);
        if ($before->fetch()) {
            return 0;
        }
        $this->app->get(NotificationService::class)->notify(
            $userId,
            $type,
            $title,
            (string) $task['title'],
            ['task_id' => (int) $task['id'], 'team_id' => (int) $task['team_id']],
            (int) $task['team_id'],
            $dedupe . ':' . $userId
        );

        return 1;
    }
}
