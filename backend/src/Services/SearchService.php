<?php

declare(strict_types=1);

namespace Orbit;

final class SearchService
{
    public function __construct(private readonly App $app)
    {
    }

    public function search(array $user, string $query, string $type): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            throw new HttpException(422, 'Enter at least 2 characters to search.');
        }
        $like = like_term($query);
        $teamIds = $this->app->get(Access::class)->activeTeamIds((int) $user['id']);
        $result = ['tasks' => [], 'members' => [], 'teams' => [], 'announcements' => []];
        if (!$teamIds) {
            return $result;
        }
        $in = placeholders(count($teamIds));
        if ($type === 'all' || $type === 'tasks') {
            $stmt = $this->app->pdo()->prepare(
                "SELECT t.id, t.title, t.status, t.priority, t.team_id, teams.name AS team_name
                 FROM tasks t JOIN teams ON teams.id = t.team_id
                 WHERE t.team_id IN ($in) AND t.deleted_at IS NULL AND t.is_template = 0
                   AND (t.title LIKE ? OR t.description LIKE ?)
                 ORDER BY t.updated_at DESC LIMIT 20"
            );
            $stmt->execute(array_merge($teamIds, [$like, $like]));
            $result['tasks'] = array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'title' => $row['title'],
                'status' => $row['status'],
                'priority' => $row['priority'],
                'team_id' => (int) $row['team_id'],
                'team_name' => $row['team_name'],
            ], $stmt->fetchAll());
        }
        if ($type === 'all' || $type === 'members') {
            $stmt = $this->app->pdo()->prepare(
                "SELECT DISTINCT u.id, u.full_name, u.avatar_path, tm.team_id, teams.name AS team_name, tm.role
                 FROM team_members tm
                 JOIN users u ON u.id = tm.user_id
                 JOIN teams ON teams.id = tm.team_id
                 WHERE tm.team_id IN ($in) AND tm.status = 'active' AND u.full_name LIKE ?
                 ORDER BY u.full_name LIMIT 20"
            );
            $stmt->execute(array_merge($teamIds, [$like]));
            $result['members'] = array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'full_name' => $row['full_name'],
                'has_avatar' => !empty($row['avatar_path']),
                'team_id' => (int) $row['team_id'],
                'team_name' => $row['team_name'],
                'role' => $row['role'],
            ], $stmt->fetchAll());
        }
        if ($type === 'all' || $type === 'teams') {
            $stmt = $this->app->pdo()->prepare(
                "SELECT t.id, t.name, t.description, tm.role
                 FROM teams t
                 JOIN team_members tm ON tm.team_id = t.id AND tm.user_id = ? AND tm.status = 'active'
                 WHERE t.deleted_at IS NULL AND (t.name LIKE ? OR t.description LIKE ?)
                 ORDER BY t.name LIMIT 20"
            );
            $stmt->execute([$user['id'], $like, $like]);
            $result['teams'] = array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'description' => $row['description'],
                'role' => $row['role'],
            ], $stmt->fetchAll());
        }
        if ($type === 'all' || $type === 'announcements') {
            $stmt = $this->app->pdo()->prepare(
                "SELECT a.id, a.title, a.team_id, teams.name AS team_name, a.created_at
                 FROM announcements a JOIN teams ON teams.id = a.team_id
                 WHERE a.team_id IN ($in) AND a.deleted_at IS NULL AND (a.title LIKE ? OR a.body LIKE ?)
                 ORDER BY a.id DESC LIMIT 20"
            );
            $stmt->execute(array_merge($teamIds, [$like, $like]));
            $result['announcements'] = array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'title' => $row['title'],
                'team_id' => (int) $row['team_id'],
                'team_name' => $row['team_name'],
                'created_at' => iso($row['created_at']),
            ], $stmt->fetchAll());
        }

        return $result;
    }
}
