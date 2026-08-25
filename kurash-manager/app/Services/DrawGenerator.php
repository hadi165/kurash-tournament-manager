<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\User;
use App\Models\WeightCategory;
use App\Support\TournamentFormat;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Draws a weight class in whichever format its field calls for.
 *
 * The one door. Screens, commands and tests ask this to draw a class and it
 * decides which generator does the work — so the athlete-count rule lives in
 * TournamentFormatPolicy, the bracket lives in BracketGenerator, the round
 * robin lives in RoundRobinGenerator, and no caller has to know which of the
 * three it wanted.
 *
 * Three things happen here that belong to neither generator:
 *
 *  1. The requested format is checked against the rule, server-side, every
 *     time. A browser cannot ask for a round robin of sixteen by editing a
 *     select, because the select is not what is trusted.
 *  2. A knockout in a field of two to five is recorded as an override, with
 *     the administrator, the reason and the moment. That is the whole point of
 *     allowing it: a departure nobody signed is one nobody can answer for.
 *  3. The category row is locked for the duration, so two administrators
 *     pressing Generate at the same moment cannot both write a draw.
 */
class DrawGenerator
{
    public function __construct(
        private readonly TournamentFormatPolicy $policy,
        private readonly BracketGenerator $brackets,
        private readonly RoundRobinGenerator $roundRobins,
        private readonly DrawEligibility $eligibility,
    ) {}

    /**
     * Draw the class.
     *
     * @param  TournamentFormat|null  $format  What was asked for. Null takes
     *                                         the stored preference, and then
     *                                         the rule — which is what the
     *                                         "just draw it" path wants.
     * @return array{format:TournamentFormat, bouts:int, rounds:int, athletes:int, byes:int, size:int|null, override:bool}
     */
    public function generate(
        WeightCategory $category,
        ?TournamentFormat $format = null,
        bool $discardResults = false,
        bool $replacePublished = false,
        ?string $overrideReason = null,
        ?User $user = null,
    ): array {
        /*
         | Everything under one lock on the category row.
         |
         | Both generators delete the existing contests and write new ones, so
         | two concurrent calls would interleave those two steps and leave a
         | class holding half of each draw. The unique index on
         | (weight_category_id, round, position_in_round) would catch some of
         | that and not all of it — a smaller second draw fits inside the holes
         | of a larger first one — so the row is held rather than relying on it.
         */
        return DB::transaction(function () use ($category, $format, $discardResults, $replacePublished, $overrideReason, $user) {
            /** @var WeightCategory $locked */
            $locked = WeightCategory::whereKey($category->id)->lockForUpdate()->firstOrFail();
            $locked->setRelation('ageCategory', $category->ageCategory);

            /*
             | The scale, checked here and not only where the numbers were
             | handed out.
             |
             | Assignment is one moment and generation is another, with a
             | weigh-in desk running in between: an athlete numbered while
             | passing can have been re-weighed and failed before anybody
             | presses Generate. This is inside the lock and the transaction
             | that write the draw, so what is checked is what is about to be
             | built — and a screen is not what is trusted to have filtered it,
             | because a command, a test or a screen written later reaches this
             | same door.
             */
            $ineligible = $this->eligibility->ineligibleInDraw($locked);

            if ($ineligible->isNotEmpty()) {
                throw new DrawEligibilityException($this->eligibility->refusal(
                    $ineligible,
                    __('The draw for :class cannot be generated.', ['class' => $locked->label]),
                ));
            }

            // Counted off the eligible field, so the format, the override gate
            // and the contests are all decided about the same set of people.
            $athletes = $locked->drawnAthletes()->count();

            if ($athletes < 1) {
                throw new RuntimeException("No athletes with a draw number in {$locked->label}.");
            }

            // Compliant on purpose: "just draw it" is nobody signing anything,
            // so a stored preference that departs from the rule for the field
            // as it stands now is not followed. The class set to knockout at
            // eight athletes that has shrunk to three is drawn as the round
            // robin the rule gives it, not as an unsigned override.
            $format ??= $this->policy->resolveCompliantFor($locked, $athletes);

            if ($format === null || ! $this->policy->allows($athletes, $format)) {
                throw new DrawFormatException($this->refusal($athletes, $format));
            }

            $override = $this->policy->isOverride($athletes, $format);

            if ($override && trim((string) $overrideReason) === '') {
                throw new DrawFormatException(__(
                    'Running :count athletes as a knockout departs from the IKA rule. Give a reason for the override.',
                    ['count' => $athletes],
                ));
            }

            $result = match ($format) {
                TournamentFormat::Knockout => $this->brackets->generate($locked, $discardResults, $replacePublished),
                TournamentFormat::RoundRobin => $this->roundRobins->generate($locked, $discardResults, $replacePublished),
                TournamentFormat::Placement => $this->placement($locked, $discardResults, $replacePublished),
            };

            // BracketGenerator predates formats and does not stamp one, so it
            // is stamped here. The round robin and the placement write their
            // own, inside their own transaction — this is the same transaction,
            // so the format and the contests are still committed together.
            $locked->forceFill([
                'draw_format' => $format->value,
                'draw_format_preference' => $format->value,
            ] + $this->overrideAudit($override, $overrideReason, $user))->save();

            $category->refresh();

            return [
                'format' => $format,
                'bouts' => (int) $result['bouts'],
                'rounds' => (int) $result['rounds'],
                'athletes' => (int) $result['athletes'],
                // Only a bracket has these: a round robin rounds up to nothing
                // and nobody sits out of one.
                'byes' => (int) ($result['byes'] ?? 0),
                'size' => $result['size'] ?? null,
                'override' => $override,
            ];
        });
    }

    /**
     * Settle a class of one athlete without inventing a contest for them.
     *
     * Being unopposed is not the same as having won, so this is never reached
     * by drawing: an administrator performs it, and their name goes on it. The
     * athlete is recorded as placed first; whether that carries a medal is the
     * medal table's question, answered from the same record.
     *
     * @return array{format:TournamentFormat, bouts:int, rounds:int, athletes:int, byes:int, size:null, override:bool}
     */
    private function placement(WeightCategory $category, bool $discardResults, bool $replacePublished): array
    {
        if ($category->isDrawLocked()) {
            throw new DrawIsProtectedException(
                "The draw for {$category->label} is locked. Unlock it before drawing again."
            );
        }

        if ($category->isDrawPublished() && ! $replacePublished) {
            throw new DrawIsProtectedException(
                "The draw for {$category->label} is published. Withdraw it before drawing again."
            );
        }

        $decided = $category->bouts()->whereNotNull('winner_athlete_id')->where('is_bye', false)->count();

        if ($decided > 0 && ! $discardResults) {
            throw new BracketHasResultsException($decided);
        }

        // A class that has shrunk to one after being drawn as something else
        // still has that draw's contests sitting on it.
        $category->bouts()->delete();

        $category->forceFill([
            'draw_generated_at' => now(),
            'draw_athlete_count' => 1,
            'draw_bucket_size' => null,
            'draw_bye_count' => 0,
            'draw_format' => TournamentFormat::Placement->value,
            'draw_version' => $category->draw_version + 1,
            'draw_published_at' => null,
            // The placement itself is a separate, signed act. Drawing the class
            // only establishes that there is nobody to fight.
            'draw_placement_athlete_id' => null,
            'draw_placement_by' => null,
            'draw_placement_at' => null,
        ])->save();

        return [
            'format' => TournamentFormat::Placement,
            'bouts' => 0,
            'rounds' => 0,
            'athletes' => 1,
            'byes' => 0,
            'size' => null,
            'override' => false,
        ];
    }

    /**
     * Award the class to its single entrant.
     *
     * Separate from generating the draw, and deliberately so: the athlete is
     * not champion because the software noticed they were alone, they are
     * champion because a named administrator decided the class was theirs.
     */
    public function placeSoleAthlete(WeightCategory $category, User $user): Athlete
    {
        if ($category->drawFormat() !== TournamentFormat::Placement) {
            throw new DrawFormatException(
                "{$category->label} is not an administrative placement — it has contests to decide it."
            );
        }

        $athlete = $category->drawnAthletes()->first();

        if ($athlete === null) {
            // Either nobody is entered, or the one entrant has not passed the
            // scale — and the second is worth saying plainly, because placing
            // is what awards the class and an unweighed athlete does not win it
            // by standing alone in it.
            $unweighed = $category->ineligibleNumberedAthletes()->get();

            if ($unweighed->isNotEmpty()) {
                throw new DrawEligibilityException($this->eligibility->refusal(
                    $unweighed,
                    __('Nobody can be placed first in :class.', ['class' => $category->label]),
                ));
            }

            throw new DrawFormatException("{$category->label} has nobody to place.");
        }

        $category->forceFill([
            'draw_placement_athlete_id' => $athlete->id,
            'draw_placement_by' => $user->id,
            'draw_placement_at' => now(),
        ])->save();

        return $athlete;
    }

    /**
     * What to write against the format when it was an override, and what to
     * clear when it was not.
     *
     * Cleared rather than left: a class redrawn as a round robin after having
     * been overridden must not keep a signature saying somebody authorised a
     * knockout, because the knockout is gone.
     *
     * @return array<string, mixed>
     */
    private function overrideAudit(bool $override, ?string $reason, ?User $user): array
    {
        if (! $override) {
            return [
                'draw_format_override_reason' => null,
                'draw_format_override_by' => null,
                'draw_format_override_at' => null,
            ];
        }

        return [
            'draw_format_override_reason' => trim((string) $reason),
            'draw_format_override_by' => $user?->id,
            'draw_format_override_at' => now(),
        ];
    }

    /** Why the requested format was refused, in words an administrator can act on. */
    private function refusal(int $athletes, ?TournamentFormat $format): string
    {
        $available = $this->policy->availableFor($athletes);

        $names = implode(', ', array_map(fn (TournamentFormat $f) => $f->label(), $available));

        return __(':format cannot be drawn for :count athlete(s). Available: :available.', [
            'format' => $format?->label() ?? __('That format'),
            'count' => $athletes,
            'available' => $names !== '' ? $names : __('none'),
        ]);
    }
}
