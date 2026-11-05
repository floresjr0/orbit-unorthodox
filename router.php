<?php

declare(strict_types=1);

/**
 * Front controller for the PHP built-in server:
 * php -S localhost:8080 router.php
 */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

if ($uri !== '/' && is_file(__DIR__ . $uri)) {
    return false;
}

if (is_dir(__DIR__ . $uri)) {
    $index = rtrim($uri, '/') . '/index.html';
    if (is_file(__DIR__ . $index)) {
        header('Location: ' . $index, true, 302);
        return true;
    }
}

if ($uri === '/api' || str_starts_with($uri, '/api/')) {
    require __DIR__ . '/api/index.php';
    return true;
}

if ($uri === '/' || $uri === '') {
    header('Location: /website/index.html', true, 302);
    return true;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Not found';
return true;
