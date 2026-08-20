<?php

namespace App\Livewire\Competition;

use App\Models\AgeCategory;
use App\Models\Athlete;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Registration extends Component
{
    public AgeCategory $ageCategory;

    #[Validate('required|string|max:255')]
    public string $fullname = '';

    #[Validate('required|string|max:8')]
    public string $noc_code = '';

    #[Validate('nullable|string|max:255')]
    public string $noc_name = '';

    #[Validate('required|in:M,F')]
    public string $gender = 'M';

    #[Validate('required|integer')]
    public ?int $weight_category_id = null;

    #[Validate('nullable|string|max:255')]
    public string $national_id = '';

    public ?int $editingId = null;

    public string $search = '';

    public function mount(AgeCategory $ageCategory): void
    {
        $this->ageCategory = $ageCategory->load('championship');
    }

    public function edit(int $id): void
    {
        Gate::authorize('manage-competition');

        $athlete = $this->athleteQuery()->findOrFail($id);

        $this->editingId = $athlete->id;
        $this->fullname = $athlete->fullname;
        $this->noc_code = $athlete->noc_code;
        $this->noc_name = $athlete->noc_name ?? '';
        $this->gender = $athlete->gender;
        $this->weight_category_id = $athlete->weight_category_id;
        $this->national_id = $athlete->national_id ?? '';
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'fullname', 'noc_code', 'noc_name', 'national_id', 'weight_category_id');
        $this->gender = 'M';
        $this->resetValidation();
    }

    public function save(): void
    {
        Gate::authorize('manage-competition');
        $this->validate();

        // The weight class must belong to THIS age category. Without the check
        // a crafted form value could move an athlete into another championship.
        $weightCategory = $this->ageCategory->weightCategories()->find($this->weight_category_id);

        if ($weightCategory === null) {
            $this->addError('weight_category_id', __('Choose a weight class from this age category.'));

            return;
        }

        $attributes = [
            'fullname' => $this->fullname,
            'noc_code' => strtoupper($this->noc_code),
            'noc_name' => $this->noc_name ?: null,
            'gender' => $this->gender,
            'national_id' => $this->national_id ?: null,
            'weight_category_id' => $weightCategory->id,
        ];

        if ($this->editingId !== null) {
            $athlete = $this->athleteQuery()->findOrFail($this->editingId);

            // Moving weight class after a draw would put them in a bracket they
            // were never drawn into.
            if ($athlete->weight_category_id !== $weightCategory->id && $athlete->draw_number !== null) {
                $attributes['draw_number'] = null;
                $attributes['draw_number_source'] = null;
                session()->flash('error', __('Draw number cleared — :name changed weight class and must be drawn again.', ['name' => $athlete->fullname]));
            }

            $athlete->update($attributes);
            session()->flash('status', __('Athlete updated.'));
        } else {
            $athlete = Athlete::register($attributes + [
                'championship_id' => $this->ageCategory->championship_id,
                'age_category_id' => $this->ageCategory->id,
            ]);

            session()->flash('status', __('Registered :name — IKA ID :id', ['name' => $athlete->fullname, 'id' => $athlete->ika_id]));
        }

        $this->cancelEdit();
    }

    public function delete(int $id): void
    {
        Gate::authorize('manage-competition');

        $athlete = $this->athleteQuery()->findOrFail($id);

        if ($athlete->weightCategory?->bouts()->exists()) {
            session()->flash('error', __('Cannot remove: a bracket has already been drawn for :class. Delete that bracket on its draw screen first, then remove the athlete and draw again.', [
                'class' => $athlete->weightCategory->label,
            ]));

            return;
        }

        $athlete->delete();
        session()->flash('status', __('Athlete removed.'));
    }

    /** @return HasMany<Athlete, AgeCategory> */
    private function athleteQuery(): HasMany
    {
        return $this->ageCategory->athletes();
    }

    public function render(): View
    {
        $athletes = $this->athleteQuery()
            ->with('weightCategory')
            ->when($this->search !== '', fn ($q) => $q->where(
                fn ($w) => $w->where('fullname', 'like', "%{$this->search}%")
                    ->orWhere('ika_id', 'like', "%{$this->search}%")
                    ->orWhere('noc_code', 'like', "%{$this->search}%")
            ))
            ->orderBy('fullname')
            ->get();

        return view('livewire.competition.registration', [
            'athletes' => $athletes,
            'weightCategories' => $this->ageCategory->weightCategories()->get(),
        ]);
    }
}
