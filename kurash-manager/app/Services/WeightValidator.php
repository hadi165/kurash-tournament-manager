<?php

namespace App\Services;

use App\Models\WeightCategory;
use App\Support\WeightRange;
use App\Support\WeightVerdict;
use Illuminate\Support\Collection;

/**
 * Whether a weight belongs in a category.
 *
 * One place, because the question is asked at the scale, at approval, during an
 * import and on every screen that shows a weigh-in — and the previous answer
 * was wrong in a way that only showed up at the bottom of a class. The old rule
 * required an athlete to be within the tolerance *below the ceiling*: a -60
 * class accepted 59.5 to 60.0 and rejected everyone lighter, which is not a
 * weight class, it is a 500-gram window.
 *
 * The rule the federation actually runs:
 *
 *   upper bound   the category's own ceiling, inclusive
 *   lower bound   the ceiling of the category below it, less a 500-gram
 *                 tolerance
 *
 * So in a division of -56, -60, -66, the -60 class runs from 55.5 to 60.0.
 * 56.100, 56.200 and 56.500 all pass, which they must — an athlete who cannot
 * make -56 belongs in -60, and the tolerance is what stops a few grams of
 * breakfast from putting them in neither.
 *
 * The lower bound is derived from the division rather than stored, so adding a
 * class to a division moves the bounds of the class above it automatically and
 * no table of ranges has to be maintained alongside the categories themselves.
 */
class WeightValidator
{
    /** Grams of grace below the nominal lower bound, expressed in kilograms. */
    public const TOLERANCE_KG = 0.5;

    /**
     * The band this category accepts.
     *
     * An explicit min_kg wins — a class configured with both bounds is saying
     * what it wants. Otherwise the bound comes from the class below, and a
     * class with nothing below it has no floor at all: the lightest athletes in
     * a division have to land somewhere.
     */
    public function rangeFor(WeightCategory $category, float $tolerance = self::TOLERANCE_KG): WeightRange
    {
        $max = $category->max_kg === null ? null : (float) $category->max_kg;

        $nominalMin = $category->min_kg !== null
            ? (float) $category->min_kg
            : $this->ceilingBelow($category);

        return new WeightRange(
            min: $nominalMin === null ? null : round($nominalMin - $tolerance, 3),
            max: $max,
            tolerance: $tolerance,
            nominalMin: $nominalMin,
        );
    }

    /**
     * Check one reading, with the reason and the band that would have passed.
     */
    public function check(?WeightCategory $category, float $kg, float $tolerance = self::TOLERANCE_KG): WeightVerdict
    {
        // Somebody with no class yet cannot pass a class they are not in. Said
        // here rather than at each call site, so an import and a screen give
        // the same answer.
        if ($category === null) {
            return new WeightVerdict(
                accepted: false,
                range: new WeightRange(null, null, $tolerance),
                reason: __('No weight class assigned.'),
            );
        }

        $range = $this->rangeFor($category, $tolerance);

        if ($range->admits($kg)) {
            return new WeightVerdict(accepted: true, range: $range);
        }

        return new WeightVerdict(
            accepted: false,
            range: $range,
            reason: $range->isUnder($kg)
                ? __('Under :label — :kg kg is below the class.', ['label' => $category->label, 'kg' => $kg])
                : __('Over :label — :kg kg is above the class.', ['label' => $category->label, 'kg' => $kg]),
        );
    }

    /**
     * The ceiling of the class immediately below this one in its division.
     *
     * Ordered by the ceiling itself rather than by sort_order, because
     * sort_order is a display preference somebody can drag around and the
     * weight bands must not move when they do. An open class — no ceiling —
     * sits above every bounded one and is never anybody's lower neighbour.
     */
    private function ceilingBelow(WeightCategory $category): ?float
    {
        if ($category->max_kg === null) {
            // An open class sits on top of the division, so its floor is the
            // ceiling of the heaviest bounded class.
            return $this->siblings($category)
                ->whereNotNull('max_kg')
                ->max(fn (WeightCategory $sibling) => (float) $sibling->max_kg);
        }

        $ceiling = (float) $category->max_kg;

        return $this->siblings($category)
            ->filter(fn (WeightCategory $sibling) => $sibling->max_kg !== null
                && (float) $sibling->max_kg < $ceiling)
            ->max(fn (WeightCategory $sibling) => (float) $sibling->max_kg);
    }

    /**
     * The other classes an athlete in this one could have been placed in.
     *
     * Same division and same gender: a men's -60 is not bounded by a women's
     * -57 that happens to share the age category.
     *
     * @return Collection<int, WeightCategory>
     */
    private function siblings(WeightCategory $category): Collection
    {
        return WeightCategory::query()
            ->where('age_category_id', $category->age_category_id)
            ->where('gender', $category->gender)
            ->whereKeyNot($category->getKey())
            ->get();
    }
}
