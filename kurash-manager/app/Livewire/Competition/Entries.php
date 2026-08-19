<?php

namespace App\Livewire\Competition;

use App\Models\Athlete;
use App\Models\Championship;
use App\Models\WeightCategory;
use App\Support\BracketSeeding;
use App\Support\Noc;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Entry counts and competition readiness — specification §6.1 and §6.2.
 *
 * The two counts sit on one screen because they are the same question asked by
 * two people: a team manager wants their delegation's line, and the executive
 * team wants to know which weight classes can start. The by-weight table is the
 * launch board — it is what replaced the old standalone "Start Competition"
 * page, and its Start button opens that class's draw directly rather than
 * stopping at a file upload first.
 */
class Entries extends Component
{
    public Championship $championship;

    /** 'weight' or 'noc' — which count is on top. */
    public string $view = 'weight';

    public function mount(Championship $championship): void
    {
        $this->championship = $championship;
    }

    /**
     * One line per weight class: registered, cleared, the bracket that field
     * needs, and whether it has been drawn.
     *
     * A plain list rather than a Collection: the counts come back as positive
     * integers and Collection's value type is invariant, so a Collection here
     * would force the shape to be declared in terms phpstan infers rather than
     * terms this screen means.
     *
     * @return list<array{category: WeightCategory, registered: int, cleared: int, bracket: string|null, drawn: bool}>
     */
    private function byWeight(): array
    {
        $categories = WeightCategory::query()
            ->whereHas('ageCategory', fn ($q) => $q->where('championship_id', $this->championship->id))
            ->with('ageCategory')
            ->withCount(['athletes', 'bouts'])
            ->get()
            ->sortBy(fn (WeightCategory $c) => [$c->ageCategory->sort_order, $c->sort_order, $c->label])
            ->values();

        // One grouped query rather than a withCount alias per class, so the
        // page holds at a championship with sixty weight classes in it.
        $passed = Athlete::query()
            ->where('championship_id', $this->championship->id)
            ->where('weighin_status', 'pass')
            ->whereNotNull('weight_category_id')
            ->groupBy('weight_category_id')
            ->selectRaw('weight_category_id, COUNT(*) AS total')
            ->pluck('total', 'weight_category_id');

        $rows = [];

        foreach ($categories as $category) {
            $cleared = (int) ($passed[$category->id] ?? 0);
            $registered = (int) ($category->athletes_count ?? 0);

            $rows[] = [
                'category' => $category,
                'registered' => $registered,
                'cleared' => $cleared,
                'bracket' => $cleared >= 2 ? BracketSeeding::phaseName($cleared) : null,
                'drawn' => $category->bouts_count > 0,
            ];
        }

        return $rows;
    }

    /** @return list<array{noc: string, name: string|null, male: int, female: int, cleared: int, total: int}> */
    private function byNoc(): array
    {
        $rows = [];

        foreach ($this->championship->athletes()->get()->groupBy('noc_code') as $noc => $group) {
            $name = $group->first()?->noc_name;

            $rows[] = [
                // normalise() returns null for a blank code; the group key is
                // what the athlete rows actually hold, so it is the fallback.
                'noc' => Noc::normalise((string) $noc) ?? (string) $noc,
                'name' => is_string($name) ? $name : null,
                'male' => (int) $group->where('gender', 'M')->count(),
                'female' => (int) $group->where('gender', 'F')->count(),
                'cleared' => (int) $group->where('weighin_status', 'pass')->count(),
                'total' => (int) $group->count(),
            ];
        }

        // Largest delegations first — the order these are read in — then
        // alphabetically so equal-sized ones stay findable.
        usort($rows, fn (array $a, array $b) => [$b['total'], $a['noc']] <=> [$a['total'], $b['noc']]);

        return $rows;
    }

    public function render(): View
    {
        $byWeight = $this->byWeight();

        $readyToDraw = array_filter(
            $byWeight,
            fn (array $row) => ! $row['drawn'] && $row['cleared'] >= 2,
        );

        return view('livewire.competition.entries', [
            'byWeight' => $byWeight,
            'byNoc' => $this->byNoc(),
            'readyToDraw' => count($readyToDraw),
            'totalEntries' => array_sum(array_column($byWeight, 'registered')),
            'totalCleared' => array_sum(array_column($byWeight, 'cleared')),
        ]);
    }
}
