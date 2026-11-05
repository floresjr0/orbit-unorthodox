<?php

declare(strict_types=1);

namespace Orbit;

final class Response
{
    public static function json(bool $success, string $message, mixed $data = null, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function ok(string $message, mixed $data = null, int $status = 200): never
    {
        self::json(true, $message, $data, $status);
    }

    public static function download(string $filename, string $content, string $type): never
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'download';
        http_response_code(200);
        header('Content-Type: ' . $type);
        header('Content-Disposition: attachment; filename="' . $safe . '"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo $content;
        exit;
    }
}
