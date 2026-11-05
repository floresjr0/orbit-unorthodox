<?php

declare(strict_types=1);

namespace Orbit;

final class AuthService
{
    public function __construct(private readonly App $app)
    {
    }

    public function register(array $input): array
    {
        $email = $this->normalizeEmail($input['email'] ?? '');
        $name = clean_text($input['full_name'] ?? '', 120);
        if (mb_strlen($name) < 2) {
            throw new HttpException(422, 'Full name must be at least 2 characters.');
        }
        $password = (string) ($input['password'] ?? '');
        $confirm = (string) ($input['password_confirmation'] ?? '');
        $this->assertPassword($password);
        if (!hash_equals($password, $confirm)) {
            throw new HttpException(422, 'Password confirmation does not match.');
        }
        $this->app->get(RateLimiter::class)->hit('register:' . client_ip($this->app->config), 8, 3600);

        return $this->app->transaction(function () use ($email, $name, $password) {
            if ($this->findByEmail($email)) {
                throw new HttpException(422, 'An account with this email already exists.');
            }
            $stmt = $this->app->pdo()->prepare(
                'INSERT INTO users (email, password_hash, full_name, status, created_at, updated_at)
                 VALUES (?, ?, ?, "active", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT), $name]);
            $id = (int) $this->app->pdo()->lastInsertId();
            $this->provision($id);
            $this->app->get(AuditLog::class)->write('platform', null, $id, 'user.registered', 'A new account was created.', 'user', (string) $id);
            return $this->startSession($this->mustFind($id));
        });
    }

    public function login(array $input): array
    {
        $email = $this->normalizeEmail($input['email'] ?? '');
        $password = (string) ($input['password'] ?? '');
        $ip = client_ip($this->app->config);
        $this->app->get(RateLimiter::class)->hit('login:' . $ip . ':' . hash('sha256', $email), 8, 900);
        $user = $this->findByEmail($email);
        $hash = $user['password_hash'] ?? password_hash('orbit-timing-pad', PASSWORD_DEFAULT);
        $valid = is_string($hash) && password_verify($password, $hash);
        if (!$user || empty($user['password_hash']) || !$valid || $user['status'] === 'deleted') {
            $this->app->get(AuditLog::class)->write('platform', null, isset($user['id']) ? (int) $user['id'] : null, 'auth.login_failed', 'A sign-in attempt failed.');
            throw new HttpException(401, 'Email or password is incorrect.');
        }
        if ($user['status'] === 'suspended') {
            throw new HttpException(403, 'This account is suspended.');
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $this->app->pdo()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
        $this->touchLogin((int) $user['id']);
        $this->app->get(AuditLog::class)->write('platform', null, (int) $user['id'], 'auth.login', 'Signed in.', 'user', (string) $user['id']);

        return $this->startSession($this->mustFind((int) $user['id']));
    }

    public function google(string $idToken): array
    {
        $this->app->get(RateLimiter::class)->hit('google:' . client_ip($this->app->config), 20, 900);
        $clientId = trim((string) ($this->app->config['google']['client_id'] ?? ''));
        if ($clientId === '') {
            throw new HttpException(503, 'Google Sign-In is not configured on this server.');
        }
        $profile = $this->verifyGoogle($idToken, $clientId);
        $email = $this->normalizeEmail($profile['email'] ?? '');
        $sub = (string) ($profile['sub'] ?? '');
        if ($sub === '') {
            throw new HttpException(401, 'Google token could not be verified.');
        }
        $name = clean_text($profile['name'] ?? '', 120);
        if (mb_strlen($name) < 2) {
            $name = strstr($email, '@', true) ?: 'ORBIT member';
        }

        return $this->app->transaction(function () use ($email, $sub, $name) {
            $existing = $this->findByGoogle($sub);
            if ($existing) {
                if ($existing['status'] === 'suspended') {
                    throw new HttpException(403, 'This account is suspended.');
                }
                if ($existing['status'] === 'deleted') {
                    throw new HttpException(401, 'Google token could not be verified.');
                }
                $this->touchLogin((int) $existing['id']);
                return $this->startSession($this->mustFind((int) $existing['id']));
            }
            if ($this->findByEmail($email)) {
                throw new HttpException(409, 'An account with this email already exists. Sign in with your password.');
            }
            $stmt = $this->app->pdo()->prepare(
                'INSERT INTO users (email, full_name, google_sub, status, created_at, updated_at)
                 VALUES (?, ?, ?, "active", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $stmt->execute([$email, $name, $sub]);
            $id = (int) $this->app->pdo()->lastInsertId();
            $this->provision($id);
            $this->app->get(AuditLog::class)->write('platform', null, $id, 'user.registered', 'Account created with Google.', 'user', (string) $id);
            return $this->startSession($this->mustFind($id));
        });
    }

    public function refresh(string $raw): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $raw)) {
            throw new HttpException(401, 'This session is no longer valid. Please sign in again.');
        }

        return $this->app->transaction(function () use ($raw) {
            $tokens = $this->app->get(TokenService::class);
            $stmt = $this->app->pdo()->prepare('SELECT * FROM refresh_tokens WHERE token_hash = ? FOR UPDATE');
            $stmt->execute([$tokens->hash($raw)]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new HttpException(401, 'This session is no longer valid. Please sign in again.');
            }
            if ($row['revoked_at'] !== null) {
                $this->app->pdo()->prepare(
                    'UPDATE refresh_tokens SET revoked_at = UTC_TIMESTAMP() WHERE family_id = ? AND revoked_at IS NULL'
                )->execute([$row['family_id']]);
                $this->app->get(AuditLog::class)->write('platform', null, (int) $row['user_id'], 'auth.refresh_reuse', 'A revoked session token was reused.');
                throw new HttpException(401, 'This session is no longer valid. Please sign in again.');
            }
            if (strtotime($row['expires_at'] . ' UTC') < time()) {
                throw new HttpException(401, 'This session is no longer valid. Please sign in again.');
            }
            $user = $this->mustFind((int) $row['user_id']);
            if ($user['status'] === 'suspended') {
                throw new HttpException(403, 'This account is suspended.');
            }
            if ($user['status'] === 'deleted') {
                throw new HttpException(401, 'This session is no longer valid. Please sign in again.');
            }
            $session = $this->insertRefresh((int) $user['id'], (string) $row['family_id'], $row['device_name']);
            $this->app->pdo()->prepare(
                'UPDATE refresh_tokens SET revoked_at = UTC_TIMESTAMP(), replaced_by_id = ?, last_used_at = UTC_TIMESTAMP() WHERE id = ?'
            )->execute([$session['id'], $row['id']]);

            return $this->tokenResponse($user, $session['id'], $session['raw']);
        });
    }

    public function logout(int $sessionId): void
    {
        $stmt = $this->app->pdo()->prepare('SELECT family_id, user_id FROM refresh_tokens WHERE id = ?');
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch();
        if (!$row) {
            return;
        }
        $this->app->pdo()->prepare(
            'UPDATE refresh_tokens SET revoked_at = UTC_TIMESTAMP() WHERE family_id = ? AND revoked_at IS NULL'
        )->execute([$row['family_id']]);
        $this->app->get(AuditLog::class)->write('platform', null, (int) $row['user_id'], 'auth.logout', 'Signed out.');
    }

    public function logoutAll(int $userId): void
    {
        $this->app->pdo()->prepare(
            'UPDATE refresh_tokens SET revoked_at = UTC_TIMESTAMP() WHERE user_id = ? AND revoked_at IS NULL'
        )->execute([$userId]);
    }

    public function forgot(string $emailInput): void
    {
        $this->app->get(RateLimiter::class)->hit('forgot:' . client_ip($this->app->config), 5, 3600);
        $email = $this->normalizeEmail($emailInput);
        $user = $this->findByEmail($email);
        if (!$user || $user['status'] !== 'active') {
            return;
        }
        $raw = bin2hex(random_bytes(32));
        $ttl = gmdate('Y-m-d H:i:s', time() + 3600);
        $this->app->pdo()->prepare('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL')
            ->execute([$user['id']]);
        $this->app->pdo()->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())'
        )->execute([$user['id'], hash('sha256', $raw), $ttl]);
        $url = rtrim((string) ($this->app->config['app']['public_url'] ?? ''), '/') . '/website/reset.html?token=' . $raw;
        if (!empty($this->app->config['mail']['enabled'])) {
            $from = $this->app->config['mail']['from'] ?? 'noreply@localhost';
            $sent = @mail($email, 'Reset your ORBIT password', "Use this link within one hour:\n$url\n", 'From: ' . $from);
            app_log('mail', 'password reset mail attempted', ['email' => $email, 'sent' => (bool) $sent]);
            return;
        }
        app_log('mail', 'password reset created while mail is disabled', ['email' => $email, 'token' => $raw, 'url' => $url]);
    }

    public function reset(array $input): void
    {
        $token = strtolower(trim((string) ($input['token'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $confirm = (string) ($input['password_confirmation'] ?? '');
        $this->assertPassword($password);
        if (!hash_equals($password, $confirm)) {
            throw new HttpException(422, 'Password confirmation does not match.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new HttpException(422, 'This reset link is invalid or has expired.');
        }
        $this->app->transaction(function () use ($token, $password) {
            $stmt = $this->app->pdo()->prepare('SELECT * FROM password_resets WHERE token_hash = ? FOR UPDATE');
            $stmt->execute([hash('sha256', $token)]);
            $row = $stmt->fetch();
            if (!$row || $row['used_at'] !== null || strtotime($row['expires_at'] . ' UTC') < time()) {
                throw new HttpException(422, 'This reset link is invalid or has expired.');
            }
            $this->app->pdo()->prepare('UPDATE users SET password_hash = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $row['user_id']]);
            $this->app->pdo()->prepare('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$row['id']]);
            $this->logoutAll((int) $row['user_id']);
            $this->app->get(AuditLog::class)->write('platform', null, (int) $row['user_id'], 'auth.password_reset', 'Password was reset.');
        });
    }

    public function sessions(int $userId, int $currentId): array
    {
        $stmt = $this->app->pdo()->prepare(
            'SELECT id, device_name, ip, created_at, last_used_at
             FROM refresh_tokens
             WHERE user_id = ? AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()
             ORDER BY id DESC'
        );
        $stmt->execute([$userId]);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'device_name' => $row['device_name'],
                'created_at' => iso($row['created_at']),
                'last_used_at' => iso($row['last_used_at']),
                'current' => (int) $row['id'] === $currentId,
            ];
        }

        return $items;
    }

    public function revokeSession(int $userId, int $sessionId, int $currentId): bool
    {
        $stmt = $this->app->pdo()->prepare('SELECT family_id FROM refresh_tokens WHERE id = ? AND user_id = ?');
        $stmt->execute([$sessionId, $userId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new HttpException(404, 'That session was not found.');
        }
        $this->app->pdo()->prepare(
            'UPDATE refresh_tokens SET revoked_at = UTC_TIMESTAMP() WHERE family_id = ? AND revoked_at IS NULL'
        )->execute([$row['family_id']]);

        return $sessionId === $currentId;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->app->pdo()->prepare(
            'SELECT u.*, p.bio, p.organization, p.title, p.phone
             FROM users u
             LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE u.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->app->pdo()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function assertPassword(string $password): void
    {
        if (strlen($password) < 8 || strlen($password) > 72) {
            throw new HttpException(422, 'Password must be between 8 and 72 characters.');
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            throw new HttpException(422, 'Password must include a letter and a number.');
        }
    }

    private function findByGoogle(string $sub): ?array
    {
        $stmt = $this->app->pdo()->prepare('SELECT * FROM users WHERE google_sub = ? LIMIT 1');
        $stmt->execute([$sub]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function mustFind(int $id): array
    {
        $user = $this->findById($id);
        if (!$user) {
            throw new HttpException(404, 'The requested record was not found.');
        }

        return $user;
    }

    private function normalizeEmail(mixed $value): string
    {
        $email = strtolower(trim((string) $value));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email) || strlen($email) > 255) {
            throw new HttpException(422, 'Enter a valid email address.');
        }

        return $email;
    }

    private function provision(int $userId): void
    {
        $this->app->pdo()->prepare('INSERT INTO user_profiles (user_id) VALUES (?)')->execute([$userId]);
        $this->app->pdo()->prepare('INSERT INTO notification_preferences (user_id) VALUES (?)')->execute([$userId]);
    }

    private function touchLogin(int $userId): void
    {
        $this->app->pdo()->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$userId]);
    }

    private function startSession(array $user): array
    {
        $session = $this->insertRefresh((int) $user['id'], bin2hex(random_bytes(16)), device_name());

        return $this->tokenResponse($user, $session['id'], $session['raw']);
    }

    private function insertRefresh(int $userId, string $family, ?string $device): array
    {
        $raw = $this->app->get(TokenService::class)->issueRefresh();
        $ttl = (int) ($this->app->config['app']['refresh_ttl'] ?? 2592000);
        $expires = gmdate('Y-m-d H:i:s', time() + $ttl);
        $stmt = $this->app->pdo()->prepare(
            'INSERT INTO refresh_tokens (user_id, token_hash, family_id, device_name, ip, expires_at, created_at, last_used_at)
             VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $stmt->execute([
            $userId,
            $this->app->get(TokenService::class)->hash($raw),
            $family,
            $device ? mb_substr($device, 0, 120) : null,
            client_ip($this->app->config),
            $expires,
        ]);

        return ['id' => (int) $this->app->pdo()->lastInsertId(), 'raw' => $raw];
    }

    private function tokenResponse(array $user, int $sessionId, string $refresh): array
    {
        return [
            'access_token' => $this->app->get(TokenService::class)->issueAccess((int) $user['id'], $sessionId),
            'refresh_token' => $refresh,
            'expires_in' => (int) ($this->app->config['app']['access_ttl'] ?? 900),
            'user' => Present::user($user, true),
        ];
    }

    private function verifyGoogle(string $idToken, string $clientId): array
    {
        if ($idToken === '' || strlen($idToken) > 4000) {
            throw new HttpException(401, 'Google token could not be verified.');
        }
        $ch = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode((string) $raw, true);
        if ($status !== 200 || !is_array($data)) {
            throw new HttpException(401, 'Google token could not be verified.');
        }
        $issuer = $data['iss'] ?? '';
        if (!in_array($issuer, ['accounts.google.com', 'https://accounts.google.com'], true)) {
            throw new HttpException(401, 'Google token could not be verified.');
        }
        if (($data['aud'] ?? '') !== $clientId) {
            throw new HttpException(401, 'Google token could not be verified.');
        }
        $verified = $data['email_verified'] ?? false;
        if ($verified !== true && $verified !== 'true') {
            throw new HttpException(401, 'The Google account email is not verified.');
        }

        return $data;
    }
}
