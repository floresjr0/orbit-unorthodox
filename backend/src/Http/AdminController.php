<?php

declare(strict_types=1);

namespace Orbit;

final class AdminController extends Controller
{
    public function stats(Request $request): void
    {
        Response::ok('Platform statistics loaded.', $this->app->get(AdminService::class)->stats());
    }

    public function users(Request $request): void
    {
        [$page, $perPage, $offset] = page_params($request);
        Response::ok('Users loaded.', $this->app->get(AdminService::class)->users((string) ($request->query['q'] ?? ''), $page, $perPage, $offset));
    }

    public function suspend(Request $request): void
    {
        $this->app->get(AdminService::class)->setStatus($this->user($request), $this->intParam($request, 'id'), 'suspended');
        Response::ok('Account suspended.');
    }

    public function reactivate(Request $request): void
    {
        $this->app->get(AdminService::class)->setStatus($this->user($request), $this->intParam($request, 'id'), 'active');
        Response::ok('Account reactivated.');
    }

    public function reports(Request $request): void
    {
        [$page, $perPage, $offset] = page_params($request);
        Response::ok('Reports loaded.', $this->app->get(AdminService::class)->reports($page, $perPage, $offset));
    }

    public function resolve(Request $request): void
    {
        $this->app->get(AdminService::class)->resolveReport($this->user($request), $this->intParam($request, 'id'), (string) $request->input('status', ''));
        Response::ok('Report updated.');
    }

    public function audit(Request $request): void
    {
        [$page, $perPage, $offset] = page_params($request);
        Response::ok('Audit log loaded.', $this->app->get(AdminService::class)->audit($page, $perPage, $offset));
    }

    public function messages(Request $request): void
    {
        [$page, $perPage, $offset] = page_params($request);
        Response::ok('Messages loaded.', $this->app->get(AdminService::class)->messages($page, $perPage, $offset));
    }

    public function releases(Request $request): void
    {
        Response::ok('Releases loaded.', $this->app->get(AdminService::class)->releases());
    }

    public function createRelease(Request $request): void
    {
        $input = $request->body ?: $request->post;
        Response::ok('Release published.', $this->app->get(AdminService::class)->createRelease($this->user($request), $input, $request->files), 201);
    }
}
