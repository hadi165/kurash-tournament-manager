<?php

namespace App\Livewire\Competition;

use App\Livewire\Concerns\ScopesToCompetition;
use App\Models\Championship;
use App\Models\WeightCategory;
use App\Services\MedalTable;
use Illuminate\View\View;
use Livewire\Component;

class Medals extends Component
{
    use ScopesToCompetition;

    public Championship $championship;

    public function mount(Championship $championship): void
    {
        $this->championship = $championship;
    }

    public function render(): View
    {
        $medals = app(MedalTable::class);

        $categories = WeightCategory::query()
            ->tap(fn ($q) => $this->scopeWeightCategories($q))
            ->with('ageCategory')
            ->orderBy('age_category_id')
            ->orderBy('sort_order')
            ->get();

        $events = $categories
            ->map(fn (WeightCategory $c) => ['category' => $c] + $medals->forCategory($c))
            ->filter(fn (array $e) => $e['decided'])
            ->values();

        return view('livewire.competition.medals', [
            'events' => $events,
            // The standing follows what is on the page: a men's medal table
            // counts the men's podiums.
            'standings' => $medals->standings($this->championship->id, $this->scopedCompetition()),
            'pending' => $categories->count() - $events->count(),
        ]);
    }
}
