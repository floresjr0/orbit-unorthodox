<?php

declare(strict_types=1);

namespace Orbit;

final class UserController extends Controller
{
    public function update(Request $request): void
    {
        Response::ok('Profile updated.', $this->app->get(UserService::class)->update($this->user($request), $request->body));
    }

    public function avatar(Request $request): void
    {
        Response::ok('Profile image updated.', $this->app->get(UserService::class)->avatar($this->user($request), $request->files));
    }

    public function password(Request $request): void
    {
        $this->app->get(UserService::class)->changePassword($this->user($request), $request->body);
        Response::ok('Password updated. Sign in again on your devices.');
    }

    public function preferences(Request $request): void
    {
        $prefs = $this->app->get(NotificationService::class)->preferences((int) $this->user($request)['id']);
        Response::ok('Preferences loaded.', $this->app->get(NotificationService::class)->publicPreferences($prefs));
    }

    public function updatePreferences(Request $request): void
    {
        $data = $this->app->get(NotificationService::class)->updatePreferences((int) $this->user($request)['id'], $request->body);
        Response::ok('Preferences saved.', $data);
    }

    public function delete(Request $request): void
    {
        $this->app->get(UserService::class)->delete($this->user($request), $request->body);
        Response::ok('Account deleted.');
    }

    public function report(Request $request): void
    {
        $this->app->get(UserService::class)->report(
            $this->user($request),
            $this->intParam($request, 'id'),
            (string) $request->input('reason', '')
        );
        Response::ok('Report submitted.', null, 201);
    }

    public function registerDevice(Request $request): void
    {
        $this->app->get(UserService::class)->registerDevice($this->user($request), $request->body);
        Response::ok('Device registered.');
    }

    public function removeDevice(Request $request): void
    {
        $this->app->get(UserService::class)->removeDevice($this->user($request), (string) $request->input('token', ''));
        Response::ok('Device removed.');
    }
}
