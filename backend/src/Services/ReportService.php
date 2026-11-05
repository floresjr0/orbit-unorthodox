<?php

declare(strict_types=1);

namespace Orbit;

final class ReportService
{
    public function __construct(private readonly App $app)
    {
    }

    public function dashboard(array $user, ?int $teamId = null): array
    {
        $leaderTeams = $this->app->get(Access::class)->leaderTeamIds((int) $user['id']);
        $memberTeams = $this->app->get(Access::class)->activeTeamIds((int) $user['id']);
        if ($teamId) {
            if (!in_array($teamId, $memberTeams, true)) {
                throw new HttpException(404, 'The requested record was not found.');
            }
            $memberTeams = [$teamId];
            $leaderTeams = in_array($teamId, $leaderTeams, true) ? [$teamId] : [];
        }
        $teams = $this->app->get(TeamService::class)->listFor($user);
        if ($teamId) {
            $teams = array_values(array_filter($teams, static fn (array $team): bool => $team['id'] === $teamId));
        }

        return [
            'viewer' => [
                'id' => (int) $user['id'],
                'full_name' => $user['full_name'],
                'timezone' => $user['timezone'],
                'leads_team' => $leaderTeams !== [],
                'teams' => count($teams),
            ],
            'teams' => $teams,
            'leader' => $leaderTeams ? $this->leader($user, $leaderTeams) : null,
            'member' => $this->member($user, $memberTeams),
        ];
    }

    public function teamReport(array $user, int $teamId): array
    {
        $this->app->get(Access::class)->requireLeader($teamId, (int) $user['id']);
        $team = $this->app->pdo()->prepare('SELECT id, name FROM teams WHERE id = ? AND deleted_at IS NULL');
        $team->execute([$teamId]);
        $row = $team->fetch();
        if (!$row) {
            throw new HttpException(404, 'The requested record was not found.');
        }
        $counts = $this->statusCounts([$teamId]);
        $total = ($counts['todo'] ?? 0) + ($counts['in_progress'] ?? 0) + ($counts['pending'] ?? 0) + ($counts['blocked'] ?? 0) + ($counts['done'] ?? 0);
        $done = $counts['done'] ?? 0;
        $members = $this->memberProgress([$teamId]);
        $overdue = $this->taskBriefs([$teamId], 't.deadline IS NOT NULL AND t.deadline < UTC_TIMESTAMP() AND t.status <> "done"', 50);
        $activity = $this->activity([$teamId], null, 30);

        return [
            'team' => ['id' => (int) $row['id'], 'name' => $row['name']],
            'summary' => [
                'total_tasks' => $total,
                'completed' => $done,
                'completion_rate' => $total > 0 ? round($done / $total * 100, 1) : 0,
                'overdue' => $counts['overdue'] ?? 0,
                'awaiting_review' => $counts['awaiting'] ?? 0,
                'by_status' => [
                    'todo' => $counts['todo'] ?? 0,
                    'in_progress' => $counts['in_progress'] ?? 0,
                    'pending' => $counts['pending'] ?? 0,
                    'blocked' => $counts['blocked'] ?? 0,
                    'done' => $done,
                ],
                'by_priority' => $this->priorityCounts([$teamId]),
            ],
            'members' => $members,
            'overdue_tasks' => $overdue,
            'tasks' => $this->taskBriefs([$teamId], '1 = 1', 100),
            'activity' => $activity,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    public function csv(array $report): string
    {
        $lines = [];
        $push = static function (array $cols) use (&$lines): void {
            $lines[] = implode(',', array_map(static function ($value): string {
                $text = (string) $value;
                return '"' . str_replace('"', '""', $text) . '"';
            }, $cols));
        };
        $summary = $report['summary'];
        $push(['ORBIT team report', $report['team']['name']]);
        $push(['Generated at', $report['generated_at']]);
        $push([]);
        $push(['Metric', 'Value']);
        $push(['Total tasks', $summary['total_tasks']]);
        $push(['Completed', $summary['completed']]);
        $push(['Completion rate', $summary['completion_rate']]);
        $push(['Overdue', $summary['overdue']]);
        $push(['Awaiting review', $summary['awaiting_review']]);
        foreach ($summary['by_status'] as $status => $count) {
            $push(['Status ' . $status, $count]);
        }
        foreach ($summary['by_priority'] as $priority => $count) {
            $push(['Priority ' . $priority, $count]);
        }
        $push([]);
        $push(['Member', 'Assigned', 'Completed', 'Overdue']);
        foreach ($report['members'] as $member) {
            $push([$member['full_name'], $member['assigned_count'], $member['completed_count'], $member['overdue_count']]);
        }
        $push([]);
        $push(['Overdue task', 'Priority', 'Deadline']);
        foreach ($report['overdue_tasks'] as $task) {
            $push([$task['title'], $task['priority'], $task['deadline']]);
        }
        $push([]);
        $push(['Task', 'Status', 'Priority', 'Deadline']);
        foreach ($report['tasks'] as $task) {
            $push([$task['title'], $task['status'], $task['priority'], $task['deadline'] ?? '']);
        }

        return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
    }

    private function leader(array $user, array $teamIds): array
    {
        $counts = $this->statusCounts($teamIds);
        $total = array_sum(array_intersect_key($counts, array_flip(['todo', 'in_progress', 'pending', 'blocked', 'done'])));

        return [
            'total_teams' => count($teamIds),
            'total_members' => $this->memberCount($teamIds),
            'total_tasks' => $total,
            'completed_tasks' => $counts['done'] ?? 0,
            'in_progress_tasks' => $counts['in_progress'] ?? 0,
            'pending_tasks' => $counts['pending'] ?? 0,
            'overdue_tasks' => $counts['overdue'] ?? 0,
            'awaiting_approval' => $counts['awaiting'] ?? 0,
            'by_status' => [
                'todo' => $counts['todo'] ?? 0,
                'in_progress' => $counts['in_progress'] ?? 0,
                'pending' => $counts['pending'] ?? 0,
                'blocked' => $counts['blocked'] ?? 0,
                'done' => $counts['done'] ?? 0,
            ],
            'upcoming' => $this->taskBriefs($teamIds, 't.deadline IS NOT NULL AND t.deadline >= UTC_TIMESTAMP() AND t.status <> "done"', 8),
            'awaiting' => $this->taskBriefs($teamIds, 't.review_status = "awaiting"', 8),
            'members' => $this->memberProgress($teamIds),
            'activity' => $this->activity($teamIds, null, 12),
        ];
    }

    private function member(array $user, array $teamIds): array
    {
        if (!$teamIds) {
            return [
                'assigned' => 0,
                'due_today' => [],
                'upcoming' => [],
                'overdue' => [],
                'completed' => 0,
                'awaiting_review' => 0,
                'announcements' => [],
                'activity' => [],
            ];
        }
        $in = placeholders(count($teamIds));
        $params = array_merge([(int) $user['id']], $teamIds);
        $stmt = $this->app->pdo()->prepare(
            "SELECT
                COUNT(*) AS assigned,
                SUM(t.status = 'done') AS completed,
                SUM(t.review_status = 'awaiting') AS awaiting
             FROM tasks t
             JOIN task_assignees ta ON ta.task_id = t.id AND ta.user_id = ?
             WHERE t.team_id IN ($in) AND t.deleted_at IS NULL AND t.is_template = 0"
        );
        $stmt->execute($params);
        $counts = $stmt->fetch() ?: ['assigned' => 0, 'completed' => 0, 'awaiting' => 0];
        $mine = 'EXISTS (SELECT 1 FROM task_assignees ta WHERE ta.task_id = t.id AND ta.user_id = ' . (int) $user['id'] . ')';
        [$start, $end] = day_bounds_utc((string) ($user['timezone'] ?: 'UTC'));
        $ann = $this->app->pdo()->prepare(
            "SELECT a.* FROM announcements a
             WHERE a.team_id IN ($in) AND a.deleted_at IS NULL
             ORDER BY a.id DESC LIMIT 5"
        );
        $ann->execute($teamIds);

        return [
            'assigned' => (int) $counts['assigned'],
            'completed' => (int) $counts['completed'],
            'awaiting_review' => (int) $counts['awaiting'],
            'due_today' => $this->taskBriefs($teamIds, "t.deadline >= " . $this->quote($start) . " AND t.deadline < " . $this->quote($end) . " AND t.status <> 'done' AND $mine", 8),
            'upcoming' => $this->taskBriefs($teamIds, "t.deadline IS NOT NULL AND t.deadline >= UTC_TIMESTAMP() AND t.status <> 'done' AND $mine", 8),
            'overdue' => $this->taskBriefs($teamIds, "t.deadline IS NOT NULL AND t.deadline < UTC_TIMESTAMP() AND t.status <> 'done' AND $mine", 8),
            'announcements' => array_map([Present::class, 'announcement'], $ann->fetchAll()),
            'activity' => $this->activity($teamIds, (int) $user['id'], 12),
        ];
    }

    private function statusCounts(array $teamIds): array
    {
        $in = placeholders(count($teamIds));
        $stmt = $this->app->pdo()->prepare(
            "SELECT
                SUM(status = 'todo') AS todo,
                SUM(status = 'in_progress') AS in_progress,
                SUM(status = 'pending') AS pending,
                SUM(status = 'blocked') AS blocked,
                SUM(status = 'done') AS done,
                SUM(review_status = 'awaiting') AS awaiting,
                SUM(deadline IS NOT NULL AND deadline < UTC_TIMESTAMP() AND status <> 'done') AS overdue
             FROM tasks
             WHERE team_id IN ($in) AND deleted_at IS NULL AND is_template = 0"
        );
        $stmt->execute($teamIds);
        $row = $stmt->fetch() ?: [];
        $out = [];
        foreach ($row as $key => $value) {
            $out[$key] = (int) $value;
        }

        return $out;
    }

    private function priorityCounts(array $teamIds): array
    {
        $in = placeholders(count($teamIds));
        $stmt = $this->app->pdo()->prepare(
            "SELECT priority, COUNT(*) AS total FROM tasks
             WHERE team_id IN ($in) AND deleted_at IS NULL AND is_template = 0
             GROUP BY priority"
        );
        $stmt->execute($teamIds);
        $out = ['low' => 0, 'medium' => 0, 'high' => 0, 'urgent' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['priority']] = (int) $row['total'];
        }

        return $out;
    }

    private function memberCount(array $teamIds): int
    {
        $stmt = $this->app->pdo()->prepare(
            'SELECT COUNT(DISTINCT user_id) FROM team_members WHERE status = "active" AND team_id IN (' . placeholders(count($teamIds)) . ')'
        );
        $stmt->execute($teamIds);

        return (int) $stmt->fetchColumn();
    }

    private function memberProgress(array $teamIds): array
    {
        $in = placeholders(count($teamIds));
        $stmt = $this->app->pdo()->prepare(
            "SELECT u.id, u.full_name, u.avatar_path,
                COUNT(DISTINCT t.id) AS assigned_count,
                COUNT(DISTINCT CASE WHEN t.status = 'done' THEN t.id END) AS completed_count,
                COUNT(DISTINCT CASE WHEN t.deadline < UTC_TIMESTAMP() AND t.status <> 'done' THEN t.id END) AS overdue_count
             FROM team_members tm
             JOIN users u ON u.id = tm.user_id
             LEFT JOIN task_assignees ta ON ta.user_id = tm.user_id
             LEFT JOIN tasks t ON t.id = ta.task_id AND t.team_id = tm.team_id AND t.deleted_at IS NULL AND t.is_template = 0
             WHERE tm.team_id IN ($in) AND tm.status = 'active'
             GROUP BY u.id, u.full_name, u.avatar_path
             ORDER BY overdue_count DESC, u.full_name ASC"
        );
        $stmt->execute($teamIds);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'full_name' => $row['full_name'],
                'has_avatar' => !empty($row['avatar_path']),
                'assigned_count' => (int) $row['assigned_count'],
                'completed_count' => (int) $row['completed_count'],
                'overdue_count' => (int) $row['overdue_count'],
            ];
        }

        return $items;
    }

    private function taskBriefs(array $teamIds, string $extra, int $limit): array
    {
        $in = placeholders(count($teamIds));
        $limit = max(1, min(50, $limit));
        $stmt = $this->app->pdo()->prepare(
            "SELECT t.id, t.title, t.status, t.priority, t.review_status, t.deadline, t.team_id, teams.name AS team_name
             FROM tasks t
             JOIN teams ON teams.id = t.team_id
             WHERE t.team_id IN ($in) AND t.deleted_at IS NULL AND t.is_template = 0 AND $extra
             ORDER BY (t.deadline IS NULL), t.deadline ASC, t.id DESC
             LIMIT $limit"
        );
        $stmt->execute($teamIds);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'title' => $row['title'],
                'status' => $row['status'],
                'priority' => $row['priority'],
                'review_status' => $row['review_status'],
                'deadline' => iso($row['deadline']),
                'team_id' => (int) $row['team_id'],
                'team_name' => $row['team_name'],
            ];
        }

        return $items;
    }

    private function activity(array $teamIds, ?int $assigneeId, int $limit): array
    {
        $in = placeholders(count($teamIds));
        $params = $teamIds;
        $join = '';
        if ($assigneeId) {
            $join = 'JOIN task_assignees ta ON ta.task_id = l.task_id AND ta.user_id = ?';
            array_unshift($params, $assigneeId);
        }
        $stmt = $this->app->pdo()->prepare(
            "SELECT l.id, l.task_id, l.action, l.summary, l.actor_name, l.created_at
             FROM task_activity_logs l
             $join
             WHERE l.team_id IN ($in)
             ORDER BY l.id DESC
             LIMIT $limit"
        );
        $stmt->execute($params);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'task_id' => (int) $row['task_id'],
                'action' => $row['action'],
                'summary' => $row['summary'],
                'actor_name' => $row['actor_name'],
                'created_at' => iso($row['created_at']),
            ];
        }

        return $items;
    }

    private function quote(string $value): string
    {
        return $this->app->pdo()->quote($value);
    }
}
