<?php

namespace App\Livewire\Referee;

use App\Models\Bout;
use App\Models\Court;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Where a referee lands: the mats they may work, and nothing else.
 *
 * A referee account has no dashboard to be sent to — every competition screen
 * is closed to it — so signing in has to arrive somewhere it can actually
 * start. This is that screen. It writes nothing; picking a mat opens the mat
 * screen, which is where the scoring is.
 *
 * Admins and supervisors reach it too, because it is a reasonable way into the
 * mats and there is no reason to make them find the championship first.
 */
class Mats extends Component
{
    public function mount(): void
    {
        Gate::authorize('mat.view');
    }

    /**
     * Mats this account may work.
     *
     * Scoped by the account's championship where it has one, the same way the
     * scoreboard viewer's list is — a referee assigned to one event should not
     * be offered another's mats, and the scope is applied in the query rather
     * than filtered afterwards.
     *
     * @return Collection<int, Court>
     */
    private function courts(): Collection
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

    public function render(): View
    {
        $courts = $this->courts();

        // What is on each mat right now, so a referee walking up to one knows
        // whether it is running before they open it. One query for every mat
        // rather than one per row.
        $live = Bout::query()
            ->whereIn('court_id', $courts->pluck('id'))
            ->where('status', Bout::STATUS_ON_COURT)
            ->whereNull('winner_athlete_id')
            ->with(['athleteA', 'athleteB', 'weightCategory'])
            ->get()
            ->keyBy('court_id');

        return view('livewire.referee.mats', [
            'courts' => $courts,
            'live' => $live,
        ]);
    }
}
