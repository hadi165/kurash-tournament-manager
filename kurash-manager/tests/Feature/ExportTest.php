<?php

use App\Exports\ConfirmedWeighInReport;
use App\Exports\DrawSheetReport;
use App\Exports\EntriesByWeightCategoryReport;
use App\Exports\FightOrderReport;
use App\Exports\MedalStandingReport;
use App\Exports\ResultsReport;
use App\Models\AgeCategory;
use App\Models\Athlete;
use App\Models\Championship;
use App\Models\User;
use App\Models\WeightCategory;
use App\Services\BoutAdvancer;
use App\Services\BracketGenerator;
use App\Services\FightOrderScheduler;
use App\Services\MedalTable;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->viewer = User::factory()->create(['role' => 'viewer']);
});

/** A weight class with athletes who all made the scale. */
function weighedClass(int $count, string $gender = 'M', string $label = '-91'): WeightCategory
{
    $ageCategory = AgeCategory::factory()->create();

    $category = WeightCategory::factory()->create([
        'age_category_id' => $ageCategory->id,
        'label' => $label,
        'gender' => $gender,
    ]);

    foreach (range(1, $count) as $draw) {
        Athlete::factory()->drawn($draw)->create([
            'championship_id' => $ageCategory->championship_id,
            'age_category_id' => $ageCategory->id,
            'weight_category_id' => $category->id,
            'fullname' => "Athlete {$draw}",
            'noc_code' => 'UZB',
            'weighin_status' => 'pass',
        ]);
    }

    return $category->refresh();
}

describe('the confirmed weigh-in list', function () {
    /**
     * The specification fixes this filename because the federation files the
     * printouts by it.
     */
    it('is named by gender and weight class', function () {
        $category = weighedClass(4, 'M', '-91');

        expect((new ConfirmedWeighInReport($category))->filename())->toBe('Male -91');
    });

    it('names a women\'s class by its own gender', function () {
        $category = weighedClass(4, 'F', '-63');

        expect((new ConfirmedWeighInReport($category))->filename())->toBe('Female -63');
    });

    /**
     * The whole point of this sheet: the executive team writes the draw numbers
     * on by hand, so the column must be blank even for athletes who already
     * hold one in the database.
     */
    it('leaves the draw number blank even when one is already assigned', function () {
        $category = weighedClass(4);

        expect($category->athletes()->whereNotNull('draw_number')->count())->toBe(4);

        $rows = (new ConfirmedWeighInReport($category))->rows();
        $drawColumn = array_column($rows, 5);

        expect($drawColumn)->toBe(['', '', '', '']);
    });

    it('leaves out anyone who failed the scale', function () {
        $category = weighedClass(4);
        $category->athletes()->limit(1)->update(['weighin_status' => 'fail']);

        expect((new ConfirmedWeighInReport($category))->rows())->toHaveCount(3);
    });

    it('states the bracket the field will be drawn into', function () {
        expect((new ConfirmedWeighInReport(weighedClass(5)))->meta()['Bracket Title'])->toBe('1/4 Final')
            ->and((new ConfirmedWeighInReport(weighedClass(12)))->meta()['Bracket Title'])->toBe('1/8 Final')
            ->and((new ConfirmedWeighInReport(weighedClass(2)))->meta()['Bracket Title'])->toBe('Final');
    });
});

describe('the draw sheet', function () {
    it('is named with the Draw- prefix the specification requires', function () {
        $category = weighedClass(4, 'M', '-66');

        expect((new DrawSheetReport($category))->filename())->toBe('Draw-Male -66');
    });

    it('lists every bout in the bracket', function () {
        $category = weighedClass(8);
        app(BracketGenerator::class)->generate($category);

        expect((new DrawSheetReport($category))->rows())->toHaveCount(7);
    });

    /** An empty slot must read BYE, not blank — §7.1. */
    it('labels a walkover slot BYE', function () {
        $category = weighedClass(5);
        app(BracketGenerator::class)->generate($category);

        $cells = collect((new DrawSheetReport($category))->rows())->flatten()->all();

        expect($cells)->toContain('BYE');
    });

    it('is empty before the draw is made', function () {
        expect((new DrawSheetReport(weighedClass(4)))->rows())->toBe([]);
    });
});

describe('the fight order sheet', function () {
    /**
     * The federation's sheets list each corner on its own line sharing a fight
     * number, which is why Color is a column rather than two athlete columns.
     */
    it('gives the blue and green corners a row each', function () {
        $category = weighedClass(4);
        app(BracketGenerator::class)->generate($category);
        app(FightOrderScheduler::class)->schedule($category->ageCategory->championship);

        $rows = (new FightOrderReport($category->ageCategory->championship))->rows();

        expect($rows)->toHaveCount(6)                      // 3 bouts, two corners each
            ->and(array_column($rows, 3))->toBe(['Blue', 'Green', 'Blue', 'Green', 'Blue', 'Green']);
    });

    it('marks the win on the winner\'s own line', function () {
        $category = weighedClass(4);
        app(BracketGenerator::class)->generate($category);
        $championship = $category->ageCategory->championship;
        app(FightOrderScheduler::class)->schedule($championship);

        $bout = $championship->bouts()->where('fight_number', 1)->first();
        app(BoutAdvancer::class)->recordResult(
            bout: $bout,
            winnerAthleteId: $bout->athlete_a_id,
            winType: 'halal',
            user: $this->admin,
            source: 'operator',
        );

        $rows = (new FightOrderReport($championship->refresh()))->rows();

        // Row 0 is fight 1's blue corner — the winner — and row 1 its green.
        expect($rows[0][7])->toBe('WIN')
            ->and($rows[1][7])->toBe('');
    });

    it('omits bouts with no fight number', function () {
        $category = weighedClass(4);
        app(BracketGenerator::class)->generate($category);

        // Never scheduled, so nothing has a running-order number.
        expect((new FightOrderReport($category->ageCategory->championship))->rows())->toBe([]);
    });
});

describe('entries by weight category', function () {
    it('uses the Not Started and Done vocabulary from the specification', function () {
        $category = weighedClass(4);
        $championship = $category->ageCategory->championship;

        $before = (new EntriesByWeightCategoryReport($championship))->rows();
        expect($before[0][5])->toBe('Not Started');

        app(BracketGenerator::class)->generate($category);

        $after = (new EntriesByWeightCategoryReport($championship))->rows();
        expect($after[0][5])->toBe('Done');
    });

    it('counts registered and weighed-in separately', function () {
        $category = weighedClass(4);
        $category->athletes()->limit(1)->update(['weighin_status' => 'fail']);

        $row = (new EntriesByWeightCategoryReport($category->ageCategory->championship))->rows()[0];

        expect($row[2])->toBe(4)     // registered
            ->and($row[3])->toBe(3); // passed the scale
    });
});

describe('results and medal standing', function () {
    /** Run a whole class to its final so there is a podium to report. */
    function decide(WeightCategory $category): void
    {
        app(BracketGenerator::class)->generate($category);

        $advancer = app(BoutAdvancer::class);
        $rounds = (int) $category->bouts()->max('round');

        for ($round = 1; $round <= $rounds; $round++) {
            foreach ($category->bouts()->where('round', $round)->get() as $bout) {
                if ($bout->isReadyToFight()) {
                    $advancer->recordResult(
                        bout: $bout,
                        winnerAthleteId: $bout->athlete_a_id,
                        winType: 'halal',
                        user: null,
                        source: 'operator',
                    );
                }
            }
        }
    }

    it('leaves an undecided class off the results sheet', function () {
        $category = weighedClass(4);
        app(BracketGenerator::class)->generate($category);

        $report = new ResultsReport($category->ageCategory->championship, app(MedalTable::class));

        expect($report->rows())->toBe([]);
    });

    it('reports the podium once the class is decided', function () {
        $category = weighedClass(4);
        decide($category);

        $rows = (new ResultsReport($category->ageCategory->championship, app(MedalTable::class)))->rows();

        expect($rows)->toHaveCount(1)
            ->and($rows[0][1])->toBe('Male -91')
            ->and($rows[0][2])->not->toBeNull()   // gold
            ->and($rows[0][4])->not->toBeNull();  // silver
    });

    it('ranks the standing by gold before silver before bronze', function () {
        $championship = Championship::factory()->create();
        $ageCategory = AgeCategory::factory()->create(['championship_id' => $championship->id]);

        // Two classes. In each, seed 1 wins everything — and seeds are drawn in
        // order, so the NOC on draw 1 takes both golds.
        foreach (['-66', '-73'] as $label) {
            $category = WeightCategory::factory()->create([
                'age_category_id' => $ageCategory->id,
                'label' => $label,
                'gender' => 'M',
            ]);

            foreach (range(1, 4) as $draw) {
                Athlete::factory()->drawn($draw)->create([
                    'championship_id' => $championship->id,
                    'age_category_id' => $ageCategory->id,
                    'weight_category_id' => $category->id,
                    'noc_code' => "NC{$draw}",
                    'weighin_status' => 'pass',
                ]);
            }

            decide($category->refresh());
        }

        $rows = (new MedalStandingReport($championship, app(MedalTable::class)))->rows();

        expect($rows[0][1])->toBe('NC1')   // two golds
            ->and($rows[0][2])->toBe(2)
            ->and($rows[0][0])->toBe(1);
    });
});

describe('serving the files', function () {
    it('streams a CSV that opens in Excel as UTF-8', function () {
        $category = weighedClass(4);

        $response = $this->actingAs($this->admin)
            ->get(route('exports.weigh-in', ['weightCategory' => $category, 'format' => 'csv']));

        $response->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertDownload('Male -91.csv');

        // Without the byte order mark Excel reads the file as its local
        // codepage and mangles every non-ASCII name.
        expect($response->streamedContent())->toStartWith("\u{FEFF}");
    });

    it('renders a PDF', function () {
        $category = weighedClass(4);

        $response = $this->actingAs($this->admin)
            ->get(route('exports.weigh-in', ['weightCategory' => $category, 'format' => 'pdf']));

        $response->assertOk();

        expect($response->getContent())->toStartWith('%PDF-');
    });

    it('lets a viewer download paperwork', function () {
        $category = weighedClass(4);

        $this->actingAs($this->viewer)
            ->get(route('exports.weigh-in', ['weightCategory' => $category, 'format' => 'csv']))
            ->assertOk();
    });

    it('refuses an anonymous visitor', function () {
        $category = weighedClass(4);

        $this->get(route('exports.weigh-in', ['weightCategory' => $category, 'format' => 'csv']))
            ->assertRedirect(route('login'));
    });

    it('rejects a format it does not produce', function () {
        $category = weighedClass(4);

        $this->actingAs($this->admin)
            ->get("/exports/weight-classes/{$category->id}/weigh-in.xlsx")
            ->assertNotFound();
    });
});
