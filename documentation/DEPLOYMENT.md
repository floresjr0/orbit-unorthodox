# Deployment

The site, the API, and the admin desk are one PHP application. A typical layout on shared hosting is the contents of this project as the document root, or the project in a subdirectory.

The host needs:

- HTTPS
- PHP 8.1 or newer, with PDO MySQL, curl, gd, and json
- MySQL or MariaDB
- Cron, at least every five minutes
- Outbound HTTPS, for Google token checks and Firebase Cloud Messaging

## Steps

1. Upload the project. Do not upload `backend/config/config.php` from your laptop if it contains the local empty root password.
2. Create `backend/config/config.php` on the server from `config.example.php`. Set a long `app.key`, the production database user, `env` to `production`, and `debug` to `false`. Set `public_url` to the HTTPS origin.
3. Create the database and import `database/schema.sql`, or run `php backend/bin/migrate.php` once. Do not use `--fresh` on a server that already has data.
4. Point the document root at the project. Apache should honor the root `.htaccess`, which blocks `backend/`, `database/`, and `documentation/`. The API rewrite lives in `api/.htaccess`.
5. Make `backend/storage/` writable by PHP and not publicly readable. The storage `.htaccess` denies web access.
6. Create the platform admin with `php backend/bin/create-admin.php` and a new password. Do not reuse a local development password.
7. Cron entry:

```
*/5 * * * * php /path/to/orbit/backend/bin/cron.php
```

8. For Android push, place the Firebase service account JSON outside the document root if the host allows it, and set `fcm.project_id` plus `fcm.service_account` to that path. Without those values, reminders are still stored in the database and written to `storage/logs/push.log`.
9. Set `google.client_id` to enable Google Sign-In. Leave it empty to keep that route unavailable.
10. Set `mail.enabled` and the from address when the host can send mail. Until then, reset links stay in the log and should not be used for real accounts.

Publish APKs from `/admin/` after you have a signed build. The download page will not offer a file that is not on disk.

The Android app’s API base must be the production `https://your-domain/api/v1`. Change it on the sign-in screen, or build with that default if you later set it in `mobile/www/js/config.js` for native builds. The native default is the emulator address, which is correct only for local Android testing.
