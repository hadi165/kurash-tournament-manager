<?php

namespace App\Livewire\Competition;

use App\Models\Championship;
use App\Services\MedalTable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Closed competitions and the reports that came out of them.
 *
 * The specification's ninth navigation item. What makes it an archive rather
 * than a filter is that an archived championship stops accepting changes — the
 * exports below it will say the same thing next season as they do today.
 */
class Archive extends Component
{
    /** Championship the operator is being asked to confirm reopening. */
    public ?int $confirmingReopen = null;

    public string $reopenReason = '';

    public function archive(int $championshipId): void
    {
        Gate::authorize('manage-competition');

        $championship = Championship::findOrFail($championshipId);

        $undecided = $championship->bouts()->whereNull('winner_athlete_id')->count();

        if ($undecided > 0) {
            session()->flash('error', trans_choice(
                '{1}:count contest has not been decided yet.|[2,*]:count contests have not been decided yet.',
                $undecided,
                ['count' => $undecided]
            ));

            return;
        }

        $championship->archive(auth()->user());

        session()->flash('status', __(':title is archived.', ['title' => $championship->title]));
    }

    public function confirmReopen(int $championshipId): void
    {
        $this->confirmingReopen = $championshipId;
        $this->reopenReason = '';
    }

    public function cancelReopen(): void
    {
        $this->confirmingReopen = null;
        $this->reopenReason = '';
    }

    /**
     * Reopening asks for a reason, because it is the one action that can change
     * a result somebody has already been given a medal for. The reason goes on
     * the record next to who did it.
     */
    public function reopen(int $championshipId): void
    {
        Gate::authorize('manage-competition');

        $reason = trim($this->reopenReason);

        if ($reason === '') {
            $this->addError('reopenReason', __('Say why this is being reopened.'));

            return;
        }

        Championship::findOrFail($championshipId)->reopen(auth()->user(), $reason);

        $this->cancelReopen();

        session()->flash('status', __('Reopened. The reason is on the record.'));
    }

    /** @return Collection<int, Championship> */
    private function archived(): Collection
    {
        return Championship::query()
            ->archived()
            ->with(['events.user', 'archivedBy'])
            ->withCount('athletes')
            ->orderByDesc('archived_at')
            ->get();
    }

    /**
     * Finished competitions that have not been closed yet — the ones this
     * screen is actually asking someone to do something about.
     *
     * @return Collection<int, Championship>
     */
    private function closable(): Collection
    {
        return Championship::query()
            ->open()
            ->withCount([
                'bouts',
                'bouts as undecided_count' => fn ($q) => $q->whereNull('winner_athlete_id'),
            ])
            ->having('bouts_count', '>', 0)
            ->orderBy('title')
            ->get();
    }

    public function render(): View
    {
        $medals = app(MedalTable::class);

        $archived = $this->archived();

        return view('livewire.competition.archive', [
            'archived' => $archived,
            'closable' => $this->closable(),
            // The headline figure a closed championship is remembered by.
            'standings' => $archived->mapWithKeys(
                fn (Championship $c) => [$c->id => $medals->standings($c->id)->take(3)]
            ),
        ]);
    }
}
