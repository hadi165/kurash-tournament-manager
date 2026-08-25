<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\WeightCategory;
use Illuminate\Support\Collection;

/**
 * Who the rules admit to a draw, and what to say when somebody is not admitted.
 *
 * The IKA rule is that an athlete who has not been weighed must not be admitted
 * to competition. Where that condition lives is App\Models\Athlete — one scope,
 * one definition. What this class adds is everything the application has to do
 * *about* it: which athletes in a class breach it, whether an existing draw has
 * come to contain one, and the wording an official is shown.
 *
 * It exists so that the answer is the same at every gate. A draw is checked
 * when the numbers are handed out, again when it is generated, and again when
 * it is published — three moments, minutes or hours apart, with a weigh-in
 * desk running between them. Three copies of the rule would eventually be two
 * versions of it.
 */
class DrawEligibility
{
    /**
     * Athletes holding a draw number whom the rules do not admit.
     *
     * @return Collection<int, Athlete>
     */
    public function ineligibleInDraw(WeightCategory $category): Collection
    {
        return $category->ineligibleNumberedAthletes()->get();
    }

    /**
     * Athletes the *generated* draw contains who are no longer admitted.
     *
     * Read off the bouts and not off the draw numbers, because the two can
     * disagree: clearing somebody's draw number does not take them out of a
     * bracket that was already built around them, and an athlete who is in the
     * contests is in the competition whatever the athletes table says about
     * their number. A class settled by administrative placement has no bouts
     * at all, so the placed athlete is checked directly.
     *
     * @return Collection<int, Athlete>
     */
    public function ineligibleInGeneratedDraw(WeightCategory $category): Collection
    {
        /** @var list<int> $ids */
        $ids = [];

        foreach ($category->bouts()->get(['athlete_a_id', 'athlete_b_id']) as $bout) {
            foreach ([$bout->athlete_a_id, $bout->athlete_b_id] as $athleteId) {
                if ($athleteId !== null) {
                    $ids[] = (int) $athleteId;
                }
            }
        }

        if ($category->draw_placement_athlete_id !== null) {
            $ids[] = (int) $category->draw_placement_athlete_id;
        }

        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return collect();
        }

        return Athlete::query()
            ->whereIn('id', $ids)
            ->failedOrPendingWeighIn()
            ->orderBy('draw_number')
            ->orderBy('fullname')
            ->get();
    }

    /**
     * Is this athlete already committed to a draw that has been generated?
     *
     * The question a weigh-in desk needs answered before it changes somebody's
     * status: not "is there a draw" but "is this person in it". An athlete
     * registered in a drawn class who was never numbered — a late entry, or
     * somebody who failed the first time — can be weighed freely, because
     * nothing about the existing contests depends on them.
     */
    public function isCommittedToDraw(Athlete $athlete): bool
    {
        $category = $athlete->weight_category_id === null ? null : $athlete->weightCategory;

        if ($category === null) {
            return false;
        }

        $inBouts = $category->bouts()
            ->where(function ($query) use ($athlete) {
                $query->where('athlete_a_id', $athlete->id)
                    ->orWhere('athlete_b_id', $athlete->id);
            })
            ->exists();

        if ($inBouts) {
            return true;
        }

        // A class of one has no contests. Being the athlete an administrative
        // placement was recorded against is the same commitment.
        return $category->draw_placement_athlete_id !== null
            && (int) $category->draw_placement_athlete_id === (int) $athlete->id;
    }

    /**
     * The refusal, naming the people it is about.
     *
     * Named rather than counted: an official standing at a screen has to go and
     * find somebody, and "2 athletes are ineligible" does not tell them who.
     *
     * @param  Collection<int, Athlete>  $athletes
     */
    public function refusal(Collection $athletes, string $context): string
    {
        $names = $athletes
            ->map(fn (Athlete $athlete) => trim(sprintf(
                '%s (%s)',
                $athlete->fullname,
                $athlete->weighin_status === Athlete::WEIGHIN_FAIL
                    ? __('failed weigh-in')
                    : __('not weighed'),
            )))
            ->implode(', ');

        return __(':context Nobody may be drawn who has not passed the weigh-in: :names.', [
            'context' => $context,
            'names' => $names,
        ]);
    }
}
