<?php

namespace App\Livewire\Competition;

use App\Models\Athlete;
use App\Models\WeightCategory;
use App\Support\BracketSeeding;
use App\Support\Noc;
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
        $order = $this->weightCategory->drawnAthletes()->pluck('draw_number')
            ->map(fn ($number) => (int) $number)
            ->all();

        shuffle($order);

        Cache::put(
            self::paceKey($this->weightCategory->id),
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

        $total = $this->weightCategory->drawnAthletes()->count();

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

        $total = $this->weightCategory->drawnAthletes()->count();

        $this->setRevealed(min($total, $this->revealed($total) + 1));
    }

    private function setRevealed(int $revealed): void
    {
        Cache::put(
            self::paceKey($this->weightCategory->id),
            ['revealed' => $revealed],
            now()->addHours(6),
        );
    }

    /** The cache key the draw stamps when it runs, so the reveal can be paced. */
    public static function paceKey(int $weightCategoryId): string
    {
        return "draw-ceremony:{$weightCategoryId}";
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
        $pace = Cache::get(self::paceKey($this->weightCategory->id));

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
        $pace = Cache::get(self::paceKey($this->weightCategory->id));
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
        $pace = Cache::get(self::paceKey($this->weightCategory->id));

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

    public function render(): View
    {
        /** @var Collection<int, Athlete> $drawn keyed by draw number */
        $drawn = $this->weightCategory->drawnAthletes()->get()->keyBy('draw_number');

        $total = $drawn->count();
        $revealed = $this->revealed($total);

        $size = $total >= 2 ? BracketSeeding::size($total) : 0;

        // Which positions have been told, in the order they were told. An
        // announced ceremony counts from one; an automatic one does not, which
        // is the only thing that separates them.
        $order = $this->order($total);
        $placed = array_slice($order, 0, $revealed);
        $filled = array_flip($placed);

        // Waiting is a ceremony that has not begun, which is not the same as
        // one with nothing in it.
        $waiting = $this->ceremony && $total > 0 && ! Cache::has(self::paceKey($this->weightCategory->id));

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

        return view('livewire.competition.draw-ceremony', [
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
