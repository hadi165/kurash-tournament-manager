<?php

namespace App\Support;

/**
 * One age group's span, and the birth years it works out to.
 *
 * The IKA prints both — "Cadets (14-15 years, born in 2012-2011 years)" — but
 * only one of them is a rule. The ages are the rule; the birth years are that
 * rule applied to a particular competition year, which is why they are derived
 * here rather than stored.
 *
 * Both ends are inclusive. A null maximum is an open top end, which is what
 * the veterans' last bracket and the women's seniors both have.
 */
final readonly class AgeBand
{
    public function __construct(
        public string $ageGroup,
        public int $minAge,
        public ?int $maxAge = null,
    ) {}

    /** Does this band include somebody of this competition age? */
    public function admits(int $age): bool
    {
        if ($age < $this->minAge) {
            return false;
        }

        return $this->maxAge === null || $age <= $this->maxAge;
    }

    /**
     * The birth years this band covers in a given competition year.
     *
     * Returned oldest-first — [earliest birth year, latest birth year] — with
     * a null earliest where the band has no upper age. The IKA prints these
     * the other way round ("born in 2012-2011"), youngest first, because it
     * lists them alongside an ascending age range; the order here is the one a
     * range is normally read in.
     *
     * @return array{0: int|null, 1: int}
     */
    public function birthYears(int $competitionYear): array
    {
        return [
            $this->maxAge === null ? null : $competitionYear - $this->maxAge,
            $competitionYear - $this->minAge,
        ];
    }

    /** "14-15 years", or "36 years and above" where the band has no ceiling. */
    public function ageLabel(): string
    {
        if ($this->maxAge === null) {
            return __(':min years and above', ['min' => $this->minAge]);
        }

        if ($this->maxAge === $this->minAge) {
            return __(':min years', ['min' => $this->minAge]);
        }

        return __(':min-:max years', ['min' => $this->minAge, 'max' => $this->maxAge]);
    }

    /** "born in 2012-2011", the way an entry form's help text should read it. */
    public function birthYearLabel(int $competitionYear): string
    {
        [$earliest, $latest] = $this->birthYears($competitionYear);

        if ($earliest === null) {
            return __('born in :latest or earlier', ['latest' => $latest]);
        }

        if ($earliest === $latest) {
            return __('born in :year', ['year' => $latest]);
        }

        return __('born in :latest-:earliest', ['latest' => $latest, 'earliest' => $earliest]);
    }
}
