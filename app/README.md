# Kurash Competition Manager

PHP application for managing Kurash championships, athlete registration,
weigh-ins, competition draws, brackets, results, medals, and scoreboard
webhooks.

## Layout

```
kurash-project/
├── app/     the application — this folder becomes public_html on the server
├── data/    the SQLite database, deliberately OUTSIDE the web root
└── tests/   acceptance and smoke tests
```

## Run locally

Requirements: PHP 8.3 with the `pdo_sqlite` extension.

```sh
php setup.php
php -S localhost:8000
```

Then open <http://localhost:8000/welcome.php>.

`setup.php` creates `../data/kurash.db` and prints a one-time random
administrator password. Write it down — it is not stored anywhere else and is
not shown again. Running `setup.php` a second time will not duplicate tables,
seed data, or the user.

If `pdo_sqlite` is not available on your machine, everything runs in Docker
instead:

```sh
docker run --rm -v "$PWD/..":/proj -w /proj/app -u "$(id -u):$(id -g)" \
    php:8.3-cli php setup.php
```

## Configuration

All settings come from the environment, so no secret is ever committed.

| Variable | Purpose |
| --- | --- |
| `KURASH_DB` | Absolute path to the SQLite file. Must be outside the web root. Defaults to `../data/kurash.db`. |
| `SCOREBOARD_WEBHOOK_SECRET` | Shared secret for `scoreboard-webhook.php`. **The webhook refuses every request until this is set.** |
| `KURASH_DEBUG` | `1` shows errors in the browser. Anything else logs them only. Never set this on a live server. |
| `KURASH_TZ` | Timezone, defaults to `UTC`. |

## Tests

```sh
./tests/run.sh
```

Runs a complete mock tournament in a throwaway database: signs in, checks the
guards on the bracket generator, generates an 8-athlete bracket and a
3-athlete bracket with a bye, posts every result through the scoreboard
webhook, and asserts the correct four athletes reach the podium.

`tests/smoke.php` requests every page as a signed-in administrator and confirms
the database is not reachable over HTTP.

## Hosting

This is a server-side PHP application with a writable SQLite database.

- GitHub is appropriate for source control, but GitHub Pages cannot run PHP.
- Vercel Functions have a read-only filesystem and cannot persist SQLite
  writes, so Vercel is not suitable without moving the database to a managed
  database service and adapting the application.
- On DirectAdmin, point the domain at `app/` and keep `data/` outside it —
  for example `/home/user/kurash-data/kurash.db`, with `KURASH_DB` set to
  match. `app/.htaccess` denies database files as a second layer, but the path
  is what actually protects them.

Never commit a production database, `.env` file, API key, or webhook secret.
