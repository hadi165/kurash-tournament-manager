<?php

namespace App\Livewire\Concerns;

use App\Models\Bout;
use App\Models\Championship;
use App\Models\WeightCategory;
use App\Support\Gender;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

/**
 * A championship screen narrowed to one of the competitions it runs.
 *
 * Registration and the weigh-in belong to a competition — they are that
 * competition's screen, and their address says so. These are different: the
 * entries, the brackets, the mats and the medals are the whole championship's,
 * and a competition is a way of reading them. So it is a filter in the query
 * string rather than a segment of the path, and dropping it shows everything.
 *
 * The component using this declares the championship it is reading; naming it
 * here is what lets the trait ask about it.
 *
 * @property Championship $championship
 */
trait ScopesToCompetition
{
    /** '' for the whole championship, otherwise one of its genders. */
    #[Url]
    public string $competition = '';

    /**
     * The competitions this championship runs.
     *
     * @return list<string>
     */
    public function competitions(): array
    {
        return $this->championship->configuredGenders();
    }

    /** Which one is in view, or null for all of them. */
    public function scopedCompetition(): ?string
    {
        return in_array($this->competition, $this->competitions(), true)
            ? $this->competition
            : null;
    }

    /** "Men", "Women", or null when nothing is narrowed. */
    public function competitionLabel(): ?string
    {
        $competition = $this->scopedCompetition();

        return $competition === null ? null : Gender::label($competition);
    }

    /**
     * The divisions in view. Null rather than every id, so a caller can tell
     * "all of them" from "these particular ones".
     *
     * @return list<int>|null
     */
    public function scopedDivisionIds(): ?array
    {
        $competition = $this->scopedCompetition();

        if ($competition === null) {
            return null;
        }

        $ids = $this->championship->ageCategories()
            ->where('gender', $competition)
            ->pluck('id')
            ->all();

        return array_values(array_map('intval', $ids));
    }

    /**
     * Narrows weight classes to this championship, and to one competition when
     * one is in view.
     *
     * @param  Builder<WeightCategory>  $query
     */
    public function scopeWeightCategories(Builder $query): void
    {
        $competition = $this->scopedCompetition();

        $query->whereHas('ageCategory', fn (Builder $division) => $division
            ->where('championship_id', $this->championship->id)
            ->when($competition !== null, fn (Builder $q) => $q->where('gender', $competition)));
    }

    /**
     * Narrows contests the same way. Bouts carry their division directly, so
     * this asks the column rather than joining back through the weight class.
     *
     * @param  Builder<Bout>  $query
     */
    public function scopeBouts(Builder $query): void
    {
        $divisions = $this->scopedDivisionIds();

        if ($divisions !== null) {
            $query->whereIn('age_category_id', $divisions);
        }
    }
}
