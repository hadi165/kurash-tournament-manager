<?php

namespace App\Livewire\Scoreboard;

use App\Models\Championship;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Which board to watch.
 *
 * Everything here is scoped in the query, not filtered in the view: an account
 * pinned to one championship never has another one's mats in the payload, so
 * there is nothing to reveal by reading the page source.
 */
class Selection extends Component
{
    public function mount(): void
    {
        Gate::authorize('scoreboard.view');
    }

    public function render(): View
    {
        $user = auth()->user();

        $championships = Championship::query()
            ->whereNull('archived_at')
            ->when(
                $user?->scoreboard_championship_id !== null,
                fn ($query) => $query->whereKey($user?->scoreboard_championship_id),
            )
            ->with(['courts' => fn ($q) => $q->where('is_active', true)->withCount('bouts')])
            ->orderByDesc('starts_on')
            ->get();

        return view('livewire.scoreboard.selection', [
            'championships' => $championships,
            'readOnly' => (bool) $user?->isScoreboardViewer(),
        ]);
    }
}
