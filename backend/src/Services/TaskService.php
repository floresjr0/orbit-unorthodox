<?php

declare(strict_types=1);

namespace Orbit;

final class TaskService
{
    private const STATUSES = ['todo', 'in_progress', 'pending', 'blocked', 'done'];
    private const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    public function __construct(private readonly App $app)
    {
    }

    public function list(array $user, Request $request): array
    {
        [$page, $perPage, $offset] = page_params($request);
        [$where, $params] = $this->filters($user, $request);
        $pdo = $this->app->pdo();
        $count = $pdo->prepare("SELECT COUNT(*) FROM tasks t WHERE $where");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $stmt = $pdo->prepare(
            "SELECT t.* FROM tasks t WHERE $where
             ORDER BY (t.deadline IS NULL), t.deadline ASC, t.id DESC
             LIMIT $perPage OFFSET $offset"
        );
        $stmt->execute($params);

        return [
            'items' => $this->hydrate($stmt->fetchAll()),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
        ];
    }

    public function calendar(array $user, Request $request): array
    {
        $from = parse_datetime($request->query['from'] ?? null, true);
        $to = parse_datetime($request->query['to'] ?? null, true);
        $teamId = (int) ($request->query['team_id'] ?? 0);
        $teamIds = $this->scopedTeams($user, $teamId, (string) ($request->query['scope'] ?? 'mine'));
        if (!$teamIds) {
            return [];
        }
        $params = array_merge($teamIds, [$from, $to, $from, $to]);
        $sql = 'SELECT t.* FROM tasks t WHERE t.deleted_at IS NULL AND t.is_template = 0 AND t.team_id IN ('
            . placeholders(count($teamIds)) . ')
            AND ((t.deadline >= ? AND t.deadline <= ?) OR (t.start_date >= ? AND t.start_date <= ?))';
        if (($request->query['scope'] ?? 'mine') !== 'team') {
            $sql .= ' AND EXISTS (SELECT 1 FROM task_assignees ta WHERE ta.task_id = t.id AND ta.user_id = ?)';
            $params[] = $user['id'];
        }
        $sql .= ' ORDER BY COALESCE(t.deadline, t.start_date) ASC LIMIT 200';
        $stmt = $this->app->pdo()->prepare($sql);
        $stmt->execute($params);

        return $this->hydrate($stmt->fetchAll(), false);
    }

    public function create(array $user, int $teamId, array $input): array
    {
        $member = $this->app->get(Access::class)->requireLeader($teamId, (int) $user['id']);
        $this->assertMutableTeam($teamId);
        $data = $this->validate($input, $this->team($teamId), false);
        $assignees = $this->assertAssignees($teamId, $input['assignee_ids'] ?? []);
        $checklist = $this->cleanChecklist($input['checklist'] ?? []);

        return $this->app->transaction(function () use ($user, $teamId, $data, $assignees, $checklist) {
            $template = $data['recurrence'] !== 'none';
            $taskId = $this->insertTask($teamId, (int) $user['id'], $data, $template);
            $this->replaceAssignees($taskId, $assignees, (int) $user['id']);
            $this->insertChecklist($taskId, $checklist);
            $visibleId = $taskId;
            if ($template) {
                $visibleId = $this->app->get(RecurrenceService::class)->spawnFirst($taskId);
            }
            $created = $this->row($visibleId);
            $this->log($created, $user, 'task_created', 'Task was created.');
            $this->log($created, $user, 'task_assigned', 'Task was assigned.');
            $this->app->get(NotificationService::class)->notifyMany(
                array_diff($assignees, [(int) $user['id']]),
                'task_assigned',
                'New task assigned',
                $created['title'],
                ['task_id' => $visibleId, 'team_id' => $teamId],
                $teamId,
                'assigned:' . $visibleId
            );

            return $this->present($visibleId);
        });
    }

    public function get(array $user, int $taskId): array
    {
        $task = $this->row($taskId);
        $this->assertCanView($user, $task);

        return $this->present($taskId);
    }

    public function update(array $user, int $taskId, array $input): array
    {
        return $this->app->transaction(function () use ($user, $taskId, $input) {
            $task = $this->lock($taskId);
            $this->guard($task);
            $this->app->get(Access::class)->requireLeader((int) $task['team_id'], (int) $user['id']);
            $this->assertMutableTeam((int) $task['team_id']);
            $fields = [];
            $logs = [];
            $notifyType = null;
            if (array_key_exists('title', $input)) {
                $title = clean_text($input['title'], 160);
                if (mb_strlen($title) < 2) {
                    throw new HttpException(422, 'Task title must be at least 2 characters.');
                }
                if ($title !== $task['title']) {
                    $fields['title'] = $title;
                    $logs[] = ['task_updated', 'Title was updated.'];
                    $notifyType = 'task_updated';
                }
            }
            foreach (['description' => 8000, 'instructions' => 8000] as $key => $max) {
                if (array_key_exists($key, $input)) {
                    $value = trim((string) $input[$key]);
                    if (mb_strlen($value) > $max) {
                        throw new HttpException(422, 'That text is too long.');
                    }
                    if ($key === 'instructions' && $value !== '') {
                        $value = $this->driveLink($value, 2000);
                    }
                    $value = $value === '' ? null : $value;
                    if ($value !== $task[$key]) {
                        $fields[$key] = $value;
                        $logs[] = ['task_updated', ucfirst($key) . ' was updated.'];
                        $notifyType = 'task_updated';
                    }
                }
            }
            if (array_key_exists('priority', $input)) {
                if (!in_array($input['priority'], self::PRIORITIES, true)) {
                    throw new HttpException(422, 'Choose a valid priority.');
                }
                if ($input['priority'] !== $task['priority']) {
                    $fields['priority'] = $input['priority'];
                    $logs[] = ['task_updated', 'Priority was updated.'];
                    $notifyType = 'task_updated';
                }
            }
            if (array_key_exists('category', $input)) {
                $category = clean_text($input['category'], 80);
                $category = $category === '' ? null : $category;
                if ($category !== $task['category']) {
                    $fields['category'] = $category;
                    $logs[] = ['task_updated', 'Category was updated.'];
                }
            }
            $start = $task['start_date'];
            $deadline = $task['deadline'];
            if (array_key_exists('start_date', $input)) {
                $start = parse_datetime($input['start_date']);
                if ($start !== $task['start_date']) {
                    $fields['start_date'] = $start;
                    $logs[] = ['task_updated', 'Start date was updated.'];
                }
            }
            if (array_key_exists('deadline', $input)) {
                $deadline = parse_datetime($input['deadline']);
                if ($deadline !== $task['deadline']) {
                    $fields['deadline'] = $deadline;
                    $logs[] = ['deadline_modified', 'Deadline was modified.'];
                    $notifyType = 'deadline_changed';
                }
            }
            if ($start && $deadline && $deadline < $start) {
                throw new HttpException(422, 'The deadline must be on or after the start date.');
            }
            if (array_key_exists('requires_verification', $input)) {
                $flag = !empty($input['requires_verification']) ? 1 : 0;
                if ($flag !== (int) $task['requires_verification']) {
                    $fields['requires_verification'] = $flag;
                    $logs[] = ['task_updated', 'Verification setting was updated.'];
                }
            }
            $assigneeChange = false;
            $newAssignees = null;
            if (array_key_exists('assignee_ids', $input)) {
                $newAssignees = $this->assertAssignees((int) $task['team_id'], $input['assignee_ids']);
                $current = $this->assigneeIds($taskId);
                sort($current);
                $compare = $newAssignees;
                sort($compare);
                $assigneeChange = $current !== $compare;
            }
            if (!$fields && !$assigneeChange) {
                return $this->present($taskId);
            }
            if ($fields) {
                $sets = [];
                $params = [];
                foreach ($fields as $column => $value) {
                    $sets[] = $column . ' = ?';
                    $params[] = $value;
                }
                $sets[] = 'version = version + 1';
                $sets[] = 'updated_at = UTC_TIMESTAMP()';
                $params[] = $taskId;
                $this->app->pdo()->prepare('UPDATE tasks SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
            } else {
                $this->bump($taskId);
            }
            if ($assigneeChange && $newAssignees !== null) {
                $previous = $this->assigneeIds($taskId);
                $this->replaceAssignees($taskId, $newAssignees, (int) $user['id']);
                $this->log($task, $user, 'task_reassigned', 'Task assignees were changed.');
                $added = array_diff($newAssignees, $previous);
                $this->app->get(NotificationService::class)->notifyMany(
                    $added,
                    'task_reassigned',
                    'Task assigned to you',
                    $task['title'],
                    ['task_id' => $taskId, 'team_id' => (int) $task['team_id']],
                    (int) $task['team_id'],
                    'reassigned:' . $taskId . ':' . $task['version']
                );
            }
            $fresh = $this->row($taskId);
            foreach ($logs as [$action, $summary]) {
                $this->log($fresh, $user, $action, $summary);
            }
            if ($notifyType) {
                $title = $notifyType === 'deadline_changed' ? 'Task deadline changed' : 'Task details changed';
                $this->app->get(NotificationService::class)->notifyMany(
                    array_diff($this->assigneeIds($taskId), [(int) $user['id']]),
                    $notifyType,
                    $title,
                    $fresh['title'],
                    ['task_id' => $taskId, 'team_id' => (int) $task['team_id']],
                    (int) $task['team_id']
                );
            }

            return $this->present($taskId);
        });
    }

    public function delete(array $user, int $taskId): void
    {
        $this->app->transaction(function () use ($user, $taskId) {
            $task = $this->lock($taskId);
            $this->guard($task);
            $this->app->get(Access::class)->requireLeader((int) $task['team_id'], (int) $user['id']);
            $this->log($task, $user, 'task_deleted', 'Task was deleted.');
            $this->app->pdo()->prepare(
                'UPDATE tasks SET deleted_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP(), version = version + 1 WHERE id = ?'
            )->execute([$taskId]);
        });
    }

    public function changeStatus(array $actor, int $taskId, string $status, ?int $baseVersion = null, ?string $baseStatus = null, ?string $note = null): array
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new HttpException(422, 'Choose a valid task status.');
        }

        return $this->app->transaction(function () use ($actor, $taskId, $status, $baseVersion, $baseStatus, $note) {
            $task = $this->lock($taskId);
            $this->guard($task);
            $member = $this->app->get(Access::class)->requireActiveMember((int) $task['team_id'], (int) $actor['id']);
            $this->assertMutableTeam((int) $task['team_id']);
            $isLeader = $member['role'] === 'leader';
            $isAssignee = $this->app->get(Access::class)->isAssignee($taskId, (int) $actor['id']);
            if (!$isLeader && !$isAssignee) {
                throw new HttpException(403, 'You are not authorized to perform this action.');
            }
            $this->assertVersion($task, $baseVersion, $baseStatus);
            if ($status === 'done') {
                return $this->complete($actor, $task, $isLeader, $note);
            }
            if ($task['status'] === $status && $task['review_status'] !== 'awaiting') {
                return $this->present($taskId);
            }
            $review = $task['review_status'];
            if ($review === 'awaiting') {
                $review = 'none';
                $this->closeReview($taskId, (int) $actor['id'], 'rejected', 'The status changed before review.');
            }
            $this->app->pdo()->prepare(
                'UPDATE tasks SET status = ?, review_status = ?, completed_at = NULL, version = version + 1, updated_at = UTC_TIMESTAMP() WHERE id = ?'
            )->execute([$status, $review, $taskId]);
            $fresh = $this->row($taskId);
            $this->log($fresh, $actor, 'status_changed', 'Status changed to ' . str_replace('_', ' ', $status) . '.');
            $this->notifyStatus($actor, $fresh, $isLeader);

            return $this->present($taskId);
        });
    }

    public function review(array $actor, int $taskId, array $input): array
    {
        $decision = (string) ($input['decision'] ?? '');
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new HttpException(422, 'Choose approve or reject.');
        }

        return $this->app->transaction(function () use ($actor, $taskId, $input, $decision) {
            $task = $this->lock($taskId);
            $this->guard($task);
            $this->app->get(Access::class)->requireLeader((int) $task['team_id'], (int) $actor['id']);
            if ($task['review_status'] !== 'awaiting') {
                throw new HttpException(422, 'This task is not waiting for review.');
            }
            if ($decision === 'approved') {
                $feedback = $this->shortNote($input['feedback'] ?? null);
                $this->closeReview($taskId, (int) $actor['id'], 'approved', $feedback);
                $this->app->pdo()->prepare(
                    'UPDATE tasks SET status = "done", review_status = "approved", completed_at = UTC_TIMESTAMP(), version = version + 1, updated_at = UTC_TIMESTAMP() WHERE id = ?'
                )->execute([$taskId]);
                $fresh = $this->row($taskId);
                $this->log($fresh, $actor, 'completion_approved', 'Completion was approved.');
                $this->app->get(NotificationService::class)->notifyMany(
                    $this->assigneeIds($taskId),
                    'review_approved',
                    'Task completion approved',
                    $fresh['title'],
                    ['task_id' => $taskId, 'team_id' => (int) $fresh['team_id']],
                    (int) $fresh['team_id']
                );

                return $this->present($taskId);
            }
            $feedback = trim((string) ($input['feedback'] ?? ''));
            if (mb_strlen($feedback) < 1) {
                throw new HttpException(422, 'Add feedback when rejecting a submission.');
            }
            $returnStatus = (string) ($input['return_status'] ?? 'in_progress');
            if (!in_array($returnStatus, ['todo', 'in_progress', 'pending', 'blocked'], true)) {
                throw new HttpException(422, 'Choose a valid status to return the task to.');
            }
            $this->closeReview($taskId, (int) $actor['id'], 'rejected', mb_substr($feedback, 0, 2000));
            $this->app->pdo()->prepare(
                'UPDATE tasks SET status = ?, review_status = "rejected", completed_at = NULL, version = version + 1, updated_at = UTC_TIMESTAMP() WHERE id = ?'
            )->execute([$returnStatus, $taskId]);
            $fresh = $this->row($taskId);
            $this->log($fresh, $actor, 'completion_rejected', 'Completion was rejected.');
            $this->app->get(NotificationService::class)->notifyMany(
                $this->assigneeIds($taskId),
                'review_rejected',
                'Task completion needs another look',
                $fresh['title'],
                ['task_id' => $taskId, 'team_id' => (int) $fresh['team_id']],
                (int) $fresh['team_id']
            );

            return $this->present($taskId);
        });
    }

    public function comments(array $user, int $taskId): array
    {
        $task = $this->row($taskId);
        $this->assertCanView($user, $task);
        $stmt = $this->app->pdo()->prepare(
            'SELECT * FROM task_comments WHERE task_id = ? AND deleted_at IS NULL ORDER BY id ASC'
        );
        $stmt->execute([$taskId]);

        return array_map([Present::class, 'comment'], $stmt->fetchAll());
    }

    public function addComment(array $actor, int $taskId, string $body, string $type = 'comment'): array
    {
        $body = trim($body);
        if (mb_strlen($body) < 1 || mb_strlen($body) > 4000) {
            throw new HttpException(422, 'Comment must be between 1 and 4000 characters.');
        }
        if (!in_array($type, ['comment', 'progress'], true)) {
            $type = 'comment';
        }

        return $this->app->transaction(function () use ($actor, $taskId, $body, $type) {
            $task = $this->lock($taskId);
            $this->guard($task);
            $this->assertWorker($actor, $task);
            $this->assertMutableTeam((int) $task['team_id']);
            $stmt = $this->app->pdo()->prepare(
                'INSERT INTO task_comments (task_id, user_id, author_name, body, comment_type, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $stmt->execute([$taskId, $actor['id'], $actor['full_name'], $body, $type]);
            $commentId = (int) $this->app->pdo()->lastInsertId();
            $this->bump($taskId);
            $fresh = $this->row($taskId);
            $this->log($fresh, $actor, $type === 'progress' ? 'progress_submitted' : 'comment_submitted', $type === 'progress' ? 'A progress update was posted.' : 'A comment was posted.');
            $this->notifyConversation($actor, $fresh, $type);
            $row = $this->app->pdo()->prepare('SELECT * FROM task_comments WHERE id = ?');
            $row->execute([$commentId]);

            return Present::comment($row->fetch());
        });
    }

    public function updateComment(array $actor, int $commentId, string $body): array
    {
        $body = trim($body);
        if (mb_strlen($body) < 1 || mb_strlen($body) > 4000) {
            throw new HttpException(422, 'Comment must be between 1 and 4000 characters.');
        }
        $comment = $this->commentRow($commentId);
        if ((int) $comment['user_id'] !== (int) $actor['id']) {
            throw new HttpException(403, 'You are not authorized to perform this action.');
        }
        $task = $this->row((int) $comment['task_id']);
        $this->assertWorker($actor, $task);
        $this->app->pdo()->prepare(
            'UPDATE task_comments SET body = ?, edited_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?'
        )->execute([$body, $commentId]);
        $this->log($task, $actor, 'comment_edited', 'A comment was edited.');
        $row = $this->commentRow($commentId);

        return Present::comment($row);
    }

    public function deleteComment(array $actor, int $commentId): void
    {
        $comment = $this->commentRow($commentId);
        $task = $this->row((int) $comment['task_id']);
        $member = $this->app->get(Access::class)->requireActiveMember((int) $task['team_id'], (int) $actor['id']);
        $owns = (int) $comment['user_id'] === (int) $actor['id'];
        if (!$owns && $member['role'] !== 'leader') {
            throw new HttpException(403, 'You are not authorized to perform this action.');
        }
        $this->app->pdo()->prepare(
            'UPDATE task_comments SET deleted_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?'
        )->execute([$commentId]);
        $this->log($task, $actor, 'comment_deleted', 'A comment was removed.');
    }

    public function addChecklistItem(array $actor, int $taskId, string $content): array
    {
        $content = clean_text($content, 300);
        if ($content === '') {
            throw new HttpException(422, 'Checklist item text is required.');
        }

        return $this->app->transaction(function () use ($actor, $taskId, $content) {
            $task = $this->lock($taskId);
            $this->guard($task);
            $this->app->get(Access::class)->requireLeader((int) $task['team_id'], (int) $actor['id']);
            $checklistId = $this->checklistId($taskId);
            $countStmt = $this->app->pdo()->prepare('SELECT COUNT(*) FROM task_checklist_items WHERE checklist_id = ?');
            $countStmt->execute([$checklistId]);
            $count = (int) $countStmt->fetchColumn();
            if ($count >= 30) {
                throw new HttpException(422, 'A task can have up to 30 checklist items.');
            }
            $this->app->pdo()->prepare(
                'INSERT INTO task_checklist_items (checklist_id, content, is_done, position, updated_at) VALUES (?, ?, 0, ?, UTC_TIMESTAMP())'
            )->execute([$checklistId, $content, $count]);
            $this->bump($taskId);
            $this->log($this->row($taskId), $actor, 'task_updated', 'A checklist item was added.');

            return $this->present($taskId);
        });
    }

    public function toggleChecklistItem(array $actor, int $itemId, bool $done, ?bool $baseDone = null): array
    {
        return $this->app->transaction(function () use ($actor, $itemId, $done, $baseDone) {
            $stmt = $this->app->pdo()->prepare(
                'SELECT i.*, c.task_id FROM task_checklist_items i
                 JOIN task_checklists c ON c.id = i.checklist_id
                 WHERE i.id = ? FOR UPDATE'
            );
            $stmt->execute([$itemId]);
            $item = $stmt->fetch();
            if (!$item) {
                throw new HttpException(404, 'The requested record was not found.');
            }
            $task = $this->lock((int) $item['task_id']);
            $this->guard($task);
            $this->assertWorker($actor, $task);
            $this->assertMutableTeam((int) $task['team_id']);
            $current = (bool) $item['is_done'];
            if ($current === $done) {
                return $this->present((int) $task['id']);
            }
            if ($baseDone !== null && $current !== $baseDone) {
                throw new HttpException(409, 'This checklist item was updated somewhere else.', [
                    'task' => $this->present((int) $task['id']),
                ]);
            }
            $this->app->pdo()->prepare(
                'UPDATE task_checklist_items
                 SET is_done = ?, completed_by = ?, completed_at = IF(? = 1, UTC_TIMESTAMP(), NULL), updated_at = UTC_TIMESTAMP()
                 WHERE id = ?'
            )->execute([$done ? 1 : 0, $done ? $actor['id'] : null, $done ? 1 : 0, $itemId]);
            $this->bump((int) $task['id']);
            $this->log($this->row((int) $task['id']), $actor, 'checklist_updated', $done ? 'A checklist item was completed.' : 'A checklist item was reopened.');

            return $this->present((int) $task['id']);
        });
    }

    public function deleteChecklistItem(array $actor, int $itemId): array
    {
        return $this->app->transaction(function () use ($actor, $itemId) {
            $stmt = $this->app->pdo()->prepare(
                'SELECT i.id, c.task_id FROM task_checklist_items i JOIN task_checklists c ON c.id = i.checklist_id WHERE i.id = ?'
            );
            $stmt->execute([$itemId]);
            $item = $stmt->fetch();
            if (!$item) {
                throw new HttpException(404, 'The requested record was not found.');
            }
            $task = $this->lock((int) $item['task_id']);
            $this->guard($task);
            $this->app->get(Access::class)->requireLeader((int) $task['team_id'], (int) $actor['id']);
            $this->app->pdo()->prepare('DELETE FROM task_checklist_items WHERE id = ?')->execute([$itemId]);
            $this->bump((int) $task['id']);
            $this->log($this->row((int) $task['id']), $actor, 'task_updated', 'A checklist item was removed.');

            return $this->present((int) $task['id']);
        });
    }

    public function addAttachment(array $actor, int $taskId, array $files): array
    {
        $task = $this->row($taskId);
        $this->guard($task);
        $this->assertWorker($actor, $task);
        $this->assertMutableTeam((int) $task['team_id']);
        $count = $this->app->pdo()->prepare('SELECT COUNT(*) FROM task_attachments WHERE task_id = ?');
        $count->execute([$taskId]);
        if ((int) $count->fetchColumn() >= 5) {
            throw new HttpException(422, 'A task can have up to 5 attachments.');
        }
        $file = $files['file'] ?? null;
        if (!is_array($file)) {
            throw new HttpException(422, 'Choose a file to upload.');
        }
        $stored = $this->app->get(MediaService::class)->storeAttachment($file);
        $this->app->pdo()->prepare(
            'INSERT INTO task_attachments (task_id, uploaded_by, original_name, stored_name, mime_type, size_bytes, created_at)
             VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        )->execute([$taskId, $actor['id'], $stored['original_name'], $stored['stored_name'], $stored['mime_type'], $stored['size_bytes']]);
        $this->log($task, $actor, 'attachment_added', 'An attachment was added.');

        return $this->attachments($taskId);
    }

    public function attachmentForDownload(array $user, int $attachmentId): array
    {
        $stmt = $this->app->pdo()->prepare('SELECT * FROM task_attachments WHERE id = ?');
        $stmt->execute([$attachmentId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new HttpException(404, 'File not found.');
        }
        $task = $this->row((int) $row['task_id']);
        $this->assertCanView($user, $task);
        $row['path'] = $this->app->get(MediaService::class)->path('attachments', $row['stored_name']);

        return $row;
    }

    public function deleteAttachment(array $actor, int $attachmentId): void
    {
        $stmt = $this->app->pdo()->prepare('SELECT * FROM task_attachments WHERE id = ?');
        $stmt->execute([$attachmentId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new HttpException(404, 'File not found.');
        }
        $task = $this->row((int) $row['task_id']);
        $member = $this->app->get(Access::class)->requireActiveMember((int) $task['team_id'], (int) $actor['id']);
        if ($member['role'] !== 'leader' && (int) $row['uploaded_by'] !== (int) $actor['id']) {
            throw new HttpException(403, 'You are not authorized to perform this action.');
        }
        $path = storage_path('uploads/attachments/' . $row['stored_name']);
        if (is_file($path)) {
            unlink($path);
        }
        $this->app->pdo()->prepare('DELETE FROM task_attachments WHERE id = ?')->execute([$attachmentId]);
        $this->log($task, $actor, 'attachment_removed', 'An attachment was removed.');
    }

    public function activity(array $user, int $taskId): array
    {
        $task = $this->row($taskId);
        $this->assertCanView($user, $task);
        $stmt = $this->app->pdo()->prepare(
            'SELECT id, action, summary, actor_id, actor_name, meta, created_at
             FROM task_activity_logs WHERE task_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$taskId]);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $meta = $row['meta'] ? json_decode((string) $row['meta'], true) : null;
            $items[] = [
                'id' => (int) $row['id'],
                'action' => $row['action'],
                'summary' => $row['summary'],
                'actor_id' => $row['actor_id'] !== null ? (int) $row['actor_id'] : null,
                'actor_name' => $row['actor_name'],
                'meta' => is_array($meta) ? $meta : null,
                'created_at' => iso($row['created_at']),
            ];
        }

        return $items;
    }

    public function changedSince(array $user, string $since): array
    {
        $teamIds = $this->app->get(Access::class)->activeTeamIds((int) $user['id']);
        if (!$teamIds) {
            return [];
        }
        $params = array_merge($teamIds, [$since]);
        $stmt = $this->app->pdo()->prepare(
            'SELECT t.* FROM tasks t
             WHERE t.is_template = 0 AND t.team_id IN (' . placeholders(count($teamIds)) . ') AND t.updated_at >= ?
             ORDER BY t.updated_at ASC LIMIT 300'
        );
        $stmt->execute($params);

        return $this->hydrate($stmt->fetchAll());
    }

    private function complete(array $actor, array $task, bool $isLeader, ?string $note): array
    {
        $taskId = (int) $task['id'];
        $link = $this->driveLink($note);
        $finished = $task['status'] === 'done' && $task['review_status'] !== 'awaiting';
        if (!$isLeader && $link === null && empty($task['work_link']) && !$finished) {
            throw new HttpException(422, 'Paste a Google Drive link to mark this done.');
        }
        if ($finished) {
            $this->saveWorkLink($taskId, $link);

            return $this->present($taskId);
        }
        if ($isLeader) {
            if ($task['review_status'] === 'awaiting') {
                $this->closeReview($taskId, (int) $actor['id'], 'approved', $this->shortNote($note));
            }
            $review = ((int) $task['requires_verification'] === 1 || $task['review_status'] === 'awaiting') ? 'approved' : 'none';
            $this->app->pdo()->prepare(
                'UPDATE tasks SET status = "done", review_status = ?, submitted_at = COALESCE(submitted_at, UTC_TIMESTAMP()),
                 completed_at = UTC_TIMESTAMP(), version = version + 1, updated_at = UTC_TIMESTAMP() WHERE id = ?'
            )->execute([$review, $taskId]);
            $this->saveWorkLink($taskId, $link);
            $fresh = $this->row($taskId);
            $this->log($fresh, $actor, 'status_changed', 'Status changed to done.');
            $this->notifyStatus($actor, $fresh, true);

            return $this->present($taskId);
        }
        if ((int) $task['requires_verification'] === 1) {
            if ($task['review_status'] === 'awaiting') {
                return $this->present($taskId);
            }
            $this->app->pdo()->prepare(
                'INSERT INTO task_reviews (task_id, submitted_by, submission_note, decision, previous_status, created_at)
                 VALUES (?, ?, ?, "pending", ?, UTC_TIMESTAMP())'
            )->execute([$taskId, $actor['id'], $this->shortNote($note), $task['status']]);
            $working = $task['status'] === 'todo' ? 'in_progress' : ($task['status'] === 'done' ? 'in_progress' : $task['status']);
            $this->app->pdo()->prepare(
                'UPDATE tasks SET status = ?, review_status = "awaiting", submitted_at = UTC_TIMESTAMP(), version = version + 1, updated_at = UTC_TIMESTAMP() WHERE id = ?'
            )->execute([$working, $taskId]);
            $this->saveWorkLink($taskId, $link);
            $fresh = $this->row($taskId);
            $this->log($fresh, $actor, 'completion_submitted', 'Completion was submitted for review.');
            $leader = $this->leaderId((int) $task['team_id']);
            $this->app->get(NotificationService::class)->notify(
                $leader,
                'review_requested',
                'Task waiting for review',
                $fresh['title'],
                ['task_id' => $taskId, 'team_id' => (int) $task['team_id']],
                (int) $task['team_id'],
                'review:' . $taskId . ':' . $fresh['version']
            );

            return $this->present($taskId);
        }
        $this->app->pdo()->prepare(
            'UPDATE tasks SET status = "done", review_status = "none", completed_at = UTC_TIMESTAMP(), version = version + 1, updated_at = UTC_TIMESTAMP() WHERE id = ?'
        )->execute([$taskId]);
        $this->saveWorkLink($taskId, $link);
        $fresh = $this->row($taskId);
        $this->log($fresh, $actor, 'status_changed', 'Status changed to done.');
        $this->notifyStatus($actor, $fresh, false);

        return $this->present($taskId);
    }

    private function filters(array $user, Request $request): array
    {
        $teamId = (int) ($request->query['team_id'] ?? 0);
        $scope = (string) ($request->query['scope'] ?? 'mine');
        $teamIds = $this->scopedTeams($user, $teamId, $scope);
        if (!$teamIds) {
            return ['1 = 0', []];
        }
        $where = 't.deleted_at IS NULL AND t.is_template = 0 AND t.team_id IN (' . placeholders(count($teamIds)) . ')';
        $params = $teamIds;
        if ($scope !== 'team') {
            $where .= ' AND EXISTS (SELECT 1 FROM task_assignees ta WHERE ta.task_id = t.id AND ta.user_id = ?)';
            $params[] = $user['id'];
        }
        $status = (string) ($request->query['status'] ?? '');
        if ($status === 'awaiting') {
            $where .= ' AND t.review_status = "awaiting"';
        } elseif (in_array($status, self::STATUSES, true)) {
            $where .= ' AND t.status = ?';
            $params[] = $status;
        }
        $priority = (string) ($request->query['priority'] ?? '');
        if (in_array($priority, self::PRIORITIES, true)) {
            $where .= ' AND t.priority = ?';
            $params[] = $priority;
        }
        $q = trim((string) ($request->query['q'] ?? ''));
        if (mb_strlen($q) >= 2) {
            $where .= ' AND (t.title LIKE ? OR t.description LIKE ?)';
            $like = like_term($q);
            $params[] = $like;
            $params[] = $like;
        }
        $due = (string) ($request->query['due'] ?? '');
        if ($due === 'today') {
            [$start, $end] = day_bounds_utc((string) ($user['timezone'] ?: 'UTC'));
            $where .= ' AND t.deadline >= ? AND t.deadline < ? AND t.status <> "done"';
            $params[] = $start;
            $params[] = $end;
        } elseif ($due === 'overdue') {
            $where .= ' AND t.deadline IS NOT NULL AND t.deadline < UTC_TIMESTAMP() AND t.status <> "done"';
        } elseif ($due === 'upcoming') {
            $where .= ' AND t.deadline IS NOT NULL AND t.deadline >= UTC_TIMESTAMP() AND t.status <> "done"';
        }
        $assignee = (int) ($request->query['assignee_id'] ?? 0);
        if ($assignee > 0) {
            $where .= ' AND EXISTS (SELECT 1 FROM task_assignees tax WHERE tax.task_id = t.id AND tax.user_id = ?)';
            $params[] = $assignee;
        }

        return [$where, $params];
    }

    private function scopedTeams(array $user, int $teamId, string $scope): array
    {
        if ($scope === 'team') {
            if ($teamId > 0) {
                $this->app->get(Access::class)->requireLeader($teamId, (int) $user['id']);

                return [$teamId];
            }

            return $this->app->get(Access::class)->leaderTeamIds((int) $user['id']);
        }
        $ids = $this->app->get(Access::class)->activeTeamIds((int) $user['id']);
        if ($teamId > 0) {
            if (!in_array($teamId, $ids, true)) {
                throw new HttpException(404, 'The requested record was not found.');
            }

            return [$teamId];
        }

        return $ids;
    }

    private function validate(array $input, array $team, bool $partial): array
    {
        $title = clean_text($input['title'] ?? '', 160);
        if (mb_strlen($title) < 2) {
            throw new HttpException(422, 'Task title must be at least 2 characters.');
        }
        $description = trim((string) ($input['description'] ?? ''));
        $instructions = trim((string) ($input['instructions'] ?? ''));
        if (mb_strlen($description) > 8000 || mb_strlen($instructions) > 8000) {
            throw new HttpException(422, 'That text is too long.');
        }
        if ($instructions !== '') {
            $instructions = $this->driveLink($instructions, 2000);
        }
        $priority = (string) ($input['priority'] ?? 'medium');
        if (!in_array($priority, self::PRIORITIES, true)) {
            throw new HttpException(422, 'Choose a valid priority.');
        }
        $start = parse_datetime($input['start_date'] ?? null);
        $deadline = parse_datetime($input['deadline'] ?? null);
        if ($start && $deadline && $deadline < $start) {
            throw new HttpException(422, 'The deadline must be on or after the start date.');
        }
        $recurrence = (string) ($input['recurrence'] ?? 'none');
        if (!in_array($recurrence, ['none', 'daily', 'weekly', 'monthly', 'custom'], true)) {
            throw new HttpException(422, 'Choose a valid repeat schedule.');
        }
        $interval = max(1, (int) ($input['recurrence_interval'] ?? 1));
        if ($interval > 365) {
            throw new HttpException(422, 'Repeat interval is too large.');
        }
        $unit = $input['recurrence_unit'] ?? null;
        if ($recurrence === 'daily') {
            $unit = 'day';
        } elseif ($recurrence === 'weekly') {
            $unit = 'week';
        } elseif ($recurrence === 'monthly') {
            $unit = 'month';
        } elseif ($recurrence === 'custom') {
            if (!in_array($unit, ['day', 'week', 'month'], true)) {
                throw new HttpException(422, 'Choose a day, week, or month interval.');
            }
        } else {
            $unit = null;
            $interval = 1;
        }
        $verification = array_key_exists('requires_verification', $input)
            ? (!empty($input['requires_verification']) ? 1 : 0)
            : (int) $team['require_task_verification'];

        return [
            'title' => $title,
            'description' => $description === '' ? null : $description,
            'instructions' => $instructions === '' ? null : $instructions,
            'priority' => $priority,
            'category' => ($cat = clean_text($input['category'] ?? '', 80)) === '' ? null : $cat,
            'start_date' => $start,
            'deadline' => $deadline,
            'requires_verification' => $verification,
            'recurrence' => $recurrence,
            'recurrence_interval' => $interval,
            'recurrence_unit' => $unit,
            'recurrence_until' => parse_date($input['recurrence_until'] ?? null),
        ];
    }

    private function insertTask(int $teamId, int $userId, array $data, bool $template): int
    {
        $stmt = $this->app->pdo()->prepare(
            'INSERT INTO tasks (
                team_id, title, description, instructions, priority, status, review_status, category,
                start_date, deadline, requires_verification, is_template, recurrence, recurrence_interval,
                recurrence_unit, recurrence_until, created_by, version, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, "todo", "none", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $stmt->execute([
            $teamId,
            $data['title'],
            $data['description'],
            $data['instructions'],
            $data['priority'],
            $data['category'],
            $data['start_date'],
            $data['deadline'],
            $data['requires_verification'],
            $template ? 1 : 0,
            $data['recurrence'],
            $data['recurrence_interval'],
            $data['recurrence_unit'],
            $data['recurrence_until'],
            $userId,
        ]);

        return (int) $this->app->pdo()->lastInsertId();
    }

    private function hydrate(array $rows, bool $withItems = true): array
    {
        if (!$rows) {
            return [];
        }
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $in = placeholders(count($ids));
        $assignees = [];
        $stmt = $this->app->pdo()->prepare(
            "SELECT ta.task_id, ta.assigned_at, u.id, u.full_name, u.avatar_path
             FROM task_assignees ta JOIN users u ON u.id = ta.user_id
             WHERE ta.task_id IN ($in) ORDER BY u.full_name"
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $row) {
            $assignees[(int) $row['task_id']][] = [
                'id' => (int) $row['id'],
                'full_name' => $row['full_name'],
                'has_avatar' => !empty($row['avatar_path']),
                'assigned_at' => iso($row['assigned_at']),
            ];
        }
        $items = [];
        if ($withItems) {
            $itemStmt = $this->app->pdo()->prepare(
                "SELECT i.id, i.content, i.is_done, i.position, c.task_id
                 FROM task_checklist_items i
                 JOIN task_checklists c ON c.id = i.checklist_id
                 WHERE c.task_id IN ($in)
                 ORDER BY i.position ASC, i.id ASC"
            );
            $itemStmt->execute($ids);
            foreach ($itemStmt->fetchAll() as $row) {
                $items[(int) $row['task_id']][] = [
                    'id' => (int) $row['id'],
                    'content' => $row['content'],
                    'is_done' => (bool) $row['is_done'],
                    'position' => (int) $row['position'],
                ];
            }
        }
        $comments = $this->countMap('task_comments', 'task_id', $ids, 'deleted_at IS NULL');
        $attachments = $this->countMap('task_attachments', 'task_id', $ids, '1 = 1');
        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $out[] = Present::task(
                $row,
                $assignees[$id] ?? [],
                $items[$id] ?? [],
                $comments[$id] ?? 0,
                $attachments[$id] ?? 0
            );
        }

        return $out;
    }

    private function present(int $taskId): array
    {
        $rows = $this->hydrate([$this->row($taskId)]);

        return $rows[0];
    }

    private function countMap(string $table, string $column, array $ids, string $extra): array
    {
        $stmt = $this->app->pdo()->prepare(
            "SELECT $column AS task_id, COUNT(*) AS total FROM $table WHERE $column IN (" . placeholders(count($ids)) . ") AND $extra GROUP BY $column"
        );
        $stmt->execute($ids);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['task_id']] = (int) $row['total'];
        }

        return $map;
    }

    private function attachments(int $taskId): array
    {
        $stmt = $this->app->pdo()->prepare(
            'SELECT id, original_name, mime_type, size_bytes, created_at FROM task_attachments WHERE task_id = ? ORDER BY id'
        );
        $stmt->execute([$taskId]);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'name' => $row['original_name'],
                'mime_type' => $row['mime_type'],
                'size_bytes' => (int) $row['size_bytes'],
                'created_at' => iso($row['created_at']),
            ];
        }

        return $items;
    }

    private function row(int $taskId): array
    {
        $stmt = $this->app->pdo()->prepare('SELECT * FROM tasks WHERE id = ? LIMIT 1');
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();
        if (!$task) {
            throw new HttpException(404, 'The requested record was not found.');
        }

        return $task;
    }

    private function lock(int $taskId): array
    {
        $stmt = $this->app->pdo()->prepare('SELECT * FROM tasks WHERE id = ? FOR UPDATE');
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();
        if (!$task) {
            throw new HttpException(404, 'The requested record was not found.');
        }

        return $task;
    }

    private function guard(array $task): void
    {
        if ($task['deleted_at'] !== null || (int) $task['is_template'] === 1) {
            throw new HttpException(404, 'The requested record was not found.');
        }
    }

    private function assertCanView(array $user, array $task): void
    {
        $this->guard($task);
        $this->app->get(Access::class)->requireActiveMember((int) $task['team_id'], (int) $user['id']);
    }

    private function assertWorker(array $actor, array $task): void
    {
        $this->guard($task);
        $member = $this->app->get(Access::class)->requireActiveMember((int) $task['team_id'], (int) $actor['id']);
        if ($member['role'] === 'leader') {
            return;
        }
        if (!$this->app->get(Access::class)->isAssignee((int) $task['id'], (int) $actor['id'])) {
            throw new HttpException(403, 'You are not authorized to perform this action.');
        }
    }

    private function assertVersion(array $task, ?int $baseVersion, ?string $baseStatus): void
    {
        if ($baseVersion === null || (int) $task['version'] === $baseVersion) {
            return;
        }
        if ($baseStatus !== null && $task['status'] === $baseStatus) {
            return;
        }
        throw new HttpException(409, 'This task was updated somewhere else.', [
            'task' => $this->present((int) $task['id']),
        ]);
    }

    private function assertMutableTeam(int $teamId): void
    {
        $stmt = $this->app->pdo()->prepare('SELECT status, deleted_at FROM teams WHERE id = ?');
        $stmt->execute([$teamId]);
        $team = $stmt->fetch();
        if (!$team || $team['deleted_at'] !== null || $team['status'] === 'deleted') {
            throw new HttpException(404, 'The requested record was not found.');
        }
        if ($team['status'] !== 'active') {
            throw new HttpException(403, 'This team is archived.');
        }
    }

    private function team(int $teamId): array
    {
        $stmt = $this->app->pdo()->prepare('SELECT * FROM teams WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$teamId]);
        $team = $stmt->fetch();
        if (!$team) {
            throw new HttpException(404, 'The requested record was not found.');
        }

        return $team;
    }

    private function assertAssignees(int $teamId, mixed $ids): array
    {
        if (!is_array($ids) || !$ids) {
            throw new HttpException(422, 'Assign the task to at least one team member.');
        }
        $clean = array_values(array_unique(array_map('intval', $ids)));
        $stmt = $this->app->pdo()->prepare(
            'SELECT user_id FROM team_members WHERE team_id = ? AND status = "active" AND user_id IN (' . placeholders(count($clean)) . ')'
        );
        $stmt->execute(array_merge([$teamId], $clean));
        $found = array_map('intval', array_column($stmt->fetchAll(), 'user_id'));
        sort($found);
        $expected = $clean;
        sort($expected);
        if ($found !== $expected) {
            throw new HttpException(422, 'Tasks can only be assigned to active members of this team.');
        }

        return $expected;
    }

    private function replaceAssignees(int $taskId, array $ids, int $actorId): void
    {
        $this->app->pdo()->prepare('DELETE FROM task_assignees WHERE task_id = ?')->execute([$taskId]);
        $stmt = $this->app->pdo()->prepare(
            'INSERT INTO task_assignees (task_id, user_id, assigned_by, assigned_at) VALUES (?, ?, ?, UTC_TIMESTAMP())'
        );
        foreach ($ids as $id) {
            $stmt->execute([$taskId, $id, $actorId]);
        }
    }

    private function assigneeIds(int $taskId): array
    {
        $stmt = $this->app->pdo()->prepare('SELECT user_id FROM task_assignees WHERE task_id = ?');
        $stmt->execute([$taskId]);

        return array_map('intval', array_column($stmt->fetchAll(), 'user_id'));
    }

    private function cleanChecklist(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            $text = clean_text(is_array($item) ? ($item['content'] ?? '') : $item, 300);
            if ($text !== '') {
                $out[] = $text;
            }
            if (count($out) >= 30) {
                break;
            }
        }

        return $out;
    }

    private function insertChecklist(int $taskId, array $items): void
    {
        if (!$items) {
            return;
        }
        $this->app->pdo()->prepare('INSERT INTO task_checklists (task_id, title, position) VALUES (?, "Checklist", 0)')
            ->execute([$taskId]);
        $checklistId = (int) $this->app->pdo()->lastInsertId();
        $stmt = $this->app->pdo()->prepare(
            'INSERT INTO task_checklist_items (checklist_id, content, is_done, position, updated_at) VALUES (?, ?, 0, ?, UTC_TIMESTAMP())'
        );
        foreach ($items as $position => $content) {
            $stmt->execute([$checklistId, $content, $position]);
        }
    }

    private function checklistId(int $taskId): int
    {
        $stmt = $this->app->pdo()->prepare('SELECT id FROM task_checklists WHERE task_id = ? ORDER BY id ASC LIMIT 1');
        $stmt->execute([$taskId]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int) $id;
        }
        $this->app->pdo()->prepare('INSERT INTO task_checklists (task_id, title, position) VALUES (?, "Checklist", 0)')
            ->execute([$taskId]);

        return (int) $this->app->pdo()->lastInsertId();
    }

    private function bump(int $taskId): void
    {
        $this->app->pdo()->prepare('UPDATE tasks SET version = version + 1, updated_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([$taskId]);
    }

    private function log(array $task, array $actor, string $action, string $summary): void
    {
        $this->app->pdo()->prepare(
            'INSERT INTO task_activity_logs (task_id, team_id, actor_id, actor_name, action, summary, created_at)
             VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        )->execute([
            $task['id'],
            $task['team_id'],
            $actor['id'],
            $actor['full_name'],
            $action,
            mb_substr($summary, 0, 255),
        ]);
    }

    private function closeReview(int $taskId, int $reviewerId, string $decision, ?string $feedback): void
    {
        $this->app->pdo()->prepare(
            'UPDATE task_reviews SET decision = ?, feedback = ?, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP()
             WHERE task_id = ? AND decision = "pending"'
        )->execute([$decision, $feedback, $reviewerId, $taskId]);
    }

    private function notifyStatus(array $actor, array $task, bool $actorIsLeader): void
    {
        $label = str_replace('_', ' ', $task['status']);
        if ($task['review_status'] === 'awaiting') {
            $label = 'awaiting review';
        }
        $body = $task['title'];
        $title = 'Task status is now ' . $label;
        $data = ['task_id' => (int) $task['id'], 'team_id' => (int) $task['team_id'], 'status' => $task['status']];
        if ($actorIsLeader) {
            $this->app->get(NotificationService::class)->notifyMany(
                array_diff($this->assigneeIds((int) $task['id']), [(int) $actor['id']]),
                'status_changed',
                $title,
                $body,
                $data,
                (int) $task['team_id']
            );
            return;
        }
        $this->app->get(NotificationService::class)->notify(
            $this->leaderId((int) $task['team_id']),
            'status_changed',
            $title,
            $body,
            $data,
            (int) $task['team_id']
        );
    }

    private function notifyConversation(array $actor, array $task, string $type): void
    {
        $member = $this->app->get(Access::class)->membership((int) $task['team_id'], (int) $actor['id']);
        $title = $type === 'progress' ? 'New progress update' : 'New task comment';
        $data = ['task_id' => (int) $task['id'], 'team_id' => (int) $task['team_id']];
        if (($member['role'] ?? '') === 'leader') {
            $recipients = array_diff($this->assigneeIds((int) $task['id']), [(int) $actor['id']]);
        } else {
            $recipients = [$this->leaderId((int) $task['team_id'])];
        }
        $this->app->get(NotificationService::class)->notifyMany(
            $recipients,
            $type === 'progress' ? 'progress' : 'comment',
            $title,
            $task['title'],
            $data,
            (int) $task['team_id']
        );
    }

    private function leaderId(int $teamId): int
    {
        $stmt = $this->app->pdo()->prepare('SELECT leader_id FROM teams WHERE id = ?');
        $stmt->execute([$teamId]);

        return (int) $stmt->fetchColumn();
    }

    private function commentRow(int $id): array
    {
        $stmt = $this->app->pdo()->prepare('SELECT * FROM task_comments WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new HttpException(404, 'The requested record was not found.');
        }

        return $row;
    }

    private function driveLink(?string $note, int $max = 500): ?string
    {
        $note = trim((string) $note);
        if ($note === '') {
            return null;
        }
        if (strlen($note) > $max || !filter_var($note, FILTER_VALIDATE_URL)) {
            throw new HttpException(422, 'Paste a Google Drive link.');
        }
        $host = strtolower((string) parse_url($note, PHP_URL_HOST));
        if (!in_array($host, ['drive.google.com', 'docs.google.com', 'drive.usercontent.google.com'], true)) {
            throw new HttpException(422, 'Paste a Google Drive link.');
        }

        return $note;
    }

    private function saveWorkLink(int $taskId, ?string $link): void
    {
        if ($link === null) {
            return;
        }
        $this->app->pdo()->prepare('UPDATE tasks SET work_link = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$link, $taskId]);
    }

    private function shortNote(mixed $note): ?string
    {
        $text = trim((string) $note);

        return $text === '' ? null : mb_substr($text, 0, 2000);
    }
}
