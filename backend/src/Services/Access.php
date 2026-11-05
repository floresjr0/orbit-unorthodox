<?php

declare(strict_types=1);

namespace Orbit;

final class Access
{
    public function __construct(private readonly App $app)
    {
    }

    public function isPlatformAdmin(int $userId): bool
    {
        $stmt = $this->app->pdo()->prepare('SELECT id FROM platform_admins WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);

        return (bool) $stmt->fetch();
    }

    public function requireAdmin(int $userId): void
    {
        if (!$this->isPlatformAdmin($userId)) {
            throw new HttpException(403, 'You are not authorized to perform this action.');
        }
    }

    public function membership(int $teamId, int $userId): ?array
    {
        $stmt = $this->app->pdo()->prepare(
            'SELECT tm.*, t.status AS team_status, t.deleted_at AS team_deleted
             FROM team_members tm
             JOIN teams t ON t.id = tm.team_id
             WHERE tm.team_id = ? AND tm.user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$teamId, $userId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function requireActiveMember(int $teamId, int $userId): array
    {
        $member = $this->membership($teamId, $userId);
        if (
            !$member
            || $member['status'] !== 'active'
            || $member['team_deleted'] !== null
            || $member['team_status'] === 'deleted'
        ) {
            throw new HttpException(404, 'The requested record was not found.');
        }

        return $member;
    }

    public function requireLeader(int $teamId, int $userId): array
    {
        $member = $this->requireActiveMember($teamId, $userId);
        if ($member['role'] !== 'leader') {
            throw new HttpException(403, 'You are not authorized to perform this action.');
        }

        return $member;
    }

    public function activeTeamIds(int $userId): array
    {
        $stmt = $this->app->pdo()->prepare(
            'SELECT tm.team_id
             FROM team_members tm
             JOIN teams t ON t.id = tm.team_id
             WHERE tm.user_id = ? AND tm.status = "active" AND t.deleted_at IS NULL AND t.status <> "deleted"'
        );
        $stmt->execute([$userId]);

        return array_map('intval', array_column($stmt->fetchAll(), 'team_id'));
    }

    public function leaderTeamIds(int $userId): array
    {
        $stmt = $this->app->pdo()->prepare(
            'SELECT tm.team_id
             FROM team_members tm
             JOIN teams t ON t.id = tm.team_id
             WHERE tm.user_id = ? AND tm.role = "leader" AND tm.status = "active"
               AND t.deleted_at IS NULL AND t.status = "active"'
        );
        $stmt->execute([$userId]);

        return array_map('intval', array_column($stmt->fetchAll(), 'team_id'));
    }

    public function sharesTeam(int $viewerId, int $otherId): bool
    {
        if ($viewerId === $otherId) {
            return true;
        }
        $stmt = $this->app->pdo()->prepare(
            'SELECT 1
             FROM team_members a
             JOIN team_members b ON b.team_id = a.team_id
             WHERE a.user_id = ? AND b.user_id = ? AND a.status = "active" AND b.status = "active"
             LIMIT 1'
        );
        $stmt->execute([$viewerId, $otherId]);

        return (bool) $stmt->fetch();
    }

    public function isAssignee(int $taskId, int $userId): bool
    {
        $stmt = $this->app->pdo()->prepare('SELECT 1 FROM task_assignees WHERE task_id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$taskId, $userId]);

        return (bool) $stmt->fetch();
    }
}
