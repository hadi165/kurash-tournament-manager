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

    /** Seconds each position is held on screen before the next is revealed. */
    private const PACE = 3;

    public function mount(WeightCategory $weightCategory, bool $ceremony = false): void
    {
        $this->weightCategory = $weightCategory->load('ageCategory.championship');
        $this->ceremony = $ceremony;

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
     * Start the reveal, or run it again.
     *
     * Presentation state and nothing else: it stamps when the telling began,
     * which is the only thing the board reads to decide how much of the draw
     * the hall has seen. The draw itself was committed before this screen was
     * ever opened and no method here can touch it.
     */
    public function startCeremony(): void
    {
        Gate::authorize('presentation.operate');

        Cache::put(
            self::paceKey($this->weightCategory->id),
            ['at' => (int) now()->timestamp, 'per' => self::PACE],
            now()->addHour(),
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

        if (! is_array($pace) || ! isset($pace['at'])) {
            // A ceremony nobody has started shows nothing yet; a board with no
            // ceremony behind it simply shows the draw as it stands.
            return $this->ceremony ? 0 : $total;
        }

        $elapsed = max(0, (int) now()->timestamp - (int) $pace['at']);

        return min($total, intdiv($elapsed, (int) ($pace['per'] ?? self::PACE)));
    }

    /**
     * When the telling began and how long each position is held.
     *
     * @return array{at:int, per:int}|null
     */
    private function pace(): ?array
    {
        $pace = Cache::get(self::paceKey($this->weightCategory->id));

        return is_array($pace) && isset($pace['at'])
            ? ['at' => (int) $pace['at'], 'per' => (int) ($pace['per'] ?? self::PACE)]
            : null;
    }

    public function render(): View
    {
        /** @var Collection<int, Athlete> $drawn keyed by draw number */
        $drawn = $this->weightCategory->drawnAthletes()->get()->keyBy('draw_number');

        $total = $drawn->count();
        $revealed = $this->revealed($total);

        $size = $total >= 2 ? BracketSeeding::size($total) : 0;

        // The one being pulled now is the next position, not a spare state of
        // its own: drawing is simply "revealed + 1" until there is no more.
        $drawing = $revealed < $total ? $drawn->get($revealed + 1) : null;

        $seats = $size > 0
            ? collect(BracketSeeding::order($size))->map(fn (int $seed) => [
                'seed' => $seed,
                'athlete' => $seed <= $revealed ? $drawn->get($seed) : null,
                // The newest information on the board, and the only thing that
                // animates.
                'justFilled' => $seed === $revealed && $revealed > 0,
            ])->all()
            : [];

        // The pool is the same figure read the other way round: everyone after
        // the one being drawn. placed + drawing + remaining is the entry list,
        // every time, because all three come off $revealed.
        $remaining = $drawn->filter(fn (Athlete $a, int $number) => $number > $revealed + 1);

        return view('livewire.competition.draw-ceremony', [
            'size' => $size,
            'rounds' => $size > 0 ? BracketSeeding::totalRounds($size) : 0,
            'seats' => $seats,
            'total' => $total,
            'revealed' => $revealed,
            'drawing' => $drawing,
            'complete' => $total > 0 && $revealed >= $total,
            // Waiting is a ceremony that has not begun, which is not the same
            // as one with nothing in it.
            'waiting' => $this->ceremony && $total > 0 && ! Cache::has(self::paceKey($this->weightCategory->id)),
            'pool' => $remaining
                ->groupBy('noc_code')
                ->map(fn (Collection $group, string $noc) => [
                    'noc' => Noc::normalise($noc),
                    'name' => $group->first()?->noc_name,
                    'count' => $group->count(),
                ])
                ->sortByDesc('count')
                ->values(),
            'remainingCount' => $remaining->count(),
            // The clock the reveal is derived from, handed to the page so it
            // can find the beat inside the current position without asking the
            // server again. The draw itself is never recomputed from it.
            'pace' => $this->pace(),
        ]);
    }
}
