<h1 align="center">Kurash Competition Manager</h1>

<p align="center">
  Tournament software for kurash championships — entry, accreditation, weigh-in,
  draw, bracket, live scoring, venue displays, medals and paperwork.
</p>

<p align="center">
  <a href="https://github.com/hadi165/kurash-tournament-manager/actions/workflows/tests.yml"><img alt="tests" src="https://github.com/hadi165/kurash-tournament-manager/actions/workflows/tests.yml/badge.svg"></a>
  <img alt="PHP 8.3+" src="https://img.shields.io/badge/PHP-8.3%2B-777bb4">
  <img alt="Laravel 13" src="https://img.shields.io/badge/Laravel-13-ff2d20">
  <img alt="Livewire 4" src="https://img.shields.io/badge/Livewire-4-fb70a9">
  <img alt="MariaDB 10.11" src="https://img.shields.io/badge/MariaDB-10.11-003545">
  <img alt="1555 tests" src="https://img.shields.io/badge/tests-1555%20passing-brightgreen">
</p>

---

## What this is

A complete competition management system for kurash, written against the
[IKA competition rules](https://kurash-ika.org/2022/08/20/kurash-rules/). It runs
a championship end to end: a federation's entry spreadsheet goes in one side,
and draws, running orders, live scoring on the mats, venue boards, medal
standings and printable paperwork come out the other.

It replaces a flat-PHP application that spread the same work across CSV files on
disk, a role column nothing ever checked, and a fight order typed by hand. Every
rule that used to live in a screen now lives in one class with tests around it.

**Designed for the hall, not the office.** The mat screen is operated at the edge
of the tatami by somebody who cannot look away from the contest. The venue
displays are read by a room full of spectators. The desk runs several screens at
once across several machines. Each of those is a different trade, and the
architecture makes them separately.

### Highlights

- **Rules engine, not screens with opinions.** Formats, scoring, tie-breaks, age
  eligibility and weight limits each live in one class, sourced to the published
  rules, with the inferences flagged as inferences.
- **Round robin *and* knockout**, chosen by the IKA field-size rule, with a
  signed override when a local decision departs from it.
- **Append-only match log.** Scores are derived from events, never held in
  counters, so a call can be taken back and the tally recomputed to exactly what
  it would have been had the call never been made.
- **Versioned rule editions.** A championship fought under one edition keeps
  being read under that edition, whatever a later edition changes.
- **Seven roles behind fourteen gates**, including two confined roles that reach
  a mat or a board and nothing else.
- **Twelve documents** in PDF, CSV and XLSX, rendered from the database on
  request so a re-download is never stale.
- **Scoreboard hardware integration** — queued outbound push, authenticated
  inbound webhook, swappable driver, and a fake for rehearsing a venue.
- **Database safety enforced in four layers**, because this database has been
  emptied three times.

---

## Table of contents

- [Requirements](#requirements)
- [Quick start](#quick-start)
- [Demonstration data](#demonstration-data)
- [Repository layout](#repository-layout)
- [The competition day](#the-competition-day)
- [The rules engine](#the-rules-engine)
- [Roles and permissions](#roles-and-permissions)
- [Screens and routes](#screens-and-routes)
- [Documents and exports](#documents-and-exports)
- [Venue displays](#venue-displays)
- [Scoreboard integration](#scoreboard-integration)
- [Configuration](#configuration)
- [Testing and quality gates](#testing-and-quality-gates)
- [Database safety and backups](#database-safety-and-backups)
- [Deployment](#deployment)
- [How the code is written](#how-the-code-is-written)
- [Traps](#traps)
- [Contributing](#contributing)
- [Licence and credits](#licence-and-credits)

---

## Requirements

| | |
|---|---|
| PHP | 8.3 or 8.4, with `mbstring`, `xml`, `curl`, `zip`, `fileinfo`, `openssl`, `tokenizer`, `ctype`, `pdo_mysql`, `gd` |
| Database | MariaDB 10.11 (or MySQL 8) — **not** SQLite, see below |
| Node | 22, for building assets only |
| Composer | 2 |
| Docker | optional, for the local database container |

`gd` is not decorative: Dompdf rasterises the PNG logo and the country flags
through it, and without it the printouts fall back to a typographic mark.

**Why not SQLite.** The schema leans on CHECK constraints and foreign keys, and
SQLite does not enforce them the same way. A suite passing there would prove less
than it appears to, so the tests run against MariaDB.

---

## Quick start

From the repository root:

```sh
./dev.sh
```

That starts the MariaDB container, waits for it, creates the test database,
installs dependencies if they are missing, runs migrations, creates an
administrator if no account exists, and serves the app.

It listens on **every interface**, not just loopback — a competition is several
screens at once (a mat screen on a tablet, a scoreboard on the projector, the
desk on a laptop) and none of them are this machine. The script prints both
addresses.

```sh
./dev.sh          # start the database and the app
./dev.sh stop     # stop both
./dev.sh reset    # refuses on purpose — see Database safety
```

Override with `APP_HOST=127.0.0.1 ./dev.sh` for loopback only, or
`APP_PORT=8080 ./dev.sh`.

<details>
<summary><b>Setting it up by hand</b></summary>

```sh
docker run -d --name kurash-mariadb \
    -e MARIADB_ROOT_PASSWORD=devroot -e MARIADB_DATABASE=kurash \
    -e MARIADB_USER=kurash -e MARIADB_PASSWORD=devpass \
    -p 127.0.0.1:3307:3306 mariadb:10.11

cd kurash-manager
cp .env.example .env
composer install
php artisan key:generate
npm install && npm run build
php artisan migrate
php artisan kurash:create-admin --email=you@example.com
php artisan serve
```

`kurash:create-admin` generates the password and prints it once unless you pass
`--password`. There is no shipped default, so there is nothing to forget to
change. Create the first account here rather than letting whoever reaches the
sign-up page first become an administrator.

The test database, created once:

```sh
docker exec kurash-mariadb mariadb -uroot -pdevroot \
    -e "CREATE DATABASE kurash_test; GRANT ALL ON kurash_test.* TO 'kurash'@'%';"
```
</details>

<details>
<summary><b>Front-end development</b></summary>

```sh
npm run dev      # Vite dev server, bound to every interface
npm run build    # production assets into public/build
npm run flags    # copy flag SVGs out of node_modules and rasterise them for print
```

`public/build` is **committed on purpose** — shared hosting has no usable Node
toolchain, so deploys use assets built locally. Run `npm run build` before
committing any change under `resources/`.

No webfonts are fetched from a CDN at build time or at run time. The interface
prefers the platform face and falls back to Source Sans 3, whose WOFF2 files are
committed under `public/fonts`.
</details>

---

## Demonstration data

```sh
php artisan kurash:demo --fresh-all
```

Builds a whole championship to look at: every weight class entered, weighed,
drawn and part-fought, with a contest live on each mat so the mat screen and the
venue displays have something to show.

| Option | Default | What it does |
|---|---|---|
| `--title=` | Asian Kurash Championship 2026 | Championship name |
| `--location=` | Tashkent, Uzbekistan | Where it is held |
| `--per-class=` | 14 | Athletes entered in each weight class |
| `--mats=` | 4 | How many mats |
| `--stage=` | running | `registered`, `weighed`, `drawn`, `running`, `finished` |
| `--small-classes=` | 3 | Classes given a field of 2–5, which the IKA rule runs as a round robin |
| `--reject-rate=` | 8 | One athlete in this many is entered wrongly, on age or on weight |
| `--fresh` | | Delete any existing championship with this title first |
| `--fresh-all` | | Delete every championship in the database first |

Nothing in the test suite depends on it — it exists to be demonstrated and thrown
away. It never touches the `users` table.

---

## Repository layout

```
.
├── dev.sh                          start / stop the local stack
├── scripts/reset-local-database.sh the only sanctioned database rebuild
├── .github/workflows/tests.yml     CI: pint, phpstan, pest
└── kurash-manager/                 the application
    ├── app/
    │   ├── Console/Commands/       kurash:demo, kurash:backup, kurash:create-admin, flags:rasterise
    │   ├── Contracts/              ScoreboardDriver
    │   ├── Exports/                twelve documents, three writers (PDF / CSV / XLSX)
    │   ├── Http/                   display + export controllers, scoreboard webhook, middleware
    │   ├── Jobs/                   PushBoutToScoreboard
    │   ├── Livewire/               every screen, grouped Competition / Operator / Referee / Scoreboard / Settings
    │   ├── Models/                 Championship, AgeCategory, WeightCategory, Athlete, Court, Bout, BoutEvent, User
    │   ├── Observers/              archived-championship guard, display cache invalidation
    │   ├── Services/               the rule engine — see below
    │   └── Support/                value objects: verdicts, ranges, bands, seeding, NOC table
    ├── config/kurash.php           every rule constant a federation might change
    ├── database/migrations/
    ├── resources/views/            livewire screens, venue displays, PDF templates
    └── tests/                      Pest 4 — 53 files, 1555 tests
```

---

## The competition day

The application is arranged in the order an event actually runs.

### 1. Championship and divisions

A championship declares which **competitions** it runs (`M`, `F`, `X`) and which
**age groups** (`Senior`, `Junior`, `Cadet`, `Veteran`). Divisions outside that
declaration are refused rather than silently offered. Contest length is set per
age category — cadets, juniors and seniors do not fight for the same time.

Weight classes are created under each division. The class label (`-60`, `+90`) is
the federation's own name for it and is what everything else derives from: the
weight bounds, the sort order on the running order, the export filename.

### 2. Registration and accreditation

Athletes are entered by hand or imported from the spreadsheet a federation sent.

**The importer reads in two steps.** `parse()` touches nothing and reports what
every row would do; `commit()` writes the rows that were ready, in one
transaction. An official sees what is wrong before anything is saved, and a file
that is half wrong still registers the half that is right. Row numbers are the
workbook's own, heading row included, because the only useful thing to say about
a bad row is where to find it in the file they are looking at.

Column headings are matched loosely — case, spaces and punctuation stripped —
against a table of everything a federation might have called them, and the first
list is what this system's own athlete export writes, so a sheet exported from
here can be edited and sent back.

Nations are IOC three-letter codes mapped to ISO 3166-1 flags through a hand-kept
table, because the two do not agree in any way a rule could derive (BRN is
Bahrain, not Brunei; IRI is Iran; KSA is Saudi Arabia and RSA is South Africa).
Getting one wrong puts another country's flag beside an athlete's name on a
screen in front of their delegation.

Accreditation numbers are allocated per championship and printed on cards with a
QR code drawn as inline SVG from the encoder's module matrix — no Imagick, no
rasterised image scaled into a 20 mm box.

### 3. Weigh-in

Admission to competition is `weighin_status = 'pass'`. **"Not fail" is not the
same question** — that distinction was a real bug, and it now lives in exactly
one scope, `Athlete::scopePassedWeighIn()`.

The weight rule, in one class asked at the scale, at approval, during an import
and on every screen that shows a weigh-in:

- **upper bound** — the category's limit plus a 500 g tolerance
- **lower bound** — the limit of the class below, less the same tolerance

So in a division of −56, −60, −66 the −60 class runs from 55.5 to 60.5. An
athlete at 60.4 has made −60; at 60.6 they have not. The lower bound is derived
from the division rather than stored, so adding a class moves the bounds of the
class above it automatically.

The rule this replaced required an athlete to be within the tolerance *below the
ceiling* — a −60 class that accepted 59.5 to 60.0 and rejected everyone lighter.
That is not a weight class, it is a 500-gram window.

### 4. Draw

`DrawGenerator` is the one door. Screens, commands and tests ask it to draw a
class and it decides which generator does the work.

Three things happen there that belong to neither generator: the requested format
is re-checked against the rule server-side every time (a browser cannot ask for a
round robin of sixteen by editing a `<select>`); a knockout in a small field is
recorded as an **override** with the administrator, the reason and the moment;
and the category row is locked for the duration so two administrators cannot draw
the same class twice.

Eligibility is checked at three moments — when the numbers are handed out, when
the bracket is generated, and when the draw is published — and all three ask
`DrawEligibility`, so they cannot become two versions of the rule.

A draw can be **published**, which freezes it for presentation. Every display
path reads what a draw *was generated as* (`drawFormat()`, `numberedAthletes()`),
never what today's entry list would recompute — an operator presenting a
published table must see the table that was published.

### 5. The draw ceremony

A read-only board for the hall, in two modes that differ only in the *telling*:

- **announced** — the person at the microphone places each position by pressing,
  in seeded order
- **automatic** — the board places them itself, one a second, in an order that
  looks nothing like counting

The draw itself is made and committed in one transaction on the admin screen
before this board shows a single position. Refreshing mid-ceremony cannot change
a single seat. Which mode is running is carried by the URL rather than by a flag,
so an operator whose browser reloads comes back to the ceremony they started.

### 6. Fight order

`FightOrderScheduler` numbers every contested bout in the championship. Byes take
no slot — nobody steps onto a mat for a walkover.

The order is **round-major**: every first-round bout across all weight classes,
then every second round, and so on, interleaving classes within each round. That
keeps classes progressing together instead of running one to its final while
another has not started, and it is what naturally separates an athlete's own
bouts. Classes are ordered lightest first, which is a question about kilograms
and not about `id` or about string order — sorted as text, `-100` comes before
`-60` and `+100` before both, which would put the heaviest athletes of the day on
the first mat of the morning.

Two properties are guaranteed and both are tested across several draw shapes:

1. **A bout is always numbered after both bouts that feed it.** The hand-typed
   CSV this replaced had nothing preventing a semi-final being called before its
   quarter-final.
2. **There is a minimum gap between a bout and each of its feeders** (three by
   default). This is structural — it depends on bracket position, not on who
   wins, so it holds whatever the results are.

Where a draw is too small to give that rest — one weight class, or the closing
rounds of any championship — the shortfall is **reported rather than hidden**, so
organisers can schedule a break. Manual reordering swaps neighbours and refuses
any swap that would put a bout ahead of one that feeds it.

### 7. On the mat

The mat screen is one press per action and never asks a question mid-bout that it
could have answered itself. The two it genuinely cannot answer — a contest level
all the way down the tie-break at time, and whether a call was a mistake — are
the only two that stop and ask.

The clock belongs to the mat, not to the application. It runs in the browser and
the operator's press carries the reading with it, which is what keeps a paused
tab from silently drifting away from the contest in front of them.

Each mat picks its own finish sound, served from the venue's own machine. At
match time there may be no route off the hall's network, and a buzzer that has to
be fetched is a buzzer that does not sound — and two mats side by side want to be
told apart by ear.

### 8. Medals and archive

`MedalTable` and `RoundRobinStandings` are derived from bout rows on every read
and never cached. Certificates and the medal standing come off the same numbers.

Archiving a championship stops it accepting changes —
`ArchivedChampionshipGuard` blocks every write to an archived championship's
models — so the exports underneath it say the same thing next season as they do
today. Reopening is recorded with an account and a reason.

---

## The rules engine

Everything sourced to the published rules lives in `config/kurash.php` or in one
class in `app/Services`. **Sourced rules are kept separate from inferences**:
where the published rules are ambiguous or silent, the config block says so and
flags it for federation confirmation rather than picking silently.

### Tournament format

`TournamentFormatPolicy` turns the athlete count into a decision, and everywhere
else asks it or reads the answer it stored:

| Field | Format |
|---|---|
| 1 athlete | administrative placement, which somebody signs for |
| 2–5 | round robin — the IKA rule, and the default |
| 6 or more | knockout |

Knockout in a field of two to five is available, but as a **local decision taken
against the rule**, not as an alternative reading of it: it needs
`draw.override_format`, an administrator, and a stated reason.

### The bracket

Three classes, all covered by tests, all independent of the UI:

- **`Support\BracketSeeding`** — standard single-elimination seed order,
  generated rather than looked up, and pinned in tests against the hard-coded
  tables the old system used so it cannot drift from pairings the federation
  already recognises.
- **`Services\BracketGenerator`** — builds every bout for a weight category,
  links each to the one its winner walks into, and resolves byes. A bout is only
  a walkover when the opposing slot can *never* be filled; one still waiting on
  an undecided bout is pending.
- **`Services\BoutAdvancer`** — records a result and advances the winner.
  Idempotent, so a scoreboard retry cannot advance twice. A corrected result
  unwinds everything downstream of it before re-applying.

### The round robin

`RoundRobinGenerator` is a sibling of the bracket generator rather than a mode of
it, and builds the schedule by the circle method — everybody meets everybody
once. Nothing there writes `next_bout_id` and nothing there creates a walkover,
because a round robin has no tree to advance through.

Standings are configurable in one block, and nothing else reads it:

| Setting | Default | |
|---|---|---|
| `points.win` / `points.loss` | 1 / 0 | Flat — a win is a win, however it was won |
| `tie_breaks` | wins → points → head_to_head → mini_table → match_time → referee | Walked in the order written |
| `match_time` | `disabled` | See below |
| `medals` | 1 gold, 2 silver, 3 bronze | One bronze — a round robin has no second semi-final to lose |
| `minimum_rest` | 3 bouts | The rest a bracket gets for free from its own shape |

The points table is deliberately **not** derived from `ScoreTally::points()`.
That method encodes yonbosh in the whole part and chala in the tenths for the
scoreboard column — ten chala would read there as one yonbosh. It describes one
contest to a screen. It is not a currency, and summing it across a group would
rank athletes on an encoding.

**The match-time tie-break is off**, and that is a decision rather than an
omission. The IKA wording does not say which reading it means, and the three
candidates — fastest single win, least total time across all wins (which
penalises an athlete for having won more), mean time per win — rank athletes
differently. Choosing one silently would decide medals on an assumption, so a tie
that reaches the step falls through to an explicit referee decision. Nothing
fought before the setting existed has a recorded winning time either, and the
standings refuse to use the step where any tied athlete's timing is missing,
whatever it is set to.

### Match calls

Scoring is derived from `bout_events` rather than held in counters, so a call can
be taken back and the tally recomputed to exactly what it would have been had the
call never been made.

| Call | Effect |
|---|---|
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
`TECHNIQUE`, `MANUAL`, `AUTO_FROM_T` or `AUTO_FROM_D`. `sequence_number` gives
the log a total order, because two calls inside the same second are
indistinguishable by `created_at` and the rules turn on order.

**Nothing is ever rewritten.** Supersession and withdrawal both append void
events; the current state is the newest row.

**Jazzo:** half the contest gone with nothing scored by either athlete stops the
contest, and the board shows a yellow box until the mat resumes. The browser
notices, because the browser holds the clock, but the halfway mark and the empty
board are both checked again on the server. A penalty on the board is a scored
board — it stops jazzo being offered.

### Deciding a contest that reaches time

`BoutDecisionPolicy` walks a **versioned** order, and a championship pins the
version it was fought under. The 2022 edition, as supplied by the federation for
this project:

1. **higher_appraisal** — khalol > yonbosh > chala, evaluated before origin and
   before recency. A later chala can never defeat a yonbosh.
2. **more_chala** — at an equal top appraisal, the greater count wins.
3. **technique_origin** — a technique-earned appraisal outranks an automatic one
   of equal value and count.
4. **last_appraisal** — read from the event sequence, never the clock.
5. **latest_warning** — whoever was warned **most recently** loses.

Level all the way down is a referee decision, and the mat screen asks for one
rather than picking a side. The two questions the rules genuinely leave open are
listed as `ambiguities` in the config and named in the verdict, so the official
is told what the rules did not cover.

The federation's order settles two points on which this software briefly shipped
the wrong reading: score origin does rank, but *below* count rather than above
it; and the warning rule is latest-loses, not cautioned-first-wins. The two agree
when each athlete holds one warning and disagree from the second onward.

### Age eligibility

Competition age is `competition year − birth year`, **never** a date-of-birth
subtraction — which is why a 29 February birthday needs no special handling
anywhere in the code.

Section 23 prints, for each division, an age span *and* the birth years that
produced it in one particular year. What is stored is the span; the birth years
are derived for whichever year a championship is held in. That reproduces every
printed range exactly and survives into next season without an edit.

Bands are versioned by the year they came into force, and a version stays in
force until a later one supersedes it. A championship held before the earliest
version is **left unjudged** rather than guessed at, so importing a 2019 event
does not retrospectively invalidate its entries. A championship may pin a version
explicitly when it is run under rules other than the ones current for its year.

**Section 25(2)** — youths of 16–17 may enter an adults' competition with the
Chief Referee's sanction. `AgeSanctions` enforces four conditions rather than
leaving them to a screen: the athlete is in the window for their competition
year, the division is an adults' competition, the account signing holds the
office, and a reason is given. The last is not a formality — a sanction with no
reason is indistinguishable afterwards from an accident, and the whole point of
naming an official in the rule is that somebody can be asked. Sanctions are
append-only; withdrawing one writes a second row.

`judged` and `eligible` on a verdict are **different questions**. "No rule covers
this" is not "checked and fine", and only the first may pass a credentials check.

---

## Roles and permissions

`users.role` is one of `admin`, `supervisor`, `chief_referee`, `official`,
`viewer`, `referee`, `scoreboard_viewer`. Every role predicate on `User` checks
`is_active` first.

| Gate | Admits | Opens |
|---|---|---|
| `manage-competition` | admin, supervisor | Anything that changes competition data — entries, weigh-ins, draws, brackets, the fight order |
| `manage-users` | admin | The accounts screen |
| `access-admin` | everyone not confined to a mat | The competition application at all |
| `score-bout` | admin, supervisor, referee | Every write on the mat screen: calls, the clock, jazzo, declaring a winner |
| `mat.view` | everyone except `scoreboard_viewer` | Opening a mat screen — an official watches a mat they cannot score on |
| `scoreboard.view` | every active role | Reading a board |
| `draw.publish` | admin, supervisor | Freezing a draw for presentation |
| `draw.view_published` | everyone not confined to a mat | The operator's published-draw screens |
| `presentation.operate` | everyone not confined to a mat | Running a ceremony |
| `draw.override_format` | **admin only** | Knockout in a field the rule runs as a round robin |
| `athlete.sanction_age` | **chief referee only** | Signing a youth into an adults' competition |
| `scoreboard.select_event` / `select_division` / `select_court` | scoped per account | Which board an account may choose |

Three things worth knowing:

**Narrow gates are narrow on purpose.** `draw.override_format` and
`athlete.sanction_age` deliberately exclude administrators, because an approval
anybody senior could give is one nobody can be asked about afterwards.

**Scoring is not a corner of `manage-competition`.** A referee is trusted with
the result and should not be able to regenerate the draw it sits in.

**`referee` and `scoreboard_viewer` are confined.** They reach a mat and a board
and no other screen, and `access-admin` is what keeps them out by typing a URL
rather than by hiding a link. A referee signs in and lands on `/referee/mats`; a
scoreboard account lands on its board. Passed a mat, `score-bout` and `mat.view`
ask the harder question — not "may this account score" but "may this account
score *here*" — which is what stops mat three being reached by editing a number
in the address bar.

The system this replaced had a role column and a `kurash-access-guard.php` that
checked it, but no file ever included the guard, so every account had full
access. Every gate here is enforced in the Livewire action and asserted in tests.

### Accounts and sign-in

Laravel Fortify with registration, password reset, email verification,
**two-factor authentication** (confirmed, with recovery codes) and
**passkeys / WebAuthn**. Admins hand out two kinds of account from the settings
screen — an operator who works the competition, and a scoreboard viewer who only
watches one. Admin is deliberately not on that list: an account that can mint
accounts is not something a form should be able to create.

In production, passwords must be at least 12 characters, mixed case, with numbers
and symbols, and checked against known breaches.

---

## Screens and routes

<details>
<summary><b>Competition — behind <code>access-admin</code></b></summary>

| Route | Screen |
|---|---|
| `/dashboard` | The desk's command centre: blockers, live mats, what is called next, progress, decided results, medal leaders |
| `/championships` | Championships |
| `/championships/{c}` | Divisions, age groups and weight classes |
| `/championships/{c}/athletes/{M\|F\|X}` | Registration, and the spreadsheet import |
| `/championships/{c}/weigh-in/{M\|F\|X}` | The scale |
| `/championships/{c}/entries` | Entry counts by delegation and by weight class — the launch board |
| `/championships/{c}/brackets` | Every drawn bracket, with its sheet |
| `/championships/{c}/fight-order` | The running order, with manual reordering |
| `/championships/{c}/mats` | Mats, their finish sounds and referee assignments |
| `/championships/{c}/medals` | Podium and NOC standings |
| `/weight-classes/{w}/bracket` | Draw, bracket and results for one class, on one screen |
| `/archive` | Closed championships and the reports that came out of them |
</details>

<details>
<summary><b>Mats — behind <code>mat.view</code></b></summary>

| Route | Screen |
|---|---|
| `/referee/mats` | Where a referee lands: the mats they may work, and nothing else |
| `/mats/{court}/live` | Live scoring for one mat |
</details>

<details>
<summary><b>Presentation — behind <code>draw.view_published</code></b></summary>

| Route | Screen |
|---|---|
| `/operator/draws` | The weight classes an operator may present |
| `/operator/draws/{w}` | The published draw, read-only in the strongest sense |
| `/operator/draws/{w}/ceremony` | Announced ceremony — the microphone places each position |
| `/operator/draws/{w}/present` | Automatic ceremony — the board places them itself |
</details>

<details>
<summary><b>Boards and displays</b></summary>

| Route | Screen | Gate |
|---|---|---|
| `/scoreboard`, `/scoreboard/mats/{court}` | The signed-in board | `scoreboard.view` |
| `/display/championships/{c}` | All mats | `DISPLAY_PUBLIC` |
| `/display/championships/{c}/fight-order` | The running order | `DISPLAY_PUBLIC` |
| `/display/championships/{c}/medals` | Medal standings | `DISPLAY_PUBLIC` |
| `/display/weight-classes/{w}/bracket` | One bracket | `DISPLAY_PUBLIC` |
| `/display/mats/{court}/scoreboard` | The wall scoreboard for one mat | `DISPLAY_PUBLIC` |
| `/display/weight-classes/{w}/draw-ceremony` | The ceremony board for a projector | `DISPLAY_PUBLIC` |
</details>

---

## Documents and exports

Every table is rendered from the database at the moment it is requested, so a
re-download is never out of date. An archived championship's exports are stable
by construction.

| Document | Formats | Scope |
|---|---|---|
| Athlete list | PDF · CSV · XLSX | Championship, optionally one delegation (`?noc=UZB`) |
| Entries by NOC | PDF · CSV · XLSX | Championship |
| Entries by weight class | PDF · CSV · XLSX | Championship |
| Weigh-in form | PDF · CSV · XLSX | Weight class |
| Draw sheet | PDF · CSV · XLSX | Weight class |
| Draw numbers | PDF · CSV · XLSX | Weight class |
| Bracket sheet | PDF · XLSX | Weight class — a tree, not a table, so never CSV |
| Fight order | PDF · CSV · XLSX | Championship |
| Results | PDF · CSV · XLSX | Championship |
| Medal standing | PDF · CSV · XLSX | Championship |
| Certificates | PDF | Championship or one weight class |
| Accreditation cards | PDF | Championship, one age category, or one athlete |

A bracket sheet cannot be expressed in comma-separated rows at all, which is why
it is the one that is not offered as CSV. Certificates and accreditation cards
are laid-out documents rather than tables, so they are PDF only.

Every PDF carries the federation's mark and the producing company at the foot, in
the same place on every sheet — a page that leaves the venue should say who
produced it.

---

## Venue displays

The display screens are deliberately **outside** the Livewire application.

A bracket on `wire:poll.3s` in front of 200 spectators is roughly 66 requests a
second, each booting the framework, hydrating a component and querying the
database. That is what actually falls over at an event — not the data, which is
tiny.

So every screen for a championship hangs off a single version number. Bumping it
invalidates all of them at once, which means a result is visible on the next
request and a stale result is impossible. `DISPLAY_TTL` is a backstop for
anything that changes without touching a bout row, not the correctness mechanism.

The mat scoreboard is the exception and polls instead: it has one viewer and has
to be right within a second of a call, which is the opposite trade from a bracket
two thousand people are reading.

Anonymous access is **off by default**. Turning on `DISPLAY_PUBLIC` publishes
athlete names, draws and results to anyone with the URL — normal for a
championship, but a decision to make deliberately rather than inherit.

---

## Scoreboard integration

Everything vendor-specific lives in `HttpScoreboardDriver` behind the
`ScoreboardDriver` contract. Swap it with `SCOREBOARD_DRIVER`:

| Driver | Use |
|---|---|
| `http` | Real hardware |
| `fake` | Tests, and rehearsing a venue setup before the equipment arrives |
| `null` | Run the competition with no scoreboards at all |

**Outbound.** Assigning a bout to a mat queues `PushBoutToScoreboard`, which
retries four times with backoff. It is queued because a display on a flaky venue
network can take the full timeout to fail, and the official pressing "send to
mat" should not wait for that — nor should the assignment fail because something
is unplugged.

**Inbound.** Point the vendor's result callback at `POST /webhooks/scoreboard`.
No session, no CSRF — the caller is a device on the mat. Authentication is a
shared secret header, plus a rate limit so a device stuck in a retry loop cannot
flood the database mid-event. The endpoint **fails closed**: with
`SCOREBOARD_WEBHOOK_SECRET` unset it answers 503 to everything rather than
falling back to a placeholder published in the source.

```json
{ "play_code": "12-01-000-ab3f", "winner_side": "a",
  "score_a": 10, "score_b": 0, "win_type": "khalol" }
```

Retries are safe — an identical repeat is a no-op and writes no second audit
entry. A play code from a bracket that has since been redrawn gets a 404 rather
than landing on whatever now occupies that slot.

The payload shape is a best guess. When the manufacturer documents theirs,
`ScoreboardResultController` translates it and nothing else changes. Scoreboard
API keys are encrypted at rest and never sent back to a browser.

---

## Configuration

Rule constants a federation might change live in `config/kurash.php`, one
documented block per rule family, each readable from the environment.

<details>
<summary><b>Match rules</b></summary>

| Variable | Default | |
|---|---|---|
| `KURASH_BOUT_SECONDS_M` | 240 | Fallback contest length; the real one is per age category |
| `KURASH_BOUT_SECONDS_F` | 180 | |
| `KURASH_BOUT_SECONDS_OPEN` | 240 | |
| `KURASH_YONBOSH_FOR_KHALOL` | 2 | |
| `KURASH_MADICHAL_FOR_DEFEAT` | 3 | |
| `KURASH_TANBEH_FOR_DAKKI` | 0 | Zero means tanbeh do not accumulate — the rule set this is written against |
| `KURASH_JAZZO_AT_FRACTION` | 0.5 | A fraction, not seconds, because contest length is no longer one number |
| `KURASH_DECISION_POLICY_FALLBACK` | 2022 | Edition used when a championship pins none |
</details>

<details>
<summary><b>Round robin</b></summary>

| Variable | Default |
|---|---|
| `KURASH_RR_POINTS_WIN` | 1 |
| `KURASH_RR_POINTS_LOSS` | 0 |
| `KURASH_RR_MATCH_TIME` | `disabled` |
| `KURASH_RR_MINIMUM_REST` | 3 |
</details>

<details>
<summary><b>Age eligibility</b></summary>

| Variable | Default |
|---|---|
| `KURASH_AGE_POLICY_FALLBACK` | *(null — a championship older than every version is left unjudged)* |
</details>

<details>
<summary><b>Displays, boards and branding</b></summary>

| Variable | Default | |
|---|---|---|
| `DISPLAY_PUBLIC` | `false` | Venue screens readable without signing in |
| `DISPLAY_TTL` | 300 | Backstop only |
| `SCOREBOARD_DRIVER` | `http` | `http` · `fake` · `null` |
| `SCOREBOARD_TIMEOUT` | 5 | |
| `SCOREBOARD_WEBHOOK_SECRET` | *(none — the endpoint refuses everything until set)* | |
| `SCOREBOARD_WEBHOOK_HEADER` | `X-Scoreboard-Token` | |
| `SCOREBOARD_FINISH_SOUND` | `sounds/match-end01.wav` | Empty string for a silent hall |
| `BRANDING_ORGANISATION` | International Kurash Association | |
| `BRANDING_SHORT_NAME` | IKA | |
| `BRANDING_COMPANY` | Arvangroup | Printed at the foot of every PDF |
| `BRANDING_LOGO` | `images/logo.png` | Sidebar, login, displays |
| `BRANDING_LOGO_PRINT` | `images/logo.png` | PDFs — supply a PNG, Dompdf renders it far more reliably than SVG |
</details>

Until real artwork is dropped at `public/images/`, the logo components fall back
to a plain typographic mark — deliberately generic, so nothing in the system
pretends to be official artwork it is not.

---

## Testing and quality gates

```sh
composer test        # config:clear, pint --test, phpstan, artisan test
```

Run it and expect all four to pass. Individually:

```sh
composer lint          # Pint, Laravel preset
composer lint:check    # Pint, no writes
composer types:check   # PHPStan level 7 + Larastan
php artisan test       # Pest 4
```

**1555 tests, 5019 assertions, all passing.** Feature tests drive real screens
against a real MariaDB; `TournamentSimulationTest` runs whole tournaments end to
end.

> **Do not use `php artisan test --parallel`.** The `kurash` database user has no
> grant to create the per-process `kurash_test_test_N` databases, so it fails
> with an access-denied error that looks like a code fault and is not one.

CI runs `composer setup` then `composer ci:check` on PHP 8.3 and Node 22, on
pushes to `main` and on every pull request.

---

## Database safety and backups

**The competition database has been emptied three times.** Twice by a
`migrate:fresh` aimed at what somebody believed was a scratch database, once by
an automated agent running a script it believed was harmless. Each time the
`users` table went with it — referees, officials, the administrator. Nothing
regenerates those: they are typed in by hand and their passwords survive only as
hashes. Each time the only thing that saved the event was a backup that happened
to exist.

Competition data can be rebuilt from `kurash:demo`. The accounts cannot.

Documentation was not what stopped it; three incidents happened with the rules
already written down. **The enforcement is technical, and it is in four places:**

| Layer | Where | Stops |
|---|---|---|
| Deny rules | `.claude/settings.json` | the named commands, typed directly |
| Command hook | `.claude/hooks/block-destructive-db.sh` | destructive SQL *anywhere* in a shell command — inside `docker exec`, `bash -c`, a heredoc |
| Runtime guard | `App\Support\DatabaseGuard` | any destructive Artisan command, and the whole test suite, unless `APP_ENV=testing` **and** the resolved database ends in `_test` |
| Credentials | separate grants, see `.env.example` | the test credential reaching the application database at all |

`DatabaseGuard` reads Laravel's **resolved** configuration, never `getenv()`.
PHPUnit's `<env>` elements do not overwrite a variable already set in the process
environment, so a stray exported `DB_DATABASE` gives you a suite that reads
`kurash_test` in `phpunit.xml` while connecting to `kurash`. Asking the config for
the name the PDO connection will actually use is what closes that; asking the
environment reproduces it.

**Two credentials, two databases.** The application credential may reach the
application database and nothing else; the test credential may reach the test
database and nothing else. `.env.example` carries the grants. That is the layer
that does not depend on this application's code being correct.

### Rebuilding a local database

Exactly one path:

```sh
./scripts/reset-local-database.sh
```

It backs up, exports the accounts separately, verifies both, requires a person to
type the database name at a terminal, rebuilds, restores the accounts and checks
the count came back. It refuses when stdin is not a TTY, so no agent and no CI
job can run it, and it has no `--force`. `./dev.sh reset` refuses and points at
it.

### Backups

```sh
php artisan kurash:backup --label=before-session-2
```

Written to `kurash-manager/storage/app/backups`, on the host filesystem — not in
`/tmp`, not inside the Docker container. A nightly `03:00` backup is scheduled,
but that is the floor rather than the plan: competition data changes in bursts
over a weekend and then not at all for weeks, so run one by hand before each
session. That is the copy you will actually want.

**Nothing deletes a backup.** There used to be a `--keep=30` that pruned older
files, and it was a liability dressed as tidiness — the three times this database
was emptied, the thing that saved it was an old backup nobody had planned to
need. Disk is cheaper than a day's weigh-ins.

<details>
<summary><b>Recovering accounts from a backup</b> (this procedure has been used, and works)</summary>

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
</details>

---

## Deployment

Targeted at DirectAdmin shared hosting, which is where this runs.

DirectAdmin serves `~/domains/example.com/public_html`, and Laravel exposes only
`public/`:

```sh
cd ~/domains/example.com
mv public_html public_html.bak
ln -s /home/youruser/kurash-manager/public public_html
```

DirectAdmin prefixes databases and users with the account name — the database
will be `youruser_kurash`, not `kurash`.

```sh
# deploy
git pull && composer install --no-dev -o && php artisan migrate --force \
    && php artisan config:cache route:cache view:cache
```

```cron
* * * * * cd /home/youruser/kurash-manager && php artisan schedule:run
* * * * * cd /home/youruser/kurash-manager && php artisan queue:work --stop-when-empty --max-time=55
```

The schedule runs the nightly backup and prunes failed scoreboard pushes weekly.
The queue worker is what delivers scoreboard pushes.

Only `storage/` and `bootstrap/cache/` need to be writable. Keep `.env` above the
web root with `APP_DEBUG=false` and `APP_ENV=production` — that last one is also
what turns on `DB::prohibitDestructiveCommands()` and the strict password rules.

Assets are committed under `public/build`; run `npm run build` locally before
deploying a change to `resources/`.

---

## How the code is written

The commenting convention is the strongest one in the repository, and it is load
bearing — most of the domain knowledge lives in it. Three devices:

- **Section banners** (`/* |---- | Title | ... */`) divide a class into narrative
  sections and carry the *reasoning*, often with the rule's source URL.
- **One-line docblocks** for anything self-evident, written as a full sentence
  and often as a question: `/** Has this athlete been weighed and admitted? */`
- **Multi-paragraph docblocks** for anything with a decision behind it: a
  one-line summary, then why it is the way it is, then `@param`/`@return`.

Comments say **why**, never what. Several of them name the bug they replaced.
Generics are always annotated (`@return HasMany<Athlete, $this>`).

| Kind | Namespace | Naming |
|---|---|---|
| Rule engine / policy | `App\Services` | `<Domain>Policy`, `<Domain>Validator` |
| Value object | `App\Support` | `<Domain>Verdict`, `<Domain>Range`, `<Domain>Band` — `final readonly`, promoted properties |
| Domain exception | `App\Services` (not `App\Exceptions`) | `<Domain>Exception extends RuntimeException`, body empty, file is all docblock |
| Rule constants | `config/kurash.php` | one documented block per rule family |

Policies are resolved with `app(...)` at call sites or constructor-injected;
there are no container bindings for them. Gates use dotted `noun.verb` names and
are defined in `AppServiceProvider::configureGates()`.

### Deliberate — do not "tidy"

- Columns added for new rules are **nullable** so existing half-fought
  competitions stay readable. That is not an invitation to make the rule optional
  going forward — the form requires what the rule requires.
- Approval history (`bout_events`, `athlete_age_sanctions`) is **append-only**.
  Withdrawing something writes a second row; the current state is the newest row.
  Never update or delete one.
- Display code reads what a draw *was generated as*, never what today's entry
  list would recompute.
- `judged` and `eligible` are different questions, and only the first may pass a
  credentials check.

---

## Traps

Each of these has cost somebody an afternoon.

- **Blade's inline `@php(...)` miscompiles inside a `@foreach`/`@forelse`.** It
  emits an unterminated `<?php(` and swallows the rest of the template; you see
  `syntax error, unexpected "endif"` pointing at a file in
  `storage/framework/views/`. Use `@php ... @endphp`, or inline the expression.
  This has broken `bracket.blade.php` twice. After editing blades, run
  `php artisan view:clear && php artisan view:cache` and lint the compiled output
  before trusting a green test run.
- **Livewire public properties are browser-owned.** Array keys that look like
  model ids are whatever was posted. Resolve them through the parent's own
  relation before writing.
- **Livewire ages flash messages** before `session('error')` can be read back in
  a test. Assert with `->assertSee(...)` instead.
- **`CarbonImmutable::createFromFormat()` throws** on a non-matching value; it
  does not return `false`. Wrap it per format or the first mismatch takes the
  request down.
- **A new composite index can steal a foreign key's supporting index.** Adding
  `(age_category_id, date_of_birth)` made MariaDB adopt it for the
  `age_category_id` constraint, and the migration then could not be rolled back.
  Check another index still leads with the FK column, and always test
  `migrate:rollback` and re-apply.
- **`ArchivedChampionshipGuard` blocks every write** to an archived
  championship's models. Tests that set up historical data must write *before*
  archiving.

---

## Contributing

```sh
git checkout -b feat/short-description   # branch from dev
composer test                            # all four gates must pass
```

`main` is the release branch and `dev` is where work lands.

Commit messages carry the request that led to the change, not only a description
of the diff:

```
<type>: <short summary of the change>

Prompt: <condensed 1-2 sentence version of what was asked>

- optional bullets, only if non-obvious
```

Keep the prompt line genuinely condensed — the final ask, not the trial and
error that got there.

When you encode something from the competition rules, **cite the source** and
keep sourced rules separate from inferences. Where the published rules are
ambiguous or silent, say so in the config block and flag it for federation
confirmation rather than picking silently. There are worked examples of this in
`config/kurash.php` under `round_robin`, `bout_decision` and `age_eligibility`.

---

## Licence and credits

The rules implemented here are the
[IKA competition rules](https://kurash-ika.org/2022/08/20/kurash-rules/); this
software is not an official IKA product and ships no official artwork.

`composer.json` still carries the MIT declaration inherited from the Laravel
Livewire starter kit this was scaffolded from. **No licence has been chosen for
the application itself** — add a `LICENSE` file before publishing or
distributing it.

Built on [Laravel](https://laravel.com), [Livewire](https://livewire.laravel.com)
and [Flux UI](https://fluxui.dev), with
[Pest](https://pestphp.com) for the tests,
[Dompdf](https://github.com/barryvdh/laravel-dompdf) and
[PhpSpreadsheet](https://phpspreadsheet.readthedocs.io) for the paperwork, and
[flag-icons](https://github.com/lipis/flag-icons) for the flags.
