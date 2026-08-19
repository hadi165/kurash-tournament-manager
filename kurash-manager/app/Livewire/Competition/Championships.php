<?php

namespace App\Livewire\Competition;

use App\Models\Championship;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Championships extends Component
{
    #[Validate('required|string|max:255')]
    public string $title = '';

    #[Validate('nullable|string|max:255')]
    public string $location = '';

    #[Validate('nullable|date')]
    public ?string $starts_on = null;

    public ?int $editingId = null;

    public function edit(int $id): void
    {
        Gate::authorize('manage-competition');

        $championship = Championship::findOrFail($id);

        $this->editingId = $championship->id;
        $this->title = $championship->title;
        $this->location = $championship->location ?? '';
        $this->starts_on = $championship->starts_on?->toDateString();
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'title', 'location', 'starts_on');
        $this->resetValidation();
    }

    public function save(): void
    {
        Gate::authorize('manage-competition');

        $data = $this->validate();

        if ($this->editingId !== null) {
            Championship::findOrFail($this->editingId)->update($data);
            $message = 'Championship updated.';
        } else {
            Championship::create($data);
            $message = 'Championship created.';
        }

        $this->cancelEdit();
        session()->flash('status', $message);
    }

    public function delete(int $id): void
    {
        Gate::authorize('manage-competition');

        $championship = Championship::withCount('athletes')->findOrFail($id);

        // Deleting cascades to categories, athletes and bouts. Refuse once
        // anyone is registered — the old system deleted without asking.
        if ($championship->athletes_count > 0) {
            session()->flash('error', "Cannot delete: {$championship->athletes_count} athlete(s) are registered. Remove them first.");

            return;
        }

        $championship->delete();
        session()->flash('status', 'Championship deleted.');
    }

    public function render(): View
    {
        return view('livewire.competition.championships', [
            'championships' => Championship::withCount(['ageCategories', 'athletes'])
                ->latest('id')
                ->get(),
        ]);
    }
}
