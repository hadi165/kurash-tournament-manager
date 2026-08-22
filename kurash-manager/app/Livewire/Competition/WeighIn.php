<?php

namespace App\Livewire\Competition;

use App\Models\AgeCategory;
use App\Models\Athlete;
use App\Models\Championship;
use App\Models\WeightCategory;
use App\Services\WeightValidator;
use App\Support\Gender;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Component;

class WeighIn extends Component
{
    /**
     * The weigh-in is scoped to a competition, the way registration is: the
     * age groups were settled when the championship was created, so the scale
     * works through one competition rather than through a list of divisions.
     */
    public Championship $championship;

    /** The competition being weighed: one of the championship's genders. */
    public string $competition = Gender::MEN;

    public ?int $weightFilter = null;

    /** @var array<int, string> athlete id => entered kilograms */
    public array $weights = [];

    public function mount(Championship $championship, string $competition): void
    {
        abort_unless(in_array($competition, $championship->configuredGenders(), true), 404);

        $this->championship = $championship;
        $this->competition = $competition;

        foreach ($this->athletes() as $athlete) {
            $this->weights[$athlete->id] = (string) ($athlete->weighin_kg ?? '');
        }
    }

    /**
     * The divisions this competition is run in.
     *
     * @return Collection<int, AgeCategory>
     */
    public function divisions(): Collection
    {
        return $this->championship->ageCategories()
            ->where('gender', $this->competition)
            ->orderBy('sort_order')
            ->get();
    }

    public function record(int $athleteId): void
    {
        Gate::authorize('manage-competition');

        // Scoped to this competition, so an athlete from another one cannot be
        // weighed here by posting their id.
        $athlete = $this->athleteScope()->with('weightCategory')->findOrFail($athleteId);

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

    /** @return HasMany<Athlete, Championship> */
    private function athleteScope(): HasMany
    {
        return $this->championship->athletes()
            ->whereIn('age_category_id', $this->divisions()->modelKeys());
    }

    /** @return Collection<int, Athlete> */
    private function athletes(): Collection
    {
        return $this->athleteScope()
            ->with(['weightCategory', 'ageCategory'])
            ->when($this->weightFilter, fn ($q) => $q->where('weight_category_id', $this->weightFilter))
            ->orderBy('age_category_id')
            ->orderBy('weight_category_id')
            ->orderBy('fullname')
            ->get();
    }

    /**
     * Every weight class in this competition, across its age groups.
     *
     * @return Collection<int, WeightCategory>
     */
    private function weightCategories(): Collection
    {
        return WeightCategory::query()
            ->whereIn('age_category_id', $this->divisions()->modelKeys())
            ->with('ageCategory')
            ->orderBy('age_category_id')
            ->orderBy('sort_order')
            ->get();
    }

    public function render(): View
    {
        return view('livewire.competition.weigh-in', [
            'athletes' => $this->athletes(),
            'weightCategories' => $this->weightCategories(),
            'divisions' => $this->divisions(),
        ]);
    }
}
