# Kurash Competition Manager — the application

This directory is the Laravel application. **The full documentation lives in the
[README at the repository root](../README.md)** — what the system does, how the
rules are encoded, roles, exports, displays, scoreboards, deployment and the
database-safety rules. One document, kept in one place, so the two cannot drift.

Working notes for anyone changing this code are in [CLAUDE.md](CLAUDE.md).

## The commands you need here

```sh
../dev.sh              # start the database and the app (run from the repo root)

composer test          # config:clear, pint --test, phpstan, artisan test
composer lint          # Pint, Laravel preset
composer types:check   # PHPStan level 7 + Larastan
php artisan test       # Pest 4 — do NOT use --parallel, see the root README

php artisan kurash:demo --fresh-all      # fill a championship to look at
php artisan kurash:backup --label=...    # dump the database, compressed
php artisan kurash:create-admin          # the first account
npm run build                            # assets — commit public/build
```

> **Never run a destructive database command.** `migrate:fresh`,
> `migrate:refresh`, `migrate:reset`, `migrate:rollback`, `db:wipe`, `db:seed`,
> `DROP`, `TRUNCATE`, `DELETE FROM`. Plain `migrate` is fine and is the only
> schema command you need. Rebuilding a local database has exactly one path:
> `../scripts/reset-local-database.sh`. See the root README for why.
