# Local development

Requirements: PHP 8.2+, MariaDB or MySQL (XAMPP is enough), and a browser. Android builds also need Node.js, Android Studio, and a device or emulator.

## Database

1. Copy `backend/config/config.example.php` to `backend/config/config.php`.
2. Set the database user. The XAMPP default is `root` with an empty password and database name `orbit`.
3. Replace `app.key` with 32 or more random bytes. The API refuses to boot on the example key.
4. Install the schema:

```
php backend/bin/migrate.php --fresh
```

`--fresh` drops the `orbit` database. Use it only on a local machine. Later schema changes belong in a new file under `database/migrations/` and should be applied by hand or by extending the runner. The current install script is `database/schema.sql`.

## API

From the project root:

```
php -S 127.0.0.1:8080 router.php
```

Apache under XAMPP can serve the same folder at `http://localhost/orbit-unorthodox/`. The app detects that path and calls `/orbit-unorthodox/api/v1`. On the built-in server the base is `http://127.0.0.1:8080/api/v1`. The sign-in screen has a Server address field if you need to point the app elsewhere. An Android emulator uses `http://10.0.2.2/orbit-unorthodox/api/v1` unless you change it.

Check `GET /api/v1/health`. It should report the database as connected.

## Platform admin

Create a local administrator. Change this password before any shared or public install:

```
php backend/bin/create-admin.php --email=admin@orbit.local --password=OrbitAdmin1 --name="Platform Admin"
```

Open `/admin/` and sign in with that account. A normal member who signs in there receives an authorization error and is not shown the desk.

## Cron

Deadline reminders and recurring tasks run from:

```
php backend/bin/cron.php
```

On Windows Task Scheduler or a host cron, run that every five minutes. The script locks `backend/storage/cache/cron.lock` so two runs do not overlap. The second run does not create a second copy of a recurring task that was already spawned.

## Mail, Google, and Firebase

Leave `mail.enabled` false locally. Password reset links are appended to `backend/storage/logs/mail.log`.

Google Sign-In stays off until `google.client_id` is the web client ID from Google Cloud. The API checks the ID token with Google. The app does not accept a name and email posted by the phone as proof of a Google login.

Push notifications stay in `backend/storage/logs/push.log` until `fcm.project_id` is set and `fcm.service_account` points at a Firebase service account JSON file that is not in the repository. The phone never receives that file. Register device tokens with `POST /api/v1/devices`.

## Capacitor and Android

```
cd mobile
npm install
npx cap add android
npx cap sync android
npx cap open android
```

`mobile/android/` is generated and ignored by git. Build a signed APK in Android Studio, then publish it from the platform admin Releases tab. The public download page only links a file that exists on disk.

The browser app uses IndexedDB. On Android, the SQLite plugin stores the same records when `Capacitor.Plugins.CapacitorSQLite` is present.

## What not to commit

`backend/config/config.php`, `backend/storage/`, uploaded avatars, release APKs, and Firebase service account files.
