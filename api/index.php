<?php

declare(strict_types=1);

use Orbit\Access;
use Orbit\App;
use Orbit\AuthService;
use Orbit\HttpException;
use Orbit\NotificationService;
use Orbit\Request;
use Orbit\Response;
use Orbit\Router;
use Orbit\TokenService;

/** @var App $app */
$app = require dirname(__DIR__) . '/backend/bootstrap.php';

\Orbit\send_cors($app->config);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

register_shutdown_function(static function () use ($app): void {
    try {
        $app->get(NotificationService::class)->flushPushes();
    } catch (Throwable $e) {
        \Orbit\app_log('push', 'Push flush failed', ['error' => $e->getMessage()]);
    }
});

set_exception_handler(static function (Throwable $e) use ($app): void {
    if ($e instanceof HttpException) {
        Response::json(false, $e->getMessage(), $e->data, $e->status);
    }
    \Orbit\app_log('app', $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
    $message = !empty($app->config['debug']) ? $e->getMessage() : 'Something went wrong.';
    Response::json(false, $message, null, 500);
});

$router = new Router($app);
$router->middleware('auth', static function (Request $request) use ($app): void {
    $token = $request->bearer();
    if (!$token) {
        throw new HttpException(401, 'Authentication is required.');
    }
    $payload = $app->get(TokenService::class)->parseAccess($token);
    $stmt = $app->pdo()->prepare('SELECT user_id, revoked_at, expires_at FROM refresh_tokens WHERE id = ?');
    $stmt->execute([(int) $payload['sid']]);
    $session = $stmt->fetch();
    if (!$session || $session['revoked_at'] !== null || strtotime($session['expires_at'] . ' UTC') < time()) {
        throw new HttpException(401, 'Your session has expired. Please sign in again.');
    }
    if ((int) $session['user_id'] !== (int) $payload['sub']) {
        throw new HttpException(401, 'Invalid authentication token.');
    }
    $user = $app->get(AuthService::class)->findById((int) $payload['sub']);
    if (!$user || $user['status'] === 'deleted' || $user['deleted_at'] !== null) {
        throw new HttpException(401, 'Authentication is required.');
    }
    if ($user['status'] === 'suspended') {
        throw new HttpException(403, 'This account is suspended.');
    }
    $request->user = $user;
    $request->token = $payload;
});
$router->middleware('admin', static function (Request $request) use ($app): void {
    $app->get(Access::class)->requireAdmin((int) $request->user['id']);
});

$register = require dirname(__DIR__) . '/backend/routes.php';
$register($router);
$router->dispatch(Request::capture());
