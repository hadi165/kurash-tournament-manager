<?php

namespace App\Livewire\Competition;

use App\Models\Athlete;
use App\Models\WeightCategory;
use App\Support\BracketSeeding;
use App\Support\Noc;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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

    /** Seconds each position is held on screen before the next is revealed. */
    private const PACE = 3;

    public function mount(WeightCategory $weightCategory): void
    {
        $this->weightCategory = $weightCategory->load('ageCategory.championship');
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
            return $total;
        }

        $elapsed = max(0, (int) now()->timestamp - (int) $pace['at']);

        return min($total, intdiv($elapsed, (int) ($pace['per'] ?? self::PACE)));
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
        ]);
    }
}
