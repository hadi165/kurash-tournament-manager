<?php

namespace App\Livewire\Competition;

use App\Models\Championship;
use App\Support\Gender;
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

    /*
     |--------------------------------------------------------------------------
     | What the championship runs
     |--------------------------------------------------------------------------
     |
     | Declared here, once, and read by every screen after it. A championship
     | that runs only seniors will not offer a junior anywhere — not on
     | registration, not at the weigh-in, not in a draw — because there is no
     | list of age groups anywhere else for one to come from.
     */

    /** @var list<string> */
    #[Validate([
        'genders' => 'required|array|min:1',
        'genders.*' => 'required|string|in:M,F,X',
    ], message: [
        'genders.required' => 'Choose at least one competition — men, women, or both.',
        'genders.min' => 'Choose at least one competition — men, women, or both.',
    ])]
    public array $genders = [Gender::MEN, Gender::WOMEN];

    /**
     * Typed as a comma-separated list, the way weight classes already are on
     * the categories screen — "Senior, Junior, Cadet".
     */
    #[Validate('required|string|max:255')]
    public string $ageGroups = 'Senior';

    public ?int $editingId = null;

    /** @return list<string> */
    private function parsedAgeGroups(): array
    {
        return array_values(
            collect(explode(',', $this->ageGroups))
                ->map(fn (string $group) => trim($group))
                ->filter()
                ->unique()
                ->values()
                ->all()
        );
    }

    public function edit(int $id): void
    {
        Gate::authorize('manage-competition');

        $championship = Championship::findOrFail($id);

        $this->editingId = $championship->id;
        $this->title = $championship->title;
        $this->location = $championship->location ?? '';
        $this->starts_on = $championship->starts_on?->toDateString();
        $this->genders = $championship->configuredGenders();
        $this->ageGroups = implode(', ', $championship->configuredAgeGroups());
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'title', 'location', 'starts_on', 'genders', 'ageGroups');
        $this->resetValidation();
    }

    public function save(): void
    {
        Gate::authorize('manage-competition');

        $data = $this->validate();

        $ageGroups = $this->parsedAgeGroups();

        if ($ageGroups === []) {
            $this->addError('ageGroups', __('Name at least one age group, e.g. Senior, Junior, Cadet.'));

            return;
        }

        $data['genders'] = Gender::sanitise($this->genders);
        $data['age_groups'] = $ageGroups;
        unset($data['ageGroups']);

        if ($this->editingId !== null) {
            $championship = Championship::findOrFail($this->editingId);

            // Withdrawing a competition or an age group would leave the
            // divisions built on it configured for nothing, so it is refused
            // while any of them still exist. Delete the division first.
            $orphaned = $championship->ageCategories()->get()
                ->reject(fn ($division) => in_array($division->gender, $data['genders'], true)
                    && in_array($division->age_group, $data['age_groups'], true))
                ->map(fn ($division) => $division->name);

            if ($orphaned->isNotEmpty()) {
                $this->addError('ageGroups', __('Cannot remove that: :divisions would be left without a configuration. Delete the division first.', [
                    'divisions' => $orphaned->join(', '),
                ]));

                return;
            }

            $championship->update($data);
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
