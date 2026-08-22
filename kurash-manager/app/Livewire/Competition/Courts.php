<?php

namespace App\Livewire\Competition;

use App\Contracts\ScoreboardDriver;
use App\Models\Bout;
use App\Models\Championship;
use App\Models\Court;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Courts extends Component
{
    public Championship $championship;

    #[Validate('required|integer|min:1|max:99')]
    public ?int $number = null;

    #[Validate('nullable|string|max:255')]
    public string $name = '';

    #[Validate('nullable|url|max:255')]
    public string $scoreboard_base_url = '';

    #[Validate('nullable|string|max:255')]
    public string $scoreboard_api_key = '';

    public ?int $editingId = null;

    /*
     |--------------------------------------------------------------------------
     | Clearing a mat before deleting it
     |--------------------------------------------------------------------------
     |
     | Refusing to delete a mat that still has contests on it is right, but on
     | its own it is a dead end: it names a problem and offers no way to it.
     | The card opens instead, lists what is assigned, and moves it.
     */

    /** Which mat's assigned bouts are on show, if any. */
    public ?int $showingBoutsFor = null;

    /** Where "move all" would send them. */
    public ?int $moveTargetId = null;

    public function mount(Championship $championship): void
    {
        $this->championship = $championship;
    }

    public function edit(int $id): void
    {
        Gate::authorize('manage-competition');

        $court = $this->championship->courts()->findOrFail($id);

        $this->editingId = $court->id;
        $this->number = $court->number;
        $this->name = $court->name ?? '';
        $this->scoreboard_base_url = $court->scoreboard_base_url ?? '';
        // Deliberately not pre-filled: the stored key is encrypted and there is
        // no reason to send it back to a browser. Leave blank to keep it.
        $this->scoreboard_api_key = '';
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'number', 'name', 'scoreboard_base_url', 'scoreboard_api_key');
        $this->resetValidation();
    }

    public function save(): void
    {
        Gate::authorize('manage-competition');
        $this->validate();

        $attributes = [
            'number' => $this->number,
            'name' => $this->name ?: null,
            'scoreboard_base_url' => $this->scoreboard_base_url ?: null,
        ];

        if ($this->scoreboard_api_key !== '') {
            $attributes['scoreboard_api_key'] = $this->scoreboard_api_key;
        }

        $duplicate = $this->championship->courts()
            ->where('number', $this->number)
            ->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))
            ->exists();

        if ($duplicate) {
            $this->addError('number', __('Mat :n already exists in this championship.', ['n' => $this->number]));

            return;
        }

        if ($this->editingId !== null) {
            $this->championship->courts()->findOrFail($this->editingId)->update($attributes);
        } else {
            $this->championship->courts()->create($attributes);
        }

        $this->cancelEdit();
        session()->flash('status', __('Mat saved.'));
    }

    public function toggleActive(int $id): void
    {
        Gate::authorize('manage-competition');

        $court = $this->championship->courts()->findOrFail($id);
        $court->update(['is_active' => ! $court->is_active]);
    }

    public function delete(int $id): void
    {
        Gate::authorize('manage-competition');

        $court = $this->championship->courts()->findOrFail($id);
        $assigned = $court->bouts()->count();

        if ($assigned > 0) {
            // Opened rather than only complained about: the next thing anybody
            // wants after this message is the list it is talking about.
            $this->showingBoutsFor = $court->id;
            $this->moveTargetId ??= $this->otherMats($court)->first()?->id;

            session()->flash('error', trans_choice(
                '{1}Cannot delete Mat :n — one contest is still assigned to it. Move it to another mat first.'
                .'|[2,*]Cannot delete Mat :n — :count contests are still assigned to it. Move them to another mat first.',
                $assigned,
                ['n' => $court->number, 'count' => $assigned],
            ));

            return;
        }

        if ($this->showingBoutsFor === $court->id) {
            $this->showingBoutsFor = null;
        }

        $court->delete();
        session()->flash('status', __('Mat deleted.'));
    }

    /** Show or hide what is assigned to a mat. */
    public function toggleBouts(int $id): void
    {
        $court = $this->championship->courts()->findOrFail($id);

        if ($this->showingBoutsFor === $court->id) {
            $this->showingBoutsFor = null;

            return;
        }

        $this->showingBoutsFor = $court->id;
        $this->moveTargetId ??= $this->otherMats($court)->first()?->id;
    }

    /** Move one contest to another mat. */
    public function moveBout(int $boutId, int $targetId): void
    {
        Gate::authorize('manage-competition');

        $bout = $this->championship->bouts()->find($boutId);
        $target = $this->championship->courts()->find($targetId);

        if ($bout === null || $target === null) {
            session()->flash('error', __('That mat is not available.'));

            return;
        }

        $bout->update(['court_id' => $target->id]);

        session()->flash('status', __('Fight :n moved to :mat.', [
            'n' => $bout->fight_number ?? $bout->id,
            'mat' => $target->label(),
        ]));
    }

    /** Move everything on a mat at once, which is what emptying one means. */
    public function moveAll(int $id): void
    {
        Gate::authorize('manage-competition');

        $court = $this->championship->courts()->findOrFail($id);
        $target = $this->championship->courts()->find($this->moveTargetId);

        if ($target === null || $target->is($court)) {
            session()->flash('error', __('Choose another mat to move them to.'));

            return;
        }

        $moved = $court->bouts()->update(['court_id' => $target->id]);

        session()->flash('status', trans_choice(
            '{1}:count contest moved to :mat.|[2,*]:count contests moved to :mat.',
            $moved,
            ['count' => $moved, 'mat' => $target->label()],
        ));
    }

    /**
     * The mats a contest could be moved to — every other one in this
     * championship, inactive ones included: a mat being brought into service
     * is a reason to move work onto it, not a reason to hide it.
     *
     * @return Collection<int, Court>
     */
    private function otherMats(Court $court): Collection
    {
        return $this->championship->courts()->whereKeyNot($court->id)->get();
    }

    /** Prove the display is reachable before the competition starts. */
    public function testConnection(int $id): void
    {
        Gate::authorize('manage-competition');

        $court = $this->championship->courts()->findOrFail($id);
        $response = app(ScoreboardDriver::class)->clearCourt($court);

        session()->flash(
            $response->successful ? 'status' : 'error',
            $response->successful
                ? __('Mat :n responded.', ['n' => $court->number])
                : __('Mat :n did not respond: :message', ['n' => $court->number, 'message' => $response->message])
        );
    }

    public function render(): View
    {
        $courts = $this->championship->courts()->withCount('bouts')->get();
        $showing = $courts->firstWhere('id', $this->showingBoutsFor);

        return view('livewire.competition.courts', [
            'courts' => $courts,
            'driver' => config('scoreboard.driver'),
            'assignedBouts' => $showing === null ? collect() : $this->assignedTo($showing),
            'moveTargets' => $showing === null ? collect() : $courts->reject(fn (Court $c) => $c->is($showing))->values(),
        ]);
    }

    /**
     * What is on a mat, in the order it would be fought.
     *
     * @return Collection<int, Bout>
     */
    private function assignedTo(Court $court): Collection
    {
        return $court->bouts()
            ->with(['athleteA', 'athleteB', 'weightCategory.ageCategory'])
            ->orderByRaw('fight_number IS NULL, fight_number')
            ->get();
    }
}
