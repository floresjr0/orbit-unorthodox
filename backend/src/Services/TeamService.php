<?php

declare(strict_types=1);

namespace Orbit;

final class TeamService
{
    public function __construct(private readonly App $app)
    {
    }

    public function listFor(array $user): array
    {
        $stmt = $this->app->pdo()->prepare(
            'SELECT t.*, tm.role, tm.status AS membership_status,
                (SELECT COUNT(*) FROM team_members m WHERE m.team_id = t.id AND m.status = "active") AS member_count
             FROM team_members tm
             JOIN teams t ON t.id = tm.team_id
             WHERE tm.user_id = ? AND tm.status IN ("active", "pending") AND t.deleted_at IS NULL
             ORDER BY t.name ASC'
        );
        $stmt->execute([$user['id']]);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $invite = $row['role'] === 'leader' ? $this->activeInvite((int) $row['id']) : null;
            $items[] = Present::team($row, $invite);
        }

        return $items;
    }

    public function create(array $user, array $input): array
    {
        $name = clean_text($input['name'] ?? '', 120);
        if (mb_strlen($name) < 2) {
            throw new HttpException(422, 'Team name must be at least 2 characters.');
        }
        $description = $this->nullableText($input['description'] ?? null, 2000);
        $category = $this->nullableText($input['category'] ?? null, 80);
        $approval = !empty($input['require_join_approval']) ? 1 : 0;
        $verification = !empty($input['require_task_verification']) ? 1 : 0;

        return $this->app->transaction(function () use ($user, $name, $description, $category, $approval, $verification) {
            $stmt = $this->app->pdo()->prepare(
                'INSERT INTO teams (name, description, category, leader_id, require_join_approval, require_task_verification, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, "active", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $stmt->execute([$name, $description, $category, $user['id'], $approval, $verification]);
            $teamId = (int) $this->app->pdo()->lastInsertId();
            $this->app->pdo()->prepare(
                'INSERT INTO team_members (team_id, user_id, role, status, joined_at, created_at, updated_at)
                 VALUES (?, ?, "leader", "active", UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            )->execute([$teamId, $user['id']]);
            $this->insertInvite($teamId, (int) $user['id'], null, null);
            $this->app->get(AuditLog::class)->write('team', $teamId, (int) $user['id'], 'team.created', 'Team was created.', 'team', (string) $teamId);

            return $this->present($teamId, (int) $user['id']);
        });
    }

    public function get(array $user, int $teamId): array
    {
        $this->visibleMembership($teamId, (int) $user['id']);

        return $this->present($teamId, (int) $user['id']);
    }

    public function update(array $user, int $teamId, array $input): array
    {
        $this->app->get(Access::class)->requireLeader($teamId, (int) $user['id']);
        $team = $this->requireTeam($teamId);
        $name = clean_text($input['name'] ?? $team['name'], 120);
        if (mb_strlen($name) < 2) {
            throw new HttpException(422, 'Team name must be at least 2 characters.');
        }
        $description = array_key_exists('description', $input) ? $this->nullableText($input['description'], 2000) : $team['description'];
        $category = array_key_exists('category', $input) ? $this->nullableText($input['category'], 80) : $team['category'];
        $approval = array_key_exists('require_join_approval', $input) ? (!empty($input['require_join_approval']) ? 1 : 0) : (int) $team['require_join_approval'];
        $verification = array_key_exists('require_task_verification', $input) ? (!empty($input['require_task_verification']) ? 1 : 0) : (int) $team['require_task_verification'];
        $this->app->pdo()->prepare(
            'UPDATE teams SET name = ?, description = ?, category = ?, require_join_approval = ?, require_task_verification = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?'
        )->execute([$name, $description, $category, $approval, $verification, $teamId]);
        $this->app->get(AuditLog::class)->write('team', $teamId, (int) $user['id'], 'team.updated', 'Team details were updated.', 'team', (string) $teamId);

        return $this->present($teamId, (int) $user['id']);
    }

    public function setAvatar(array $user, int $teamId, array $files): array
    {
        $this->app->get(Access::class)->requireLeader($teamId, (int) $user['id']);
        $team = $this->requireTeam($teamId);
        $file = $files['avatar'] ?? null;
        if (!is_array($file)) {
            throw new HttpException(422, 'Choose an image to upload.');
        }
        $name = $this->app->get(MediaService::class)->storeImage($file, 'teams');
        if (!empty($team['avatar_path'])) {
            $old = storage_path('uploads/teams/' . $team['avatar_path']);
            if (is_file($old)) {
                unlink($old);
            }
        }
        $this->app->pdo()->prepare('UPDATE teams SET avatar_path = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$name, $teamId]);

        return $this->present($teamId, (int) $user['id']);
    }

    public function archive(array $user, int $teamId, bool $restore): array
    {
        $this->app->get(Access::class)->requireLeader($teamId, (int) $user['id']);
        $team = $this->requireTeam($teamId);
        $status = $restore ? 'active' : 'archived';
        if (!$restore && $team['status'] === 'archived') {
            return $this->present($teamId, (int) $user['id']);
        }
        $this->app->pdo()->prepare('UPDATE teams SET status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$status, $teamId]);
        $this->app->get(AuditLog::class)->write('team', $teamId, (int) $user['id'], $restore ? 'team.restored' : 'team.archived', $restore ? 'Team was restored.' : 'Team was archived.', 'team', (string) $teamId);

        return $this->present($teamId, (int) $user['id']);
    }

    public function delete(array $user, int $teamId): void
    {
        $this->app->get(Access::class)->requireLeader($teamId, (int) $user['id']);
        $this->requireTeam($teamId);
        $this->app->pdo()->prepare(
            'UPDATE teams SET status = "deleted", deleted_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?'
        )->execute([$teamId]);
        $this->app->get(AuditLog::class)->write('platform', null, (int) $user['id'], 'team.deleted', 'A team was deleted.', 'team', (string) $teamId);
    }

    public function transfer(array $user, int $teamId, int $newLeaderId): array
    {
        return $this->app->transaction(function () use ($user, $teamId, $newLeaderId) {
            $this->app->get(Access::class)->requireLeader($teamId, (int) $user['id']);
            if ($newLeaderId === (int) $user['id']) {
                throw new HttpException(422, 'Choose another member to lead this team.');
            }
            $member = $this->app->get(Access::class)->membership($teamId, $newLeaderId);
            if (!$member || $member['status'] !== 'active') {
                throw new HttpException(422, 'That person is not an active member of this team.');
            }
            $this->app->pdo()->prepare(
                'UPDATE team_members SET role = "member", updated_at = UTC_TIMESTAMP() WHERE team_id = ? AND user_id = ?'
            )->execute([$teamId, $user['id']]);
            $this->app->pdo()->prepare(
                'UPDATE team_members SET role = "leader", updated_at = UTC_TIMESTAMP() WHERE team_id = ? AND user_id = ?'
            )->execute([$teamId, $newLeaderId]);
            $this->app->pdo()->prepare('UPDATE teams SET leader_id = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')
                ->execute([$newLeaderId, $teamId]);
            $this->app->get(NotificationService::class)->notify(
                $newLeaderId,
                'leadership_transferred',
                'You are now the team leader',
                'Leadership was transferred to you.',
                ['team_id' => $teamId],
                $teamId
            );
            $this->app->get(AuditLog::class)->write('team', $teamId, (int) $user['id'], 'team.transferred', 'Leadership was transferred.', 'user', (string) $newLeaderId);

            return $this->present($teamId, (int) $user['id']);
        });
    }

    public function leave(array $user, int $teamId): void
    {
        $member = $this->app->get(Access::class)->requireActiveMember($teamId, (int) $user['id']);
        if ($member['role'] === 'leader') {
            throw new HttpException(422, 'Transfer leadership before leaving this team.');
        }
        $this->app->pdo()->prepare(
            'UPDATE team_members SET status = "left", updated_at = UTC_TIMESTAMP() WHERE team_id = ? AND user_id = ?'
        )->execute([$teamId, $user['id']]);
        $team = $this->requireTeam($teamId);
        $this->app->get(NotificationService::class)->notify(
            (int) $team['leader_id'],
            'member_left',
            'A member left the team',
            $user['full_name'] . ' left the team.',
            ['team_id' => $teamId],
            $teamId
        );
        $this->app->get(AuditLog::class)->write('team', $teamId, (int) $user['id'], 'team.left', 'A member left the team.', 'user', (string) $user['id']);
    }

    public function members(array $user, int $teamId): array
    {
        $viewer = $this->app->get(Access::class)->requireActiveMember($teamId, (int) $user['id']);
        $stmt = $this->app->pdo()->prepare(
            'SELECT tm.id, tm.user_id, tm.role, tm.status, tm.joined_at, u.full_name, u.email, u.avatar_path,
                (SELECT COUNT(*) FROM task_assignees ta
                    JOIN tasks t ON t.id = ta.task_id
                    WHERE ta.user_id = tm.user_id AND t.team_id = tm.team_id AND t.deleted_at IS NULL AND t.is_template = 0) AS assigned_count,
                (SELECT COUNT(*) FROM task_assignees ta
                    JOIN tasks t ON t.id = ta.task_id
                    WHERE ta.user_id = tm.user_id AND t.team_id = tm.team_id AND t.deleted_at IS NULL AND t.is_template = 0 AND t.status = "done") AS completed_count,
                (SELECT l.summary FROM task_activity_logs l
                    WHERE l.team_id = tm.team_id AND l.actor_id = tm.user_id
                    ORDER BY l.id DESC LIMIT 1) AS current_activity
             FROM team_members tm
             JOIN users u ON u.id = tm.user_id
             WHERE tm.team_id = ? AND tm.status IN ("active", "pending")
             ORDER BY tm.role DESC, u.full_name ASC'
        );
        $stmt->execute([$teamId]);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $item = [
                'membership_id' => (int) $row['id'],
                'id' => (int) $row['user_id'],
                'full_name' => $row['full_name'],
                'has_avatar' => !empty($row['avatar_path']),
                'role' => $row['role'],
                'status' => $row['status'],
                'joined_at' => iso($row['joined_at']),
                'assigned_count' => (int) $row['assigned_count'],
                'completed_count' => (int) $row['completed_count'],
                'current_activity' => $row['current_activity'],
            ];
            if ($viewer['role'] === 'leader') {
                $item['email'] = $row['email'];
            }
            $items[] = $item;
        }

        return $items;
    }

    public function removeMember(array $user, int $teamId, int $memberId): void
    {
        $this->app->get(Access::class)->requireLeader($teamId, (int) $user['id']);
        if ($memberId === (int) $user['id']) {
            throw new HttpException(422, 'Transfer leadership before removing yourself.');
        }
        $member = $this->app->get(Access::class)->membership($teamId, $memberId);
        if (!$member || !in_array($member['status'], ['active', 'pending'], true)) {
            throw new HttpException(404, 'That member was not found.');
        }
        $this->app->pdo()->prepare(
            'UPDATE team_members SET status = "removed", updated_at = UTC_TIMESTAMP() WHERE team_id = ? AND user_id = ?'
        )->execute([$teamId, $memberId]);
        $this->app->get(NotificationService::class)->notify($memberId, 'member_left', 'Removed from a team', 'A team leader removed you from the team.', ['team_id' => $teamId], $teamId);
        $this->app->get(AuditLog::class)->write('team', $teamId, (int) $user['id'], 'member.removed', 'A member was removed.', 'user', (string) $memberId);
    }

    public function decideJoin(array $user, int $teamId, int $memberId, bool $approve): void
    {
        $this->app->get(Access::class)->requireLeader($teamId, (int) $user['id']);
        $member = $this->app->get(Access::class)->membership($teamId, $memberId);
        if (!$member || $member['status'] !== 'pending') {
            throw new HttpException(404, 'There is no pending request for that person.');
        }
        if ($approve) {
            $this->app->pdo()->prepare(
                'UPDATE team_members SET status = "active", joined_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?'
            )->execute([$member['id']]);
            $this->app->get(NotificationService::class)->notify($memberId, 'member_joined', 'You joined the team', 'Your request to join was approved.', ['team_id' => $teamId], $teamId);
        } else {
            $this->app->pdo()->prepare('UPDATE team_members SET status = "removed", updated_at = UTC_TIMESTAMP() WHERE id = ?')
                ->execute([$member['id']]);
            $this->app->get(NotificationService::class)->notify($memberId, 'member_left', 'Join request declined', 'A team leader declined the request to join.', ['team_id' => $teamId], $teamId);
        }
        $this->app->get(AuditLog::class)->write('team', $teamId, (int) $user['id'], $approve ? 'member.approved' : 'member.rejected', $approve ? 'A join request was approved.' : 'A join request was declined.', 'user', (string) $memberId);
    }

    public function invitation(array $user, int $teamId): array
    {
        $this->app->get(Access::class)->requireLeader($teamId, (int) $user['id']);
        $invite = $this->activeInvite($teamId);
        if (!$invite) {
            throw new HttpException(404, 'This team has no invitation.');
        }

        return Present::team($this->teamRow($teamId, (int) $user['id']), $invite)['invitation'];
    }

    public function updateInvitation(array $user, int $teamId, array $input): array
    {
        $this->app->get(Access::class)->requireLeader($teamId, (int) $user['id']);
        $invite = $this->activeInvite($teamId);
        if (!$invite) {
            throw new HttpException(404, 'This team has no invitation.');
        }
        $active = array_key_exists('is_active', $input) ? (!empty($input['is_active']) ? 1 : 0) : (int) $invite['is_active'];
        $expires = array_key_exists('expires_at', $input) ? parse_datetime($input['expires_at']) : $invite['expires_at'];
        $max = $invite['max_uses'];
        if (array_key_exists('max_uses', $input)) {
            if ($input['max_uses'] === null || $input['max_uses'] === '') {
                $max = null;
            } else {
                $max = (int) $input['max_uses'];
                if ($max < 1 || $max > 10000) {
                    throw new HttpException(422, 'Maximum uses must be between 1 and 10000, or empty for unlimited.');
                }
            }
        }
        $this->app->pdo()->prepare(
            'UPDATE team_invitations SET is_active = ?, expires_at = ?, max_uses = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?'
        )->execute([$active, $expires, $max, $invite['id']]);

        return $this->invitation($user, $teamId);
    }

    public function regenerate(array $user, int $teamId): array
    {
        $this->app->get(Access::class)->requireLeader($teamId, (int) $user['id']);
        $this->app->get(RateLimiter::class)->hit('invite-regen:' . $user['id'], 20, 3600);
        $current = $this->activeInvite($teamId);
        $this->app->transaction(function () use ($user, $teamId, $current) {
            $expires = is_array($current) ? ($current['expires_at'] ?? null) : null;
            $maxUses = is_array($current) && $current['max_uses'] !== null ? (int) $current['max_uses'] : null;
            if ($current) {
                $this->app->pdo()->prepare('UPDATE team_invitations SET is_active = 0, updated_at = UTC_TIMESTAMP() WHERE id = ?')
                    ->execute([$current['id']]);
            }
            $this->insertInvite($teamId, (int) $user['id'], $expires, $maxUses);
            $this->app->get(AuditLog::class)->write('team', $teamId, (int) $user['id'], 'invite.regenerated', 'The invitation code was regenerated.', 'team', (string) $teamId);
        });

        return $this->invitation($user, $teamId);
    }

    public function join(array $user, string $rawCode): array
    {
        $this->app->get(RateLimiter::class)->hit('join:' . client_ip($this->app->config), 30, 3600);
        $this->app->get(RateLimiter::class)->hit('join-user:' . $user['id'], 20, 3600);
        $code = Present::normalizeCode($rawCode);
        if (strlen($code) < 8) {
            throw new HttpException(422, 'That invitation code is not valid.');
        }

        return $this->app->transaction(function () use ($user, $code) {
            $stmt = $this->app->pdo()->prepare('SELECT * FROM team_invitations WHERE code = ? FOR UPDATE');
            $stmt->execute([$code]);
            $invite = $stmt->fetch();
            if (!$this->inviteUsable($invite)) {
                throw new HttpException(422, 'That invitation code is not valid.');
            }
            $team = $this->requireTeam((int) $invite['team_id']);
            if ($team['status'] !== 'active') {
                throw new HttpException(422, 'That invitation code is not valid.');
            }
            $existing = $this->app->get(Access::class)->membership((int) $team['id'], (int) $user['id']);
            if ($existing && $existing['status'] === 'active') {
                throw new HttpException(422, 'You are already a member of this team.');
            }
            if ($existing && $existing['status'] === 'pending') {
                throw new HttpException(422, 'Your request to join this team is waiting for approval.');
            }
            $status = !empty($team['require_join_approval']) ? 'pending' : 'active';
            if ($existing) {
                $this->app->pdo()->prepare(
                    'UPDATE team_members SET role = "member", status = ?, joined_at = IF(? = "active", UTC_TIMESTAMP(), NULL), updated_at = UTC_TIMESTAMP()
                     WHERE id = ?'
                )->execute([$status, $status, $existing['id']]);
            } else {
                $this->app->pdo()->prepare(
                    'INSERT INTO team_members (team_id, user_id, role, status, joined_at, created_at, updated_at)
                     VALUES (?, ?, "member", ?, IF(? = "active", UTC_TIMESTAMP(), NULL), UTC_TIMESTAMP(), UTC_TIMESTAMP())'
                )->execute([$team['id'], $user['id'], $status, $status]);
            }
            $this->app->pdo()->prepare('UPDATE team_invitations SET use_count = use_count + 1, updated_at = UTC_TIMESTAMP() WHERE id = ?')
                ->execute([$invite['id']]);
            $type = $status === 'pending' ? 'member_requested' : 'member_joined';
            $title = $status === 'pending' ? 'A person wants to join' : 'A member joined the team';
            $this->app->get(NotificationService::class)->notify(
                (int) $team['leader_id'],
                $type,
                $title,
                $user['full_name'] . ($status === 'pending' ? ' requested to join.' : ' joined the team.'),
                ['team_id' => (int) $team['id'], 'user_id' => (int) $user['id']],
                (int) $team['id']
            );
            $this->app->get(AuditLog::class)->write('team', (int) $team['id'], (int) $user['id'], $status === 'pending' ? 'member.requested' : 'member.joined', $status === 'pending' ? 'A join request was submitted.' : 'A member joined the team.', 'user', (string) $user['id']);

            return $this->present((int) $team['id'], (int) $user['id']);
        });
    }

    public function messages(array $user, int $teamId): array
    {
        $this->app->get(Access::class)->requireActiveMember($teamId, (int) $user['id']);
        $stmt = $this->app->pdo()->prepare(
            'SELECT * FROM (
                SELECT * FROM team_messages WHERE team_id = ? ORDER BY id DESC LIMIT 100
             ) recent ORDER BY id ASC'
        );
        $stmt->execute([$teamId]);

        return array_map([Present::class, 'message'], $stmt->fetchAll());
    }

    public function postMessage(array $user, int $teamId, string $body): array
    {
        $this->app->get(Access::class)->requireActiveMember($teamId, (int) $user['id']);
        $this->assertMutable($teamId);
        $body = trim($body);
        if (mb_strlen($body) < 1 || mb_strlen($body) > 2000) {
            throw new HttpException(422, 'A message must be between 1 and 2000 characters.');
        }
        $stmt = $this->app->pdo()->prepare(
            'INSERT INTO team_messages (team_id, user_id, author_name, body, created_at)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP())'
        );
        $stmt->execute([$teamId, $user['id'], $user['full_name'], $body]);
        $id = (int) $this->app->pdo()->lastInsertId();
        $mentioned = $this->mentionIds($teamId, $body, (int) $user['id']);
        if ($mentioned) {
            $this->app->get(NotificationService::class)->notifyMany(
                $mentioned,
                'mention',
                $user['full_name'] . ' mentioned you',
                mb_substr($body, 0, 140),
                ['team_id' => $teamId, 'message_id' => $id],
                $teamId
            );
        }
        $row = $this->app->pdo()->prepare('SELECT * FROM team_messages WHERE id = ?');
        $row->execute([$id]);

        return Present::message($row->fetch());
    }

    public function mentionIds(int $teamId, string $body, int $except): array
    {
        $stmt = $this->app->pdo()->prepare(
            'SELECT tm.user_id, u.full_name
             FROM team_members tm JOIN users u ON u.id = tm.user_id
             WHERE tm.team_id = ? AND tm.status = "active"'
        );
        $stmt->execute([$teamId]);
        $members = $stmt->fetchAll();
        usort($members, static fn (array $a, array $b): int => mb_strlen((string) $b['full_name']) <=> mb_strlen((string) $a['full_name']));
        $lower = mb_strtolower($body);
        $taken = [];
        $ids = [];
        foreach ($members as $member) {
            $name = trim((string) $member['full_name']);
            $userId = (int) $member['user_id'];
            if ($name === '' || $userId === $except) {
                continue;
            }
            $needle = '@' . mb_strtolower($name);
            $offset = 0;
            $hit = false;
            while (($pos = mb_strpos($lower, $needle, $offset)) !== false) {
                $end = $pos + mb_strlen($needle);
                $after = mb_substr($lower, $end, 1);
                $free = true;
                for ($i = $pos; $i < $end; $i++) {
                    if (!empty($taken[$i])) {
                        $free = false;
                    }
                }
                $boundary = $after === '' || !preg_match('/[\p{L}\p{N}]/u', $after);
                if ($free && $boundary) {
                    for ($i = $pos; $i < $end; $i++) {
                        $taken[$i] = true;
                    }
                    $hit = true;
                }
                $offset = $pos + 1;
            }
            if ($hit) {
                $ids[] = $userId;
            }
        }

        return $ids;
    }

    public function announcements(array $user, int $teamId): array
    {
        $this->app->get(Access::class)->requireActiveMember($teamId, (int) $user['id']);
        $stmt = $this->app->pdo()->prepare(
            'SELECT * FROM announcements WHERE team_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 50'
        );
        $stmt->execute([$teamId]);

        return array_map([Present::class, 'announcement'], $stmt->fetchAll());
    }

    public function createAnnouncement(array $user, int $teamId, array $input): array
    {
        $this->app->get(Access::class)->requireLeader($teamId, (int) $user['id']);
        $this->assertMutable($teamId);
        $title = clean_text($input['title'] ?? '', 160);
        $body = trim((string) ($input['body'] ?? ''));
        if (mb_strlen($title) < 2 || mb_strlen($body) < 1 || mb_strlen($body) > 4000) {
            throw new HttpException(422, 'An announcement needs a title and a message.');
        }
        $important = !empty($input['is_important']) ? 1 : 0;
        $stmt = $this->app->pdo()->prepare(
            'INSERT INTO announcements (team_id, author_id, author_name, title, body, is_important, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $stmt->execute([$teamId, $user['id'], $user['full_name'], $title, $body, $important]);
        $id = (int) $this->app->pdo()->lastInsertId();
        $recipients = $this->activeMemberIds($teamId, (int) $user['id']);
        $this->app->get(NotificationService::class)->notifyMany(
            $recipients,
            'announcement',
            $important ? 'Important team announcement' : 'New team announcement',
            $title,
            ['team_id' => $teamId, 'announcement_id' => $id],
            $teamId,
            'announcement:' . $id
        );
        $row = $this->app->pdo()->prepare('SELECT * FROM announcements WHERE id = ?');
        $row->execute([$id]);

        return Present::announcement($row->fetch());
    }

    public function deleteAnnouncement(array $user, int $announcementId): void
    {
        $row = $this->announcementRow($announcementId);
        $this->app->get(Access::class)->requireLeader((int) $row['team_id'], (int) $user['id']);
        $this->app->pdo()->prepare('UPDATE announcements SET deleted_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([$announcementId]);
    }

    public function teamIdsFor(int $userId): array
    {
        return $this->app->get(Access::class)->activeTeamIds($userId);
    }

    private function present(int $teamId, int $userId): array
    {
        $row = $this->teamRow($teamId, $userId);
        $invite = ($row['role'] ?? '') === 'leader' ? $this->activeInvite($teamId) : null;

        return Present::team($row, $invite);
    }

    private function teamRow(int $teamId, int $userId): array
    {
        $stmt = $this->app->pdo()->prepare(
            'SELECT t.*, tm.role, tm.status AS membership_status,
                (SELECT COUNT(*) FROM team_members m WHERE m.team_id = t.id AND m.status = "active") AS member_count
             FROM teams t
             JOIN team_members tm ON tm.team_id = t.id AND tm.user_id = ?
             WHERE t.id = ? AND t.deleted_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([$userId, $teamId]);
        $row = $stmt->fetch();
        if (!$row || !in_array($row['membership_status'], ['active', 'pending'], true)) {
            throw new HttpException(404, 'The requested record was not found.');
        }

        return $row;
    }

    private function visibleMembership(int $teamId, int $userId): array
    {
        $member = $this->app->get(Access::class)->membership($teamId, $userId);
        if (!$member || !in_array($member['status'], ['active', 'pending'], true) || $member['team_deleted'] !== null || $member['team_status'] === 'deleted') {
            throw new HttpException(404, 'The requested record was not found.');
        }

        return $member;
    }

    private function requireTeam(int $teamId): array
    {
        $stmt = $this->app->pdo()->prepare('SELECT * FROM teams WHERE id = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$teamId]);
        $team = $stmt->fetch();
        if (!$team || $team['status'] === 'deleted') {
            throw new HttpException(404, 'The requested record was not found.');
        }

        return $team;
    }

    private function assertMutable(int $teamId): void
    {
        $team = $this->requireTeam($teamId);
        if ($team['status'] !== 'active') {
            throw new HttpException(403, 'This team is archived.');
        }
    }

    private function activeInvite(int $teamId): ?array
    {
        $stmt = $this->app->pdo()->prepare(
            'SELECT * FROM team_invitations WHERE team_id = ? AND is_active = 1 ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$teamId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function insertInvite(int $teamId, int $userId, ?string $expires, ?int $maxUses): void
    {
        $code = $this->generateCode();
        $this->app->pdo()->prepare(
            'INSERT INTO team_invitations (team_id, code, created_by, expires_at, max_uses, use_count, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 0, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        )->execute([$teamId, $code, $userId, $expires, $maxUses]);
    }

    private function generateCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max = strlen($alphabet) - 1;
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $code = '';
            for ($i = 0; $i < 12; $i++) {
                $code .= $alphabet[random_int(0, $max)];
            }
            $stmt = $this->app->pdo()->prepare('SELECT id FROM team_invitations WHERE code = ?');
            $stmt->execute([$code]);
            if (!$stmt->fetch()) {
                return $code;
            }
        }
        throw new HttpException(500, 'Could not generate an invitation code.');
    }

    private function inviteUsable(?array $invite): bool
    {
        if (!$invite || !(int) $invite['is_active']) {
            return false;
        }
        if ($invite['expires_at'] !== null && strtotime($invite['expires_at'] . ' UTC') < time()) {
            return false;
        }
        if ($invite['max_uses'] !== null && (int) $invite['use_count'] >= (int) $invite['max_uses']) {
            return false;
        }

        return true;
    }

    private function activeMemberIds(int $teamId, int $except): array
    {
        $stmt = $this->app->pdo()->prepare(
            'SELECT user_id FROM team_members WHERE team_id = ? AND status = "active" AND user_id <> ?'
        );
        $stmt->execute([$teamId, $except]);

        return array_map('intval', array_column($stmt->fetchAll(), 'user_id'));
    }

    private function nullableText(mixed $value, int $max): ?string
    {
        $text = clean_text($value, $max);

        return $text === '' ? null : $text;
    }

    private function announcementRow(int $id): array
    {
        $stmt = $this->app->pdo()->prepare('SELECT * FROM announcements WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new HttpException(404, 'The requested record was not found.');
        }

        return $row;
    }
}
