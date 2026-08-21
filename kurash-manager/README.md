# Kurash Competition Manager — Laravel

The rebuild of the PHP MVP in `../app`. That folder stays running and is the
functional reference: every screen ported here is checked against it.

**Stack:** Laravel 13 · Livewire 4 · Flux UI · Tailwind 4 · MariaDB 10.11 · Pest 4

## Status

| Area | State |
| --- | --- |
| Auth, sessions, CSRF | Done — from the Livewire starter kit |
| Schema and models | Done |
| Bracket engine | Done |
| Legacy import | Done, verified against the real database |
| Screens: championships, categories, registration, weigh-in, draw, bracket, medals | Done |
| Roles and the manage-competition gate | Done |
| Scoreboard integration | Done — push, result webhook, mats screen |
| Match rules: khalol, yonbosh, chala, tanbeh, dakki, girrom, madichal, jazzo | Done |
| Referee role, scoped to a mat and a board | Done |
| Fight-order scheduling across mats | Done |
| PDF and Excel exports | Not started |

661 tests passing.

## Running it

```sh
./dev.sh
```

From the project root. It starts the database container, waits for it, runs
migrations, creates an administrator if none exists, and serves the app on
<http://127.0.0.1:8000>.

```sh
./dev.sh stop      # stop the app and the database
./dev.sh reset     # wipe the database and re-import the legacy data
```

The database runs in Docker so it matches the MariaDB on the DirectAdmin host;
the app runs on the system PHP.

<details>
<summary>Doing it by hand</summary>

```sh
docker run -d --name kurash-mariadb \
    -e MARIADB_ROOT_PASSWORD=devroot -e MARIADB_DATABASE=kurash \
    -e MARIADB_USER=kurash -e MARIADB_PASSWORD=devpass \
    -p 127.0.0.1:3307:3306 mariadb:10.11

cd kurash-manager
composer install
npm install && npm run build
php artisan migrate
php artisan kurash:create-admin        # prints a generated password once
php artisan serve
```
</details>

The host PHP has `pdo_mysql` but no `pdo_sqlite`, and the stock `php:8.3-cli`
image has the reverse, so a dev container with both lives in
`../tools/Dockerfile.dev`. Only the legacy import needs it —
`sudo apt install php8.3-sqlite3` removes the need entirely.

Tests need a second database, created once:

```sh
docker exec kurash-mariadb mariadb -uroot -pdevroot \
    -e "CREATE DATABASE kurash_test; GRANT ALL ON kurash_test.* TO 'kurash'@'%';"

./vendor/bin/pest
```

Tests run against MariaDB rather than sqlite on purpose. The schema leans on
CHECK constraints and foreign keys, and sqlite does not enforce them — passing
tests there would prove less than they appear to.

## Importing the old data

```sh
docker build -t kurash-dev:php83 -f ../tools/Dockerfile.dev ../tools

docker run --rm --network host -v "$PWD/..":/proj -w /proj/kurash-manager \
    -u "$(id -u):$(id -g)" -e HOME=/tmp \
    -e DB_HOST=127.0.0.1 -e DB_PORT=3307 -e DB_DATABASE=kurash \
    -e DB_USERNAME=kurash -e DB_PASSWORD=devpass \
    kurash-dev:php83 php artisan kurash:import-legacy /proj/data/kurash.db --fresh
```

Add `--dry-run` to see the counts without writing. Bouts are deliberately not
imported — the old match rows had no reliable forward links, so importing them
would carry the broken bracket across. Regenerate brackets from the drawn
athletes instead.

## The bracket engine

Three classes, all covered by tests, all independent of the UI:

- **`App\Support\BracketSeeding`** — standard single-elimination seed order,
  generated rather than looked up. Pinned in tests against the hard-coded
  tables the old system used, so it cannot drift from the pairings the
  federation already recognises.
- **`App\Services\BracketGenerator`** — builds every bout for a weight
  category, links each to the one its winner walks into, and resolves byes.
  A bout is only a walkover when the opposing slot can *never* be filled; one
  still waiting on an undecided bout is pending.
- **`App\Services\BoutAdvancer`** — records a result and advances the winner.
  Idempotent, so a scoreboard retry cannot advance twice. A corrected result
  unwinds everything downstream of it before re-applying.

`App\Services\MedalTable` derives the podium and the per-NOC standings.

## Deploying to DirectAdmin

PHP 8.3 or 8.4, with `mbstring`, `xml`, `curl`, `zip`, `fileinfo`, `openssl`,
`tokenizer`, `ctype` and `pdo_mysql`. Check with `php -m` before deploying.

DirectAdmin serves `~/domains/example.com/public_html`, and Laravel exposes
only `public/`:

```sh
cd ~/domains/example.com
mv public_html public_html.bak
ln -s /home/youruser/kurash-manager/public public_html
```

Remember DirectAdmin prefixes databases and users with your account name — the
database will be `youruser_kurash`, not `kurash`.

```
# deploy
git pull && composer install --no-dev -o && php artisan migrate --force \
    && php artisan config:cache route:cache view:cache

# cron
* * * * * cd /home/youruser/kurash-manager && php artisan schedule:run
* * * * * cd /home/youruser/kurash-manager && php artisan queue:work --stop-when-empty --max-time=55
```

`public/build` is committed on purpose: shared hosting has no usable Node
toolchain, so deploys use assets built locally. Run `npm run build` before
committing any change under `resources/`.

Only `storage/` and `bootstrap/cache/` need to be writable. Keep `.env` above
the web root with `APP_DEBUG=false` and `APP_ENV=production`.

## Fight order

`FightOrderScheduler` numbers every contested bout in a championship. Byes take
no slot — nobody steps onto a mat for a walkover.

The order is round-major: every first-round bout across all weight classes,
then every second round, and so on, interleaving the classes within each round.
That keeps the classes progressing together instead of running one to its final
while another has not started, and it is what naturally separates an athlete's
own bouts.

Two properties are guaranteed, and both are tested across several draw shapes:

1. **A bout is always numbered after both bouts that feed it.** The old
   hand-typed CSV had nothing preventing a semi-final being called before its
   quarter-final.
2. **There is a minimum gap between a bout and each of its feeders.** This is
   structural — it depends on bracket position, not on who wins, so it holds
   whatever the results are.

Where the draw is too small to give that rest — one weight class, or the
closing rounds of any championship — the shortfall is reported rather than
hidden, so organisers can schedule a break. Manual reordering swaps neighbours
and refuses any swap that would put a bout ahead of one that feeds it.

## Scoreboards

Everything vendor-specific lives in `HttpScoreboardDriver`. Swap the driver
with `SCOREBOARD_DRIVER`:

| Driver | Use |
| --- | --- |
| `http` | Real hardware |
| `fake` | Tests, and rehearsing a venue setup before equipment arrives |
| `null` | Run the competition with no scoreboards at all |

**Outbound.** Assigning a bout to a mat queues `PushBoutToScoreboard`, which
retries four times with backoff. It is queued because a display on a flaky
venue network can take the full timeout to fail, and the official pressing
"send to mat" should not wait for that — nor should the assignment fail
because something is unplugged.

**Inbound.** Point the vendor's result callback at `POST /webhooks/scoreboard`.
No session, no CSRF — the caller is a device on the mat. Authentication is a
shared secret header, and the endpoint **fails closed**: with
`SCOREBOARD_WEBHOOK_SECRET` unset it answers 503 to everything rather than
falling back to a placeholder.

```json
{ "play_code": "12-01-000-ab3f", "winner_side": "a",
  "score_a": 10, "score_b": 0, "win_type": "halal" }
```

Retries are safe — an identical repeat is a no-op and writes no second audit
entry. A play code from a bracket that has since been redrawn gets a 404
rather than landing on whatever now occupies that slot.

The payload shape is a best guess. When the manufacturer documents theirs,
`ScoreboardResultController` translates it and nothing else changes.

Scoreboard API keys are encrypted at rest and never sent back to a browser.

## Roles

Create the first account from the command line rather than letting whoever
reaches the sign-up page first become an administrator:

```sh
php artisan kurash:create-admin --email=you@example.com
```

The password is generated and printed once unless you pass `--password`. There
is no shipped default, so there is nothing to forget to change.

`users.role` is one of `admin`, `supervisor`, `official`, `viewer`,
`referee`, `scoreboard_viewer`. Four gates separate what they may do:

| Gate | Admits | Opens |
| --- | --- | --- |
| `manage-competition` | admin, supervisor | Anything that changes competition data — entries, weigh-ins, draws, brackets, the fight order |
| `score-bout` | admin, supervisor, referee | Every write on the mat screen: calls, the clock, jazzo, declaring a winner |
| `mat.view` | everyone except `scoreboard_viewer` | Opening a mat screen. An official watches a mat they cannot score on |
| `scoreboard.view` | every active role | Reading a board |

`referee` and `scoreboard_viewer` are *confined*: they reach a mat and a board
and no other screen in the system, and `access-admin` is what keeps them out by
typing a URL rather than by hiding a link. A referee signs in and lands on
`/referee/mats`; a scoreboard account lands on its board.

Scoring is deliberately not a corner of `manage-competition`. A referee is
trusted with the result and should not be able to regenerate the draw it sits
in.

The original system had a role column and a `kurash-access-guard.php` that
checked it, but no file ever included the guard — so every account had full
access. The gates are enforced in each Livewire action and asserted in tests.

## Match rules

Scoring is derived from `bout_events` rather than held in counters, so a call
can be taken back and the tally recomputed to exactly what it would have been
had the call never been made.

| Call | Effect |
| --- | --- |
| `khalol` | Ends the contest |
| `yonbosh` | Half a score; two make a khalol, however each was reached |
| `chala` | The smallest score, and it never accumulates |
| `tanbeh` | The opponent is given a chala |
| `dakki` | The opponent is given a yonbosh, and the automatic chala the superseded tanbeh gave them is taken back — an *earned* chala is untouched |
| `girrom` | The contest is awarded to the opponent on the spot |
| `madichal` | Transfers nothing; the third one loses the contest |

Every automatic award names the penalty that caused it in `parent_event_id`,
which is what lets a dakki find the chala it supersedes and lets a withdrawn
penalty take its consequences with it. `origin` records whether a score was
`TECHNIQUE`, `MANUAL`, `AUTO_FROM_T` or `AUTO_FROM_D`; `sequence_number` gives
the log a total order, because two calls inside the same second are
indistinguishable by `created_at` and the rules turn on order.

A contest that reaches time is decided on yonbosh, then chala, then score
origin — the side that earned its scores over one handed the same numbers by
the opponent's penalties — and then on the latest warning, where whoever was
warned most recently loses. Level all the way down is a referee decision, and
the mat screen asks for one rather than picking a side.

**Jazzo:** half the contest gone with nothing scored by either athlete stops the
contest, and the board shows a yellow box until the mat resumes. The browser
notices, because the browser holds the clock, but the halfway mark and the empty
board are both checked again on the server.

Contest length is set per age category on the championship screen — cadets,
juniors and seniors do not fight for the same time. A category without one falls
back to `config/kurash.php`, keyed on the weight class's gender. That file also
carries `yonbosh_for_khalol`, `madichal_for_defeat`, `jazzo_at_fraction`, and
`tanbeh_for_dakki` — zero by default, meaning tanbeh do not accumulate; set it
to run under a rules edition where the Nth tanbeh becomes a dakki.

## Next

1. `ScoreboardDriver` contract with a real HTTP implementation and a fake, so a
   whole tournament can be driven in a test with no hardware present.
2. Fight-order scheduling: assign fight numbers and mats across weight classes,
   replacing the hand-filled CSV the old system used.
3. `wire:poll.3s` on the bracket and a venue display — shared hosting cannot
   run a WebSocket daemon.
4. PDF and Excel exports via `barryvdh/laravel-dompdf` and
   `maatwebsite/excel`, rendered from database state rather than from HTML the
   browser posted back.
