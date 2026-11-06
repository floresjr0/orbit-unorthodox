<?php

declare(strict_types=1);

namespace Orbit;

final class FcmService
{
    public function __construct(private readonly App $app)
    {
    }

    public function configured(): bool
    {
        $fcm = $this->app->config['fcm'] ?? [];

        return ($fcm['project_id'] ?? '') !== '' && is_file($this->accountPath());
    }

    private function accountPath(): string
    {
        $path = (string) ($this->app->config['fcm']['service_account'] ?? '');
        if ($path === '') {
            return '';
        }
        $absolute = preg_match('/^(?:[A-Za-z]:[\\\\\\/]|\/|\\\\)/', $path) === 1;

        return $absolute ? $path : storage_path($path);
    }

    public function send(array $tokens, string $title, string $body, array $data): void
    {
        if (!$tokens) {
            return;
        }
        if (!$this->configured()) {
            app_log('push', 'FCM development fallback', [
                'title' => $title,
                'body' => $body,
                'tokens' => count($tokens),
                'data' => $data,
            ]);
            return;
        }

        try {
            $access = $this->accessToken();
        } catch (\Throwable $e) {
            app_log('push', 'FCM auth failed', ['error' => $e->getMessage()]);
            return;
        }

        $project = $this->app->config['fcm']['project_id'];
        $url = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode((string) $project) . '/messages:send';
        $stringData = [];
        foreach ($data as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $stringData[(string) $key] = (string) $value;
            }
        }

        foreach ($tokens as $token) {
            $payload = [
                'message' => [
                    'token' => $token,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data' => $stringData,
                    'android' => ['priority' => 'HIGH'],
                ],
            ];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $access,
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS => json_encode($payload),
            ]);
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($status === 200) {
                continue;
            }
            app_log('push', 'FCM send failed', ['status' => $status, 'body' => $raw]);
            $decoded = json_decode((string) $raw, true);
            $encoded = json_encode($decoded['error']['details'] ?? []);
            if (is_string($raw) && (str_contains($raw, 'UNREGISTERED') || str_contains((string) $encoded, 'UNREGISTERED'))) {
                $this->app->pdo()->prepare(
                    'UPDATE device_tokens SET invalidated_at = UTC_TIMESTAMP() WHERE token = ?'
                )->execute([$token]);
            }
        }
    }

    private function accessToken(): string
    {
        $cache = storage_path('cache/fcm-token.json');
        if (is_file($cache)) {
            $saved = json_decode((string) file_get_contents($cache), true);
            if (is_array($saved) && (int) ($saved['exp'] ?? 0) > time() + 60) {
                return (string) $saved['token'];
            }
        }

        $sa = json_decode((string) file_get_contents($this->accountPath()), true);
        if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
            throw new \RuntimeException('The Firebase service account file is incomplete.');
        }
        $now = time();
        $header = b64url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claim = b64url((string) json_encode([
            'iss' => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));
        $input = $header . '.' . $claim;
        $private = openssl_pkey_get_private($sa['private_key']);
        if ($private === false) {
            throw new \RuntimeException('The Firebase private key could not be read.');
        }
        $signature = '';
        if (!openssl_sign($input, $signature, $private, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('The Firebase request could not be signed.');
        }
        $jwt = $input . '.' . b64url($signature);
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]),
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode((string) $raw, true);
        if ($status !== 200 || empty($json['access_token'])) {
            throw new \RuntimeException('Firebase rejected the server credentials.');
        }
        file_put_contents($cache, json_encode([
            'token' => $json['access_token'],
            'exp' => time() + max(60, (int) ($json['expires_in'] ?? 3600) - 60),
        ]));

        return (string) $json['access_token'];
    }
}
