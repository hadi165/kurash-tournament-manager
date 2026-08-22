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

    /** @var array<int, mixed> */
    #[Validate([
        'genders' => 'required|array|min:1',
        'genders.*' => 'required|string|in:M,F,X',
    ], message: [
        'genders.required' => 'Choose at least one competition — men, women, or both.',
        'genders.min' => 'Choose at least one competition — men, women, or both.',
    ])]
    public array $genders = [Gender::MEN, Gender::WOMEN];

    /**
     * Ticked, not typed. The groups are a fixed vocabulary a federation
     * already agrees on, and a free text box invites "Seniors" and "senior"
     * to become two different competitions.
     *
     * Typed loosely because it is a public Livewire property: what arrives is
     * whatever the request carried, not what the form was rendered with.
     *
     * @var array<int, mixed>
     */
    #[Validate([
        'ageGroups' => 'required|array|min:1',
        'ageGroups.*' => 'required|string|max:100',
    ], message: [
        'ageGroups.required' => 'Choose at least one age group.',
        'ageGroups.min' => 'Choose at least one age group.',
    ])]
    public array $ageGroups = ['Senior'];

    public ?int $editingId = null;

    /**
     * The boxes to offer: the standard groups, plus anything this
     * championship already carries that is not among them.
     *
     * @return list<string>
     */
    public function ageGroupChoices(): array
    {
        $existing = $this->editingId === null
            ? []
            : (Championship::find($this->editingId)?->configuredAgeGroups() ?? []);

        return array_values(array_unique([...Championship::AGE_GROUPS, ...$existing]));
    }

    /**
     * Kept in the federation's own order rather than in the order the boxes
     * happened to be ticked, so "Senior" stays the first one everywhere it is
     * offered as a default.
     *
     * @return list<string>
     */
    private function parsedAgeGroups(): array
    {
        $chosen = collect($this->ageGroups)
            ->filter(fn ($group) => is_string($group) && trim($group) !== '')
            ->map(fn (string $group) => trim($group))
            ->unique();

        $ordered = collect($this->ageGroupChoices())->filter(fn (string $g) => $chosen->contains($g));

        return array_values(
            $ordered->concat($chosen->reject(fn (string $g) => $ordered->contains($g)))
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
        $this->ageGroups = $championship->configuredAgeGroups();
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
