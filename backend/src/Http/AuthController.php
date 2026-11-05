<?php

declare(strict_types=1);

namespace Orbit;

final class AuthController extends Controller
{
    public function providers(Request $request): void
    {
        $clientId = trim((string) ($this->app->config['google']['client_id'] ?? ''));
        Response::ok('Providers loaded.', [
            'google_enabled' => $clientId !== '',
            'google_client_id' => $clientId !== '' ? $clientId : null,
        ]);
    }

    public function register(Request $request): void
    {
        Response::ok('Account created.', $this->app->get(AuthService::class)->register($request->body), 201);
    }

    public function login(Request $request): void
    {
        Response::ok('Signed in.', $this->app->get(AuthService::class)->login($request->body));
    }

    public function google(Request $request): void
    {
        Response::ok('Signed in.', $this->app->get(AuthService::class)->google((string) $request->input('id_token', '')));
    }

    public function refresh(Request $request): void
    {
        Response::ok('Session refreshed.', $this->app->get(AuthService::class)->refresh((string) $request->input('refresh_token', '')));
    }

    public function logout(Request $request): void
    {
        $this->app->get(AuthService::class)->logout((int) $request->token['sid']);
        Response::ok('Signed out.');
    }

    public function logoutAll(Request $request): void
    {
        $this->app->get(AuthService::class)->logoutAll((int) $this->user($request)['id']);
        Response::ok('Signed out on every device.');
    }

    public function forgot(Request $request): void
    {
        $this->app->get(AuthService::class)->forgot((string) $request->input('email', ''));
        Response::ok('If an account exists for that email, password reset instructions have been sent.');
    }

    public function reset(Request $request): void
    {
        $this->app->get(AuthService::class)->reset($request->body);
        Response::ok('Password updated. Sign in with the new password.');
    }

    public function me(Request $request): void
    {
        Response::ok('Profile loaded.', Present::user($this->user($request), true));
    }

    public function sessions(Request $request): void
    {
        $user = $this->user($request);
        Response::ok('Sessions loaded.', $this->app->get(AuthService::class)->sessions((int) $user['id'], (int) $request->token['sid']));
    }

    public function revokeSession(Request $request): void
    {
        $user = $this->user($request);
        $current = $this->app->get(AuthService::class)->revokeSession(
            (int) $user['id'],
            $this->intParam($request, 'id'),
            (int) $request->token['sid']
        );
        Response::ok($current ? 'This session was revoked.' : 'Session revoked.', ['logged_out' => $current]);
    }
}
