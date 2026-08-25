<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\AthleteAgeSanction;
use App\Models\User;
use App\Support\AgeVerdict;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Granting, withdrawing and reading the Chief Referee's sanctions.
 *
 * Section 25(2) of the IKA rules: "With the sanction of the Chief Referee,
 * youths (16-17 years) may also participate in adults' competitions."
 *
 * Four conditions, all of them enforced here rather than at the screen, so a
 * command or a screen written later cannot grant a sanction the rules do not
 * allow:
 *
 *   the athlete is in the sanction window for their competition year
 *   the division they are being signed into is an adults' competition
 *   the account signing holds the office
 *   a reason is given
 *
 * The last one is not a formality. A sanction with no reason is indis-
 * tinguishable afterwards from an accident, and the whole purpose of naming an
 * official in the rule is that somebody can be asked.
 */
class AgeSanctions
{
    public function __construct(
        private readonly AgeEligibilityPolicy $policy,
    ) {}

    /**
     * Is a sanction in force for this athlete in this division?
     *
     * The newest row for the pair wins. Read by id rather than by created_at:
     * a grant and a withdrawal recorded in the same second have to have an
     * order, and the sequence they were written in is the only honest one.
     */
    public function isSanctioned(Athlete $athlete, ?int $ageCategoryId = null): bool
    {
        return $this->current($athlete, $ageCategoryId)?->grants() ?? false;
    }

    /** The decision currently standing, or null where none was ever taken. */
    public function current(Athlete $athlete, ?int $ageCategoryId = null): ?AthleteAgeSanction
    {
        $ageCategoryId ??= $athlete->age_category_id;

        return AthleteAgeSanction::query()
            ->where('athlete_id', $athlete->id)
            ->where('age_category_id', $ageCategoryId)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Everything ever decided about this athlete, newest first.
     *
     * Across every division, because the interesting history is usually the
     * one that crosses them: signed into the seniors, withdrawn, moved to the
     * juniors.
     *
     * @return Collection<int, AthleteAgeSanction>
     */
    public function historyFor(Athlete $athlete): Collection
    {
        return AthleteAgeSanction::query()
            ->with(['actedBy', 'ageCategory'])
            ->where('athlete_id', $athlete->id)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Sign a youth into an adults' competition.
     *
     * @throws AgeEligibilityException when the rule does not permit it
     */
    public function grant(Athlete $athlete, User $chiefReferee, string $reason): AthleteAgeSanction
    {
        $reason = trim($reason);

        if (! $chiefReferee->isChiefReferee()) {
            throw new AgeEligibilityException(
                __('Only the Chief Referee may sanction a youth into an adults\' competition.')
            );
        }

        if ($reason === '') {
            throw new AgeEligibilityException(
                __('Give a reason for the sanction. It is recorded against your name.')
            );
        }

        $ageCategory = $athlete->ageCategory;
        $championship = $athlete->championship;

        if ($ageCategory === null || $championship === null) {
            throw new AgeEligibilityException(
                __(':name is not in a division, so there is nothing to sanction.', ['name' => $athlete->fullname])
            );
        }

        /*
         | The rule, asked of the entry as it stands.
         |
         | Deliberately asked without the sanction, so what comes back is the
         | verdict the athlete would get unsigned. Only one of its answers can
         | be signed away: a youth in the window standing at the door of an
         | adults' competition. Everything else — a cadet in the seniors, a
         | veteran in the juniors, a missing date of birth — is refused here,
         | because Section 25(2) is an exception for youths and not a power to
         | set Section 23 aside.
         */
        $verdict = $this->policy->check(
            dateOfBirth: $athlete->date_of_birth,
            gender: (string) $athlete->gender,
            ageGroup: (string) ($ageCategory->age_group ?? ''),
            competitionYear: $championship->competitionYear(),
            sanctioned: false,
            version: $this->policy->versionForChampionship($championship),
        );

        if (! $verdict->sanctionable) {
            throw new AgeEligibilityException(
                $verdict->state === AgeVerdict::NO_DATE
                    ? __('Record :name\'s date of birth before sanctioning them.', ['name' => $athlete->fullname])
                    : __('There is nothing here for the Chief Referee to sanction: :reason', [
                        'reason' => $verdict->reason ?? __('this entry already follows the age rules.'),
                    ])
            );
        }

        return $this->write($athlete, $chiefReferee, $reason, AthleteAgeSanction::ACTION_GRANTED, $verdict);
    }

    /**
     * Withdraw a sanction.
     *
     * A second row rather than the removal of the first: the athlete did
     * compete under it, or was entered under it, and deleting the record would
     * leave that unexplained.
     *
     * @throws AgeEligibilityException
     */
    public function revoke(Athlete $athlete, User $chiefReferee, string $reason): AthleteAgeSanction
    {
        $reason = trim($reason);

        if (! $chiefReferee->isChiefReferee()) {
            throw new AgeEligibilityException(
                __('Only the Chief Referee may withdraw a sanction.')
            );
        }

        if ($reason === '') {
            throw new AgeEligibilityException(
                __('Give a reason for withdrawing the sanction.')
            );
        }

        if (! $this->isSanctioned($athlete)) {
            throw new AgeEligibilityException(
                __('There is no sanction in force for :name to withdraw.', ['name' => $athlete->fullname])
            );
        }

        return $this->write($athlete, $chiefReferee, $reason, AthleteAgeSanction::ACTION_REVOKED, null);
    }

    /**
     * Append one decision.
     *
     * The figures the official was shown are copied onto the row, because none
     * of them can be recovered later: the rules get a new edition, a date of
     * birth gets corrected, a championship's dates get edited. A log that
     * recomputed them would quietly restate what somebody signed.
     */
    private function write(
        Athlete $athlete,
        User $user,
        string $reason,
        string $action,
        ?AgeVerdict $verdict,
    ): AthleteAgeSanction {
        $championship = $athlete->championship;

        return DB::transaction(fn (): AthleteAgeSanction => AthleteAgeSanction::create([
            'athlete_id' => $athlete->id,
            'age_category_id' => $athlete->age_category_id,
            'championship_id' => $athlete->championship_id,
            'action' => $action,
            'reason' => $reason,
            'acted_by' => $user->id,
            'policy_version' => $verdict?->policyVersion,
            'competition_year' => $championship?->competitionYear(),
            'birth_year' => $verdict === null ? $athlete->date_of_birth?->year : $verdict->birthYear,
            'competition_age' => $verdict?->competitionAge,
            'age_group' => $athlete->ageCategory?->age_group,
        ]));
    }
}
