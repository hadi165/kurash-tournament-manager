<?php

namespace App\Livewire\Competition;

use App\Models\AgeCategory;
use App\Models\Athlete;
use App\Services\WeightValidator;
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

        // One engine answers this, at the scale and everywhere else. It returns
        // the reason and the band that would have passed as well as the
        // verdict, because "failed" on its own is not something an official at
        // the scale can act on.
        $verdict = app(WeightValidator::class)->check($category, $kg);

        $athlete->update([
            'weighin_kg' => $kg,
            'weighin_status' => $verdict->status(),
            'weighin_at' => now(),
        ]);

        // An athlete with no class is never accepted, so the two conditions
        // always agree — stated together rather than reached for with a
        // nullsafe, which would suggest they might not.
        if ($verdict->accepted && $category !== null) {
            session()->flash('status', __(':name weighed in at :kg kg — inside :label.', [
                'name' => $athlete->fullname,
                'kg' => $kg,
                'label' => $category->label,
            ]));

            return;
        }

        session()->flash('error', __(':name: :reason Accepted range is :range.', [
            'name' => $athlete->fullname,
            'reason' => $verdict->reason,
            'range' => $verdict->range->label(),
        ]));
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
