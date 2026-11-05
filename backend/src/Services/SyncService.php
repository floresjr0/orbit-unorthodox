<?php

declare(strict_types=1);

namespace Orbit;

final class SyncService
{
    public function __construct(private readonly App $app)
    {
    }

    public function push(array $user, array $operations): array
    {
        if (count($operations) > 50) {
            throw new HttpException(422, 'Send at most 50 changes at a time.');
        }
        $results = [];
        foreach ($operations as $operation) {
            if (!is_array($operation)) {
                $results[] = ['op_id' => null, 'status' => 'rejected', 'message' => 'Invalid operation.'];
                continue;
            }
            $results[] = $this->one($user, $operation);
        }

        return $results;
    }

    public function pull(array $user, ?string $since): array
    {
        $cursor = $since ? parse_datetime($since, true) : '1970-01-01 00:00:00';
        $teamIds = $this->app->get(Access::class)->activeTeamIds((int) $user['id']);
        $serverTime = gmdate('Y-m-d H:i:s');
        $tasks = $teamIds ? $this->app->get(TaskService::class)->changedSince($user, $cursor) : [];
        $comments = [];
        $announcements = [];
        $members = [];
        $teams = $this->changedTeams($user, $cursor);
        if ($teamIds) {
            $in = placeholders(count($teamIds));
            $commentStmt = $this->app->pdo()->prepare(
                "SELECT c.* FROM task_comments c
                 JOIN tasks t ON t.id = c.task_id
                 WHERE t.team_id IN ($in) AND c.updated_at >= ?
                 ORDER BY c.id ASC LIMIT 400"
            );
            $commentStmt->execute(array_merge($teamIds, [$cursor]));
            foreach ($commentStmt->fetchAll() as $row) {
                $item = Present::comment($row);
                $item['deleted_at'] = iso($row['deleted_at']);
                $comments[] = $item;
            }
            $annStmt = $this->app->pdo()->prepare(
                "SELECT * FROM announcements WHERE team_id IN ($in) AND updated_at >= ? ORDER BY id ASC LIMIT 200"
            );
            $annStmt->execute(array_merge($teamIds, [$cursor]));
            foreach ($annStmt->fetchAll() as $row) {
                $item = Present::announcement($row);
                $item['deleted_at'] = iso($row['deleted_at']);
                $announcements[] = $item;
            }
            $memberStmt = $this->app->pdo()->prepare(
                "SELECT tm.*, u.full_name, u.avatar_path
                 FROM team_members tm JOIN users u ON u.id = tm.user_id
                 WHERE tm.team_id IN ($in) AND tm.updated_at >= ?"
            );
            $memberStmt->execute(array_merge($teamIds, [$cursor]));
            foreach ($memberStmt->fetchAll() as $row) {
                $members[] = [
                    'id' => (int) $row['id'],
                    'team_id' => (int) $row['team_id'],
                    'user_id' => (int) $row['user_id'],
                    'full_name' => $row['full_name'],
                    'has_avatar' => !empty($row['avatar_path']),
                    'role' => $row['role'],
                    'status' => $row['status'],
                    'updated_at' => iso($row['updated_at']),
                ];
            }
        }
        $noteStmt = $this->app->pdo()->prepare(
            'SELECT * FROM notifications WHERE user_id = ? AND created_at >= ? ORDER BY id ASC LIMIT 200'
        );
        $noteStmt->execute([$user['id'], $cursor]);

        return [
            'server_time' => iso($serverTime),
            'active_team_ids' => $teamIds,
            'teams' => $teams,
            'members' => $members,
            'tasks' => $tasks,
            'comments' => $comments,
            'announcements' => $announcements,
            'notifications' => array_map([Present::class, 'notification'], $noteStmt->fetchAll()),
        ];
    }

    private function one(array $user, array $operation): array
    {
        $opId = (string) ($operation['op_id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $opId)) {
            return ['op_id' => $opId, 'status' => 'rejected', 'message' => 'Every offline change needs a valid operation id.'];
        }
        $existing = $this->app->pdo()->prepare('SELECT result_json FROM sync_operations WHERE user_id = ? AND client_op_id = ?');
        $existing->execute([$user['id'], $opId]);
        $saved = $existing->fetchColumn();
        if ($saved) {
            $decoded = json_decode((string) $saved, true);
            return is_array($decoded) ? $decoded : ['op_id' => $opId, 'status' => 'rejected', 'message' => 'Stored result was unreadable.'];
        }
        try {
            $result = $this->apply($user, $opId, $operation);
        } catch (HttpException $e) {
            if ($e->status >= 500) {
                throw $e;
            }
            $result = [
                'op_id' => $opId,
                'status' => $e->status === 409 ? 'conflict' : 'rejected',
                'message' => $e->getMessage(),
                'data' => $e->data,
            ];
        }
        try {
            $this->app->pdo()->prepare(
                'INSERT INTO sync_operations (user_id, client_op_id, entity_type, entity_id, operation, result_json, applied_at)
                 VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
            )->execute([
                $user['id'],
                $opId,
                substr((string) ($operation['type'] ?? 'unknown'), 0, 40),
                substr((string) ($operation['entity_id'] ?? ''), 0, 64),
                substr((string) ($operation['type'] ?? 'unknown'), 0, 40),
                json_encode($result, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\PDOException $e) {
            if (!str_contains($e->getMessage(), '1062')) {
                throw $e;
            }
        }

        return $result;
    }

    private function apply(array $user, string $opId, array $operation): array
    {
        $type = (string) ($operation['type'] ?? '');
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];
        $base = isset($operation['base_version']) ? (int) $operation['base_version'] : null;
        $tasks = $this->app->get(TaskService::class);
        if ($type === 'task.status') {
            $task = $tasks->changeStatus(
                $user,
                (int) ($operation['entity_id'] ?? 0),
                (string) ($payload['status'] ?? ''),
                $base,
                isset($payload['base_status']) ? (string) $payload['base_status'] : null,
                isset($payload['note']) ? (string) $payload['note'] : null
            );

            return ['op_id' => $opId, 'status' => 'applied', 'message' => 'Task updated successfully.', 'data' => ['task' => $task]];
        }
        if ($type === 'task.comment') {
            $comment = $tasks->addComment(
                $user,
                (int) ($operation['entity_id'] ?? 0),
                (string) ($payload['body'] ?? ''),
                (string) ($payload['comment_type'] ?? 'comment')
            );

            return [
                'op_id' => $opId,
                'status' => 'applied',
                'message' => 'Comment saved.',
                'data' => ['comment' => $comment, 'local_id' => $payload['local_id'] ?? null],
            ];
        }
        if ($type === 'task.checklist') {
            $baseDone = array_key_exists('base_is_done', $payload) ? (bool) $payload['base_is_done'] : null;
            $task = $tasks->toggleChecklistItem($user, (int) ($payload['item_id'] ?? 0), !empty($payload['is_done']), $baseDone);

            return ['op_id' => $opId, 'status' => 'applied', 'message' => 'Checklist updated.', 'data' => ['task' => $task]];
        }

        throw new HttpException(422, 'This offline change is not supported.');
    }

    private function changedTeams(array $user, string $cursor): array
    {
        $stmt = $this->app->pdo()->prepare(
            'SELECT t.*, tm.role, tm.status AS membership_status,
                (SELECT COUNT(*) FROM team_members m WHERE m.team_id = t.id AND m.status = "active") AS member_count
             FROM team_members tm
             JOIN teams t ON t.id = tm.team_id
             WHERE tm.user_id = ? AND (tm.updated_at >= ? OR t.updated_at >= ?)
             ORDER BY t.id ASC'
        );
        $stmt->execute([$user['id'], $cursor, $cursor]);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $team = Present::team($row, null);
            $team['deleted_at'] = iso($row['deleted_at']);
            $items[] = $team;
        }

        return $items;
    }
}
