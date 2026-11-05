<?php

declare(strict_types=1);

namespace Orbit;

final class UserService
{
    public function __construct(private readonly App $app)
    {
    }

    public function update(array $user, array $input): array
    {
        $name = clean_text($input['full_name'] ?? $user['full_name'], 120);
        if (mb_strlen($name) < 2) {
            throw new HttpException(422, 'Full name must be at least 2 characters.');
        }
        $timezone = assert_timezone((string) ($input['timezone'] ?? $user['timezone'] ?? 'UTC'));
        $theme = (string) ($input['theme'] ?? $user['theme'] ?? 'system');
        if (!in_array($theme, ['light', 'dark', 'system'], true)) {
            throw new HttpException(422, 'Choose light, dark, or system theme.');
        }
        $organization = array_key_exists('organization', $input) ? $this->optional($input, 'organization', 160) : ($user['organization'] ?? null);
        $title = array_key_exists('title', $input) ? $this->optional($input, 'title', 120) : ($user['title'] ?? null);
        $bio = array_key_exists('bio', $input) ? $this->optional($input, 'bio', 500) : ($user['bio'] ?? null);
        $phone = array_key_exists('phone', $input) ? $this->optional($input, 'phone', 40) : ($user['phone'] ?? null);
        $email = strtolower(trim((string) ($input['email'] ?? $user['email'])));
        if ($email !== $user['email']) {
            $password = (string) ($input['current_password'] ?? '');
            $this->requirePassword($user, $password);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new HttpException(422, 'Enter a valid email address.');
            }
            $taken = $this->app->pdo()->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
            $taken->execute([$email, $user['id']]);
            if ($taken->fetch()) {
                throw new HttpException(422, 'An account with this email already exists.');
            }
        }

        $this->app->pdo()->prepare(
            'UPDATE users SET full_name = ?, email = ?, timezone = ?, theme = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?'
        )->execute([$name, $email, $timezone, $theme, $user['id']]);
        $this->app->pdo()->prepare(
            'UPDATE user_profiles SET organization = ?, title = ?, bio = ?, phone = ?, updated_at = UTC_TIMESTAMP() WHERE user_id = ?'
        )->execute([$organization, $title, $bio, $phone, $user['id']]);

        return Present::user($this->app->get(AuthService::class)->findById((int) $user['id']), true);
    }

    public function avatar(array $user, array $files): array
    {
        $file = $files['avatar'] ?? null;
        if (!is_array($file)) {
            throw new HttpException(422, 'Choose an image to upload.');
        }
        $name = $this->app->get(MediaService::class)->storeImage($file, 'avatars');
        if (!empty($user['avatar_path'])) {
            $old = storage_path('uploads/avatars/' . $user['avatar_path']);
            if (is_file($old)) {
                unlink($old);
            }
        }
        $this->app->pdo()->prepare('UPDATE users SET avatar_path = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([$name, $user['id']]);
        $fresh = $this->app->get(AuthService::class)->findById((int) $user['id']);

        return Present::user($fresh, true);
    }

    public function changePassword(array $user, array $input): void
    {
        $next = (string) ($input['password'] ?? '');
        $confirm = (string) ($input['password_confirmation'] ?? '');
        $this->app->get(AuthService::class)->assertPassword($next);
        if (!hash_equals($next, $confirm)) {
            throw new HttpException(422, 'Password confirmation does not match.');
        }
        if (!empty($user['password_hash'])) {
            $this->requirePassword($user, (string) ($input['current_password'] ?? ''));
        }
        $this->app->pdo()->prepare('UPDATE users SET password_hash = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([password_hash($next, PASSWORD_DEFAULT), $user['id']]);
        $this->app->get(AuthService::class)->logoutAll((int) $user['id']);
        $this->app->get(AuditLog::class)->write('platform', null, (int) $user['id'], 'auth.password_changed', 'Password was changed.');
    }

    public function delete(array $user, array $input): void
    {
        if (!empty($user['password_hash'])) {
            $this->requirePassword($user, (string) ($input['current_password'] ?? ''));
        } elseif (($input['confirmation'] ?? '') !== 'DELETE') {
            throw new HttpException(422, 'Type DELETE to confirm account deletion.');
        }
        $lead = $this->app->pdo()->prepare(
            'SELECT t.name FROM team_members tm
             JOIN teams t ON t.id = tm.team_id
             WHERE tm.user_id = ? AND tm.role = "leader" AND tm.status = "active" AND t.deleted_at IS NULL
             LIMIT 1'
        );
        $lead->execute([$user['id']]);
        if ($lead->fetch()) {
            throw new HttpException(422, 'Transfer leadership of your teams before deleting your account.');
        }
        $this->app->transaction(function () use ($user) {
            $id = (int) $user['id'];
            $this->app->pdo()->prepare(
                'UPDATE users SET status = "deleted", full_name = "Deleted user", email = ?, password_hash = NULL,
                 google_sub = NULL, avatar_path = NULL, deleted_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
                 WHERE id = ?'
            )->execute(['deleted-' . $id . '@users.invalid', $id]);
            $this->app->pdo()->prepare(
                'UPDATE team_members SET status = "left", updated_at = UTC_TIMESTAMP() WHERE user_id = ? AND status IN ("active","pending")'
            )->execute([$id]);
            $this->app->get(AuthService::class)->logoutAll($id);
            $this->app->pdo()->prepare('DELETE FROM device_tokens WHERE user_id = ?')->execute([$id]);
            $this->app->get(AuditLog::class)->write('platform', null, $id, 'user.deleted', 'An account was deleted.', 'user', (string) $id);
        });
    }

    public function report(array $actor, int $reportedId, string $reason): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 8 || mb_strlen($reason) > 1000) {
            throw new HttpException(422, 'Describe the report in 8 to 1000 characters.');
        }
        if ($reportedId === (int) $actor['id']) {
            throw new HttpException(422, 'You cannot report your own account.');
        }
        $user = $this->app->get(AuthService::class)->findById($reportedId);
        if (!$user || $user['status'] === 'deleted') {
            throw new HttpException(404, 'The requested record was not found.');
        }
        $this->app->get(RateLimiter::class)->hit('report:' . $actor['id'], 10, 3600);
        $this->app->pdo()->prepare(
            'INSERT INTO user_reports (reporter_id, reported_user_id, reason, status, created_at) VALUES (?, ?, ?, "open", UTC_TIMESTAMP())'
        )->execute([$actor['id'], $reportedId, $reason]);
        $this->app->get(AuditLog::class)->write('platform', null, (int) $actor['id'], 'user.reported', 'An account was reported.', 'user', (string) $reportedId);
    }

    public function registerDevice(array $user, array $input): void
    {
        $token = trim((string) ($input['token'] ?? ''));
        if (strlen($token) < 20 || strlen($token) > 512) {
            throw new HttpException(422, 'A device token is required.');
        }
        $platform = substr((string) ($input['platform'] ?? 'android'), 0, 32);
        $name = clean_text($input['device_name'] ?? device_name(), 120);
        $this->app->pdo()->prepare(
            'INSERT INTO device_tokens (user_id, token, platform, device_name, last_seen_at, created_at)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), platform = VALUES(platform),
             device_name = VALUES(device_name), last_seen_at = UTC_TIMESTAMP(), invalidated_at = NULL'
        )->execute([$user['id'], $token, $platform, $name]);
    }

    public function removeDevice(array $user, string $token): void
    {
        $this->app->pdo()->prepare('DELETE FROM device_tokens WHERE user_id = ? AND token = ?')
            ->execute([$user['id'], $token]);
    }

    private function optional(array $input, string $key, int $max): ?string
    {
        if (!array_key_exists($key, $input)) {
            return null;
        }
        $value = clean_text($input[$key], $max);

        return $value === '' ? null : $value;
    }

    private function requirePassword(array $user, string $password): void
    {
        if ($password === '' || empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
            throw new HttpException(422, 'Current password is incorrect.');
        }
    }
}
