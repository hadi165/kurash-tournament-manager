<?php

namespace App\Livewire\Competition;

use App\Models\Championship;
use App\Services\DashboardSnapshot;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Component;

/**
 * What is on each mat, as a panel the dashboard can refresh on its own.
 *
 * A separate component for one reason: this is the only part of the dashboard
 * that changes second to second, and the rest of the page costs a dozen queries
 * to build. Polling the whole screen every ten seconds would recount the entry
 * list, rebuild the medal standings and re-derive every blocker in order to
 * find out whether a contest had finished.
 *
 * The venue's own mats screen is still the authority for the hall; this is the
 * desk's copy of the same question, rendered in the operator design system.
 */
class LiveMats extends Component
{
    /**
     * Held as an id, not a model.
     *
     * A Livewire property arrives from the browser on every poll. Resolving it
     * through Championship::open() each time costs one indexed lookup and means
     * a tampered or newly archived id renders nothing rather than a mat list
     * from a competition the viewer was never shown.
     */
    public int $championshipId;

    public function render(): View
    {
        $championship = Championship::open()->whereKey($this->championshipId)->first();

        return view('livewire.competition.live-mats', [
            'championship' => $championship,
            'mats' => $championship === null
                ? new Collection
                : app(DashboardSnapshot::class)->mats($championship),
        ]);
    }
}
