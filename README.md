# ORBIT

ORBIT is a team collaboration and task manager. Leaders create teams, invite members with a code, and assign work. Members update their own tasks. The phone keeps a local copy and syncs when the network returns.

- `mobile/` — Capacitor app (HTML, CSS, vanilla JavaScript, IndexedDB or SQLite)
- `website/` — public site
- `admin/` — platform administration
- `backend/` — PHP 8 REST API
- `database/schema.sql` — MySQL install
- `documentation/` — setup, API, schema, deployment, and tests

Local API, with the PHP built-in server from this folder:

`http://127.0.0.1:8080/api/v1`

The site is `http://127.0.0.1:8080/website/index.html`. The app is `http://127.0.0.1:8080/mobile/www/index.html`. Platform admin is `http://127.0.0.1:8080/admin/`.

Start with [documentation/DEVELOPMENT.md](documentation/DEVELOPMENT.md).
