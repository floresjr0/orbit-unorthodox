<?php

declare(strict_types=1);

namespace Orbit;

final class Request
{
    public array $params = [];
    public ?array $user = null;
    public array $token = [];

    private function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $body,
        public readonly array $files,
        public readonly array $post
    ) {
    }

    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $marker = '/api';
        $pos = strpos($uri, $marker);
        $path = $pos === false ? $uri : substr($uri, $pos + strlen($marker));
        $path = '/' . trim((string) $path, '/');
        if ($path === '/') {
            $path = '/';
        }

        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
        $body = [];
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            if ($raw === false) {
                $raw = '';
            }
            if (strlen($raw) > 1_000_000) {
                throw new HttpException(413, 'The request is too large.');
            }
            if (trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    throw new HttpException(400, 'The request body must be valid JSON.');
                }
                $body = $decoded;
            }
        }

        return new self($method, $path, $_GET, $body, $_FILES, $_POST);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->body)) {
            return $this->body[$key];
        }
        if (array_key_exists($key, $this->post)) {
            return $this->post[$key];
        }
        if (array_key_exists($key, $this->query)) {
            return $this->query[$key];
        }

        return $default;
    }

    public function bearer(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($header === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }
        if (preg_match('/^Bearer\s+(\S+)$/i', (string) $header, $m)) {
            return $m[1];
        }

        return null;
    }
}
