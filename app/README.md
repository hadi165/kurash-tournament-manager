# Kurash Competition Manager

PHP application for managing Kurash championships, athlete registration,
weigh-ins, competition draws, brackets, results, medals, and scoreboard
webhooks.

## Run locally

Requirements: PHP with the `pdo_sqlite` extension enabled.

```sh
php setup.php
php -S localhost:8000
```

Then open <http://localhost:8000/welcome.php>.

`setup.php` creates `kurash.db`, which is intentionally not committed. It also
creates a default administrator for local preview; change those credentials
before using the system with real competition data.

## Hosting

This is a server-side PHP application with a writable SQLite database.

- GitHub is appropriate for source control, but GitHub Pages cannot run PHP.
- Vercel Functions have a read-only filesystem and cannot persist SQLite
  writes, so Vercel is not suitable without moving the database to a managed
  database service and adapting the application.
- Deploy the current application to a PHP-capable host with persistent storage
  and the `pdo_sqlite` extension, then configure `SCOREBOARD_WEBHOOK_SECRET`
  for the webhook endpoint.

Never commit a production database, `.env` file, API key, or webhook secret.
