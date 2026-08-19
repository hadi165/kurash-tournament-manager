<?php

namespace App\Livewire\Competition;

use App\Models\AgeCategory;
use App\Models\Championship;
use App\Models\WeightCategory;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Categories extends Component
{
    public Championship $championship;

    #[Validate('required|string|max:255')]
    public string $ageCategoryName = '';

    /**
     * Weight classes are typed as a comma-separated list, the way the old
     * system accepted them — but they are stored as rows, not as two
     * slash-delimited strings that had to stay index-aligned.
     */
    #[Validate('required|string|max:500')]
    public string $weightLabels = '';

    #[Validate('required|in:M,F,X')]
    public string $gender = 'M';

    public ?int $editingId = null;

    public function mount(Championship $championship): void
    {
        $this->championship = $championship;
    }

    public function edit(int $id): void
    {
        Gate::authorize('manage-competition');

        $ageCategory = $this->championship->ageCategories()->with('weightCategories')->findOrFail($id);

        $this->editingId = $ageCategory->id;
        $this->ageCategoryName = $ageCategory->name;
        $this->weightLabels = $ageCategory->weightCategories->pluck('label')->implode(', ');
        $this->gender = $ageCategory->weightCategories->first()->gender ?? 'M';
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'ageCategoryName', 'weightLabels');
        $this->gender = 'M';
        $this->resetValidation();
    }

    public function save(): void
    {
        Gate::authorize('manage-competition');
        $this->validate();

        $labels = $this->parseLabels();

        if ($labels === []) {
            $this->addError('weightLabels', __('Enter at least one weight class, e.g. -66, -73, +90.'));

            return;
        }

        $ageCategory = $this->editingId
            ? $this->championship->ageCategories()->findOrFail($this->editingId)
            : $this->championship->ageCategories()->make();

        $ageCategory->fill(['name' => $this->ageCategoryName])->save();

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
                'gender' => $this->gender,
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
            'ageCategories' => $this->championship
                ->ageCategories()
                ->with(['weightCategories' => fn ($q) => $q->withCount('athletes')])
                ->withCount('athletes')
                ->get(),
        ]);
    }
}
