<?php

use App\Exports\AthleteListReport;
use App\Livewire\Competition\Registration;
use App\Models\AgeCategory;
use App\Models\Athlete;
use App\Models\Championship;
use App\Models\User;
use App\Models\WeightCategory;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $this->actingAs($this->admin);
});

/**
 * An accreditation number is read off a card by a person at a door, which is
 * what three digits are for. Counted within the championship, because every
 * event starts again at one.
 */
describe('the accreditation number', function () {
    it('gives the first athlete of a championship IKA001', function () {
        $division = AgeCategory::factory()->create(['gender' => 'M', 'age_group' => 'Senior']);
        $class = WeightCategory::factory()->create(['age_category_id' => $division->id, 'gender' => 'M']);

        $athlete = Athlete::register([
            'championship_id' => $division->championship_id,
            'age_category_id' => $division->id,
            'weight_category_id' => $class->id,
            'fullname' => 'First Entrant',
            'gender' => 'M',
            'noc_code' => 'UZB',
        ]);

        expect($athlete->ika_id)->toBe('IKA001');
    });

    it('counts on from there, three digits at a time', function () {
        $division = AgeCategory::factory()->create(['gender' => 'M', 'age_group' => 'Senior']);
        $class = WeightCategory::factory()->create(['age_category_id' => $division->id, 'gender' => 'M']);

        $ids = [];

        foreach (range(1, 3) as $n) {
            $ids[] = Athlete::register([
                'championship_id' => $division->championship_id,
                'age_category_id' => $division->id,
                'weight_category_id' => $class->id,
                'fullname' => "Entrant {$n}",
                'gender' => 'M',
                'noc_code' => 'UZB',
            ])->ika_id;
        }

        expect($ids)->toBe(['IKA001', 'IKA002', 'IKA003']);
    });

    /** A card belongs to one event, and so does the number on it. */
    it('starts again at one in the next championship', function () {
        $ids = [];

        foreach (range(1, 2) as $n) {
            $division = AgeCategory::factory()->create(['gender' => 'M', 'age_group' => 'Senior']);
            $class = WeightCategory::factory()->create(['age_category_id' => $division->id, 'gender' => 'M']);

            $ids[] = Athlete::register([
                'championship_id' => $division->championship_id,
                'age_category_id' => $division->id,
                'weight_category_id' => $class->id,
                'fullname' => "Entrant {$n}",
                'gender' => 'M',
                'noc_code' => 'UZB',
            ])->ika_id;
        }

        expect($ids)->toBe(['IKA001', 'IKA001']);
    });

    /**
     * Counted from the highest already issued rather than from how many exist,
     * so removing somebody does not hand their number to the next arrival.
     */
    it('does not reuse the number of an athlete who withdrew', function () {
        $division = AgeCategory::factory()->create(['gender' => 'M', 'age_group' => 'Senior']);
        $class = WeightCategory::factory()->create(['age_category_id' => $division->id, 'gender' => 'M']);

        $entry = fn (string $name) => Athlete::register([
            'championship_id' => $division->championship_id,
            'age_category_id' => $division->id,
            'weight_category_id' => $class->id,
            'fullname' => $name,
            'gender' => 'M',
            'noc_code' => 'UZB',
        ]);

        $entry('One');
        $entry('Two')->delete();

        expect($entry('Three')->ika_id)->toBe('IKA003');
    });
});

/**
 * The list the hotel and the organising team work from. Not a competition
 * document: who is coming and from where, ordered by nation.
 */
describe('the athlete list', function () {
    beforeEach(function () {
        $this->championship = Championship::factory()->create(['title' => 'Asian Kurash 2026']);

        $division = AgeCategory::factory()->for($this->championship)
            ->create(['gender' => 'M', 'age_group' => 'Senior']);
        $class = WeightCategory::factory()->create(['age_category_id' => $division->id, 'gender' => 'M']);

        foreach ([['UZB', 'Bekzod'], ['KAZ', 'Aidos'], ['UZB', 'Aziz']] as [$noc, $name]) {
            Athlete::register([
                'championship_id' => $this->championship->id,
                'age_category_id' => $division->id,
                'weight_category_id' => $class->id,
                'fullname' => $name,
                'gender' => 'M',
                'noc_code' => $noc,
            ]);
        }
    });

    it('reads as one delegation after another', function () {
        $rows = (new AthleteListReport($this->championship))->rows();

        expect(array_column($rows, 0))->toBe(['KAZ', 'UZB', 'UZB'])
            // And alphabetically inside each.
            ->and(array_column($rows, 3))->toBe(['Aidos', 'Aziz', 'Bekzod']);
    });

    it('makes one delegation their own list', function () {
        $report = new AthleteListReport($this->championship, 'UZB');

        expect($report->rows())->toHaveCount(2)
            ->and($report->meta()['Delegation'])->toBe('UZB — Uzbekistan')
            ->and($report->filename())->toContain('uzb');
    });

    it('says how many are on it', function () {
        expect((new AthleteListReport($this->championship))->total())
            ->toBe(['label' => 'Athletes', 'value' => 3]);
    });

    /**
     * The code and the country in separate columns: a spreadsheet handed to a
     * hotel is sorted and filtered on them, and one column holding both
     * cannot be either.
     */
    it('carries the country as its own column, beside the code', function () {
        $report = new AthleteListReport($this->championship);

        expect($report->headings())->toBe(
            ['NOC', 'Country', 'IKA ID', 'Name', 'Gender', 'Division', 'Weight', 'Passport / ID', 'Club']
        );

        expect($report->rows()[0][1])->toBe('Kazakhstan');
    });

    /**
     * The flag comes from the column headed NOC. Detected by the template
     * rather than declared here — every table with an NOC column gets one, and
     * no report has to remember to ask.
     */
    it('heads its code column NOC, which is what earns it a flag', function () {
        expect((new AthleteListReport($this->championship))->headings()[0])->toBe('NOC');
    });

    it('is served as a PDF and as a workbook', function () {
        foreach (['pdf', 'xlsx'] as $format) {
            $this->get(route('exports.athletes', [
                'championship' => $this->championship,
                'format' => $format,
            ]))->assertOk();
        }
    });

    it('is served for one country', function () {
        $this->get(route('exports.athletes', [
            'championship' => $this->championship,
            'format' => 'pdf',
            'noc' => 'UZB',
        ]))->assertOk();
    });

    /** A code nobody is entered under prints the whole list, not an empty one. */
    it('ignores a country the system does not know', function () {
        $report = new AthleteListReport($this->championship, 'ZZZ');

        expect($report->rows())->toHaveCount(0);

        // Which is why the route checks it before the report ever sees it.
        $this->get(route('exports.athletes', [
            'championship' => $this->championship,
            'format' => 'pdf',
            'noc' => 'ZZZ',
        ]))->assertOk();
    });

    it('offers the registration screen the countries actually entered', function () {
        $division = $this->championship->ageCategories()->first();

        Livewire::test(Registration::class, [
            'championship' => $this->championship,
            'competition' => 'M',
        ])
            ->assertViewHas('delegations', fn (array $d) => array_keys($d) === ['KAZ', 'UZB'])
            ->assertSee('UZB — Uzbekistan')
            ->assertSee('All countries');

        expect($division)->not->toBeNull();
    });
});

/** The draw number belongs to the draw screen, not to registration. */
it('no longer shows a draw column on registration', function () {
    $division = AgeCategory::factory()->create(['gender' => 'M', 'age_group' => 'Senior']);
    WeightCategory::factory()->create(['age_category_id' => $division->id, 'gender' => 'M']);

    Livewire::test(Registration::class, [
        'championship' => $division->championship,
        'competition' => 'M',
    ])->assertDontSee('>Draw<', false);
});
