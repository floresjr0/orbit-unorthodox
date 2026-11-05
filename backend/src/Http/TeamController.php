<?php

declare(strict_types=1);

namespace Orbit;

final class TeamController extends Controller
{
    public function index(Request $request): void
    {
        Response::ok('Teams loaded.', $this->app->get(TeamService::class)->listFor($this->user($request)));
    }

    public function create(Request $request): void
    {
        Response::ok('Team created.', $this->app->get(TeamService::class)->create($this->user($request), $request->body), 201);
    }

    public function show(Request $request): void
    {
        Response::ok('Team loaded.', $this->app->get(TeamService::class)->get($this->user($request), $this->intParam($request, 'id')));
    }

    public function update(Request $request): void
    {
        Response::ok('Team updated.', $this->app->get(TeamService::class)->update($this->user($request), $this->intParam($request, 'id'), $request->body));
    }

    public function avatar(Request $request): void
    {
        Response::ok('Team image updated.', $this->app->get(TeamService::class)->setAvatar($this->user($request), $this->intParam($request, 'id'), $request->files));
    }

    public function archive(Request $request): void
    {
        $team = $this->app->get(TeamService::class)->archive($this->user($request), $this->intParam($request, 'id'), !empty($request->input('restore')));
        Response::ok(!empty($request->input('restore')) ? 'Team restored.' : 'Team archived.', $team);
    }

    public function delete(Request $request): void
    {
        $this->app->get(TeamService::class)->delete($this->user($request), $this->intParam($request, 'id'));
        Response::ok('Team deleted.');
    }

    public function transfer(Request $request): void
    {
        $team = $this->app->get(TeamService::class)->transfer(
            $this->user($request),
            $this->intParam($request, 'id'),
            (int) $request->input('user_id', 0)
        );
        Response::ok('Leadership transferred.', $team);
    }

    public function leave(Request $request): void
    {
        $this->app->get(TeamService::class)->leave($this->user($request), $this->intParam($request, 'id'));
        Response::ok('You left the team.');
    }

    public function members(Request $request): void
    {
        Response::ok('Members loaded.', $this->app->get(TeamService::class)->members($this->user($request), $this->intParam($request, 'id')));
    }

    public function removeMember(Request $request): void
    {
        $this->app->get(TeamService::class)->removeMember($this->user($request), $this->intParam($request, 'id'), $this->intParam($request, 'userId'));
        Response::ok('Member removed.');
    }

    public function approve(Request $request): void
    {
        $this->app->get(TeamService::class)->decideJoin($this->user($request), $this->intParam($request, 'id'), $this->intParam($request, 'userId'), true);
        Response::ok('Member approved.');
    }

    public function reject(Request $request): void
    {
        $this->app->get(TeamService::class)->decideJoin($this->user($request), $this->intParam($request, 'id'), $this->intParam($request, 'userId'), false);
        Response::ok('Request declined.');
    }

    public function invitation(Request $request): void
    {
        Response::ok('Invitation loaded.', $this->app->get(TeamService::class)->invitation($this->user($request), $this->intParam($request, 'id')));
    }

    public function updateInvitation(Request $request): void
    {
        Response::ok('Invitation updated.', $this->app->get(TeamService::class)->updateInvitation($this->user($request), $this->intParam($request, 'id'), $request->body));
    }

    public function regenerate(Request $request): void
    {
        Response::ok('Invitation code regenerated.', $this->app->get(TeamService::class)->regenerate($this->user($request), $this->intParam($request, 'id')));
    }

    public function join(Request $request): void
    {
        Response::ok('Invitation accepted.', $this->app->get(TeamService::class)->join($this->user($request), (string) $request->input('code', '')));
    }

    public function announcements(Request $request): void
    {
        Response::ok('Announcements loaded.', $this->app->get(TeamService::class)->announcements($this->user($request), $this->intParam($request, 'id')));
    }

    public function createAnnouncement(Request $request): void
    {
        $item = $this->app->get(TeamService::class)->createAnnouncement($this->user($request), $this->intParam($request, 'id'), $request->body);
        Response::ok('Announcement published.', $item, 201);
    }

    public function deleteAnnouncement(Request $request): void
    {
        $this->app->get(TeamService::class)->deleteAnnouncement($this->user($request), $this->intParam($request, 'id'));
        Response::ok('Announcement removed.');
    }
}
