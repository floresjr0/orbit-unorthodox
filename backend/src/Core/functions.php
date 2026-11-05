<?php

declare(strict_types=1);

namespace Orbit;

function storage_path(string $path = ''): string
{
    $root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage';
    if ($path === '') {
        return $root;
    }

    return $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($path, '/\\'));
}

function app_log(string $channel, string $message, array $context = []): void
{
    $dir = storage_path('logs');
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $line = gmdate('c') . ' ' . $message;
    if ($context) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    file_put_contents($dir . DIRECTORY_SEPARATOR . $channel . '.log', $line . PHP_EOL, FILE_APPEND);
}

function iso(?string $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    $stamp = strtotime($value . ' UTC');
    if ($stamp === false) {
        return null;
    }

    return gmdate('Y-m-d\TH:i:s\Z', $stamp);
}

function iso_date(?string $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    return substr($value, 0, 10);
}

function parse_datetime(mixed $value, bool $required = false): ?string
{
    if ($value === null || $value === '') {
        if ($required) {
            throw new HttpException(422, 'A valid date is required.');
        }

        return null;
    }
    if (!is_string($value)) {
        throw new HttpException(422, 'A valid date is required.');
    }
    try {
        $dt = new \DateTimeImmutable($value);
    } catch (\Exception) {
        throw new HttpException(422, 'A valid date is required.');
    }

    return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function parse_date(mixed $value): ?string
{
    $dt = parse_datetime($value, false);
    return $dt ? substr($dt, 0, 10) : null;
}

function like_term(string $value): string
{
    return '%' . addcslashes($value, '%_\\') . '%';
}

function client_ip(array $config): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!empty($config['trust_proxy']) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }

    return substr($ip, 0, 45);
}

function device_name(): string
{
    $name = trim((string) ($_SERVER['HTTP_X_DEVICE_NAME'] ?? ''));
    if ($name === '') {
        $name = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown device'), 0, 120);
    }

    return mb_substr($name, 0, 120);
}

function user_agent(): string
{
    return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

function api_origin(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/api/index.php'));
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');

    return $scheme . '://' . $host . $dir;
}

function placeholders(int $count): string
{
    return implode(',', array_fill(0, $count, '?'));
}

function b64url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function unb64url(string $value): string
{
    $pad = strlen($value) % 4;
    if ($pad) {
        $value .= str_repeat('=', 4 - $pad);
    }
    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    if ($decoded === false) {
        throw new HttpException(401, 'Invalid authentication token.');
    }

    return $decoded;
}

function clean_text(mixed $value, int $max): string
{
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)) ?? '');
    if (mb_strlen($text) > $max) {
        $text = mb_substr($text, 0, $max);
    }

    return $text;
}

function assert_timezone(string $timezone): string
{
    if (!in_array($timezone, timezone_identifiers_list(), true)) {
        throw new HttpException(422, 'Choose a valid timezone.');
    }

    return $timezone;
}

function page_params(Request $request): array
{
    $page = max(1, (int) ($request->query['page'] ?? 1));
    $perPage = (int) ($request->query['per_page'] ?? 20);
    $perPage = min(50, max(1, $perPage));

    return [$page, $perPage, ($page - 1) * $perPage];
}

function send_cors(array $config): void
{
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '') {
        return;
    }
    $allowed = [
        'capacitor://localhost',
        'ionic://localhost',
        'http://localhost',
        'https://localhost',
    ];
    $ok = in_array($origin, $allowed, true) || in_array($origin, $config['cors_origins'] ?? [], true);
    $host = parse_url($origin, PHP_URL_HOST);
    if (!$ok && ($config['env'] ?? '') === 'local' && is_string($host)) {
        $ok = in_array($host, ['localhost', '127.0.0.1', '10.0.2.2'], true)
            || (bool) preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2\d|3[0-1])\.)/', $host);
    }
    if (!$ok) {
        return;
    }
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Device-Name');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Max-Age: 600');
}

function day_bounds_utc(string $timezone): array
{
    try {
        $tz = new \DateTimeZone($timezone);
    } catch (\Exception) {
        $tz = new \DateTimeZone('UTC');
    }
    $start = new \DateTimeImmutable('today', $tz);
    $end = $start->modify('+1 day');

    return [
        $start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        $end->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
    ];
}
