<?php

namespace App\Livewire\Competition;

use App\Exports\BracketSheet;
use App\Models\Championship;
use App\Models\WeightCategory;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Every drawn bracket in the championship, with its sheet.
 *
 * A reading screen: the draw sheet is what an official carries to the mat, and
 * this is where it comes off the system. Nothing here writes.
 */
class Brackets extends Component
{
    public Championship $championship;

    public function mount(Championship $championship): void
    {
        $this->championship = $championship;
    }

    public function render(): View
    {
        $categories = WeightCategory::query()
            ->whereHas('ageCategory', fn ($q) => $q->where('championship_id', $this->championship->id))
            ->with('ageCategory')
            ->withCount(['bouts', 'athletes'])
            ->get()
            ->sortBy(fn (WeightCategory $c) => [$c->ageCategory->sort_order, $c->sort_order, $c->label])
            ->values();

        return view('livewire.competition.brackets', [
            // The tree's own description, so the row can report the shape the
            // sheet will print rather than guessing at it.
            'sheets' => $categories->mapWithKeys(fn (WeightCategory $c) => [
                $c->id => $c->bouts_count > 0 ? new BracketSheet($c) : null,
            ]),
            'categories' => $categories,
        ]);
    }
}
