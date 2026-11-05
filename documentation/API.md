# API

Base path: `/api/v1`.

Success:

```json
{ "success": true, "message": "Task updated successfully.", "data": {} }
```

Failure:

```json
{ "success": false, "message": "You are not authorized to perform this action.", "data": null }
```

Send `Authorization: Bearer <access_token>` on protected routes. Access tokens last 15 minutes. Refresh with `POST /auth/refresh` and body `{ "refresh_token": "..." }`. A reused refresh token revokes that session family. Logout and password changes revoke sessions immediately, so an unexpired access token from a revoked session is rejected.

## Public

- `GET /health`
- `GET /auth/providers`
- `POST /auth/register` — `full_name`, `email`, `password`, `password_confirmation`
- `POST /auth/login` — `email`, `password`
- `POST /auth/google` — `id_token` (503 when Google is not configured)
- `POST /auth/refresh`
- `POST /auth/forgot-password`
- `POST /auth/reset-password`
- `POST /contact`
- `GET /releases/latest`
- `GET /releases/{id}/download`

## Signed in

- `POST /auth/logout`, `POST /auth/logout-all`, `GET /auth/me`
- `GET /auth/sessions`, `DELETE /auth/sessions/{id}`
- `PATCH /users/me`, `POST /users/me/avatar`, `POST /users/me/password`
- `GET` and `PUT /users/me/preferences`
- `DELETE /users/me` — current password, or `confirmation: "DELETE"` for Google-only accounts
- `POST /users/{id}/report`
- `POST /devices`, `DELETE /devices`
- `GET /dashboard`, `GET /search?q=`, `GET /calendar?from=&to=&scope=mine|team`
- `GET /notifications`, `POST /notifications/read`, `POST /notifications/{id}/read`
- `POST /sync/push`, `GET /sync/pull?since=`
- `GET /teams`, `POST /teams`, `GET /teams/{id}`, `PATCH /teams/{id}`
- `POST /teams/{id}/avatar`, `POST /teams/{id}/archive`, `DELETE /teams/{id}`
- `POST /teams/{id}/transfer`, `POST /teams/{id}/leave`
- `GET /teams/{id}/members`, `DELETE /teams/{id}/members/{userId}`
- `POST /teams/{id}/members/{userId}/approve`, `POST /teams/{id}/members/{userId}/reject`
- `GET` and `PUT /teams/{id}/invitation`, `POST /teams/{id}/invitation/regenerate`
- `POST /invitations/join` — `{ "code": "ABCD-EFGH-IJKL" }`
- `GET` and `POST /teams/{id}/announcements`, `DELETE /announcements/{id}`
- `GET /teams/{id}/report`, `GET /teams/{id}/report.csv`
- `GET /tasks`, `POST /teams/{id}/tasks`, `GET /tasks/{id}`, `PATCH /tasks/{id}`, `DELETE /tasks/{id}`
- `POST /tasks/{id}/status`, `POST /tasks/{id}/review`
- `GET /tasks/{id}/activity`, `GET` and `POST /tasks/{id}/comments`
- `PATCH` and `DELETE /comments/{id}`
- `POST /tasks/{id}/checklist`, `PATCH` and `DELETE /checklist-items/{id}`
- `POST /tasks/{id}/attachments`, `GET` and `DELETE /attachments/{id}`

List endpoints accept `page` and `per_page`. Task lists accept `scope`, `status` (`awaiting` is a review filter), `priority`, `q`, `due` (`today`, `overdue`, `upcoming`), and `team_id`.

## Status changes

`POST /tasks/{id}/status` body:

```json
{ "status": "in_progress", "base_version": 3, "base_status": "todo", "client_op_id": "optional" }
```

If `base_version` is stale but the server status still equals `base_status`, the update applies. If the status also differs, the response is HTTP 409 and includes the current task. The client must not invent a new version number.

When the team requires verification, a member marking a task done sets `review_status` to `awaiting` and leaves the working status short of done. A leader approves or rejects with `POST /tasks/{id}/review` and `{ "decision": "approved"|"rejected", "feedback": "", "status": "in_progress" }`.

## Sync

`POST /sync/push` accepts `{ "operations": [ { "client_op_id", "type", "payload" } ] }`. Types include `task.status`, `task.comment`, and `task.checklist`. The pair `(user_id, client_op_id)` is unique. Replaying an operation returns the stored result.

`GET /sync/pull?since=` returns teams, tasks, comments, announcements, and notifications changed since that UTC timestamp.

## Platform admin

These require a row in `platform_admins`. They do not return task titles.

- `GET /admin/stats`, `GET /admin/users`
- `POST /admin/users/{id}/suspend`, `POST /admin/users/{id}/reactivate`
- `GET /admin/reports`, `POST /admin/reports/{id}/resolve`
- `GET /admin/audit`, `GET /admin/messages`, `GET /admin/releases`
- `POST /admin/releases` — multipart `version`, `changelog`, and `file` (`.apk`)

## Authorization

A user who is not an active member of a team receives 404 for that team’s tasks, so changing an id does not reveal another team. Members can read team tasks and can change status, checklist items, and comments only on tasks assigned to them. Archived teams are read-only except for a leader restoring them. Registration, login, password reset, contact, and invitation joins are rate limited.
