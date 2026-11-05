<?php

declare(strict_types=1);

namespace Orbit;

final class PublicController extends Controller
{
    public function health(Request $request): void
    {
        $database = 'ok';
        try {
            $this->app->pdo()->query('SELECT 1');
        } catch (\Throwable) {
            $database = 'error';
        }
        Response::ok('ORBIT API is running.', [
            'database' => $database,
            'google' => trim((string) ($this->app->config['google']['client_id'] ?? '')) !== '' ? 'configured' : 'disabled',
            'fcm' => $this->app->get(FcmService::class)->configured() ? 'configured' : 'fallback',
            'time' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    public function contact(Request $request): void
    {
        $this->app->get(RateLimiter::class)->hit('contact:' . client_ip($this->app->config), 5, 3600);
        $name = clean_text($request->input('name', ''), 120);
        $email = strtolower(trim((string) $request->input('email', '')));
        $message = trim((string) $request->input('message', ''));
        if (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($message) < 8 || mb_strlen($message) > 4000) {
            throw new HttpException(422, 'Enter your name, a valid email, and a message.');
        }
        $this->app->get(AdminService::class)->saveContact($name, $email, $message, client_ip($this->app->config));
        Response::ok('Message received. Thank you.', null, 201);
    }

    public function latestRelease(Request $request): void
    {
        Response::ok('Release loaded.', $this->app->get(AdminService::class)->latestRelease());
    }

    public function downloadRelease(Request $request): void
    {
        $file = $this->app->get(AdminService::class)->releaseFile($this->intParam($request, 'id'));
        $this->app->get(MediaService::class)->serve($file['path'], $file['name'], false);
    }
}
