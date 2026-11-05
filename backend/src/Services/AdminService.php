<?php

declare(strict_types=1);

namespace Orbit;

final class AdminService
{
    public function __construct(private readonly App $app)
    {
    }

    public function stats(): array
    {
        $pdo = $this->app->pdo();

        return [
            'users' => (int) $pdo->query('SELECT COUNT(*) FROM users WHERE status <> "deleted"')->fetchColumn(),
            'suspended_users' => (int) $pdo->query('SELECT COUNT(*) FROM users WHERE status = "suspended"')->fetchColumn(),
            'teams' => (int) $pdo->query('SELECT COUNT(*) FROM teams WHERE deleted_at IS NULL')->fetchColumn(),
            'tasks' => (int) $pdo->query('SELECT COUNT(*) FROM tasks WHERE deleted_at IS NULL AND is_template = 0')->fetchColumn(),
            'open_reports' => (int) $pdo->query('SELECT COUNT(*) FROM user_reports WHERE status = "open"')->fetchColumn(),
            'messages' => (int) $pdo->query('SELECT COUNT(*) FROM contact_messages')->fetchColumn(),
        ];
    }

    public function users(string $query, int $page, int $perPage, int $offset): array
    {
        $where = 'u.status <> "deleted"';
        $params = [];
        if (mb_strlen($query) >= 2) {
            $where .= ' AND (u.full_name LIKE ? OR u.email LIKE ?)';
            $like = like_term($query);
            $params[] = $like;
            $params[] = $like;
        }
        $count = $this->app->pdo()->prepare("SELECT COUNT(*) FROM users u WHERE $where");
        $count->execute($params);
        $stmt = $this->app->pdo()->prepare(
            "SELECT u.id, u.full_name, u.email, u.status, u.created_at, u.last_login_at,
                (SELECT COUNT(*) FROM team_members tm WHERE tm.user_id = u.id AND tm.status = 'active') AS team_count,
                EXISTS(SELECT 1 FROM platform_admins pa WHERE pa.user_id = u.id) AS is_admin
             FROM users u WHERE $where ORDER BY u.id DESC LIMIT $perPage OFFSET $offset"
        );
        $stmt->execute($params);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'full_name' => $row['full_name'],
                'email' => $row['email'],
                'status' => $row['status'],
                'team_count' => (int) $row['team_count'],
                'is_admin' => (bool) $row['is_admin'],
                'created_at' => iso($row['created_at']),
                'last_login_at' => iso($row['last_login_at']),
            ];
        }

        return ['items' => $items, 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => (int) $count->fetchColumn()]];
    }

    public function setStatus(array $actor, int $userId, string $status): void
    {
        if ($userId === (int) $actor['id']) {
            throw new HttpException(422, 'You cannot change your own account status here.');
        }
        $user = $this->app->get(AuthService::class)->findById($userId);
        if (!$user || $user['status'] === 'deleted') {
            throw new HttpException(404, 'The requested record was not found.');
        }
        $this->app->pdo()->prepare('UPDATE users SET status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$status, $userId]);
        if ($status === 'suspended') {
            $this->app->get(AuthService::class)->logoutAll($userId);
        }
        $this->app->get(AuditLog::class)->write('platform', null, (int) $actor['id'], 'user.' . $status, 'Account status changed to ' . $status . '.', 'user', (string) $userId);
    }

    public function reports(int $page, int $perPage, int $offset): array
    {
        $total = (int) $this->app->pdo()->query('SELECT COUNT(*) FROM user_reports')->fetchColumn();
        $stmt = $this->app->pdo()->prepare(
            "SELECT r.*, reporter.full_name AS reporter_name, reported.full_name AS reported_name, reported.email AS reported_email
             FROM user_reports r
             JOIN users reporter ON reporter.id = r.reporter_id
             JOIN users reported ON reported.id = r.reported_user_id
             ORDER BY r.id DESC LIMIT $perPage OFFSET $offset"
        );
        $stmt->execute();
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'reason' => $row['reason'],
                'status' => $row['status'],
                'reporter_name' => $row['reporter_name'],
                'reported_user_id' => (int) $row['reported_user_id'],
                'reported_name' => $row['reported_name'],
                'reported_email' => $row['reported_email'],
                'created_at' => iso($row['created_at']),
            ];
        }

        return ['items' => $items, 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total]];
    }

    public function resolveReport(array $actor, int $reportId, string $status): void
    {
        if (!in_array($status, ['reviewed', 'dismissed'], true)) {
            throw new HttpException(422, 'Choose reviewed or dismissed.');
        }
        $stmt = $this->app->pdo()->prepare('UPDATE user_reports SET status = ?, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP() WHERE id = ?');
        $stmt->execute([$status, $actor['id'], $reportId]);
        if ($stmt->rowCount() === 0) {
            throw new HttpException(404, 'The requested record was not found.');
        }
        $this->app->get(AuditLog::class)->write('platform', null, (int) $actor['id'], 'report.' . $status, 'An account report was updated.', 'user_report', (string) $reportId);
    }

    public function audit(int $page, int $perPage, int $offset): array
    {
        $total = (int) $this->app->pdo()->query('SELECT COUNT(*) FROM audit_logs WHERE scope = "platform"')->fetchColumn();
        $stmt = $this->app->pdo()->prepare(
            "SELECT id, actor_id, action, summary, entity_type, entity_id, ip, created_at
             FROM audit_logs WHERE scope = 'platform' ORDER BY id DESC LIMIT $perPage OFFSET $offset"
        );
        $stmt->execute();
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'actor_id' => $row['actor_id'] !== null ? (int) $row['actor_id'] : null,
                'action' => $row['action'],
                'summary' => $row['summary'],
                'entity_type' => $row['entity_type'],
                'entity_id' => $row['entity_id'],
                'ip' => $row['ip'],
                'created_at' => iso($row['created_at']),
            ];
        }

        return ['items' => $items, 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total]];
    }

    public function messages(int $page, int $perPage, int $offset): array
    {
        $total = (int) $this->app->pdo()->query('SELECT COUNT(*) FROM contact_messages')->fetchColumn();
        $stmt = $this->app->pdo()->prepare(
            "SELECT id, name, email, message, created_at FROM contact_messages ORDER BY id DESC LIMIT $perPage OFFSET $offset"
        );
        $stmt->execute();
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'email' => $row['email'],
                'message' => $row['message'],
                'created_at' => iso($row['created_at']),
            ];
        }

        return ['items' => $items, 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total]];
    }

    public function releases(): array
    {
        $rows = $this->app->pdo()->query('SELECT * FROM app_releases ORDER BY id DESC')->fetchAll();
        return array_map(fn (array $row): array => $this->release($row), $rows);
    }

    public function latestRelease(): ?array
    {
        $stmt = $this->app->pdo()->query('SELECT * FROM app_releases WHERE is_published = 1 ORDER BY id DESC LIMIT 1');
        $row = $stmt->fetch();
        if (!$row || !is_file(storage_path('releases/' . $row['file_path']))) {
            return null;
        }

        return $this->release($row);
    }

    public function createRelease(array $actor, array $input, array $files): array
    {
        $version = clean_text($input['version'] ?? '', 40);
        $notes = trim((string) ($input['changelog'] ?? ''));
        if (!preg_match('/^[0-9A-Za-z._-]{1,40}$/', $version)) {
            throw new HttpException(422, 'Enter a version such as 1.0.0.');
        }
        if (mb_strlen($notes) < 2) {
            throw new HttpException(422, 'Add release notes.');
        }
        $file = $files['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new HttpException(422, 'Choose the APK file to publish.');
        }
        $name = (string) ($file['name'] ?? '');
        if (!str_ends_with(strtolower($name), '.apk')) {
            throw new HttpException(422, 'The release file must be an Android APK.');
        }
        if ((int) $file['size'] > 80 * 1024 * 1024) {
            throw new HttpException(422, 'The APK is larger than 80 MB.');
        }
        $stored = $version . '-' . bin2hex(random_bytes(4)) . '.apk';
        $dest = storage_path('releases/' . $stored);
        if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
            throw new HttpException(500, 'The release file could not be saved.');
        }
        $this->app->pdo()->prepare(
            'INSERT INTO app_releases (version, changelog, file_path, file_size, download_count, is_published, created_at)
             VALUES (?, ?, ?, ?, 0, 1, UTC_TIMESTAMP())'
        )->execute([$version, mb_substr($notes, 0, 4000), $stored, (int) filesize($dest)]);
        $id = (int) $this->app->pdo()->lastInsertId();
        $this->app->get(AuditLog::class)->write('platform', null, (int) $actor['id'], 'release.published', 'Version ' . $version . ' was published.', 'release', (string) $id);
        $row = $this->app->pdo()->prepare('SELECT * FROM app_releases WHERE id = ?');
        $row->execute([$id]);

        return $this->release($row->fetch());
    }

    public function releaseFile(int $id): array
    {
        $stmt = $this->app->pdo()->prepare('SELECT * FROM app_releases WHERE id = ? AND is_published = 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        $path = $row ? storage_path('releases/' . $row['file_path']) : '';
        if (!$row || !is_file($path)) {
            throw new HttpException(404, 'That download is not available.');
        }
        $this->app->pdo()->prepare('UPDATE app_releases SET download_count = download_count + 1 WHERE id = ?')->execute([$id]);

        return ['path' => $path, 'name' => 'ORBIT-' . $row['version'] . '.apk'];
    }

    public function saveContact(string $name, string $email, string $message, string $ip): void
    {
        $this->app->pdo()->prepare(
            'INSERT INTO contact_messages (name, email, message, ip, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())'
        )->execute([$name, $email, $message, $ip]);
    }

    private function release(array $row): array
    {
        $available = is_file(storage_path('releases/' . $row['file_path']));

        return [
            'id' => (int) $row['id'],
            'version' => $row['version'],
            'changelog' => $row['changelog'],
            'file_size' => (int) $row['file_size'],
            'download_count' => (int) $row['download_count'],
            'is_published' => (bool) $row['is_published'],
            'available' => $available,
            'created_at' => iso($row['created_at']),
        ];
    }
}
