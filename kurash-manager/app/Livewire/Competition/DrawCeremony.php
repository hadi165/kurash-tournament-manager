<?php

namespace App\Livewire\Competition;

use App\Models\Athlete;
use App\Models\Bout;
use App\Models\WeightCategory;
use App\Services\RoundRobinStandings;
use App\Services\TournamentFormatPolicy;
use App\Support\BracketSeeding;
use App\Support\Noc;
use App\Support\TournamentFormat;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The draw, as a hall watches it happen.
 *
 * Read-only, and deliberately so: the draw is made on the admin screen and
 * committed in one transaction before this board shows a single position. What
 * is paced here is the *reveal*, not the draw — the result on screen is
 * already the result in the database, and refreshing mid-ceremony cannot
 * change a single seat.
 *
 * Everything on the board comes off one number, the count of positions
 * revealed so far. Board, pool panel and footer all divide that same figure,
 * because a public draw board that contradicts itself between two panels is
 * worse than one that shows less.
 *
 * Two ceremonies run on this board, and the door decides which:
 *
 *   announced  — the person at the microphone places each position by pressing,
 *                and they come in seeded order, one to eight.
 *   automatic  — the board places them itself, one a second, in an order that
 *                looks nothing like counting.
 *
 * The order is the only difference, and it is a *telling* order: it decides
 * when a seat is filled, never which seat. Every athlete lands on the number
 * the draw gave them before this screen was opened, in both modes.
 *
 * ── Two boards, one ceremony ─────────────────────────────────────────────
 *
 * What is revealed here is the *draw*: which athlete holds which draw number.
 * That is the same question in every format, which is why the pacing, the
 * pool, the order and the cache behind them are shared rather than written
 * twice — a venue board and an operator screen watching the same class have to
 * agree about which position came out when, whatever shape the competition is.
 *
 * What differs is where the names land. A knockout fills seats in a tree; a
 * round robin fills a list of draw positions and then shows the fixtures those
 * positions produced. So the board is chosen by the format the draw was
 * *generated* as — `drawFormat()`, the stored snapshot — and never by today's
 * athlete count or by a preference describing a draw that has not happened.
 * A class published as a round robin is presented as one for the life of that
 * draw, even if somebody registers a sixth athlete while the hall is watching.
 */
#[Layout('components.layouts.scoreboard')]
class DrawCeremony extends Component
{
    public WeightCategory $weightCategory;

    /**
     * True when an operator opened this to run a ceremony, rather than a
     * projector showing the board of a draw already made.
     *
     * The difference is what happens before anybody presses anything: a
     * ceremony waits to be started, a board simply shows where the draw got
     * to.
     */
    public bool $ceremony = false;

    /**
     * True when the board is to run itself, which is what the Present button in
     * Entries and Draw opens.
     *
     * A mode, not a preference: it comes off the route rather than off anything
     * the page can set, so a refresh mid-ceremony comes back to the ceremony it
     * left. See routes/web.php.
     */
    public bool $automatic = false;

    /**
     * Set once the operator has saved the finished draw.
     *
     * Presentation state, like everything else here — saving produces the
     * bracket documents and writes nothing: the draw was committed before this
     * screen existed.
     */
    public bool $saved = false;

    /** Seconds each position is held on screen before the next is revealed. */
    private const PACE = 3;

    /** The beat an automatic ceremony keeps: one athlete a second. */
    private const AUTO_PACE = 1;

    public function mount(WeightCategory $weightCategory, bool $ceremony = false, bool $automatic = false): void
    {
        $this->weightCategory = $weightCategory->load('ageCategory.championship');
        $this->ceremony = $ceremony;

        // Only a ceremony can run itself. A board on a wall shows where the
        // draw got to and places nothing.
        $this->automatic = $ceremony && $automatic;

        if ($ceremony) {
            Gate::authorize('presentation.operate');

            // An archived championship is history, and history is not
            // presented as a ceremony. The same refusal the operator's draw
            // table gives, for the same reason.
            abort_if($this->weightCategory->ageCategory?->championship?->isArchived() ?? false, 403);

            // Only a table an admin approved is presented in public.
            abort_unless(
                $this->weightCategory->isDrawPublished() && $this->weightCategory->hasDraw(),
                403,
                __('This weight category has not been published yet.'),
            );
        }
    }

    /**
     * Begin the ceremony.
     *
     * Presentation state and nothing else: it records that the telling has
     * started and that nothing has been placed yet. The draw itself was
     * committed before this screen was ever opened, and no method here can
     * touch it.
     */
    public function startCeremony(): void
    {
        Gate::authorize('presentation.operate');

        if (! $this->automatic) {
            $this->setRevealed(0);

            return;
        }

        // The telling order, settled once and kept: every poll from here — this
        // screen's and the venue board's — has to agree about which position
        // came out when, and a shuffle recomputed per request would not.
        $order = $this->weightCategory->numberedAthletes()->pluck('draw_number')
            ->map(fn ($number) => (int) $number)
            ->all();

        shuffle($order);

        Cache::put(
            $this->key(),
            ['at' => now()->timestamp, 'per' => self::AUTO_PACE, 'order' => $order],
            now()->addHours(6),
        );
    }

    /**
     * Record that the finished draw has been saved.
     *
     * Nothing is written: the bracket has been in the database since before the
     * ceremony started, and the documents are rendered from it on request. What
     * this changes is what the panel offers.
     */
    public function saveDraw(): void
    {
        Gate::authorize('presentation.operate');

        $total = $this->weightCategory->numberedAthletes()->count();

        // Saving a draw that is still being told would put a half-told bracket
        // on somebody's desk.
        if ($total > 0 && $this->revealed($total) >= $total) {
            $this->saved = true;
        }
    }

    /**
     * Place the position being drawn, and move to the next.
     *
     * The person announcing the draw sets the pace — a hall does not run to a
     * three-second timer, and a position that needs a moment gets one.
     */
    public function nextDraw(): void
    {
        Gate::authorize('presentation.operate');

        $total = $this->weightCategory->numberedAthletes()->count();

        $this->setRevealed(min($total, $this->revealed($total) + 1));
    }

    private function setRevealed(int $revealed): void
    {
        Cache::put(
            $this->key(),
            ['revealed' => $revealed],
            now()->addHours(6),
        );
    }

    /**
     * The cache key the draw stamps when it runs, so the reveal can be paced.
     *
     * Keyed by the draw as well as by the class. A class redrawn — from a
     * knockout to a round robin, or simply again — is a different draw, and the
     * telling of the old one must not survive into it: a stored shuffle of
     * eight positions has nothing to say about a round robin of two, and a
     * half-finished reveal left in the cache would open the new presentation
     * part-told. Every generation bumps draw_version, so a new draw starts with
     * a key nobody has written to.
     *
     * The version defaults to zero so a caller that has only an id still gets a
     * usable key — that is the shape this had before, and the draw a class held
     * when it was first published.
     */
    public static function paceKey(int $weightCategoryId, int $drawVersion = 0): string
    {
        return "draw-ceremony:{$weightCategoryId}:v{$drawVersion}";
    }

    /** This class's key, at the version of the draw actually on it. */
    private function key(): string
    {
        return self::paceKey($this->weightCategory->id, (int) $this->weightCategory->draw_version);
    }

    /**
     * How many positions the hall has seen.
     *
     * Without a stamp — a draw entered by hand, or one made before this screen
     * was opened — everything already drawn is simply on the board. A ceremony
     * nobody started is not a ceremony to sit through.
     */
    private function revealed(int $total): int
    {
        $pace = Cache::get($this->key());

        // Announced: the operator has placed this many, one press at a time.
        if (is_array($pace) && isset($pace['revealed'])) {
            return min($total, max(0, (int) $pace['revealed']));
        }

        // Paced: a draw run from the mat screen reveals itself on a clock, and
        // a board reopened mid-ceremony works out where it had got to.
        if (is_array($pace) && isset($pace['at'])) {
            $elapsed = max(0, (int) now()->timestamp - (int) $pace['at']);

            return min($total, intdiv($elapsed, (int) ($pace['per'] ?? self::PACE)));
        }

        // A ceremony nobody has started shows nothing yet; a board with no
        // ceremony behind it simply shows the draw as it stands.
        return $this->ceremony ? 0 : $total;
    }

    /**
     * The order the positions are told in.
     *
     * An automatic ceremony stores its shuffle when it starts; everything else
     * counts from one, which is what an announced ceremony does and what a
     * board with no ceremony behind it has always shown.
     *
     * The stored order is checked against the draw rather than trusted: a class
     * redrawn under a running ceremony leaves a shuffle describing athletes who
     * are no longer there, and counting from one is the safe answer to that.
     *
     * @return list<int>
     */
    private function order(int $total): array
    {
        $pace = Cache::get($this->key());
        $stored = is_array($pace) ? ($pace['order'] ?? null) : null;

        if (is_array($stored)) {
            $order = array_values(array_map(intval(...), $stored));

            // Every position exactly once, and no position that is not in the
            // draw: anything else is a shuffle for a draw that has moved.
            $expected = range(1, $total);
            $sorted = $order;
            sort($sorted);

            if ($total > 0 && $sorted === $expected) {
                return $order;
            }
        }

        return $total > 0 ? range(1, $total) : [];
    }

    /**
     * When the telling began and how long each position is held.
     *
     * @return array{at:int, per:int}|null
     */
    private function pace(): ?array
    {
        $pace = Cache::get($this->key());

        // Only a clock-paced ceremony has a beat to find; an announced one
        // changes when somebody presses, which the poll already carries.
        if (! is_array($pace) || ! isset($pace['at']) || isset($pace['revealed'])) {
            return null;
        }

        $per = (int) ($pace['per'] ?? self::PACE);

        // A position held for a second has no room inside it for a pause before
        // the name lands: the anticipation would cover the whole beat and the
        // board would never show anybody. An automatic ceremony simply places.
        return $per > 1 ? ['at' => (int) $pace['at'], 'per' => $per] : null;
    }

    /**
     * The generated fixtures, grouped by round, as the board shows them.
     *
     * Derived entirely from the persisted Bout rows: this reads the draw, it
     * does not reconstruct it. Nothing here consults BracketSeeding, the entry
     * count or the athlete list — a fixture exists because the generator wrote
     * it, and the board's job is to say so.
     *
     * A pairing is held back until both of its athletes have been placed. That
     * is the same rule the bracket board follows for a seat, and it is what
     * stops the fixtures giving away positions the hall has not been told yet.
     *
     * @param  array<int, int>  $filled  draw numbers placed so far
     * @param  Collection<int, Bout>  $bouts  the class's contests, already loaded
     * @return array<int, list<array<string, mixed>>>
     */
    private function pairings(array $filled, Collection $bouts): array
    {
        $rounds = [];

        foreach ($bouts as $bout) {
            $a = $bout->athleteA;
            $b = $bout->athleteB;

            $rounds[(int) $bout->round][] = [
                'id' => $bout->id,
                // Both sides told, or the fixture waits.
                'revealed' => $a !== null && $b !== null
                    && isset($filled[(int) $a->draw_number])
                    && isset($filled[(int) $b->draw_number]),
                'fight' => $bout->fight_number,
                'a' => $this->competitor($a),
                'b' => $this->competitor($b),
                'winner' => $bout->winner?->fullname,
                // The id, because the board decides which *side* won by it.
                // Two athletes sharing a full name is common in a small
                // regional class, and a name comparison would gild them both.
                'winner_id' => $bout->winner_athlete_id === null ? null : (int) $bout->winner_athlete_id,
                'decided' => $bout->winner_athlete_id !== null,
            ];
        }

        return $rounds;
    }

    /**
     * One side of a fixture, with everything the board needs to draw it.
     *
     * "Draw No." rather than a seed: a round robin seeds nobody, and a number
     * presented as a seed on a public board says the competition works a way
     * it does not.
     *
     * @return array<string, mixed>|null
     */
    private function competitor(?Athlete $athlete): ?array
    {
        if ($athlete === null) {
            return null;
        }

        return [
            'id' => (int) $athlete->id,
            'draw' => $athlete->draw_number === null ? null : (int) $athlete->draw_number,
            'name' => (string) $athlete->fullname,
            'noc' => Noc::normalise($athlete->noc_code),
            'country' => $athlete->noc_name,
            'iso' => Noc::iso($athlete->noc_code),
        ];
    }

    public function render(): View
    {
        /** @var Collection<int, Athlete> $drawn keyed by draw number */
        $drawn = $this->weightCategory->numberedAthletes()->get()->keyBy('draw_number');

        $total = $drawn->count();
        $revealed = $this->revealed($total);

        /*
         | The board is chosen by what the draw *was generated as*.
         |
         | Never by the athlete count and never by the stored preference: both
         | describe a draw that might be made, and this screen is showing one
         | that already exists. A class published as a round robin that has
         | since gained a sixth entry is still presented as the round robin the
         | hall was told about.
         */
        $format = $this->weightCategory->drawFormat()
            // Nothing generated yet, which is the board's other job: the
            // positions are being pulled and the contests do not exist to be
            // read. Only here is the rule consulted, and only to preview the
            // shape the draw is heading for — the moment a draw exists, the
            // stored snapshot above answers and this line is never reached.
            // Compliant, because the shape being previewed is the one drawing
            // would produce with nobody signing an override.
            ?? app(TournamentFormatPolicy::class)->resolveCompliantFor($this->weightCategory, $total);

        // Seats belong to a tree. Nothing computes one for a format that has
        // none — a round robin asked for a bracket size would be handed the
        // next power of two and quietly draw the wrong competition.
        $size = $format === TournamentFormat::Knockout && $total >= 2
            ? BracketSeeding::size($total)
            : 0;

        // Which positions have been told, in the order they were told. An
        // announced ceremony counts from one; an automatic one does not, which
        // is the only thing that separates them.
        $order = $this->order($total);
        $placed = array_slice($order, 0, $revealed);
        $filled = array_flip($placed);

        // Waiting is a ceremony that has not begun, which is not the same as
        // one with nothing in it.
        $waiting = $this->ceremony && $total > 0 && ! Cache::has($this->key());

        // The one being pulled now is simply the next in that order — and
        // before the ceremony starts there is no such person: everybody is
        // still in the pot, and the panel that counts them has to say so.
        $drawingSeat = $waiting ? null : ($order[$revealed] ?? null);
        $drawing = $drawingSeat === null ? null : $drawn->get($drawingSeat);

        // The newest name on the board, whichever seat it landed on.
        $newest = $placed === [] ? null : $placed[count($placed) - 1];

        $seats = $size > 0
            ? collect(BracketSeeding::order($size))->map(function (int $seed) use ($drawn, $filled, $newest) {
                $athlete = isset($filled[$seed]) ? $drawn->get($seed) : null;

                return [
                    'seed' => $seed,
                    'athlete' => $athlete,
                    // Resolved here, like the pool's: which flag a code belongs
                    // to is a question about the nation, not about the markup.
                    'iso' => $athlete === null ? null : Noc::iso($athlete->noc_code),
                    // The newest information on the board, and the only thing
                    // that animates.
                    'justFilled' => $newest !== null && $seed === $newest,
                ];
            })->all()
            : [];

        // The pool is the same figure read the other way round: everyone who is
        // neither placed nor being placed. placed + drawing + remaining is the
        // entry list, every time, because all three come off the one order.
        $remaining = $drawn->reject(
            fn (Athlete $a, int $number) => isset($filled[$number]) || $number === $drawingSeat
        );

        /*
         | The positions themselves, for a telling with no contests behind it.
         |
         | drawAtRandom() can pull positions before any draw is generated, and
         | for a small field the board previews as a round robin — which has no
         | fixtures to reveal the names onto, because none exist yet. The
         | bracket board has its seat grid for this moment; the round robin
         | gets the same thing in its own terms: the draw numbers, filling one
         | by one as they are told.
         */
        $positions = $format === TournamentFormat::RoundRobin && $total > 0
            ? $drawn->keys()->map(fn ($number) => (int) $number)->sort()->values()
                ->map(fn (int $number) => [
                    'number' => $number,
                    'athlete' => isset($filled[$number]) ? $drawn->get($number) : null,
                    'iso' => isset($filled[$number]) ? Noc::iso($drawn->get($number)?->noc_code) : null,
                    'justFilled' => $newest !== null && $number === $newest,
                ])->all()
            : [];

        // One fetch for everything the round-robin board says about its
        // contests. This screen polls twice a second on a venue wall, and the
        // fixture list, the contest count and the round count must all
        // describe the same instant anyway.
        $bouts = $format === TournamentFormat::RoundRobin
            ? $this->weightCategory->bouts()
                ->with(['athleteA', 'athleteB', 'winner'])
                ->orderBy('round')
                ->orderBy('position_in_round')
                ->get()
            : collect();

        return view('livewire.competition.draw-ceremony', [
            'format' => $format,
            'positions' => $positions,
            // The fixtures, read off the contests the generator committed —
            // never recomputed here. A pairing appears once both of its
            // athletes have been placed, which is the round robin's answer to
            // a bracket filling up.
            'pairings' => $format === TournamentFormat::RoundRobin
                ? $this->pairings($filled, $bouts)
                : [],
            'contests' => $bouts->count(),
            'roundCount' => (int) ($bouts->max('round') ?? 0),
            // Shown once the telling is over: a table beside a draw still being
            // revealed answers the hall before the reveal reaches it.
            'standings' => $format === TournamentFormat::RoundRobin && $total > 0 && $revealed >= $total
                ? app(RoundRobinStandings::class)->forCategory($this->weightCategory)
                : null,
            'placed' => $format === TournamentFormat::Placement
                ? $this->weightCategory->placedAthlete
                : null,
            'size' => $size,
            'rounds' => $size > 0 ? BracketSeeding::totalRounds($size) : 0,
            'seats' => $seats,
            'total' => $total,
            'revealed' => $revealed,
            'drawing' => $drawing,
            'complete' => $total > 0 && $revealed >= $total,
            'waiting' => $waiting,
            /*
             | Everyone still in the pot, one line each.
             |
             | Athletes rather than delegations: this panel used to group by
             | nation and show a count, which read as a short list of people to
             | anybody watching — eleven rows against nineteen athletes.
             |
             | Ordered by name, which says nothing about who comes out next.
             | Draw order here would be a countdown in an announced ceremony and
             | would give the shuffle away in an automatic one.
             */
            'pool' => $remaining
                ->sortBy(fn (Athlete $athlete) => mb_strtolower($athlete->fullname))
                ->values()
                ->map(fn (Athlete $athlete) => [
                    'id' => $athlete->id,
                    'name' => $athlete->fullname,
                    'noc' => Noc::normalise($athlete->noc_code),
                    'country' => $athlete->noc_name,
                    // Resolved here rather than in the view: which flag a
                    // three-letter code belongs to is a question about the
                    // nation, not about the markup.
                    'iso' => Noc::iso($athlete->noc_code),
                ]),
            'remainingCount' => $remaining->count(),
            // The bracket documents, once there is a finished draw to save.
            'saveable' => $this->ceremony && $total > 0 && $revealed >= $total,
            // The clock the reveal is derived from, handed to the page so it
            // can find the beat inside the current position without asking the
            // server again. The draw itself is never recomputed from it.
            'pace' => $this->pace(),
        ]);
    }
}
