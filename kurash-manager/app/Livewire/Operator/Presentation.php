<?php

namespace App\Livewire\Operator;

use App\Models\Bout;
use App\Models\WeightCategory;
use App\Support\BracketSeeding;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The published draw, as an operator presents it.
 *
 * Read-only in the strongest sense: this component has no method that writes.
 * The reveal is client state — which row is showing, whether it is running —
 * and the table underneath it is the stored official one, loaded once.
 */
class Presentation extends Component
{
    public WeightCategory $weightCategory;

    /**
     * The version this page was opened against.
     *
     * If an admin regenerates while somebody is presenting, the page says so
     * rather than swapping the table underneath them: two versions of a draw
     * must never appear in one presentation.
     */
    public int $openedVersion = 0;

    public function mount(WeightCategory $weightCategory): void
    {
        Gate::authorize('draw.view_published');

        $this->weightCategory = $weightCategory->load('ageCategory.championship');

        // Published, or nothing. The check is here rather than in the query so
        // an unpublished id gets a refusal instead of a silent empty page.
        abort_unless(
            $this->weightCategory->isDrawPublished() && $this->weightCategory->hasDraw(),
            403,
            __('This weight category has not been published yet.'),
        );

        abort_if($this->weightCategory->ageCategory?->championship?->isArchived() ?? false, 403);

        $this->openedVersion = (int) $this->weightCategory->draw_version;
    }

    public function render(): View
    {
        $bouts = $this->weightCategory->bouts()
            ->with(['athleteA', 'athleteB', 'winner'])
            ->orderBy('round')
            ->orderBy('position_in_round')
            ->get();

        $rounds = $bouts->groupBy('round');
        $totalRounds = (int) ($bouts->max('round') ?? 0);

        return view('livewire.operator.presentation', [
            'bouts' => $bouts,
            'rounds' => $rounds,
            'totalRounds' => $totalRounds,
            // The order the reveal follows: the official one, round by round
            // and position by position, exactly as generated.
            'reveal' => $bouts->values(),
            'phaseName' => fn (int $round) => BracketSeeding::phaseName(
                (int) (($this->weightCategory->draw_bucket_size ?: 2) / (2 ** ($round - 1)))
            ),
            'stale' => (int) $this->weightCategory->fresh()?->draw_version !== $this->openedVersion,
            'firstRoundBouts' => $rounds->get(1)?->reject(fn (Bout $bout) => $bout->is_bye)->count() ?? 0,
        ]);
    }
}
