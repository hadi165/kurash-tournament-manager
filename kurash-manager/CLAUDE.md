# Working in this codebase

The README covers what the system does and how to run it. This file is the
things that are not visible from one file, and the things that have already
cost somebody an afternoon.

## Where you are

The repository root is `/home/hadi/WorkArea/Projects/Kurash`.

- `kurash-manager/` — the application. Laravel 13, Livewire 4, Pest 4, PHP 8.3,
  MariaDB. Everything below is about this directory. The Pest suite lives in
  `kurash-manager/tests/`; there is no other test tree.
- `dev.sh` — start, stop and reset the local stack. `reset` takes a backup,
  rebuilds the schema and fills it with `kurash:demo`, which is now the only
  way to get a populated database — nothing is imported from anywhere. It
  carries the `users` table across the rebuild; see the trap below.

The legacy PHP application this system replaces used to sit at the repository
root, along with its SQLite export in `data/`, its acceptance scripts in
`tests/` and the dual-driver dev image in `tools/`. All of it has been removed,
together with the `kurash:import-legacy` command that read the export. Recover
any of it from git history if you ever need to know what an old column meant.

## Database safety — absolute

The competition database has been emptied three times. Twice by a
`migrate:fresh` aimed at what somebody believed was a scratch database, once by
an automated agent running a script it believed was harmless. Each time the
`users` table went with it: referees, officials, the administrator. Nothing
regenerates those — they are typed in by hand and their passwords survive only
as hashes. Each time the only thing that saved the event was a backup that
happened to exist.

These are rules, not preferences:

- **Never run a destructive database command.** `migrate:fresh`,
  `migrate:refresh`, `migrate:reset`, `migrate:rollback`, `db:wipe`, `db:seed`,
  `DROP`, `TRUNCATE`, `DELETE FROM`. Plain `migrate` is fine and is the only
  schema command you need.
- **Never point the test configuration at the application database.** Not to
  reproduce something, not "just this once", not with `--filter` limiting it to
  one test. The suite drops and re-migrates on every test.
- **Never delete, truncate, recreate or reseed the `users` table.**
- **Never treat local or development data as disposable.** `APP_ENV=local` on
  this project is the machine that runs the competition, with the day's
  weigh-ins in it.
- **Ask before any database reset, even when a backup exists.** A backup makes
  a reset recoverable, not correct.
- **Never run a destructive command against `kurash` to test a safeguard.**
  Name a database that does not exist instead, so a broken guard fails to
  connect rather than succeeding.

Rebuilding a local database, when that is genuinely wanted, has exactly one
path: `./scripts/reset-local-database.sh`. It backs up, exports the accounts
separately, verifies both, requires a person to type the database name at a
terminal, rebuilds, restores the accounts and checks the count came back. It
refuses when stdin is not a TTY, so no agent and no CI job can run it, and it
has no `--force`. `./dev.sh reset` now refuses and points at it.

**None of the above is what stops you.** Instructions in this file are
supplementary; three incidents happened with a model that had read them. The
enforcement is technical and is in four places:

| Layer | Where | Stops |
|---|---|---|
| Deny rules | `.claude/settings.json` | the named commands, typed directly |
| Command hook | `.claude/hooks/block-destructive-db.sh` | destructive SQL *anywhere* in a Bash command — inside `docker exec`, `bash -c`, a heredoc |
| Runtime guard | `App\Support\DatabaseGuard` | any destructive Artisan command, and the whole test suite, unless `APP_ENV=testing` **and** the resolved database ends in `_test` |
| Credentials | separate grants, see `.env.example` | the test credential reaching `kurash` at all |

`DatabaseGuard` reads Laravel's **resolved** configuration, never `getenv()`.
PHPUnit's `<env>` elements do not overwrite a variable already set in the
process environment, so a stray exported `DB_DATABASE` gives you a suite that
reads `kurash_test` in `phpunit.xml` while connecting to `kurash`. Asking the
config for the name the PDO connection will actually use is what closes that;
asking the environment reproduces it.

Backups live in `kurash-manager/storage/app/backups`, on the host filesystem —
not in `/tmp`, not inside the Docker container. **Nothing deletes them
automatically**; `kurash:backup` used to prune to 30 and no longer does.

To recover the accounts from a backup (this procedure has been used, and works):

```sh
# 1. Load the backup into a scratch database — never over the live one.
docker exec kurash-mariadb mariadb -uroot -p<root> \
    -e "DROP DATABASE IF EXISTS kurash_rescue; CREATE DATABASE kurash_rescue;"
zcat storage/app/backups/<file>.sql.gz \
    | docker exec -i kurash-mariadb mariadb -uroot -p<root> kurash_rescue

# 2. Look at what is in there before trusting it.
docker exec kurash-mariadb mariadb -uroot -p<root> kurash_rescue \
    -e "SELECT id,name,email,role FROM users ORDER BY id;"

# 3. Copy the accounts across. UPDATE row 1 in place rather than deleting it:
#    bout_events.user_id is ON DELETE SET NULL, and bout_events is append-only,
#    so a delete silently orphans every recorded result.
docker exec kurash-mariadb mariadb -uroot -p<root> -e "
  SET FOREIGN_KEY_CHECKS=0;
  INSERT INTO kurash.users SELECT * FROM kurash_rescue.users;
  UPDATE kurash.users SET scoreboard_championship_id = NULL;
  SET FOREIGN_KEY_CHECKS=1;"

# 4. Check the count, then drop the scratch database.
```

## Checks before committing

```
composer test        # config:clear, pint --test, phpstan, artisan test
```

Run it, and expect all four to pass. Individually: `composer lint` (Pint,
Laravel preset), `composer types:check` (PHPStan level 7 + Larastan),
`php artisan test`.

**Do not use `php artisan test --parallel`.** The `kurash` database user has no
grant to create the per-process `kurash_test_test_N` databases, so it fails
with an access-denied error that looks like a code fault and is not one.

## Commit messages

When creating a commit, include a short summary of the prompt/request that
led to the change, not just a description of the diff. Format:

<type>: <short summary of the change>

Prompt: <condensed 1-2 sentence version of what was asked>

<optional bullet list of specific changes, only if non-obvious>


Keep the prompt line genuinely condensed — do not paste raw conversation
context or trial-and-error steps, only the final ask.

## How code is written here

The commenting convention is the strongest one in the repo, and it is load
bearing — most of the domain knowledge lives in it. Three devices:

- **Section banners** (`/* |---- | Title | ... */`) divide a class into
  narrative sections and carry the *reasoning*, often with the rule's source
  URL. See `Athlete.php`, `WeightCategory.php`.
- **One-line docblocks** for anything self-evident, written as a full sentence
  and often as a question: `/** Has this athlete been weighed and admitted? */`
- **Multi-paragraph docblocks** for anything with a decision behind it: a
  one-line summary, then why it is the way it is, then `@param`/`@return`.

Comments say why, never what. Several of them name the bug they replaced —
keep that when you touch them. Generics are always annotated
(`@return HasMany<Athlete, $this>`).

Where things go:

| Kind | Namespace | Naming |
|---|---|---|
| Rule engine / policy | `App\Services` | `<Domain>Policy`, `<Domain>Validator` |
| Value object | `App\Support` | `<Domain>Verdict`, `<Domain>Range`, `<Domain>Band` — `final readonly`, promoted properties |
| Domain exception | `App\Services` (not `App\Exceptions`) | `<Domain>Exception extends RuntimeException`, body empty, file is all docblock |
| Rule constants a federation might change | `config/kurash.php` | one documented block per rule family |

Policies are resolved with `app(...)` at call sites or constructor-injected;
there are no container bindings for them.

New gates use dotted `noun.verb` names (`draw.publish`, `athlete.sanction_age`)
and are defined in `AppServiceProvider::configureGates()`. Every role predicate
on `User` checks `is_active` first.

## The rules this software implements

The source is the IKA competition rules:
<https://kurash-ika.org/2022/08/20/kurash-rules/>. Cite it when you encode
something from it, and **keep sourced rules separate from inferences** — where
the published rules are ambiguous or silent, say so in the config block and
flag it for federation confirmation rather than picking silently. There are
worked examples of this in `config/kurash.php` under `round_robin` and
`age_eligibility`.

Rules already centralised — go through these, do not re-derive:

- `TournamentFormatPolicy` — round robin for 2-5 athletes, knockout for 6+,
  administrative placement for 1.
- `Athlete::scopePassedWeighIn()` — admission to competition is
  `weighin_status = 'pass'`. "Not fail" is not the same question; that was a
  real bug.
- `AgeEligibilityPolicy` — age groups by birth year. Competition age is
  `competition year − birth year`, never a date-of-birth subtraction, which is
  why leap days need no special handling.
- `RoundRobinStandings`, `MedalTable` — derived from bout rows every read,
  never cached.

## Traps

- **Blade's inline `@php(...)` miscompiles inside a `@foreach`/`@forelse`.** It
  emits an unterminated `<?php(` and swallows the rest of the template; you
  see `syntax error, unexpected "endif"` or `unexpected end of file` pointing
  at a file in `storage/framework/views/`. Use `@php ... @endphp`, or inline
  the expression. This has broken `bracket.blade.php` twice. After editing
  blades, `php artisan view:clear && php artisan view:cache` and lint the
  compiled output before trusting a green test run.
- **Livewire public properties are browser-owned.** Array keys that look like
  model ids are whatever was posted. Resolve them through the parent's own
  relation before writing — see `Bracket::saveDraws()`.
- **Livewire ages flash messages** before `session('error')` can be read back
  in a test. Assert with `->assertSee(...)` instead.
- **`CarbonImmutable::createFromFormat()` throws** on a non-matching value, it
  does not return `false`. Wrap it per format or the first mismatch takes the
  request down.
- **A new composite index can steal a foreign key's supporting index.** Adding
  `(age_category_id, date_of_birth)` made MariaDB adopt it for the
  `age_category_id` constraint, and the migration then could not be rolled
  back. Check that another index still leads with the FK column, and always
  test `migrate:rollback` and re-apply.
- **`migrate:fresh` takes the accounts with it, and nothing regenerates
  them.** Competition data can be rebuilt from `kurash:demo`; the users cannot
  — they are typed in by hand and their passwords survive only as hashes.
  `./dev.sh reset` therefore dumps `users`, rebuilds, and re-inserts it, having
  taken a full `kurash:backup` first. This is not belt-and-braces: a reset run
  without it destroyed four live accounts, and the backup was the only reason
  they came back. Any other path that calls `migrate:fresh` owes the same care.
- **`ArchivedChampionshipGuard`** blocks every write to an archived
  championship's models. Tests that set up historical data must write *before*
  archiving.

## Deliberate, do not "tidy"

- Columns added for new rules are **nullable** so existing half-fought
  competitions stay readable. That is not an invitation to make the rule
  optional going forward — the form requires what the rule requires.
- `judged` and `eligible` on a verdict are **different questions**. "No rule
  covers this" is not "checked and fine", and only the first may pass a
  credentials check.
- Approval history (`bout_events`, `athlete_age_sanctions`) is **append-only**.
  Withdrawing something writes a second row; the current state is the newest
  row. Never update or delete one.
- Narrow gates are narrow on purpose. `draw.override_format` and
  `athlete.sanction_age` deliberately exclude administrators, because an
  approval anybody senior could give does not record who decided.
- Display code reads what a draw *was generated as* (`drawFormat()`,
  `numberedAthletes()`), never what today's entry list would recompute. An
  operator presenting a published table must see the table that was published.
