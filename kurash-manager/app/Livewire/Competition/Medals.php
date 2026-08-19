<?php

namespace App\Livewire\Competition;

use App\Models\Championship;
use App\Models\WeightCategory;
use App\Services\MedalTable;
use Illuminate\View\View;
use Livewire\Component;

class Medals extends Component
{
    public Championship $championship;

    public function mount(Championship $championship): void
    {
        $this->championship = $championship;
    }

    public function render(): View
    {
        $medals = app(MedalTable::class);

        $categories = WeightCategory::whereHas(
            'ageCategory',
            fn ($q) => $q->where('championship_id', $this->championship->id)
        )->with('ageCategory')->orderBy('age_category_id')->orderBy('sort_order')->get();

        $events = $categories
            ->map(fn (WeightCategory $c) => ['category' => $c] + $medals->forCategory($c))
            ->filter(fn (array $e) => $e['decided'])
            ->values();

        return view('livewire.competition.medals', [
            'events' => $events,
            'standings' => $medals->standings($this->championship->id),
            'pending' => $categories->count() - $events->count(),
        ]);
    }
}
