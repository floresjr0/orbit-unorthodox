# Testing

## API smoke

With the built-in server running:

```
php backend/bin/smoke.php
```

The script registers two users, creates a team, joins it, assigns a task, walks verification through reject and approve, checks a stale status conflict (HTTP 409), replays a sync operation, and checks dashboard counts, CSV export, search, calendar, device registration, an unconfigured Google login (HTTP 503), password reset, contact, and a non-admin blocked from `/admin/stats`.

The last full run against this API, after the response helper namespace fix, printed `All smoke checks passed.`

Registration is limited to 8 attempts per hour per IP. A later smoke run from the same machine can return HTTP 429 until that window passes. The script creates addresses like `leader{time}@orbit.test`.

## Cron

`php backend/bin/cron.php` was run twice after a recurring task existed. The first run reported `Spawned 2 task(s)`. The second reported `Spawned 0 task(s)`, which is the duplicate guard.

A separate check moved one open task’s deadline to 30 minutes ahead. The next cron run reported `Sent 2 reminder(s)` and wrote `deadline_reminder` rows for the two users on that task. A second identical reminder for the same deadline is blocked by the notification dedupe key.

## Browser

Checked on `http://127.0.0.1:8080` with the built-in server:

- Sign-in as an existing leader reached the dashboard. The counts (2 teams, 2 members, 4 tasks, 1 done) came from the database, along with the upcoming task, activity, and the “Studio check-in” announcement.
- Teams listed Private Orbit and Studio Lab. Studio Lab showed the invitation code, both members, task counts, and the announcement.
- Opening “Private task” and choosing In progress saved the status. The history line “Status changed to in progress.” appeared with the current time.
- The landing page, download page, and admin desk loaded. The download page states that no APK is published, and the latest-release API returns `data: null`.
- Admin sign-in showed 5 users, 4 teams, 8 tasks, and the contact messages. A message posted to `POST /api/v1/contact` appeared in that list.
- `GET /admin/` redirects to `admin/index.html` through `router.php`.

Not exercised in the browser: a fresh registration (the hourly limit may already be spent), Google Sign-In (no client id), an offline status edit, or an installed Android build. The Capacitor config is in `mobile/`; run `npx cap add android` when Node and the Android SDK are available.
