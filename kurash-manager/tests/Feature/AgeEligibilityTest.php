<?php

/**
 * Which age group an athlete may be entered in.
 *
 * The rule is Section 23 of the IKA competition rules, which states each
 * division as an age span and the birth years that span produces — "Cadets
 * (14-15 years, born in 2012-2011 years)". Everything here is anchored to a
 * fixed competition year so the boundaries can be asserted against the
 * published table exactly; the code derives birth years from the year the
 * championship is held in, so the same assertions hold in any season.
 *
 * The second half is about Section 25(2), the one exception: "With the
 * sanction of the Chief Referee, youths (16-17 years) may also participate in
 * adults' competitions." That is a signature, not a wider band, and these tests
 * hold it to being exactly that — one named office, a recorded reason, and no
 * power to admit anybody outside the window.
 */

use App\Exports\AccreditationCards;
use App\Livewire\Competition\Registration;
use App\Models\AgeCategory;
use App\Models\Athlete;
use App\Models\AthleteAgeSanction;
use App\Models\Championship;
use App\Models\User;
use App\Models\WeightCategory;
use App\Services\AgeEligibilityException;
use App\Services\AgeEligibilityPolicy;
use App\Services\AgeSanctions;
use App\Services\ChampionshipArchivedException;
use App\Support\AgeVerdict;
use Carbon\CarbonImmutable;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/** The year the published table this suite asserts against was printed for. */
const TABLE_YEAR = 2026;

beforeEach(function () {
    $this->policy = app(AgeEligibilityPolicy::class);
});

/** Judge one entry in the year the IKA table was published for. */
function judge(string $dateOfBirth, string $gender, string $ageGroup, bool $sanctioned = false): AgeVerdict
{
    return app(AgeEligibilityPolicy::class)->check(
        dateOfBirth: CarbonImmutable::parse($dateOfBirth),
        gender: $gender,
        ageGroup: $ageGroup,
        competitionYear: TABLE_YEAR,
        sanctioned: $sanctioned,
    );
}

/**
 * A championship held in the table's year, with one division and one class.
 *
 * @return array{0: Championship, 1: AgeCategory, 2: WeightCategory}
 */
function eventFor(string $ageGroup = 'Senior', string $gender = 'M', ?int $year = null): array
{
    $championship = Championship::factory()->create([
        'starts_on' => CarbonImmutable::create($year ?? TABLE_YEAR, 6, 1),
        'ends_on' => null,
        'genders' => [$gender],
        'age_groups' => [$ageGroup],
    ]);

    $division = AgeCategory::factory()->create([
        'championship_id' => $championship->id,
        'gender' => $gender,
        'age_group' => $ageGroup,
    ]);

    $class = WeightCategory::factory()->create([
        'age_category_id' => $division->id,
        'gender' => $gender,
        'label' => '-66',
    ]);

    return [$championship->refresh(), $division->refresh(), $class];
}

describe('the published bands', function () {
    /**
     * Every boundary in Section 23, both ends of every band, for both genders.
     *
     * The birth years in the dataset are the ones the IKA printed, so a change
     * to the arithmetic that still produced plausible ages would fail here.
     */
    it('matches the birth years the IKA published for :dataset', function (
        string $gender, string $ageGroup, int $birthYear, bool $eligible
    ) {
        $verdict = judge("{$birthYear}-06-15", $gender, $ageGroup);

        expect($verdict->eligible)->toBe($eligible)
            ->and($verdict->competitionAge)->toBe(TABLE_YEAR - $birthYear)
            ->and($verdict->judged)->toBeTrue();
    })->with([
        // Cadets (14-15 years, born in 2012-2011 years)
        'M cadet, oldest in band' => ['M', 'Cadet', 2011, true],
        'M cadet, youngest in band' => ['M', 'Cadet', 2012, true],
        'M cadet, a year too old' => ['M', 'Cadet', 2010, false],
        'M cadet, a year too young' => ['M', 'Cadet', 2013, false],
        'F cadet, oldest in band' => ['F', 'Cadet', 2011, true],
        'F cadet, youngest in band' => ['F', 'Cadet', 2012, true],
        'F cadet, a year too young' => ['F', 'Cadet', 2013, false],

        // Juniors (16-17 years, born in 2010-2009 years)
        'M junior, oldest in band' => ['M', 'Junior', 2009, true],
        'M junior, youngest in band' => ['M', 'Junior', 2010, true],
        'M junior, a year too old' => ['M', 'Junior', 2008, false],
        'M junior, a year too young' => ['M', 'Junior', 2011, false],
        'F junior, oldest in band' => ['F', 'Junior', 2009, true],
        'F junior, youngest in band' => ['F', 'Junior', 2010, true],

        // Seniors (men 17-35, born in 2009-1991). 2009 and 2010 are the youths
        // Section 25(2) covers and are asserted separately.
        'M senior, oldest in band' => ['M', 'Senior', 1991, true],
        'M senior, youngest without a signature' => ['M', 'Senior', 2008, true],
        'M senior, a year too old' => ['M', 'Senior', 1990, false],

        // Seniors (women, above 17, born in 2009 and above) — no ceiling.
        'F senior, youngest without a signature' => ['F', 'Senior', 2008, true],
        'F senior, no upper limit' => ['F', 'Senior', 1955, true],

        // Veterans (36-45 ... born in 1990-1981 and above)
        'M veteran, youngest in band' => ['M', 'Veteran', 1990, true],
        'M veteran, no upper limit' => ['M', 'Veteran', 1940, true],
        'M veteran, a year too young' => ['M', 'Veteran', 1991, false],
    ]);

    it('names the band an athlete actually belongs in when refusing', function () {
        $verdict = judge('2012-03-01', 'M', 'Senior');

        expect($verdict->state)->toBe(AgeVerdict::OUT_OF_BAND)
            ->and($verdict->belongsIn?->ageGroup)->toBe('Cadet')
            ->and($verdict->reason)->toContain('Cadet');
    });

    /**
     * A birth-year rule does not care what day of the year somebody was born,
     * which is what makes 29 February a non-event here. Asserted because the
     * obvious wrong implementation — an age computed from today's date — is
     * exactly the one that breaks on it.
     */
    it('treats a leap-day birth like any other day of that year', function () {
        $leap = judge('2008-02-29', 'M', 'Senior');
        $newYear = judge('2008-01-01', 'M', 'Senior');
        $newYearsEve = judge('2008-12-31', 'M', 'Senior');

        expect($leap->competitionAge)->toBe(18)
            ->and($newYear->competitionAge)->toBe(18)
            ->and($newYearsEve->competitionAge)->toBe(18)
            ->and($leap->eligible)->toBeTrue();
    });

    it('derives the same bands in a later competition year', function () {
        // The 2026 table is not hard-coded: a cadet in 2027 is born in 2013.
        $verdict = app(AgeEligibilityPolicy::class)->check(
            dateOfBirth: CarbonImmutable::parse('2013-05-05'),
            gender: 'M',
            ageGroup: 'Cadet',
            competitionYear: 2027,
        );

        expect($verdict->eligible)->toBeTrue()
            ->and($verdict->competitionAge)->toBe(14);
    });
});

describe('what cannot be judged', function () {
    it('refuses an entry with no date of birth, without pretending to have checked', function () {
        $verdict = app(AgeEligibilityPolicy::class)->check(
            dateOfBirth: null, gender: 'M', ageGroup: 'Senior', competitionYear: TABLE_YEAR,
        );

        expect($verdict->eligible)->toBeFalse()
            ->and($verdict->judged)->toBeFalse()
            ->and($verdict->verified())->toBeFalse()
            ->and($verdict->state)->toBe(AgeVerdict::NO_DATE);
    });

    it('refuses a date of birth after the competition', function () {
        $verdict = judge('2030-01-01', 'M', 'Senior');

        expect($verdict->eligible)->toBeFalse()
            ->and($verdict->state)->toBe(AgeVerdict::FUTURE_DATE);
    });

    /**
     * An organizer may name their own age group — the championship form
     * accepts any string. There is no rule to hold such an entry against, and
     * inventing one would refuse a competition the software simply does not
     * know about.
     */
    it('leaves an age group the rules say nothing about unjudged rather than refusing it', function () {
        $verdict = judge('1975-01-01', 'M', 'Masters 45');

        expect($verdict->eligible)->toBeTrue()
            ->and($verdict->judged)->toBeFalse()
            ->and($verdict->state)->toBe(AgeVerdict::UNREGULATED_GROUP);
    });

    it('leaves a competition year older than every policy unjudged', function () {
        $verdict = app(AgeEligibilityPolicy::class)->check(
            dateOfBirth: CarbonImmutable::parse('2005-01-01'),
            gender: 'M', ageGroup: 'Senior', competitionYear: 2019,
        );

        expect($verdict->eligible)->toBeTrue()
            ->and($verdict->judged)->toBeFalse()
            ->and($verdict->state)->toBe(AgeVerdict::UNSUPPORTED_YEAR);
    });

    /**
     * Not knowing when somebody was born is a fact about the athlete, not
     * about which rules apply — so it is the answer even where no policy
     * covers the year. Asked the other way round, a legacy championship handed
     * out credentials to entries nobody had checked.
     */
    it('says the date is missing before it says the year is unsupported', function () {
        $verdict = app(AgeEligibilityPolicy::class)->check(
            dateOfBirth: null, gender: 'M', ageGroup: 'Senior', competitionYear: 2019,
        );

        expect($verdict->state)->toBe(AgeVerdict::NO_DATE)
            ->and($verdict->eligible)->toBeFalse();
    });

    it('ignores a pinned policy version nobody has written rules for', function () {
        [$championship] = eventFor('Senior', 'M');

        $championship->forceFill(['age_policy_version' => 1999])->save();

        // Falls back to the year's own version rather than switching age
        // checking off for the whole event.
        expect(app(AgeEligibilityPolicy::class)->versionForChampionship($championship->refresh()))
            ->toBe(2026);
    });

    it('names the narrowest band an age falls in', function () {
        // A man of 46 is out of the seniors (17-35) and into the veterans, and
        // no other band admits him.
        expect(judge('1980-01-01', 'M', 'Cadet')->belongsIn?->ageGroup)->toBe('Veteran');

        // A 17-year-old man is admitted by the junior band AND by the printed
        // senior band. The narrower one is what he is told he belongs in,
        // which is also the one he can enter without a signature.
        expect(judge('2009-01-01', 'M', 'Cadet')->belongsIn?->ageGroup)->toBe('Junior');
    });

    /**
     * A woman of 46 is inside the women's senior band, which the IKA prints
     * with no ceiling at all ("above 17 years"), and also inside the veterans'
     * band this system infers for women because the rules print none. Both
     * are open-topped, so the first the policy lists wins and she is told she
     * belongs in the seniors — which is what Section 23 literally says.
     *
     * Asserted rather than left to chance because it is one of the places the
     * published rules are genuinely ambiguous, and a federation may want the
     * other answer. See config/kurash.php.
     */
    it('places an older woman in the open-topped senior band the rules print', function () {
        expect(judge('1980-01-01', 'F', 'Cadet')->belongsIn?->ageGroup)->toBe('Senior');
    });

    it('keeps a policy in force for years after it was published', function () {
        expect(app(AgeEligibilityPolicy::class)->versionFor(2026))->toBe(2026)
            ->and(app(AgeEligibilityPolicy::class)->versionFor(2031))->toBe(2026)
            ->and(app(AgeEligibilityPolicy::class)->versionFor(2019))->toBeNull();
    });
});

describe('Section 25(2), the Chief Referee\'s sanction', function () {
    it('asks for a signature for a youth in an adults\' competition', function (int $birthYear) {
        $verdict = judge("{$birthYear}-06-01", 'M', 'Senior');

        expect($verdict->eligible)->toBeFalse()
            ->and($verdict->state)->toBe(AgeVerdict::NEEDS_SANCTION)
            ->and($verdict->sanctionable)->toBeTrue()
            ->and($verdict->reason)->toContain('Chief Referee');
    })->with([
        'sixteen, below the printed senior band' => [2010],
        'seventeen, inside it' => [2009],
    ]);

    /**
     * The overlap the published table contains: male juniors are 2010-2009 and
     * male seniors 2009-1991, so a 17-year-old man is inside both. Taking the
     * band alone would admit him to an adults' competition unsigned, which is
     * the thing Section 25(2) exists to prevent.
     */
    it('does not let the junior-senior overlap admit a seventeen-year-old unsigned', function () {
        expect(judge('2009-06-01', 'M', 'Junior')->eligible)->toBeTrue()
            ->and(judge('2009-06-01', 'M', 'Senior')->state)->toBe(AgeVerdict::NEEDS_SANCTION);
    });

    it('admits the same youth once the sanction is on file', function () {
        $verdict = judge('2010-06-01', 'M', 'Senior', sanctioned: true);

        expect($verdict->eligible)->toBeTrue()
            ->and($verdict->state)->toBe(AgeVerdict::SANCTIONED);
    });

    it('offers no sanction for an age outside the window', function (string $dob, string $group) {
        $verdict = judge($dob, 'M', $group);

        expect($verdict->eligible)->toBeFalse()
            ->and($verdict->sanctionable)->toBeFalse();
    })->with([
        'a cadet in the seniors' => ['2012-01-01', 'Senior'],
        'a fifteen-year-old in the veterans' => ['2011-01-01', 'Veteran'],
        'a thirty-six-year-old in the seniors' => ['1990-01-01', 'Senior'],
    ]);

    it('does not ask for a signature in a junior competition', function () {
        expect(judge('2010-01-01', 'M', 'Junior')->state)->toBe(AgeVerdict::ELIGIBLE);
    });

    /**
     * The clause admits youths to the adults' competition — the seniors. The
     * veterans are an adults' competition in the ordinary sense of the words,
     * but they carry a floor of 36, and reading 25(2) as a power to waive any
     * age limit would let the Chief Referee sign a sixteen-year-old into a
     * division for the over-thirty-fives.
     */
    it('offers no signature for a youth in the veterans', function () {
        $verdict = judge('2010-01-01', 'M', 'Veteran');

        expect($verdict->eligible)->toBeFalse()
            ->and($verdict->sanctionable)->toBeFalse()
            ->and($verdict->state)->toBe(AgeVerdict::OUT_OF_BAND);
    });

    it('refuses to sanction a youth into the veterans', function () {
        [$championship, $division, $class] = eventFor('Veteran', 'M');

        $youth = Athlete::factory()->create([
            'championship_id' => $championship->id,
            'age_category_id' => $division->id,
            'weight_category_id' => $class->id,
            'gender' => 'M',
            'date_of_birth' => '2010-01-01',
        ]);

        $chief = User::factory()->create(['role' => User::ROLE_CHIEF_REFEREE]);

        expect(fn () => app(AgeSanctions::class)->grant($youth, $chief, 'Exceptional.'))
            ->toThrow(AgeEligibilityException::class);
    });
});

describe('granting and withdrawing a sanction', function () {
    beforeEach(function () {
        [$this->championship, $this->division, $this->class] = eventFor('Senior', 'M');

        $this->youth = Athlete::factory()->create([
            'championship_id' => $this->championship->id,
            'age_category_id' => $this->division->id,
            'weight_category_id' => $this->class->id,
            'fullname' => 'Young Entrant',
            'gender' => 'M',
            'date_of_birth' => '2010-04-04',
        ]);

        $this->chief = User::factory()->create(['role' => User::ROLE_CHIEF_REFEREE]);
    });

    it('records the decision with the official, the reason and the moment', function () {
        app(AgeSanctions::class)->grant($this->youth, $this->chief, 'National federation request, medical clearance on file.');

        $entry = AthleteAgeSanction::query()->firstOrFail();

        expect($entry->action)->toBe(AthleteAgeSanction::ACTION_GRANTED)
            ->and($entry->acted_by)->toBe($this->chief->id)
            ->and($entry->reason)->toContain('medical clearance')
            ->and($entry->created_at)->not->toBeNull()
            // Frozen, so a later correction to any of them cannot restate what
            // was signed.
            ->and($entry->competition_year)->toBe(TABLE_YEAR)
            ->and($entry->birth_year)->toBe(2010)
            ->and($entry->competition_age)->toBe(16)
            ->and($entry->policy_version)->toBe(2026)
            ->and($entry->age_group)->toBe('Senior');
    });

    it('makes the athlete eligible once granted', function () {
        expect($this->youth->ageVerdict()->eligible)->toBeFalse();

        app(AgeSanctions::class)->grant($this->youth, $this->chief, 'Sanctioned.');

        expect($this->youth->refresh()->ageVerdict()->eligible)->toBeTrue()
            ->and($this->youth->ageVerdict()->state)->toBe(AgeVerdict::SANCTIONED);
    });

    it('refuses an account that is not the Chief Referee', function (string $role) {
        $user = User::factory()->create(['role' => $role]);

        expect(fn () => app(AgeSanctions::class)->grant($this->youth, $user, 'Because I say so.'))
            ->toThrow(AgeEligibilityException::class);

        expect(AthleteAgeSanction::count())->toBe(0);
    })->with([
        // The administrator is on this list on purpose: the rule names an
        // office, and "can do everything" is not that office.
        'an administrator' => [User::ROLE_ADMIN],
        'a supervisor' => [User::ROLE_SUPERVISOR],
        'an official' => [User::ROLE_OFFICIAL],
        'a referee' => [User::ROLE_REFEREE],
    ]);

    it('refuses a closed Chief Referee account', function () {
        $closed = User::factory()->create(['role' => User::ROLE_CHIEF_REFEREE, 'is_active' => false]);

        expect(fn () => app(AgeSanctions::class)->grant($this->youth, $closed, 'Sanctioned.'))
            ->toThrow(AgeEligibilityException::class);
    });

    it('refuses a sanction with no reason', function (string $reason) {
        expect(fn () => app(AgeSanctions::class)->grant($this->youth, $this->chief, $reason))
            ->toThrow(AgeEligibilityException::class);

        expect(AthleteAgeSanction::count())->toBe(0);
    })->with([
        'empty' => [''],
        'whitespace only' => ["   \n\t "],
    ]);

    it('refuses to sanction an athlete the exception does not cover', function () {
        $cadet = Athlete::factory()->create([
            'championship_id' => $this->championship->id,
            'age_category_id' => $this->division->id,
            'weight_category_id' => $this->class->id,
            'gender' => 'M',
            'date_of_birth' => '2013-01-01',
        ]);

        expect(fn () => app(AgeSanctions::class)->grant($cadet, $this->chief, 'Very talented.'))
            ->toThrow(AgeEligibilityException::class);
    });

    it('refuses to sanction an athlete with no date of birth', function () {
        $unknown = Athlete::factory()->create([
            'championship_id' => $this->championship->id,
            'age_category_id' => $this->division->id,
            'weight_category_id' => $this->class->id,
            'gender' => 'M',
            'date_of_birth' => null,
        ]);

        expect(fn () => app(AgeSanctions::class)->grant($unknown, $this->chief, 'Looks about right.'))
            ->toThrow(AgeEligibilityException::class);
    });

    it('withdraws by appending rather than erasing', function () {
        app(AgeSanctions::class)->grant($this->youth, $this->chief, 'Granted at the technical meeting.');
        app(AgeSanctions::class)->revoke($this->youth, $this->chief, 'Medical certificate withdrawn.');

        $history = app(AgeSanctions::class)->historyFor($this->youth);

        expect($history)->toHaveCount(2)
            // Newest first, and both decisions still readable.
            ->and($history->first()->action)->toBe(AthleteAgeSanction::ACTION_REVOKED)
            ->and($history->last()->action)->toBe(AthleteAgeSanction::ACTION_GRANTED)
            ->and($history->last()->reason)->toContain('technical meeting')
            ->and(app(AgeSanctions::class)->isSanctioned($this->youth))->toBeFalse()
            ->and($this->youth->refresh()->ageVerdict()->eligible)->toBeFalse();
    });

    it('can be granted again after a withdrawal, and the log keeps all three', function () {
        $sanctions = app(AgeSanctions::class);

        $sanctions->grant($this->youth, $this->chief, 'First.');
        $sanctions->revoke($this->youth, $this->chief, 'Second.');
        $sanctions->grant($this->youth, $this->chief, 'Third.');

        expect($sanctions->historyFor($this->youth))->toHaveCount(3)
            ->and($sanctions->isSanctioned($this->youth))->toBeTrue();
    });

    it('refuses to withdraw a sanction that was never granted', function () {
        expect(fn () => app(AgeSanctions::class)->revoke($this->youth, $this->chief, 'Never existed.'))
            ->toThrow(AgeEligibilityException::class);
    });

    /** A sanction is for one entry, not a property of the person. */
    it('does not carry a sanction across into another division', function () {
        app(AgeSanctions::class)->grant($this->youth, $this->chief, 'Seniors only.');

        $veterans = AgeCategory::factory()->create([
            'championship_id' => $this->championship->id,
            'gender' => 'M',
            'age_group' => 'Veteran',
        ]);

        expect(app(AgeSanctions::class)->isSanctioned($this->youth, $veterans->id))->toBeFalse();
    });

    it('keeps the record when the signing account is deleted', function () {
        app(AgeSanctions::class)->grant($this->youth, $this->chief, 'Granted.');

        $this->chief->delete();

        $entry = AthleteAgeSanction::query()->firstOrFail();

        expect($entry->reason)->toContain('Granted.')
            ->and($entry->acted_by)->toBeNull();
    });
});

describe('the registration form', function () {
    beforeEach(function () {
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        [$this->championship, $this->division, $this->class] = eventFor('Cadet', 'M');
    });

    /** @return Testable */
    function registering(Championship $championship, AgeCategory $division, WeightCategory $class, string $dob)
    {
        return Livewire::test(Registration::class, [
            'championship' => $championship,
            'competition' => 'M',
        ])
            ->set('fullname', 'Entered Athlete')
            ->set('noc_code', 'UZB')
            ->set('gender', 'M')
            ->set('age_category_id', $division->id)
            ->set('weight_category_id', $class->id)
            ->set('date_of_birth', $dob);
    }

    it('registers an athlete whose birth year fits the age group', function () {
        $this->actingAs($this->admin);

        registering($this->championship, $this->division, $this->class, '2012-05-05')
            ->call('save')
            ->assertHasNoErrors();

        expect(Athlete::where('fullname', 'Entered Athlete')->first()?->date_of_birth?->toDateString())
            ->toBe('2012-05-05');
    });

    it('refuses one whose birth year does not, and says which group they belong in', function () {
        $this->actingAs($this->admin);

        registering($this->championship, $this->division, $this->class, '2005-05-05')
            ->call('save')
            ->assertHasErrors('age_category_id');

        expect(Athlete::where('fullname', 'Entered Athlete')->exists())->toBeFalse();
    });

    it('asks for a date of birth at all', function () {
        $this->actingAs($this->admin);

        registering($this->championship, $this->division, $this->class, '')
            ->call('save')
            ->assertHasErrors('date_of_birth');
    });

    it('refuses a date of birth in the future', function () {
        $this->actingAs($this->admin);

        registering($this->championship, $this->division, $this->class, now()->addYear()->toDateString())
            ->call('save')
            ->assertHasErrors('date_of_birth');
    });

    it('keeps what was typed when it refuses', function () {
        $this->actingAs($this->admin);

        registering($this->championship, $this->division, $this->class, '2005-05-05')
            ->call('save')
            ->assertSet('fullname', 'Entered Athlete')
            ->assertSet('date_of_birth', '2005-05-05')
            ->assertSet('noc_code', 'UZB');
    });

    it('shows the competition age and the band as the form is filled in', function () {
        $this->actingAs($this->admin);

        registering($this->championship, $this->division, $this->class, '2012-05-05')
            ->assertSee('Born 2012')
            ->assertSee('Age checked');
    });

    it('explains a mismatch on screen before anything is submitted', function () {
        $this->actingAs($this->admin);

        registering($this->championship, $this->division, $this->class, '1990-05-05')
            ->assertSee('Wrong age group')
            ->assertSee('Veteran');
    });

    /** Moving somebody between age groups is where a good entry goes wrong. */
    it('refuses a move into an age group the athlete has outgrown', function () {
        $this->actingAs($this->admin);

        $seniors = AgeCategory::factory()->create([
            'championship_id' => $this->championship->id,
            'gender' => 'M',
            'age_group' => 'Senior',
        ]);
        $seniorClass = WeightCategory::factory()->create([
            'age_category_id' => $seniors->id, 'gender' => 'M', 'label' => '-73',
        ]);

        $this->championship->forceFill(['age_groups' => ['Cadet', 'Senior']])->save();

        $cadet = Athlete::factory()->create([
            'championship_id' => $this->championship->id,
            'age_category_id' => $this->division->id,
            'weight_category_id' => $this->class->id,
            'gender' => 'M',
            'date_of_birth' => '2012-05-05',
        ]);

        Livewire::test(Registration::class, ['championship' => $this->championship->refresh(), 'competition' => 'M'])
            ->call('edit', $cadet->id)
            ->set('age_category_id', $seniors->id)
            ->set('weight_category_id', $seniorClass->id)
            ->call('save')
            ->assertHasErrors('age_category_id');

        expect($cadet->refresh()->age_category_id)->toBe($this->division->id);
    });
});

describe('the sanction on the registration screen', function () {
    beforeEach(function () {
        [$this->championship, $this->division, $this->class] = eventFor('Senior', 'M');

        $this->youth = Athlete::factory()->create([
            'championship_id' => $this->championship->id,
            'age_category_id' => $this->division->id,
            'weight_category_id' => $this->class->id,
            'fullname' => 'Sanction Candidate',
            'gender' => 'M',
            'date_of_birth' => '2010-04-04',
        ]);
    });

    it('offers the control to the Chief Referee', function () {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CHIEF_REFEREE]));

        Livewire::test(Registration::class, ['championship' => $this->championship, 'competition' => 'M'])
            ->call('edit', $this->youth->id)
            ->assertSee('Sanction under 25(2)');
    });

    it('offers an administrator an explanation instead of the control', function () {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));

        Livewire::test(Registration::class, ['championship' => $this->championship, 'competition' => 'M'])
            ->call('edit', $this->youth->id)
            ->assertDontSee('Sanction under 25(2)')
            ->assertSee('Only the Chief Referee may sanction this entry.');
    });

    it('records a sanction from the screen', function () {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CHIEF_REFEREE]));

        Livewire::test(Registration::class, ['championship' => $this->championship, 'competition' => 'M'])
            ->call('edit', $this->youth->id)
            ->set('sanctionReason', 'Cleared at the technical meeting.')
            ->call('grantAgeSanction')
            ->assertHasNoErrors();

        expect(app(AgeSanctions::class)->isSanctioned($this->youth->refresh()))->toBeTrue();
    });

    it('refuses one with no reason and says so on the field', function () {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CHIEF_REFEREE]));

        Livewire::test(Registration::class, ['championship' => $this->championship, 'competition' => 'M'])
            ->call('edit', $this->youth->id)
            ->set('sanctionReason', '')
            ->call('grantAgeSanction')
            ->assertHasErrors('sanctionReason');

        expect(AthleteAgeSanction::count())->toBe(0);
    });

    it('refuses the action outright for an account without the office', function () {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_SUPERVISOR]));

        Livewire::test(Registration::class, ['championship' => $this->championship, 'competition' => 'M'])
            ->call('edit', $this->youth->id)
            ->set('sanctionReason', 'Trying it on.')
            ->call('grantAgeSanction')
            ->assertForbidden();

        expect(AthleteAgeSanction::count())->toBe(0);
    });

    it('shows the history once something has been decided', function () {
        $chief = User::factory()->create(['role' => User::ROLE_CHIEF_REFEREE, 'name' => 'Chief Officer']);
        app(AgeSanctions::class)->grant($this->youth, $chief, 'Federation request.');

        $this->actingAs($chief);

        Livewire::test(Registration::class, ['championship' => $this->championship, 'competition' => 'M'])
            ->call('edit', $this->youth->id)
            ->assertSee('Federation request.')
            ->assertSee('Chief Officer')
            ->assertSee('Withdraw sanction');
    });
});

describe('athletes registered before dates of birth were collected', function () {
    beforeEach(function () {
        [$this->championship, $this->division, $this->class] = eventFor('Senior', 'M');

        $this->legacy = Athlete::factory()->create([
            'championship_id' => $this->championship->id,
            'age_category_id' => $this->division->id,
            'weight_category_id' => $this->class->id,
            'fullname' => 'Long Standing Entrant',
            'gender' => 'M',
            'date_of_birth' => null,
        ]);
    });

    it('stays readable, and says the age is not verified', function () {
        expect($this->legacy->hasDateOfBirth())->toBeFalse()
            ->and($this->legacy->dateOfBirthVerified())->toBeFalse()
            ->and($this->legacy->ageVerdict()->state)->toBe(AgeVerdict::NO_DATE)
            ->and($this->legacy->ageVerdict()->verified())->toBeFalse();
    });

    it('is still listed on the entry screen', function () {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));

        Livewire::test(Registration::class, ['championship' => $this->championship, 'competition' => 'M'])
            ->assertSee('Long Standing Entrant');
    });

    it('is refused a credential until a date is recorded', function () {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));

        $this->get(route('exports.accreditation.athlete', $this->legacy))->assertNotFound();

        $this->legacy->forceFill(['date_of_birth' => '1998-01-01'])->save();

        $this->get(route('exports.accreditation.athlete', $this->legacy->refresh()))->assertOk();
    });

    it('is left out of a batch of cards rather than stopping the run', function () {
        $fine = Athlete::factory()->create([
            'championship_id' => $this->championship->id,
            'age_category_id' => $this->division->id,
            'weight_category_id' => $this->class->id,
            'fullname' => 'Checked Entrant',
            'gender' => 'M',
            'date_of_birth' => '1998-01-01',
        ]);

        $cards = AccreditationCards::forChampionship($this->championship);

        expect($cards->isEmpty())->toBeFalse()
            ->and($cards->excluded())->toHaveCount(1)
            ->and($cards->excluded()->first()['athlete']->id)->toBe($this->legacy->id);

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
        $this->get(route('exports.accreditation.athlete', $fine))->assertOk();
    });

    it('can be found as a worklist', function () {
        expect(Athlete::query()->missingDateOfBirth()->count())->toBe(1);
    });
});

describe('an archived championship', function () {
    it('stays readable and is not re-judged into invalidity', function () {
        [$championship, $division, $class] = eventFor('Senior', 'M', year: 2019);

        $athlete = Athlete::factory()->create([
            'championship_id' => $championship->id,
            'age_category_id' => $division->id,
            'weight_category_id' => $class->id,
            'fullname' => 'Historical Entrant',
            'gender' => 'M',
            'date_of_birth' => null,
        ]);

        $championship->forceFill(['archived_at' => now()])->save();

        // The athlete has no date of birth, and that is the answer whatever
        // year the event was held in — a card is not printed for somebody
        // nobody has established the age of. What matters for a closed
        // competition is that the row is still there and still readable.
        $verdict = $athlete->refresh()->ageVerdict();

        expect($verdict->state)->toBe(AgeVerdict::NO_DATE)
            ->and($verdict->judged)->toBeFalse()
            ->and($athlete->fullname)->toBe('Historical Entrant');

        // And it stays closed: the archive guard refuses the write, which is
        // why the unsupported-year case is exercised on its own event below
        // rather than by editing this one.
        expect(fn () => $athlete->forceFill(['date_of_birth' => '1990-01-01'])->save())
            ->toThrow(ChampionshipArchivedException::class);
    });

    /**
     * A competition fought years before these rules were written is a record,
     * not an entry list to be re-approved. With a date of birth on file it is
     * left unjudged rather than refused.
     */
    it('leaves an entry from a year no policy covers unjudged', function () {
        [$championship, $division, $class] = eventFor('Senior', 'M', year: 2019);

        $athlete = Athlete::factory()->create([
            'championship_id' => $championship->id,
            'age_category_id' => $division->id,
            'weight_category_id' => $class->id,
            'gender' => 'M',
            'date_of_birth' => '1990-01-01',
        ]);

        $verdict = $athlete->ageVerdict();

        expect($verdict->state)->toBe(AgeVerdict::UNSUPPORTED_YEAR)
            ->and($verdict->judged)->toBeFalse()
            ->and($verdict->eligible)->toBeTrue();
    });

    it('honours a version pinned on the championship itself', function () {
        [$championship] = eventFor('Senior', 'M', year: 2019);

        $championship->forceFill(['age_policy_version' => 2026])->save();

        expect(app(AgeEligibilityPolicy::class)->versionForChampionship($championship->refresh()))->toBe(2026);
    });
});
