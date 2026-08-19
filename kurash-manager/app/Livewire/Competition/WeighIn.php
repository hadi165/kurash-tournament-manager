<?php

namespace App\Livewire\Competition;

use App\Models\AgeCategory;
use App\Models\Athlete;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Component;

class WeighIn extends Component
{
    public AgeCategory $ageCategory;

    public ?int $weightFilter = null;

    /** @var array<int, string> athlete id => entered kilograms */
    public array $weights = [];

    public function mount(AgeCategory $ageCategory): void
    {
        $this->ageCategory = $ageCategory->load('championship');

        foreach ($this->athletes() as $athlete) {
            $this->weights[$athlete->id] = (string) ($athlete->weighin_kg ?? '');
        }
    }

    public function record(int $athleteId): void
    {
        Gate::authorize('manage-competition');

        $athlete = $this->ageCategory->athletes()->with('weightCategory')->findOrFail($athleteId);

        $value = $this->weights[$athleteId] ?? '';

        if (! is_numeric($value) || (float) $value <= 0) {
            session()->flash('error', __('Enter a weight in kilograms for :name.', ['name' => $athlete->fullname]));

            return;
        }

        $kg = round((float) $value, 2);

        // An athlete can be registered before being placed in a weight class,
        // so the foreign key is nullable and the relation may genuinely be
        // absent. Reading it through the key states that plainly.
        $category = $athlete->weight_category_id === null ? null : $athlete->weightCategory;

        // The category itself decides whether the weight passes, so the rule
        // lives in one place instead of being re-parsed from a label string.
        // Someone with no class yet cannot pass a class they are not in.
        $status = $category !== null && $category->admits($kg) ? 'pass' : 'fail';

        $athlete->update([
            'weighin_kg' => $kg,
            'weighin_status' => $status,
            'weighin_at' => now(),
        ]);

        $label = $category !== null ? $category->label : __('no weight class');

        session()->flash('status', $status === 'pass'
            ? __(':name weighed in at :kg kg — inside :label.', ['name' => $athlete->fullname, 'kg' => $kg, 'label' => $label])
            : __(':name weighed :kg kg — outside :label.', ['name' => $athlete->fullname, 'kg' => $kg, 'label' => $label]));
    }

    /** @return Collection<int, Athlete> */
    private function athletes(): Collection
    {
        return $this->ageCategory->athletes()
            ->with('weightCategory')
            ->when($this->weightFilter, fn ($q) => $q->where('weight_category_id', $this->weightFilter))
            ->orderBy('weight_category_id')
            ->orderBy('fullname')
            ->get();
    }

    public function render(): View
    {
        return view('livewire.competition.weigh-in', [
            'athletes' => $this->athletes(),
            'weightCategories' => $this->ageCategory->weightCategories()->get(),
        ]);
    }
}
