<?php

namespace App\Livewire\Competition;

use App\Models\Athlete;
use App\Models\Championship;
use App\Models\WeightCategory;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The first screen after signing in.
 *
 * Written to answer one question — what needs doing next — rather than to
 * display totals. Running an event is a sequence (register, weigh in, draw,
 * schedule, fight), and the common failure is not knowing which step a weight
 * class is stuck on. So each championship reports its blockers, and the
 * numbers are there to support them.
 */
class Dashboard extends Component
{
    public function render(): View
    {
        $championships = Championship::query()
            ->withCount('athletes')
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Championship $c) => $this->summarise($c));

        return view('livewire.competition.dashboard', [
            'championships' => $championships,
        ])->title(__('Dashboard'));
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(Championship $championship): array
    {
        $categories = WeightCategory::query()
            ->whereHas('ageCategory', fn ($q) => $q->where('championship_id', $championship->id))
            ->withCount([
                'athletes',
                'bouts',
            ])
            ->get();

        $passed = Athlete::query()
            ->where('championship_id', $championship->id)
            ->where('weighin_status', 'pass')
            ->count();

        $awaitingScale = Athlete::query()
            ->where('championship_id', $championship->id)
            ->where('weighin_status', 'pending')
            ->count();

        $bouts = $championship->bouts();

        $total = (clone $bouts)->where('is_bye', false)->count();
        $decided = (clone $bouts)->where('is_bye', false)->whereNotNull('winner_athlete_id')->count();
        $onMat = (clone $bouts)->whereNotNull('court_id')->whereNull('winner_athlete_id')->count();
        $unscheduled = (clone $bouts)->where('is_bye', false)->whereNull('fight_number')->count();

        // A class with athletes but no bracket is the single most common thing
        // to be stuck on, and the one the fight order cannot work around.
        $undrawn = $categories->filter(fn (WeightCategory $c) => $c->athletes_count > 0 && $c->bouts_count === 0);

        return [
            'model' => $championship,
            'athletes' => $championship->athletes_count,
            'classes' => $categories->count(),
            'passed' => $passed,
            'awaiting_scale' => $awaitingScale,
            'bouts' => $total,
            'decided' => $decided,
            'on_mat' => $onMat,
            'progress' => $total > 0 ? (int) round($decided / $total * 100) : 0,
            'mats' => $championship->courts()->where('is_active', true)->count(),
            'next_steps' => $this->nextSteps($championship, $categories, $undrawn, $unscheduled, $awaitingScale),
        ];
    }

    /**
     * The blockers, in the order an event actually hits them.
     *
     * @param  Collection<int, WeightCategory>  $categories
     * @param  Collection<int, WeightCategory>  $undrawn
     * @return list<array{text: string, route: ?string, params: array<string, mixed>, label: ?string}>
     */
    private function nextSteps(
        Championship $championship,
        Collection $categories,
        Collection $undrawn,
        int $unscheduled,
        int $awaitingScale,
    ): array {
        $steps = [];

        if ($categories->isEmpty()) {
            $steps[] = [
                'text' => __('No weight classes yet.'),
                'route' => 'championships.show',
                'params' => ['championship' => $championship],
                'label' => __('Set up categories'),
            ];

            return $steps;
        }

        if ($championship->athletes_count === 0) {
            $steps[] = [
                'text' => __('Nobody is registered yet.'),
                'route' => 'championships.show',
                'params' => ['championship' => $championship],
                'label' => __('Register athletes'),
            ];

            return $steps;
        }

        if ($awaitingScale > 0) {
            $steps[] = [
                'text' => trans_choice(
                    '{1}:count athlete has not been weighed in.|[2,*]:count athletes have not been weighed in.',
                    $awaitingScale,
                    ['count' => $awaitingScale]
                ),
                'route' => null, 'params' => [], 'label' => null,
            ];
        }

        if ($undrawn->isNotEmpty()) {
            $first = $undrawn->first();

            $steps[] = [
                'text' => trans_choice(
                    '{1}:count weight class has athletes but no bracket.|[2,*]:count weight classes have athletes but no bracket.',
                    $undrawn->count(),
                    ['count' => $undrawn->count()]
                ),
                'route' => 'bracket.show',
                'params' => ['weightCategory' => $first],
                'label' => __('Draw :label kg', ['label' => $first->label]),
            ];
        }

        if ($unscheduled > 0) {
            $steps[] = [
                'text' => trans_choice(
                    '{1}:count bout has no fight number.|[2,*]:count bouts have no fight number.',
                    $unscheduled,
                    ['count' => $unscheduled]
                ),
                'route' => 'fight-order.index',
                'params' => ['championship' => $championship],
                'label' => __('Build the running order'),
            ];
        }

        if ($championship->courts()->where('is_active', true)->count() === 0) {
            $steps[] = [
                'text' => __('No mats are set up, so nothing can be sent to a scoreboard.'),
                'route' => 'courts.index',
                'params' => ['championship' => $championship],
                'label' => __('Add a mat'),
            ];
        }

        return $steps;
    }
}
