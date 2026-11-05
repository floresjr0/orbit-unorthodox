<?php

declare(strict_types=1);

namespace Orbit;

final class WorkspaceController extends Controller
{
    public function dashboard(Request $request): void
    {
        $teamId = (int) ($request->query['team_id'] ?? 0);
        Response::ok('Dashboard loaded.', $this->app->get(ReportService::class)->dashboard($this->user($request), $teamId ?: null));
    }

    public function search(Request $request): void
    {
        $type = (string) ($request->query['type'] ?? 'all');
        if (!in_array($type, ['all', 'tasks', 'members', 'teams', 'announcements'], true)) {
            $type = 'all';
        }
        Response::ok('Search complete.', $this->app->get(SearchService::class)->search($this->user($request), (string) ($request->query['q'] ?? ''), $type));
    }

    public function report(Request $request): void
    {
        Response::ok('Report ready.', $this->app->get(ReportService::class)->teamReport($this->user($request), $this->intParam($request, 'id')));
    }

    public function export(Request $request): void
    {
        $report = $this->app->get(ReportService::class)->teamReport($this->user($request), $this->intParam($request, 'id'));
        Response::download('orbit-team-' . $report['team']['id'] . '-report.csv', $this->app->get(ReportService::class)->csv($report), 'text/csv; charset=utf-8');
    }

    public function notifications(Request $request): void
    {
        [$page, $perPage, $offset] = page_params($request);
        $data = $this->app->get(NotificationService::class)->listFor(
            (int) $this->user($request)['id'],
            $page,
            $perPage,
            $offset,
            ($request->query['unread'] ?? '') === '1'
        );
        Response::ok('Notifications loaded.', $data);
    }

    public function readAll(Request $request): void
    {
        $this->app->get(NotificationService::class)->markRead((int) $this->user($request)['id']);
        Response::ok('Notifications marked as read.');
    }

    public function readOne(Request $request): void
    {
        $this->app->get(NotificationService::class)->markRead((int) $this->user($request)['id'], $this->intParam($request, 'id'));
        Response::ok('Notification marked as read.');
    }

    public function push(Request $request): void
    {
        $ops = $request->input('operations', []);
        if (!is_array($ops)) {
            throw new HttpException(422, 'Operations must be a list.');
        }
        Response::ok('Synchronization processed.', [
            'results' => $this->app->get(SyncService::class)->push($this->user($request), $ops),
        ]);
    }

    public function pull(Request $request): void
    {
        $since = $request->query['since'] ?? null;
        Response::ok('Synchronization loaded.', $this->app->get(SyncService::class)->pull($this->user($request), is_string($since) ? $since : null));
    }

    public function avatar(Request $request): void
    {
        $userId = $this->intParam($request, 'id');
        if (!$this->app->get(Access::class)->sharesTeam((int) $this->user($request)['id'], $userId)) {
            throw new HttpException(404, 'File not found.');
        }
        $user = $this->app->get(AuthService::class)->findById($userId);
        if (!$user || empty($user['avatar_path'])) {
            throw new HttpException(404, 'File not found.');
        }
        $path = $this->app->get(MediaService::class)->path('avatars', $user['avatar_path']);
        $this->app->get(MediaService::class)->serve($path, 'avatar', true);
    }

    public function teamAvatar(Request $request): void
    {
        $teamId = $this->intParam($request, 'id');
        $this->app->get(Access::class)->requireActiveMember($teamId, (int) $this->user($request)['id']);
        $stmt = $this->app->pdo()->prepare('SELECT avatar_path FROM teams WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$teamId]);
        $name = $stmt->fetchColumn();
        if (!$name) {
            throw new HttpException(404, 'File not found.');
        }
        $path = $this->app->get(MediaService::class)->path('teams', (string) $name);
        $this->app->get(MediaService::class)->serve($path, 'team', true);
    }
}
