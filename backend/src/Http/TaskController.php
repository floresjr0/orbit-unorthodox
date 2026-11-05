<?php

declare(strict_types=1);

namespace Orbit;

final class TaskController extends Controller
{
    public function index(Request $request): void
    {
        Response::ok('Tasks loaded.', $this->app->get(TaskService::class)->list($this->user($request), $request));
    }

    public function calendar(Request $request): void
    {
        Response::ok('Calendar loaded.', $this->app->get(TaskService::class)->calendar($this->user($request), $request));
    }

    public function create(Request $request): void
    {
        $task = $this->app->get(TaskService::class)->create($this->user($request), $this->intParam($request, 'id'), $request->body);
        Response::ok('Task created.', $task, 201);
    }

    public function show(Request $request): void
    {
        Response::ok('Task loaded.', $this->app->get(TaskService::class)->get($this->user($request), $this->intParam($request, 'id')));
    }

    public function update(Request $request): void
    {
        Response::ok('Task updated successfully.', $this->app->get(TaskService::class)->update($this->user($request), $this->intParam($request, 'id'), $request->body));
    }

    public function delete(Request $request): void
    {
        $this->app->get(TaskService::class)->delete($this->user($request), $this->intParam($request, 'id'));
        Response::ok('Task deleted.');
    }

    public function status(Request $request): void
    {
        $task = $this->app->get(TaskService::class)->changeStatus(
            $this->user($request),
            $this->intParam($request, 'id'),
            (string) $request->input('status', ''),
            $request->input('base_version') !== null ? (int) $request->input('base_version') : null,
            $request->input('base_status') !== null ? (string) $request->input('base_status') : null,
            $request->input('note') !== null ? (string) $request->input('note') : null
        );
        Response::ok('Task updated successfully.', $task);
    }

    public function review(Request $request): void
    {
        Response::ok('Review saved.', $this->app->get(TaskService::class)->review($this->user($request), $this->intParam($request, 'id'), $request->body));
    }

    public function activity(Request $request): void
    {
        Response::ok('Activity loaded.', $this->app->get(TaskService::class)->activity($this->user($request), $this->intParam($request, 'id')));
    }

    public function comments(Request $request): void
    {
        Response::ok('Comments loaded.', $this->app->get(TaskService::class)->comments($this->user($request), $this->intParam($request, 'id')));
    }

    public function addComment(Request $request): void
    {
        $comment = $this->app->get(TaskService::class)->addComment(
            $this->user($request),
            $this->intParam($request, 'id'),
            (string) $request->input('body', ''),
            (string) $request->input('comment_type', 'comment')
        );
        Response::ok('Comment saved.', $comment, 201);
    }

    public function updateComment(Request $request): void
    {
        Response::ok('Comment updated.', $this->app->get(TaskService::class)->updateComment($this->user($request), $this->intParam($request, 'id'), (string) $request->input('body', '')));
    }

    public function deleteComment(Request $request): void
    {
        $this->app->get(TaskService::class)->deleteComment($this->user($request), $this->intParam($request, 'id'));
        Response::ok('Comment removed.');
    }

    public function addChecklist(Request $request): void
    {
        Response::ok('Checklist updated.', $this->app->get(TaskService::class)->addChecklistItem($this->user($request), $this->intParam($request, 'id'), (string) $request->input('content', '')));
    }

    public function toggleChecklist(Request $request): void
    {
        $base = $request->input('base_is_done');
        $task = $this->app->get(TaskService::class)->toggleChecklistItem(
            $this->user($request),
            $this->intParam($request, 'id'),
            !empty($request->input('is_done')),
            $base === null ? null : (bool) $base
        );
        Response::ok('Checklist updated.', $task);
    }

    public function deleteChecklist(Request $request): void
    {
        Response::ok('Checklist updated.', $this->app->get(TaskService::class)->deleteChecklistItem($this->user($request), $this->intParam($request, 'id')));
    }

    public function addAttachment(Request $request): void
    {
        Response::ok('Attachment added.', $this->app->get(TaskService::class)->addAttachment($this->user($request), $this->intParam($request, 'id'), $request->files), 201);
    }

    public function downloadAttachment(Request $request): void
    {
        $file = $this->app->get(TaskService::class)->attachmentForDownload($this->user($request), $this->intParam($request, 'id'));
        $inline = str_starts_with((string) $file['mime_type'], 'image/');
        $this->app->get(MediaService::class)->serve($file['path'], $file['original_name'], $inline);
    }

    public function deleteAttachment(Request $request): void
    {
        $this->app->get(TaskService::class)->deleteAttachment($this->user($request), $this->intParam($request, 'id'));
        Response::ok('Attachment removed.');
    }
}
