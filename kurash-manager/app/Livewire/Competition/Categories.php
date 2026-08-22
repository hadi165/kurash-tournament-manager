<?php

namespace App\Livewire\Competition;

use App\Models\AgeCategory;
use App\Models\Championship;
use App\Models\WeightCategory;
use App\Support\Gender;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Categories extends Component
{
    public Championship $championship;

    /**
     * A division is one of the championship's competitions paired with one of
     * its age groups. Neither is typed: both are chosen from what the
     * championship declared, and the name follows from the pair.
     */
    #[Validate('required|string|max:100')]
    public string $ageGroup = '';

    /**
     * Weight classes are typed as a comma-separated list, the way the old
     * system accepted them — but they are stored as rows, not as two
     * slash-delimited strings that had to stay index-aligned.
     */
    #[Validate('required|string|max:500')]
    public string $weightLabels = '';

    #[Validate('required|in:M,F,X')]
    public string $gender = '';

    /**
     * How long a contest in this division runs, in minutes.
     *
     * Typed in minutes because that is how a federation states it — "cadets
     * fight three" — and stored in seconds because that is what a clock counts.
     * Blank means the configured default for the weight class's gender, which
     * is what every category had before this field existed.
     *
     * A string rather than a number: an empty text input posts "", and a
     * nullable numeric property would take that as zero and put every cadet
     * contest on a clock of no length at all.
     */
    #[Validate('nullable|numeric|min:0.5|max:20')]
    public string $boutMinutes = '';

    public ?int $editingId = null;

    public function mount(Championship $championship): void
    {
        $this->championship = $championship;
        $this->cancelEdit();
    }

    public function edit(int $id): void
    {
        Gate::authorize('manage-competition');

        $ageCategory = $this->championship->ageCategories()->with('weightCategories')->findOrFail($id);

        $this->editingId = $ageCategory->id;
        $this->ageGroup = $ageCategory->age_group ?? '';
        $this->weightLabels = $ageCategory->weightCategories->pluck('label')->implode(', ');
        $this->gender = $ageCategory->gender;
        $this->boutMinutes = $ageCategory->bout_seconds === null
            ? ''
            : rtrim(rtrim(number_format($ageCategory->bout_seconds / 60, 2, '.', ''), '0'), '.');
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'ageGroup', 'weightLabels', 'boutMinutes');

        // Opens on the championship's own first competition and age group
        // rather than on a fixed default that it may not even run.
        $this->gender = $this->championship->configuredGenders()[0];
        $this->ageGroup = $this->championship->configuredAgeGroups()[0];
        $this->resetValidation();
    }

    public function save(): void
    {
        Gate::authorize('manage-competition');
        $this->validate();

        // The authority is the championship, not the form. A pair posted at
        // this component that the championship never declared is refused here
        // whether it came from the select or from a crafted request.
        if (! $this->championship->allowsDivision($this->gender, $this->ageGroup)) {
            $this->addError('ageGroup', __(':division is not part of this championship. It runs :genders — :groups.', [
                'division' => AgeCategory::composeName($this->gender, $this->ageGroup),
                'genders' => collect($this->championship->configuredGenders())->map(fn (string $g) => Gender::label($g))->join(', '),
                'groups' => implode(', ', $this->championship->configuredAgeGroups()),
            ]));

            return;
        }

        $labels = $this->parseLabels();

        if ($labels === []) {
            $this->addError('weightLabels', __('Enter at least one weight class, e.g. -66, -73, +90.'));

            return;
        }

        $ageCategory = $this->editingId
            ? $this->championship->ageCategories()->findOrFail($this->editingId)
            : $this->championship->ageCategories()->make();

        // A championship cannot run the same competition and age group twice.
        $clash = $this->championship->ageCategories()
            ->where('gender', $this->gender)
            ->where('age_group', $this->ageGroup)
            ->when($this->editingId !== null, fn ($q) => $q->whereKeyNot($this->editingId))
            ->exists();

        if ($clash) {
            $this->addError('ageGroup', __(':division already exists in this championship.', [
                'division' => AgeCategory::composeName($this->gender, $this->ageGroup),
            ]));

            return;
        }

        $ageCategory->fill([
            'gender' => $this->gender,
            'age_group' => $this->ageGroup,
            // Rounded to the second on the way in, so a clock never counts down
            // from a fraction it cannot show.
            'bout_seconds' => $this->boutMinutes === ''
                ? null
                : (int) round(((float) $this->boutMinutes) * 60),
        ])->save();

        $this->syncWeightCategories($ageCategory, $labels);

        $this->cancelEdit();
        session()->flash('status', __('Category saved.'));
    }

    /**
     * Weight classes already carrying athletes are updated in place rather than
     * replaced, so a rename cannot orphan a registration.
     *
     * @param  list<string>  $labels
     */
    private function syncWeightCategories(AgeCategory $ageCategory, array $labels): void
    {
        $existing = $ageCategory->weightCategories()->get()->keyBy('label');
        $kept = [];

        foreach ($labels as $sortOrder => $label) {
            $category = $existing->get($label) ?? new WeightCategory(['age_category_id' => $ageCategory->id]);

            $category->fill([
                'age_category_id' => $ageCategory->id,
                'label' => $label,
                'min_kg' => str_starts_with($label, '+') ? (float) ltrim($label, '+') : null,
                'max_kg' => str_starts_with($label, '+') ? null : (float) ltrim($label, '-'),
                // Taken from the division, never from the form: a class in a
                // women's division is a women's class by definition.
                'gender' => $ageCategory->gender,
                'sort_order' => $sortOrder,
            ])->save();

            $kept[] = $category->id;
        }

        $removable = $ageCategory->weightCategories()
            ->whereNotIn('id', $kept)
            ->withCount('athletes')
            ->get();

        foreach ($removable as $category) {
            if ($category->athletes_count > 0) {
                session()->flash('error', __('Kept :label — :count athlete(s) are registered in it.', [
                    'label' => $category->label,
                    'count' => $category->athletes_count,
                ]));

                continue;
            }

            $category->delete();
        }
    }

    /** @return list<string> */
    private function parseLabels(): array
    {
        return array_values(
            collect(explode(',', $this->weightLabels))
                ->map(fn (string $l) => trim($l))
                ->filter()
                ->unique()
                ->all()
        );
    }

    public function delete(int $id): void
    {
        Gate::authorize('manage-competition');

        $ageCategory = $this->championship->ageCategories()->withCount('athletes')->findOrFail($id);

        if ($ageCategory->athletes_count > 0) {
            session()->flash('error', __('Cannot delete: athletes are registered in this category.'));

            return;
        }

        $ageCategory->delete();
        session()->flash('status', __('Category deleted.'));
    }

    public function render(): View
    {
        return view('livewire.competition.categories', [
            'genders' => $this->championship->configuredGenders(),
            'ageGroups' => $this->championship->configuredAgeGroups(),
            'ageCategories' => $this->championship
                ->ageCategories()
                ->with(['weightCategories' => fn ($q) => $q->withCount('athletes')])
                ->withCount('athletes')
                ->get(),
        ]);
    }
}
