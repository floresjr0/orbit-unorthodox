<?php

declare(strict_types=1);

namespace Orbit;

final class RecurrenceService
{
    public function __construct(private readonly App $app)
    {
    }

    public function spawnFirst(int $templateId): int
    {
        $template = $this->template($templateId);
        $occurrence = $template['start_date'] ? substr((string) $template['start_date'], 0, 10) : gmdate('Y-m-d');
        $id = $this->copy($template, $occurrence);
        $next = $this->advance($occurrence, $template['recurrence_unit'], (int) $template['recurrence_interval']);
        if ($template['recurrence_until'] && $next > $template['recurrence_until']) {
            $next = null;
        }
        $this->app->pdo()->prepare('UPDATE tasks SET next_occurrence = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([$next, $templateId]);

        return $id;
    }

    public function spawnDue(): int
    {
        $stmt = $this->app->pdo()->query(
            'SELECT tasks.* FROM tasks
             JOIN teams ON teams.id = tasks.team_id
             WHERE tasks.is_template = 1 AND tasks.deleted_at IS NULL
               AND tasks.next_occurrence IS NOT NULL AND tasks.next_occurrence <= UTC_DATE()
               AND teams.deleted_at IS NULL AND teams.status = "active"'
        );
        $created = 0;
        foreach ($stmt->fetchAll() as $template) {
            $guard = 0;
            while ($template['next_occurrence'] && $template['next_occurrence'] <= gmdate('Y-m-d') && $guard < 30) {
                if ($template['recurrence_until'] && $template['next_occurrence'] > $template['recurrence_until']) {
                    $template['next_occurrence'] = null;
                    break;
                }
                $exists = $this->app->pdo()->prepare('SELECT id FROM tasks WHERE series_id = ? AND occurrence_on = ?');
                $exists->execute([$template['id'], $template['next_occurrence']]);
                if (!$exists->fetch()) {
                    try {
                        $this->copy($template, (string) $template['next_occurrence']);
                        $created++;
                    } catch (\PDOException $e) {
                        if (!str_contains($e->getMessage(), '1062')) {
                            throw $e;
                        }
                    }
                }
                $template['next_occurrence'] = $this->advance(
                    (string) $template['next_occurrence'],
                    $template['recurrence_unit'],
                    (int) $template['recurrence_interval']
                );
                if ($template['recurrence_until'] && $template['next_occurrence'] > $template['recurrence_until']) {
                    $template['next_occurrence'] = null;
                }
                $guard++;
            }
            $this->app->pdo()->prepare('UPDATE tasks SET next_occurrence = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')
                ->execute([$template['next_occurrence'], $template['id']]);
        }

        return $created;
    }

    private function copy(array $template, string $occurrence): int
    {
        $stmt = $this->app->pdo()->prepare(
            'INSERT INTO tasks (
                team_id, title, description, instructions, priority, status, review_status, category,
                start_date, deadline, requires_verification, is_template, recurrence, recurrence_interval,
                recurrence_unit, recurrence_until, series_id, occurrence_on, created_by, version, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, "todo", "none", ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $stmt->execute([
            $template['team_id'],
            $template['title'],
            $template['description'],
            $template['instructions'],
            $template['priority'],
            $template['category'],
            $template['start_date'],
            $template['deadline'],
            $template['requires_verification'],
            $template['recurrence'],
            $template['recurrence_interval'],
            $template['recurrence_unit'],
            $template['recurrence_until'],
            $template['id'],
            $occurrence,
            $template['created_by'],
        ]);
        $id = (int) $this->app->pdo()->lastInsertId();
        $this->app->pdo()->prepare(
            'INSERT INTO task_assignees (task_id, user_id, assigned_by, assigned_at)
             SELECT ?, user_id, assigned_by, UTC_TIMESTAMP() FROM task_assignees WHERE task_id = ?'
        )->execute([$id, $template['id']]);
        $lists = $this->app->pdo()->prepare('SELECT id, title, position FROM task_checklists WHERE task_id = ?');
        $lists->execute([$template['id']]);
        foreach ($lists->fetchAll() as $list) {
            $this->app->pdo()->prepare('INSERT INTO task_checklists (task_id, title, position) VALUES (?, ?, ?)')
                ->execute([$id, $list['title'], $list['position']]);
            $newList = (int) $this->app->pdo()->lastInsertId();
            $this->app->pdo()->prepare(
                'INSERT INTO task_checklist_items (checklist_id, content, is_done, position, updated_at)
                 SELECT ?, content, 0, position, UTC_TIMESTAMP() FROM task_checklist_items WHERE checklist_id = ?'
            )->execute([$newList, $list['id']]);
        }

        return $id;
    }

    private function template(int $id): array
    {
        $stmt = $this->app->pdo()->prepare('SELECT * FROM tasks WHERE id = ? AND is_template = 1 AND deleted_at IS NULL');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new HttpException(404, 'The requested record was not found.');
        }

        return $row;
    }

    private function advance(string $date, ?string $unit, int $interval): string
    {
        $interval = max(1, $interval);
        $spec = match ($unit) {
            'week' => 'weeks',
            'month' => 'months',
            default => 'days',
        };

        return (new \DateTimeImmutable($date . ' 00:00:00 UTC'))
            ->modify('+' . $interval . ' ' . $spec)
            ->format('Y-m-d');
    }
}
