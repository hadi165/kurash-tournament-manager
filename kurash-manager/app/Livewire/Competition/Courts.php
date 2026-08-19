<?php

namespace App\Livewire\Competition;

use App\Contracts\ScoreboardDriver;
use App\Models\Championship;
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

        if ($court->bouts()->exists()) {
            session()->flash('error', __('Cannot delete: bouts are assigned to this mat.'));

            return;
        }

        $court->delete();
        session()->flash('status', __('Mat deleted.'));
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
        return view('livewire.competition.courts', [
            'courts' => $this->championship->courts()->withCount('bouts')->get(),
            'driver' => config('scoreboard.driver'),
        ]);
    }
}
