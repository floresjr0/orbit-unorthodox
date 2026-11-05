<?php

declare(strict_types=1);

namespace Orbit;

final class NotificationService
{
    private const TYPE_COLUMN = [
        'task_assigned' => 'task_assigned',
        'task_reassigned' => 'task_assigned',
        'task_updated' => 'task_updated',
        'deadline_changed' => 'task_updated',
        'status_changed' => 'status_changed',
        'comment' => 'comments',
        'progress' => 'comments',
        'announcement' => 'announcements',
        'deadline_reminder' => 'deadlines',
        'overdue' => 'deadlines',
        'review_requested' => 'reviews',
        'review_approved' => 'reviews',
        'review_rejected' => 'reviews',
        'member_joined' => 'membership',
        'member_requested' => 'membership',
        'member_left' => 'membership',
        'leadership_transferred' => 'membership',
    ];

    /** @var array<int, array<string, mixed>> */
    private array $pushes = [];

    public function __construct(private readonly App $app)
    {
    }

    public function clearPushes(): void
    {
        $this->pushes = [];
    }

    public function preferences(int $userId): array
    {
        $stmt = $this->app->pdo()->prepare('SELECT * FROM notification_preferences WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) {
            $this->app->pdo()->prepare('INSERT INTO notification_preferences (user_id) VALUES (?)')->execute([$userId]);
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
        }

        return $row ?: [];
    }

    public function updatePreferences(int $userId, array $input): array
    {
        $current = $this->preferences($userId);
        $columns = [
            'in_app_enabled', 'push_enabled', 'task_assigned', 'task_updated', 'status_changed',
            'comments', 'announcements', 'deadlines', 'reviews', 'membership',
            'reminder_24h', 'reminder_1h', 'reminder_deadline', 'overdue_reminders',
        ];
        $sets = [];
        $values = [];
        foreach ($columns as $column) {
            if (array_key_exists($column, $input)) {
                $sets[] = $column . ' = ?';
                $values[] = !empty($input[$column]) ? 1 : 0;
            }
        }
        if ($sets) {
            $values[] = $userId;
            $this->app->pdo()->prepare(
                'UPDATE notification_preferences SET ' . implode(', ', $sets) . ', updated_at = UTC_TIMESTAMP() WHERE user_id = ?'
            )->execute($values);
        }

        return $this->publicPreferences($this->preferences($userId));
    }

    public function publicPreferences(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            if (in_array($key, ['user_id', 'updated_at'], true)) {
                continue;
            }
            $out[$key] = (bool) $value;
        }

        return $out;
    }

    public function notify(
        int $userId,
        string $type,
        string $title,
        string $body,
        array $data = [],
        ?int $teamId = null,
        ?string $dedupe = null
    ): void {
        if ($userId <= 0) {
            return;
        }
        $prefs = $this->preferences($userId);
        $column = self::TYPE_COLUMN[$type] ?? null;
        if ($column !== null && empty($prefs[$column])) {
            return;
        }
        if (empty($prefs['in_app_enabled']) && empty($prefs['push_enabled'])) {
            return;
        }

        $title = mb_substr(trim($title), 0, 160);
        $body = mb_substr(trim($body), 0, 300);
        try {
            $stmt = $this->app->pdo()->prepare(
                'INSERT INTO notifications
                (user_id, team_id, type, title, body, data_json, is_read, dedupe_key, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 0, ?, UTC_TIMESTAMP())'
            );
            $stmt->execute([
                $userId,
                $teamId,
                $type,
                $title,
                $body,
                $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
                $dedupe,
            ]);
            $notificationId = (int) $this->app->pdo()->lastInsertId();
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), '1062')) {
                return;
            }
            throw $e;
        }

        if (!empty($prefs['push_enabled'])) {
            $payload = $data;
            $payload['type'] = $type;
            $payload['notification_id'] = $notificationId;
            if ($teamId) {
                $payload['team_id'] = $teamId;
            }
            $this->pushes[] = [
                'user_id' => $userId,
                'title' => $title,
                'body' => $body,
                'data' => $payload,
            ];
        }
    }

    public function notifyMany(array $userIds, string $type, string $title, string $body, array $data = [], ?int $teamId = null, ?string $dedupePrefix = null): void
    {
        foreach (array_unique(array_map('intval', $userIds)) as $userId) {
            $dedupe = $dedupePrefix ? $dedupePrefix . ':' . $userId : null;
            $this->notify($userId, $type, $title, $body, $data, $teamId, $dedupe);
        }
    }

    public function flushPushes(): void
    {
        if (!$this->pushes) {
            return;
        }
        $batch = $this->pushes;
        $this->pushes = [];
        $fcm = $this->app->get(FcmService::class);
        foreach ($batch as $push) {
            $tokens = $this->tokens((int) $push['user_id']);
            if (!$tokens) {
                if (!$fcm->configured()) {
                    app_log('push', 'FCM fallback, no device token', [
                        'user_id' => $push['user_id'],
                        'title' => $push['title'],
                    ]);
                }
                continue;
            }
            $fcm->send($tokens, $push['title'], $push['body'], $push['data']);
        }
    }

    public function listFor(int $userId, int $page, int $perPage, int $offset, bool $unreadOnly = false): array
    {
        $prefs = $this->preferences($userId);
        if (empty($prefs['in_app_enabled'])) {
            return [
                'items' => [],
                'unread_count' => 0,
                'paused' => true,
                'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => 0],
            ];
        }
        $where = 'user_id = ?';
        $params = [$userId];
        if ($unreadOnly) {
            $where .= ' AND is_read = 0';
        }
        $count = $this->app->pdo()->prepare("SELECT COUNT(*) FROM notifications WHERE $where");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $stmt = $this->app->pdo()->prepare(
            "SELECT * FROM notifications WHERE $where ORDER BY id DESC LIMIT $perPage OFFSET $offset"
        );
        $stmt->execute($params);
        $unread = $this->app->pdo()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $unread->execute([$userId]);

        return [
            'items' => array_map([Present::class, 'notification'], $stmt->fetchAll()),
            'unread_count' => (int) $unread->fetchColumn(),
            'paused' => false,
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
        ];
    }

    public function markRead(int $userId, ?int $id = null): void
    {
        if ($id) {
            $this->app->pdo()->prepare(
                'UPDATE notifications SET is_read = 1, read_at = UTC_TIMESTAMP() WHERE id = ? AND user_id = ?'
            )->execute([$id, $userId]);
            return;
        }
        $this->app->pdo()->prepare(
            'UPDATE notifications SET is_read = 1, read_at = UTC_TIMESTAMP() WHERE user_id = ? AND is_read = 0'
        )->execute([$userId]);
    }

    private function tokens(int $userId): array
    {
        $stmt = $this->app->pdo()->prepare(
            'SELECT token FROM device_tokens WHERE user_id = ? AND invalidated_at IS NULL'
        );
        $stmt->execute([$userId]);

        return array_column($stmt->fetchAll(), 'token');
    }
}
