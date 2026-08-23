<?php

use App\Exports\BracketSheet;
use App\Exports\BracketSheetWriter;
use App\Exports\DocumentReference;
use App\Exports\DrawNumbersReport;
use App\Exports\DrawSheetReport;
use App\Exports\EntriesByWeightCategoryReport;
use App\Exports\FightOrderReport;
use App\Exports\HasTotal;
use App\Exports\MedalStandingReport;
use App\Exports\Report;
use App\Exports\ResultsReport;
use App\Exports\WeighInFormReport;
use App\Models\AgeCategory;
use App\Models\Athlete;
use App\Models\Championship;
use App\Models\User;
use App\Models\WeightCategory;
use App\Services\BoutAdvancer;
use App\Services\BracketGenerator;
use App\Services\FightOrderScheduler;
use App\Services\MedalTable;
use App\Support\BracketSeeding;
use PhpOffice\PhpSpreadsheet\IOFactory;

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

describe('the weigh-in form', function () {
    /**
     * The specification fixes this filename because the federation files the
     * printouts by it.
     */
    it('is named by gender and weight class', function () {
        $category = weighedClass(4, 'M', '-91');

        expect((new WeighInFormReport($category))->filename())->toBe('Male -91');
    });

    it('names a women\'s class by its own gender', function () {
        $category = weighedClass(4, 'F', '-63');

        expect((new WeighInFormReport($category))->filename())->toBe('Female -63');
    });

    /**
     * The sheet the referee writes on. It is printed before the scale opens,
     * so it has to list the class when nobody has been weighed at all — which
     * is exactly when it used to come out blank.
     */
    it('lists everyone entered before a single weight is recorded', function () {
        $category = weighedClass(4);
        // "pending" is what an athlete who has not been weighed is — the
        // column is not nullable, and the sheet has to read it as blank.
        $category->athletes()->update(['weighin_kg' => null, 'weighin_status' => 'pending']);

        $rows = (new WeighInFormReport($category->refresh()))->rows();

        expect($rows)->toHaveCount(4)
            // The space the referee writes the reading into.
            ->and(array_column($rows, 5))->toBe(['', '', '', ''])
            ->and(array_column($rows, 6))->toBe(['', '', '', '']);
    });

    it('carries the weight once it has been recorded', function () {
        $category = weighedClass(4);
        $athlete = $category->athletes()->first();
        $athlete->update(['weighin_kg' => 59.4, 'weighin_status' => 'pass']);

        $row = collect((new WeighInFormReport($category))->rows())
            ->firstWhere(1, $athlete->ika_id);

        expect($row[5])->toBe('59.4')
            ->and($row[6])->toBe('Passed');
    });

    /**
     * Kept on the sheet rather than dropped from it. This is what the draw
     * numbers get written onto, and an athlete who missed the weight must not
     * be drafted onto a draw because the paper stayed silent about them.
     */
    it('keeps anyone who failed the scale, and says so', function () {
        $category = weighedClass(4);
        $failed = $category->athletes()->first();
        $failed->update(['weighin_status' => 'fail']);

        $rows = (new WeighInFormReport($category->refresh()))->rows();

        expect($rows)->toHaveCount(4)
            ->and(collect($rows)->firstWhere(1, $failed->ika_id)[6])->toBe('Failed');
    });

    /** Weight where the draw number used to be. */
    it('asks for a weight rather than a draw number', function () {
        expect((new WeighInFormReport(weighedClass(2)))->headings())
            ->toBe(["Athlete's Name", "Athlete's ID (IKA)", 'NOC', 'Country', 'Bracket Title', 'Weight', 'Result']);
    });

    /** The bracket the class will actually be drawn into. */
    it('counts the bracket without the athletes who missed the weight', function () {
        $category = weighedClass(5);
        $category->athletes()->limit(1)->update(['weighin_status' => 'fail']);

        // Five entered is a bracket of eight; four is a bracket of four.
        expect((new WeighInFormReport($category->refresh()))->meta()['Bracket Title'])
            ->toBe('Semi Final');
    });

    it('states the bracket the field will be drawn into', function () {
        expect((new WeighInFormReport(weighedClass(5)))->meta()['Bracket Title'])->toBe('1/4 Final')
            ->and((new WeighInFormReport(weighedClass(12)))->meta()['Bracket Title'])->toBe('1/8 Final')
            ->and((new WeighInFormReport(weighedClass(2)))->meta()['Bracket Title'])->toBe('Final');
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
     * The sheet is the screen. One row per contest, the same seven columns in
     * the same order, so a table official checking one against the other is
     * not translating between two documents.
     */
    it('prints one row per contest, in the screen\'s columns', function () {
        $category = weighedClass(4);
        app(BracketGenerator::class)->generate($category);
        $championship = $category->ageCategory->championship;
        app(FightOrderScheduler::class)->schedule($championship);

        $report = new FightOrderReport($championship);

        expect($report->headings())->toBe(['No.', 'Category', 'Phase', 'Blue', 'Green', 'Mat', 'Winner'])
            ->and($report->rows())->toHaveCount(3);   // three contests, three rows
    });

    it('names each corner the way the screen does', function () {
        $category = weighedClass(4);
        app(BracketGenerator::class)->generate($category);
        $championship = $category->ageCategory->championship;
        app(FightOrderScheduler::class)->schedule($championship);

        $bout = $championship->bouts()->where('fight_number', 1)->first();
        $blue = $bout->athleteA;

        $row = collect((new FightOrderReport($championship))->rows())
            ->firstWhere(0, 1);

        expect($row[3])->toBe($blue->fullname.' ('.$blue->noc_code.')')
            ->and($row[1])->toContain($category->label)
            ->and($row[1])->toContain($category->ageCategory->name);
    });

    it('puts the winner in the winner\'s column', function () {
        $category = weighedClass(4);
        app(BracketGenerator::class)->generate($category);
        $championship = $category->ageCategory->championship;
        app(FightOrderScheduler::class)->schedule($championship);

        $bout = $championship->bouts()->where('fight_number', 1)->first();
        app(BoutAdvancer::class)->recordResult(
            bout: $bout,
            winnerAthleteId: $bout->athlete_a_id,
            winType: 'khalol',
            user: $this->admin,
            source: 'operator',
        );

        $rows = collect((new FightOrderReport($championship->refresh()))->rows());

        expect($rows->firstWhere(0, 1)[6])->toContain($bout->athleteA->fullname)
            // An undecided contest has nobody in it yet.
            ->and($rows->firstWhere(0, 3)[6])->toBe('—');
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
                        winType: 'khalol',
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

        // pdf, xlsx and csv are the three; anything else is not a document
        // this system makes.
        $this->actingAs($this->admin)
            ->get("/exports/weight-classes/{$category->id}/weigh-in.docx")
            ->assertNotFound();
    });

    it('produces the spreadsheet the Excel buttons ask for', function () {
        $category = weighedClass(4);

        $this->actingAs($this->admin)
            ->get(route('exports.weigh-in', ['weightCategory' => $category, 'format' => 'xlsx']))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    });
});

describe('the printed sheet', function () {
    beforeEach(fn () => $this->actingAs($this->admin));

    /** Rendered as HTML rather than as a PDF: the template is what is on trial. */
    function renderedSheet(Report $report): string
    {
        return view('exports.table', [
            'title' => $report->title(),
            'meta' => $report->meta(),
            'headings' => $report->headings(),
            'rows' => $report->rows(),
            'documentTag' => DocumentReference::tag($report),
            'documentReference' => DocumentReference::reference($report),
            'total' => $report instanceof HasTotal ? $report->total() : null,
            'footerLine' => $report->meta()['Competition'] ?? null,
        ])->render();
    }

    it('carries a document type and a filing reference', function () {
        $category = weighedClass(4);
        $championship = $category->ageCategory->championship;

        $html = renderedSheet(new EntriesByWeightCategoryReport($championship));

        expect($html)->toContain('Entries by Weight Category')
            ->and($html)->toContain('IKA-ENT-'.now()->format('Y'));
    });

    /**
     * The reference is cited in correspondence weeks later, so the same
     * document has to keep producing the same one.
     */
    it('gives the same document the same reference every time', function () {
        $category = weighedClass(4);
        $championship = $category->ageCategory->championship;

        $first = DocumentReference::reference(new EntriesByWeightCategoryReport($championship));
        $second = DocumentReference::reference(new EntriesByWeightCategoryReport($championship));

        expect($first)->toBe($second);
    });

    it('sets draw status as a chip in the fixed vocabulary', function () {
        $category = weighedClass(4);
        $championship = $category->ageCategory->championship;

        $html = renderedSheet(new EntriesByWeightCategoryReport($championship));

        expect($html)->toContain('chip-status chip-idle');

        app(BracketGenerator::class)->generate($category);

        expect(renderedSheet(new EntriesByWeightCategoryReport($championship)))
            ->toContain('chip-status chip-done');
    });

    it('totals the reports that have something to add up', function () {
        $category = weighedClass(6);
        $championship = $category->ageCategory->championship;

        $report = new EntriesByWeightCategoryReport($championship);

        expect($report->total())->toBe(['label' => 'Total weighed in', 'value' => 6])
            ->and(renderedSheet($report))->toContain('Total weighed in');
    });

    /** A running order has nothing to sum, and a spurious total is worse than none. */
    it('leaves the total off a report that has no meaningful sum', function () {
        $category = weighedClass(4);
        $championship = $category->ageCategory->championship;

        expect(new FightOrderReport($championship))->not->toBeInstanceOf(HasTotal::class);
    });

    it('drops the total row when there is nothing to report', function () {
        $championship = Championship::factory()->create();

        expect(renderedSheet(new EntriesByWeightCategoryReport($championship)))
            ->toContain('Nothing to report yet.')
            ->and(renderedSheet(new EntriesByWeightCategoryReport($championship)))
            ->not->toContain('Total weighed in');
    });
});

describe('the draw numbers', function () {
    beforeEach(fn () => $this->actingAs($this->admin));

    it('lists everybody holding a number, in draw order', function () {
        $category = weighedClass(6);
        app(BracketGenerator::class)->generate($category);

        $report = new DrawNumbersReport($category->refresh());
        $rows = $report->rows();

        expect($rows)->toHaveCount(6)
            ->and(array_column($rows, 0))->toBe([1, 2, 3, 4, 5, 6])
            ->and($rows[0][1])->toBe('Athlete 1');
    });

    /**
     * The confirmed weigh-in list leaves the column blank on purpose — it is
     * the sheet the numbers are written onto. This is the other half.
     */
    it('is the answer sheet, where the weigh-in list is the blank one', function () {
        $category = weighedClass(4);

        $weighIn = (new WeighInFormReport($category))->rows();
        $numbers = (new DrawNumbersReport($category))->rows();

        expect(array_column($weighIn, 5))->each->toBe('')
            ->and(array_filter(array_column($numbers, 0)))->toHaveCount(4);
    });

    /**
     * The sheet is the numbers and who holds them. How each was arrived at is
     * recorded on the athlete and stays off the printed list.
     */
    it('prints the number and the athlete, and nothing about the method', function () {
        $category = weighedClass(4);
        $category->athletes()->update(['draw_number_source' => 'random']);

        $report = new DrawNumbersReport($category->refresh());

        expect($report->headings())->toBe(['Draw No.', "Athlete's Name", "Athlete's ID (IKA)", 'NOC', 'Country'])
            ->and($report->rows()[0])->toHaveCount(5)
            ->and(collect($report->rows())->flatten()->contains('Random draw'))->toBeFalse();
    });

    it('leaves out anybody who was never drawn', function () {
        $category = weighedClass(5);
        $category->athletes()->orderByDesc('draw_number')->first()->update(['draw_number' => null]);

        expect((new DrawNumbersReport($category->refresh()))->rows())->toHaveCount(4);
    });

    it('downloads in both formats', function () {
        $category = weighedClass(4);

        $this->get(route('exports.draw-numbers', ['weightCategory' => $category, 'format' => 'pdf']))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->get(route('exports.draw-numbers', ['weightCategory' => $category, 'format' => 'csv']))
            ->assertOk();
    });

    it('reports the bracket the draw was actually built for', function () {
        $category = weighedClass(6);
        app(BracketGenerator::class)->generate($category);

        expect((new DrawNumbersReport($category->refresh()))->meta()['Bracket'])->toBe('Bracket of 8');
    });
});

describe('the bracket sheet', function () {
    beforeEach(fn () => $this->actingAs($this->admin));

    it('seats the tree in the order the draw seeded it', function () {
        $category = weighedClass(8);
        app(BracketGenerator::class)->generate($category);

        $sheet = new BracketSheet($category->refresh());

        expect($sheet->size())->toBe(8)
            ->and($sheet->rounds())->toBe(3)
            ->and(array_column($sheet->seats(), 'seed'))->toBe(BracketSeeding::order(8))
            // The upper seat of every pair is blue, the lower green.
            ->and(array_column($sheet->seats(), 'corner'))->toBe(['blue', 'green', 'blue', 'green', 'blue', 'green', 'blue', 'green']);
    });

    it('puts a square on every match, with its fight number', function () {
        $championship = championshipWithBrackets(['-66' => 8]);
        app(FightOrderScheduler::class)->schedule($championship);

        $category = $championship->ageCategories()->first()->weightCategories()->first();
        $sheet = new BracketSheet($category->refresh());

        expect($sheet->matches(1))->toHaveCount(4)
            ->and($sheet->matches(2))->toHaveCount(2)
            ->and($sheet->matches(3))->toHaveCount(1)
            // Each spans the rows it feeds from: 2, 4, 8.
            ->and(array_column($sheet->matches(2), 'span'))->toBe([4, 4])
            ->and($sheet->matches(1)[0]['fight'])->toStartWith('No. ');
    });

    it('marks the empty seats as byes rather than inventing people', function () {
        $category = weighedClass(5);
        app(BracketGenerator::class)->generate($category);

        $seats = (new BracketSheet($category->refresh()))->seats();

        expect(collect($seats)->where('bye', true))->toHaveCount(3)
            ->and(collect($seats)->firstWhere('bye', true)['name'])->toBe('BYE');
    });

    it('downloads as a PDF and as a spreadsheet', function () {
        $category = weighedClass(8);
        app(BracketGenerator::class)->generate($category);

        $this->get(route('exports.bracket-sheet', ['weightCategory' => $category, 'format' => 'pdf']))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->get(route('exports.bracket-sheet', ['weightCategory' => $category, 'format' => 'xlsx']))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    });

    it('has nothing to draw before the draw is made', function () {
        $category = weighedClass(8);

        $this->get(route('exports.bracket-sheet', ['weightCategory' => $category, 'format' => 'pdf']))
            ->assertNotFound();
    });

    /** The fight number goes in the square, which on a worksheet is a merged range. */
    it('writes each fight number into the cell that spans its match', function () {
        $championship = championshipWithBrackets(['-66' => 8]);
        app(FightOrderScheduler::class)->schedule($championship);

        $category = $championship->ageCategories()->first()->weightCategories()->first();

        $path = tempnam(sys_get_temp_dir(), 'bracket').'.xlsx';
        $response = app(BracketSheetWriter::class)->xlsx(new BracketSheet($category->refresh()));

        ob_start();
        $response->sendContent();
        file_put_contents($path, ob_get_clean());

        $book = IOFactory::load($path);
        $page = $book->getActiveSheet();

        // Four first-round matches, two quarters, one final, plus the champion.
        expect($page->getMergeCells())->toHaveCount(8);

        $numbers = collect($page->getMergeCells())
            ->map(fn (string $range) => (string) $page->getCell(explode(':', $range)[0])->getValue())
            ->filter(fn (string $value) => str_starts_with($value, 'No. '));

        expect($numbers)->toHaveCount(7);

        unlink($path);
    });
});
