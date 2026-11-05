# Database

Install with `database/schema.sql`. Times are stored in UTC. The connection sets `time_zone = '+00:00'`.

| Table | Role |
| --- | --- |
| users | Account, password hash, status, theme, timezone |
| user_profiles | Organization, title, phone, bio |
| platform_admins | Platform operators, separate from team leaders |
| refresh_tokens | Hashed refresh tokens, family, revocation |
| password_resets | Hashed reset tokens |
| teams | Team record, leader, verification and approval flags |
| team_members | Membership role and status, unique per user and team |
| team_invitations | Code, expiry, max uses, active flag |
| tasks | Status, review status, version, recurrence, soft delete |
| task_assignees | Many members per task |
| task_checklists / task_checklist_items | Optional checklist |
| task_comments | Comments and progress notes, soft delete |
| task_attachments | Stored files |
| task_activity_logs | Append-only history |
| task_reviews | Approval and rejection records |
| announcements | Team announcements |
| notifications | In-app rows, unique dedupe key per user |
| notification_preferences | Category and reminder switches |
| device_tokens | FCM tokens, many per user |
| sync_operations | Idempotent offline operations |
| audit_logs | Platform or team scope |
| rate_limits | Authentication and invite windows |
| user_reports | Account reports for platform review |
| contact_messages | Website contact form |
| app_releases | Published APK metadata |
| schema_migrations | Install marker |

A task belongs to one team. A user can belong to many teams. Recurring templates set `is_template = 1` and are hidden from lists. Each generated instance stores `series_id` and `occurrence_on`, unique together, so a cron run cannot insert the same occurrence twice.

Soft deletion is used for users, teams, tasks, comments, and announcements. Activity rows are not deleted when a task is updated.

Back up the MySQL database and `backend/storage/` (avatars, attachments, release APKs) together. Restoring one without the other leaves broken file links.
