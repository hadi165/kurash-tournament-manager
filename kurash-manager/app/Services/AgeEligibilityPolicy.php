<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\Championship;
use App\Support\AgeBand;
use App\Support\AgeVerdict;
use Carbon\CarbonInterface;

/**
 * Which age group an athlete may be entered in, and who may sign for the
 * exception.
 *
 * The single place a date of birth is turned into a decision. The registration
 * form, the importer, the credentials export and the tests all ask this class,
 * so the rule can be corrected in one file rather than found in five — and so
 * that a spreadsheet of two hundred entries is judged by exactly the same code
 * as the one typed in by hand.
 *
 * The rule, from the IKA competition rules, Section 23
 * (https://kurash-ika.org/2022/08/20/kurash-rules/), stated in full with its
 * versions and its ambiguities in config/kurash.php under `age_eligibility`.
 * In short:
 *
 *   competition age = competition year - birth year
 *
 *   Cadet    14-15
 *   Junior   16-17
 *   Senior   17-35 for men, 17 and above for women
 *   Veteran  36 and above
 *
 * Section 25(2) adds the one exception: "With the sanction of the Chief
 * Referee, youths (16-17 years) may also participate in adults' competitions."
 * That is an exception to Section 23 rather than a reading of it, so it is
 * handled as a signature and not as a wider band — see check().
 *
 * ── What this class refuses to guess ─────────────────────────────────────
 *
 * Two situations produce an answer of "we cannot say", never a refusal:
 *
 *   an age group no version states a rule for — organizers may name their own
 *   a competition year older than the earliest version on file
 *
 * Both come back with judged = false. A caller must not read that as
 * eligibility established: it is the absence of a rule, and the credentials
 * check treats it as unverified. Refusing instead would mean an upgrade
 * invalidated the entry list of every championship already in the database,
 * which is a worse failure than admitting the software does not know.
 */
class AgeEligibilityPolicy
{
    /**
     * Every policy version, newest first, keyed by the year it came into force.
     *
     * @return array<int, array<string, mixed>>
     */
    public function versions(): array
    {
        /** @var array<int, array<string, mixed>> $versions */
        $versions = (array) config('kurash.age_eligibility.versions', []);

        krsort($versions);

        return $versions;
    }

    /**
     * The version a championship in this year is judged by.
     *
     * The newest version that had come into force by then — a policy stays in
     * force until a later one supersedes it, so 2027 is judged by the 2026
     * rules until somebody writes a 2027 entry. A year older than every
     * version returns the configured fallback, which is null by default: a
     * historical import is a record of what happened and not an entry list to
     * be re-approved.
     */
    public function versionFor(int $competitionYear): ?int
    {
        foreach ($this->versions() as $year => $ignored) {
            if ($year <= $competitionYear) {
                return $year;
            }
        }

        $fallback = config('kurash.age_eligibility.fallback_version');

        return $fallback === null ? null : (int) $fallback;
    }

    /**
     * The bands one version states for one gender, keyed by age group.
     *
     * Keyed by the athlete's gender rather than the division's: a division may
     * be open ('X'), but every athlete in it is still entered as a man or a
     * woman and is judged by the band for that.
     *
     * @return array<string, AgeBand>
     */
    public function bandsFor(int $version, string $gender): array
    {
        /** @var array<string, array{min:int, max:int|null}> $bands */
        $bands = (array) config("kurash.age_eligibility.versions.{$version}.bands.{$gender}", []);

        $resolved = [];

        foreach ($bands as $ageGroup => $span) {
            $resolved[$ageGroup] = new AgeBand(
                ageGroup: (string) $ageGroup,
                minAge: (int) $span['min'],
                maxAge: isset($span['max']) ? (int) $span['max'] : null,
            );
        }

        return $resolved;
    }

    /** The band for one group, or null where the version states none. */
    public function bandFor(int $version, string $gender, string $ageGroup): ?AgeBand
    {
        return $this->bandsFor($version, $gender)[$ageGroup] ?? null;
    }

    /** Is this age group an adults' competition for Section 25(2)? */
    public function isAdultGroup(int $version, string $ageGroup): bool
    {
        /** @var list<string> $groups */
        $groups = (array) config("kurash.age_eligibility.versions.{$version}.adult_groups", []);

        return in_array($ageGroup, $groups, true);
    }

    /**
     * The competition ages Section 25(2) lets the Chief Referee sanction.
     *
     * @return array{0: int, 1: int}
     */
    public function sanctionWindow(int $version): array
    {
        return [
            (int) config("kurash.age_eligibility.versions.{$version}.sanction_window.min", 16),
            (int) config("kurash.age_eligibility.versions.{$version}.sanction_window.max", 17),
        ];
    }

    /** Is a youth of this age one the Chief Referee may sign into an adults' competition? */
    public function withinSanctionWindow(int $version, int $competitionAge): bool
    {
        [$min, $max] = $this->sanctionWindow($version);

        return $competitionAge >= $min && $competitionAge <= $max;
    }

    /**
     * Judge one entry.
     *
     * The whole rule, in the order the rules themselves impose it.
     *
     * @param  bool  $sanctioned  whether a Chief Referee's sanction is on file
     *                            for this athlete in this age group
     */
    public function check(
        ?CarbonInterface $dateOfBirth,
        string $gender,
        string $ageGroup,
        int $competitionYear,
        bool $sanctioned = false,
        ?int $version = null,
    ): AgeVerdict {
        $version ??= $this->versionFor($competitionYear);

        /*
         | No date of birth is the first answer, before anything about which
         | rules apply.
         |
         | The order matters. Asked the other way round, an athlete with no
         | date at all in a championship no policy covers came back as "not
         | judged, but allowed" — and the credentials check would then print a
         | card for somebody whose age nobody had established. Not knowing when
         | somebody was born is a fact about the athlete, not about the rules,
         | so it does not wait on a policy version.
         */
        if ($dateOfBirth === null) {
            return new AgeVerdict(
                eligible: false,
                judged: false,
                state: AgeVerdict::NO_DATE,
                reason: __('No date of birth on file, so the age group cannot be checked.'),
                policyVersion: $version,
            );
        }

        if ($version === null) {
            return new AgeVerdict(
                // Allowed, but not established. No version of the rules covers
                // this year, so there is nothing to hold the entry against.
                eligible: true,
                judged: false,
                state: AgeVerdict::UNSUPPORTED_YEAR,
                reason: __('No age rules are on file for :year, so this entry cannot be checked.', ['year' => $competitionYear]),
                birthYear: (int) $dateOfBirth->year,
            );
        }

        $birthYear = (int) $dateOfBirth->year;

        // Born after the competition was held. Not an age at all, and worth
        // its own answer: it is nearly always a typed year, not a real one.
        if ($birthYear > $competitionYear) {
            return new AgeVerdict(
                eligible: false,
                judged: true,
                state: AgeVerdict::FUTURE_DATE,
                reason: __('That date of birth is after the competition year (:year).', ['year' => $competitionYear]),
                birthYear: $birthYear,
                policyVersion: $version,
            );
        }

        $age = $competitionYear - $birthYear;
        $bands = $this->bandsFor($version, $gender);
        $band = $bands[$ageGroup] ?? null;

        if ($band === null) {
            return new AgeVerdict(
                // Same reasoning as an unsupported year: the organizer named a
                // group these rules say nothing about, so there is no rule to
                // break. It is still not an age anybody has established.
                eligible: true,
                judged: false,
                state: AgeVerdict::UNREGULATED_GROUP,
                reason: __('The :group age group has no age rule on file, so this entry cannot be checked.', ['group' => $ageGroup]),
                competitionAge: $age,
                birthYear: $birthYear,
                policyVersion: $version,
            );
        }

        $belongsIn = $this->bandContaining($bands, $age);

        /*
         | Section 25(2), before Section 23's bands.
         |
         | Asked first because it is an exception to them, and it has to cover
         | both shapes the exception takes. A 16-year-old is below the senior
         | band and needs the signature to cross into it. A 17-year-old man is
         | *inside* the printed senior band (17-35) and inside the junior band
         | (16-17) at the same time — the two overlap at that age in the
         | published table — and taking the band alone would wave him into an
         | adults' competition with no signature at all, which is the thing the
         | clause exists to prevent.
         |
         | Whether the overlap is deliberate is a question for the federation;
         | until it is answered, the reading that asks for a signature is the
         | one that cannot admit a minor by accident.
         */
        if ($this->isAdultGroup($version, $ageGroup) && $this->withinSanctionWindow($version, $age)) {
            return new AgeVerdict(
                eligible: $sanctioned,
                judged: true,
                state: $sanctioned ? AgeVerdict::SANCTIONED : AgeVerdict::NEEDS_SANCTION,
                reason: $sanctioned
                    ? __(':age in :year — entered in :group under the Chief Referee\'s sanction.', [
                        'age' => $age, 'year' => $competitionYear, 'group' => $ageGroup,
                    ])
                    : __('A :age-year-old may only enter :group with the sanction of the Chief Referee.', [
                        'age' => $age, 'group' => $ageGroup,
                    ]),
                competitionAge: $age,
                birthYear: $birthYear,
                band: $band,
                belongsIn: $belongsIn,
                policyVersion: $version,
                sanctionable: true,
            );
        }

        if ($band->admits($age)) {
            return new AgeVerdict(
                eligible: true,
                judged: true,
                state: AgeVerdict::ELIGIBLE,
                reason: null,
                competitionAge: $age,
                birthYear: $birthYear,
                band: $band,
                belongsIn: $belongsIn,
                policyVersion: $version,
            );
        }

        return new AgeVerdict(
            eligible: false,
            judged: true,
            state: AgeVerdict::OUT_OF_BAND,
            reason: $belongsIn === null
                ? __('Born :birthYear, so :age in :year — outside :group (:band).', [
                    'birthYear' => $birthYear, 'age' => $age, 'year' => $competitionYear,
                    'group' => $ageGroup, 'band' => $band->ageLabel(),
                ])
                : __('Born :birthYear, so :age in :year — that is :belongs, not :group (:band).', [
                    'birthYear' => $birthYear, 'age' => $age, 'year' => $competitionYear,
                    'belongs' => $belongsIn->ageGroup, 'group' => $ageGroup, 'band' => $band->ageLabel(),
                ]),
            competitionAge: $age,
            birthYear: $birthYear,
            band: $band,
            belongsIn: $belongsIn,
            policyVersion: $version,
        );
    }

    /**
     * Judge an athlete as they are currently entered.
     *
     * The convenience every screen actually wants: it reads the division and
     * the championship off the athlete rather than making each caller
     * assemble them, and asks AgeSanctions whether a signature is on file.
     */
    public function checkAthlete(Athlete $athlete): AgeVerdict
    {
        $ageCategory = $athlete->ageCategory;
        $championship = $athlete->championship;

        if ($ageCategory === null || $championship === null) {
            return new AgeVerdict(
                eligible: true,
                judged: false,
                state: AgeVerdict::UNREGULATED_GROUP,
                reason: __('This athlete is not in a division, so the age group cannot be checked.'),
                birthYear: $athlete->date_of_birth?->year,
            );
        }

        return $this->check(
            dateOfBirth: $athlete->date_of_birth,
            gender: (string) $athlete->gender,
            ageGroup: (string) ($ageCategory->age_group ?? ''),
            competitionYear: $championship->competitionYear(),
            sanctioned: app(AgeSanctions::class)->isSanctioned($athlete, $ageCategory->id),
            version: $this->versionForChampionship($championship),
        );
    }

    /**
     * The version a championship is judged by, honouring an explicit pin.
     *
     * An event run under rules other than the ones current for its year says
     * so on its own row, which is how a championship held to last season's
     * regulations is judged by last season's regulations.
     */
    public function versionForChampionship(Championship $championship): ?int
    {
        $pinned = $championship->age_policy_version;

        // Honoured only if it names a version that exists. A pin at a year
        // nobody has written rules for would otherwise switch age checking off
        // for the whole event and look like a configured decision.
        if ($pinned !== null && array_key_exists((int) $pinned, $this->versions())) {
            return (int) $pinned;
        }

        return $this->versionFor($championship->competitionYear());
    }

    /**
     * Which band an age actually falls in, so a refusal can name it.
     *
     * The first match wins, and the bands are read in the order the config
     * lists them — cadet, junior, senior, veteran — so the overlap between
     * junior and senior at 17 resolves to junior, which is where a 17-year-old
     * belongs unless somebody signs for the other thing.
     *
     * @param  array<string, AgeBand>  $bands
     */
    private function bandContaining(array $bands, int $age): ?AgeBand
    {
        $best = null;

        foreach ($bands as $band) {
            if (! $band->admits($age)) {
                continue;
            }

            // The narrowest match wins. The women's senior band has no ceiling,
            // so read first-match-first it would claim every veteran as well
            // and a fifty-year-old would be told she belongs in the seniors.
            if ($best === null || $this->span($band) < $this->span($best)) {
                $best = $band;
            }
        }

        return $best;
    }

    /** How many years a band covers; an open top end counts as very wide. */
    private function span(AgeBand $band): int
    {
        return $band->maxAge === null ? PHP_INT_MAX : $band->maxAge - $band->minAge;
    }
}
