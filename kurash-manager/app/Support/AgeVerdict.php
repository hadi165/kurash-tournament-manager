<?php

namespace App\Support;

/**
 * What the age rules said about one entry.
 *
 * Carries the reason, the age it worked out, and the band that would have
 * accepted the athlete, because "wrong age group" on its own is not something
 * a registrar can act on — they need to know which group the athlete belongs
 * in, and whether the answer is a refusal or something the Chief Referee can
 * sanction.
 *
 * ── Two different negatives ──────────────────────────────────────────────
 *
 * `judged` and `eligible` are separate on purpose. An entry can be
 * unjudged — no policy covers the year, or the organizer invented the age
 * group — and an unjudged entry is *allowed*, because this system does not
 * know a rule it can hold the entry against. What it must never do is call
 * that "eligible" and let it pass a credentials check as though the age had
 * been established. Screens read `judged` to decide whether to say "Age not
 * verified", and `eligible` to decide whether to refuse.
 */
final readonly class AgeVerdict
{
    /** No date of birth on file, so nothing can be worked out. */
    public const NO_DATE = 'no_date_of_birth';

    /** A date of birth after the competition itself. */
    public const FUTURE_DATE = 'future_date_of_birth';

    /** Judged, and inside the band for this age group. */
    public const ELIGIBLE = 'eligible';

    /** Judged, and outside it. */
    public const OUT_OF_BAND = 'out_of_band';

    /** A 16- or 17-year-old entering an adults' competition, unsanctioned. */
    public const NEEDS_SANCTION = 'needs_sanction';

    /** The same, with the Chief Referee's sanction recorded. */
    public const SANCTIONED = 'sanctioned';

    /** An age group this policy states no rule for. */
    public const UNREGULATED_GROUP = 'unregulated_group';

    /** A competition year no policy version covers. */
    public const UNSUPPORTED_YEAR = 'unsupported_year';

    public function __construct(
        /** May this athlete be entered here? */
        public bool $eligible,
        /** Did a policy actually evaluate this, or is the answer "we cannot say"? */
        public bool $judged,
        /** One of the constants above. */
        public string $state,
        public ?string $reason = null,
        public ?int $competitionAge = null,
        public ?int $birthYear = null,
        /** The band for the group that was asked about, where one exists. */
        public ?AgeBand $band = null,
        /** The band the athlete's age actually falls in, where there is one. */
        public ?AgeBand $belongsIn = null,
        public ?int $policyVersion = null,
        /**
         * Could the Chief Referee sanction this entry under Section 25(2)?
         *
         * True only for a youth in the sanction window entering an adults'
         * competition. Never true for any other refusal: the clause is an
         * exception for 16- and 17-year-olds, not a power to waive the age
         * groups generally, so a 13-year-old in a senior class cannot be
         * signed into it by anybody.
         */
        public bool $sanctionable = false,
    ) {}

    /** Is this entry waiting on a Chief Referee's signature? */
    public function needsSanction(): bool
    {
        return $this->state === self::NEEDS_SANCTION;
    }

    /** Has the age been established one way or the other? */
    public function verified(): bool
    {
        return $this->judged && $this->state !== self::NO_DATE;
    }
}
