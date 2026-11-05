<?php

declare(strict_types=1);

namespace Orbit;

final class TokenService
{
    public function __construct(private readonly App $app)
    {
    }

    private function key(): string
    {
        $key = (string) ($this->app->config['app']['key'] ?? '');
        if (strlen($key) < 32 || str_contains($key, 'change-me')) {
            throw new \RuntimeException('Set a unique app.key of at least 32 characters in backend/config/config.php.');
        }

        return $key;
    }

    public function issueAccess(int $userId, int $sessionId): string
    {
        $ttl = (int) ($this->app->config['app']['access_ttl'] ?? 900);
        $payload = [
            'sub' => $userId,
            'sid' => $sessionId,
            'iat' => time(),
            'exp' => time() + $ttl,
            'jti' => bin2hex(random_bytes(8)),
        ];
        $body = b64url((string) json_encode($payload));
        $sig = b64url(hash_hmac('sha256', $body, $this->key(), true));

        return $body . '.' . $sig;
    }

    public function parseAccess(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            throw new HttpException(401, 'Invalid authentication token.');
        }
        [$body, $sig] = $parts;
        $expected = b64url(hash_hmac('sha256', $body, $this->key(), true));
        if (!hash_equals($expected, $sig)) {
            throw new HttpException(401, 'Invalid authentication token.');
        }
        $payload = json_decode(unb64url($body), true);
        if (!is_array($payload) || (int) ($payload['exp'] ?? 0) < time()) {
            throw new HttpException(401, 'Your session has expired. Please sign in again.');
        }
        if (empty($payload['sub']) || empty($payload['sid'])) {
            throw new HttpException(401, 'Invalid authentication token.');
        }

        return $payload;
    }

    public function issueRefresh(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
