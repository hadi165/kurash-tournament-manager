<?php

namespace App\Livewire\Scoreboard;

use App\Models\Court;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The whole scoreboard experience for an account that may only watch.
 *
 * One component covers all three shapes the spec asks for — no mats, one mat,
 * several — because they are the same question answered against the same
 * scoped query. Picking a mat changes which board is on screen and nothing
 * else: there is no method here that writes.
 */
#[Layout('components.layouts.scoreboard-viewer')]
class Viewer extends Component
{
    public ?int $courtId = null;

    public function mount(?Court $court = null): void
    {
        Gate::authorize('scoreboard.view');

        // A mat named in the URL is authorised before it is accepted, so a
        // tampered id is refused rather than quietly swapped for a safe one.
        if ($court?->exists) {
            Gate::authorize('scoreboard.select_court', $court);

            $this->courtId = $court->id;
        }

        $this->settle();
    }

    /**
     * Choose a mat.
     *
     * The id is checked against the same scoped query the selector was built
     * from, so a forged request naming somebody else's mat finds nothing and
     * is refused — the browser's copy of the list is never the authority.
     */
    public function selectMat(int $id): void
    {
        Gate::authorize('scoreboard.view');

        $court = $this->availableCourts()->firstWhere('id', $id);

        abort_if($court === null, 403);

        $this->courtId = $court->id;
    }

    /**
     * Mats this account may watch, straight from a scoped query.
     *
     * @return Collection<int, Court>
     */
    private function availableCourts(): Collection
    {
        $user = auth()->user();

        return Court::query()
            ->where('is_active', true)
            ->whereHas('championship', function ($query) use ($user) {
                $query->whereNull('archived_at');

                if ($user?->scoreboard_championship_id !== null) {
                    $query->whereKey($user->scoreboard_championship_id);
                }
            })
            ->with('championship')
            ->orderBy('championship_id')
            ->orderBy('number')
            ->get();
    }

    /**
     * Keep the selection honest.
     *
     * One mat selects itself — nobody should have to choose from a list of
     * one — and a selection that has since been deactivated or moved out of
     * scope is dropped rather than carried into a board the account may no
     * longer see.
     */
    private function settle(): void
    {
        $courts = $this->availableCourts();

        if ($this->courtId !== null && ! $courts->contains('id', $this->courtId)) {
            $this->courtId = null;
        }

        if ($this->courtId === null && $courts->count() === 1) {
            $this->courtId = $courts->first()?->id;
        }
    }

    public function render(): View
    {
        $this->settle();

        $courts = $this->availableCourts();

        return view('livewire.scoreboard.viewer', [
            'courts' => $courts,
            'court' => $this->courtId ? $courts->firstWhere('id', $this->courtId) : null,
        ]);
    }
}
