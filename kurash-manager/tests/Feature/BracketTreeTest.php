<?php

/**
 * The shape of the tree.
 *
 * A bracket is two things at once: a set of contests, which BracketGenerator
 * and BracketSeeding own, and a drawing, which is what these are about. The
 * drawing has to hold at every size the federation runs and at every state a
 * class can be in — undrawn seats, byes, half a round decided — because a
 * sheet whose lines stop meeting is a sheet nobody trusts.
 *
 * Everything here is asserted against the geometry rather than against pixels:
 * a connector is right when the round to its left ends on the same row the
 * round to its right begins on, and that is arithmetic.
 */

use App\Exports\BracketSheet;
use App\Exports\BracketSheetWriter;
use App\Livewire\Competition\Bracket;
use App\Models\Bout;
use App\Models\User;
use App\Services\BoutAdvancer;
use App\Services\BracketGenerator;
use App\Services\FightOrderScheduler;
use App\Support\Noc;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->actingAs($this->admin);
});

/** A drawn class of `$count` athletes, ready to be looked at. */
function drawnSheet(int $count, bool $fightNumbers = true): BracketSheet
{
    [$category] = categoryWithAthletes($count, "-{$count}");
    app(BracketGenerator::class)->generate($category);

    return new BracketSheet($category->refresh(), $fightNumbers);
}

/** Play a whole bracket out, the lower draw number always winning. */
function decideEveryBout(mixed $category): void
{
    $advancer = app(BoutAdvancer::class);

    while (true) {
        $bout = $category->bouts()
            ->whereNull('winner_athlete_id')
            ->where('is_bye', false)
            ->whereNotNull('athlete_a_id')
            ->whereNotNull('athlete_b_id')
            ->orderBy('round')
            ->orderBy('position_in_round')
            ->first();

        if (! $bout instanceof Bout) {
            return;
        }

        $winner = ($bout->athleteA->draw_number ?? PHP_INT_MAX) < ($bout->athleteB->draw_number ?? PHP_INT_MAX)
            ? $bout->athleteA
            : $bout->athleteB;

        $advancer->recordResult(
            bout: $bout,
            winnerAthleteId: $winner->id,
            winType: 'khalol',
            user: User::first(),
            source: 'operator',
        );
    }
}

describe('the tree is built from the bracket rather than from a case per size', function () {
    it('halves the branches every round, whatever the field', function (int $athletes, int $size, int $rounds) {
        $sheet = drawnSheet($athletes);
        $branches = collect($sheet->branches());

        expect($sheet->size())->toBe($size)
            ->and($sheet->rounds())->toBe($rounds)
            ->and($sheet->halfRows())->toBe($size * 2);

        // Sixteen, eight, four, two, one: two nodes into one, all the way down.
        $expected = [];

        for ($round = 1; $round <= $rounds; $round++) {
            $expected[$round] = intdiv($size, 2 ** $round);
        }

        expect($branches->groupBy('round')->map->count()->all())->toBe($expected);
    })->with([
        'two' => [2, 2, 1],
        'four' => [4, 4, 2],
        'eight' => [8, 8, 3],
        'sixteen' => [16, 16, 4],
        'thirty-two' => [32, 32, 5],
        'sixty-four' => [64, 64, 6],
    ]);

    it('doubles the height of a branch every round', function () {
        $spans = collect(drawnSheet(32)->branches())
            ->groupBy('round')
            ->map(fn ($set) => $set->pluck('span')->unique()->values()->all())
            ->all();

        expect($spans)->toBe([1 => [2], 2 => [4], 3 => [8], 4 => [16], 5 => [32]]);
    });

    /**
     * The invariant every drawn line rests on. A table cannot leave a hole:
     * the rows a branch does not cover still need a cell, or every column
     * below the hole slides up by one.
     */
    it('accounts for every row of every column, exactly once', function (int $athletes) {
        $sheet = drawnSheet($athletes);

        for ($round = 1; $round <= $sheet->rounds(); $round++) {
            $at = 0;

            foreach ($sheet->column($round) as $cell) {
                expect($cell['row'])->toBe($at, "round {$round} leaves a hole at row {$at}");
                $at += $cell['span'];
            }

            expect($at)->toBe($sheet->halfRows(), "round {$round} does not reach the foot of the sheet");
        }
    })->with([2, 4, 8, 16, 32]);

    /**
     * What makes a connector continuous rather than two segments that happen
     * to meet: the line a branch leaves by is the *same* line the next round
     * arrives on, because it is that cell's own border.
     */
    it('leaves each branch on the line the next round arrives on', function () {
        $sheet = drawnSheet(16);
        $branches = collect($sheet->branches());

        for ($round = 2; $round <= $sheet->rounds(); $round++) {
            foreach ($branches->where('round', $round) as $branch) {
                $feeders = $branches->where('round', $round - 1)
                    ->whereIn('position', [$branch['position'] * 2, $branch['position'] * 2 + 1])
                    ->values();

                expect($feeders)->toHaveCount(2);

                // The upper feeder leaves on this branch's top edge, the lower
                // on its bottom edge.
                expect($feeders[0]['centre'])->toBe($branch['row'])
                    ->and($feeders[1]['centre'])->toBe($branch['row'] + $branch['span']);
            }
        }
    });

    /** Round one hangs off the seats the same way, half a seat down. */
    it('leaves each seat on the line the opening round arrives on', function () {
        $sheet = drawnSheet(8);

        foreach (collect($sheet->branches())->where('round', 1) as $branch) {
            // Seat i covers half-rows 2i and 2i+1, so its centre is 2i + 1.
            $upper = $branch['position'] * 2;
            $lower = $upper + 1;

            expect($branch['row'])->toBe($upper * 2 + 1)
                ->and($branch['row'] + $branch['span'])->toBe($lower * 2 + 1);
        }
    });

    /** The tree does not stop at the final. */
    it('carries the final on to a champion of its own', function (int $athletes) {
        $sheet = drawnSheet($athletes);

        $final = collect($sheet->branches())->firstWhere('round', $sheet->rounds());

        expect($final)->not->toBeNull()
            ->and($final['final'])->toBeTrue()
            ->and($sheet->championRow())->toBe($final['centre'])
            // Dead centre of the sheet, which is where a final's centre is.
            ->and($sheet->championRow())->toBe(intdiv($sheet->halfRows(), 2));
    })->with([2, 4, 8, 16, 32]);

    /** Byes are seats like any other: the drawing does not change shape. */
    it('keeps its shape when the field does not fill the bracket', function () {
        $full = drawnSheet(8);

        [$short] = categoryWithAthletes(5, '-short');
        app(BracketGenerator::class)->generate($short);
        $partial = new BracketSheet($short->refresh());

        expect($partial->size())->toBe(8)
            ->and($partial->halfRows())->toBe($full->halfRows())
            ->and($partial->championRow())->toBe($full->championRow())
            ->and(array_map(
                fn (array $b) => [$b['round'], $b['row'], $b['span']],
                $partial->branches()
            ))->toBe(array_map(
                fn (array $b) => [$b['round'], $b['row'], $b['span']],
                $full->branches()
            ));

        expect(collect($partial->seats())->where('bye', true))->toHaveCount(3);
    });

    /**
     * A class nobody has been drawn into has no tree — not an empty one. The
     * shape comes from the field, and there is no field.
     */
    it('draws nothing at all for a class with nobody in it', function () {
        [$category] = categoryWithAthletes(0, '-empty');

        $sheet = new BracketSheet($category->refresh());

        expect($sheet->size())->toBe(0)
            ->and($sheet->branches())->toBe([])
            ->and($sheet->column(1))->toBe([]);
    });

    /**
     * The shape is the field's, not the bracket's: a class with draw numbers
     * and no generated bouts already knows what it will look like, which is
     * what lets the sheet be drawn before a single contest exists.
     */
    it('knows its shape before the bracket is generated', function () {
        [$category] = categoryWithAthletes(8, '-ungenerated');

        $sheet = new BracketSheet($category->refresh());

        expect($sheet->size())->toBe(8)
            ->and($sheet->branches())->toHaveCount(7)
            // Nothing has happened in it yet, so nothing is written on it.
            ->and(collect($sheet->branches())->pluck('winner')->filter())->toBeEmpty();
    });
});

describe('what the tree says happened', function () {
    it('writes the winner on the line they went through on', function () {
        [$category] = categoryWithAthletes(8, '-played');
        app(BracketGenerator::class)->generate($category);
        decideEveryBout($category->refresh());

        $branches = collect((new BracketSheet($category->refresh()))->branches());

        expect($branches->pluck('winner')->filter())->toHaveCount(7);

        // The final's winner is the champion, and both say the same name.
        $final = $branches->firstWhere('final', true);

        expect($final['winner'])->toBe((new BracketSheet($category->refresh()))->champion())
            ->and($final['winner'])->toBe('Athlete 1');
    });

    /** A walkover is advancement too, and shows as one. */
    it('advances a bye without anybody recording it', function () {
        [$category] = categoryWithAthletes(5, '-walkover');
        app(BracketGenerator::class)->generate($category);

        $opening = collect((new BracketSheet($category->refresh()))->branches())->where('round', 1);

        // Three byes, each already carrying whoever walked through it.
        expect($opening->pluck('winner')->filter())->toHaveCount(3);
    });

    it('leaves the lines clear when nothing has been decided', function () {
        expect(collect(drawnSheet(8)->branches())->pluck('winner')->filter())->toBeEmpty();
    });

    it('numbers a branch only when the running order has numbered it', function () {
        $numbered = collect(drawnSheet(8)->branches())->pluck('fight')->filter();

        // Nothing scheduled yet, so nothing to print.
        expect($numbered)->toBeEmpty();
    });
});

describe('the printed sheet', function () {
    /**
     * The paper grows with the tree rather than the tree shrinking to fit A4.
     * Landscape short side, in points.
     */
    it('chooses its paper from the size of the field', function (int $size, string $paper) {
        expect(app(BracketSheetWriter::class)->paper($size)[0])->toBe($paper);
    })->with([
        [2, 'a4'],
        [8, 'a4'],
        [16, 'a3'],
        [32, 'a3'],
        [64, 'a2'],
        [128, 'a1'],
    ]);

    /**
     * A bracket split across a fold is two half-trees, and the connectors
     * between them are the ones that matter.
     */
    it('prints on exactly one page, at every size', function (int $athletes, float $width) {
        $pdf = app(BracketSheetWriter::class)->pdf(drawnSheet($athletes))->getContent();

        expect(preg_match_all('#/Type\s*/Page[^s]#', (string) $pdf))->toBe(1);

        preg_match('#/MediaBox\s*\[([^\]]*)\]#', (string) $pdf, $box);

        expect(round((float) explode(' ', trim($box[1]))[2]))->toBe($width);
    })->with([
        'four on A4' => [4, 842.0],
        'eight on A4' => [8, 842.0],
        'sixteen on A3' => [16, 1191.0],
        'thirty-two on A3' => [32, 1191.0],
    ]);

    /** Rows sized to what the page leaves, so the tree fills it and no more. */
    it('sizes its rows to the page it chose', function () {
        $writer = app(BracketSheetWriter::class);

        $small = $writer->scale(drawnSheet(4));
        $large = $writer->scale(drawnSheet(32));

        expect($small['halfRow'])->toBeGreaterThan($large['halfRow'])
            // Floored, so a bracket of thirty-two is still readable.
            ->and($large['halfRow'])->toBeGreaterThanOrEqual(6.0)
            ->and($large['name'])->toBeGreaterThanOrEqual(6.0);
    });

    it('gives every round a column of its own, and the champion one too', function () {
        $sheet = drawnSheet(8);
        $html = view('exports.bracket', [
            'sheet' => $sheet,
            'scale' => app(BracketSheetWriter::class)->scale($sheet),
        ])->render();

        expect(substr_count($html, 'class="branch'))->toBe(7)
            ->and($html)->toContain('td.champion')
            ->and($html)->toContain('>Champion<')
            // Two rows per seat: the tree is ruled in half-seats.
            ->and(substr_count($html, 'class="seat-under"'))->toBe(8);
    });

    /**
     * The rule that made every connector in this sheet disappear: `.tree td`
     * outranks a bare `.branch`, so the borders were set and then unset.
     */
    it('states its borders where they outrank the blanket rule', function () {
        $sheet = drawnSheet(8);
        $html = view('exports.bracket', [
            'sheet' => $sheet,
            'scale' => app(BracketSheetWriter::class)->scale($sheet),
        ])->render();

        expect($html)->toContain('.tree td.branch')
            ->toContain('.tree td.champion')
            ->toContain('.tree td.seat');
    });
});

describe('the worksheet', function () {
    /** @return array{0:Worksheet, 1:string} */
    function workbookFor(BracketSheet $sheet): array
    {
        $path = tempnam(sys_get_temp_dir(), 'bracket').'.xlsx';

        ob_start();
        app(BracketSheetWriter::class)->xlsx($sheet)->sendContent();
        file_put_contents($path, ob_get_clean());

        return [IOFactory::load($path)->getActiveSheet(), $path];
    }

    it('merges a cell for every seat, every branch and the champion', function () {
        [$page, $path] = workbookFor(drawnSheet(8));

        // Eight seats, twice each; seven branches; one champion.
        expect($page->getMergeCells())->toHaveCount(8 * 2 + 7 + 1);

        unlink($path);
    });

    /** Three sides carry a line, and the fourth is where the tree came from. */
    it('borders every branch top, right and bottom', function () {
        $sheet = drawnSheet(8);
        [$page, $path] = workbookFor($sheet);

        foreach ($sheet->branches() as $branch) {
            // Column A is the seed and B the name, so round one is column C.
            $column = chr(ord('C') + $branch['round'] - 1);

            // A range takes its borders on the edge each one belongs to: the
            // top lands on the first row of the merge and the bottom on the
            // last, which is the outline of the branch and not a box drawn
            // around every cell inside it.
            $top = $column.(6 + $branch['row']);
            $foot = $column.(6 + $branch['row'] + $branch['span'] - 1);

            expect($page->getStyle($top)->getBorders()->getTop()->getBorderStyle())
                ->toBe(Border::BORDER_THIN, "{$top} has no top")
                ->and($page->getStyle($foot)->getBorders()->getBottom()->getBorderStyle())
                ->toBe(Border::BORDER_THIN, "{$foot} has no bottom")
                ->and($page->getStyle($top)->getBorders()->getRight()->getBorderStyle())
                ->toBe(Border::BORDER_THIN, "{$top} has no right")
                ->and($page->getStyle($foot)->getBorders()->getRight()->getBorderStyle())
                ->toBe(Border::BORDER_THIN, "{$foot} has no right");
        }

        unlink($path);
    });

    it('rules a line under the champion, where the final leaves off', function () {
        $sheet = drawnSheet(8);
        [$page, $path] = workbookFor($sheet);

        // One column past the last round.
        $column = chr(ord('C') + $sheet->rounds());
        $range = $column.'6:'.$column.(6 + $sheet->championRow() - 1);

        expect($page->getMergeCells())->toContain($range)
            // On the foot of the range, which is the final's own centre line.
            ->and($page->getStyle($column.(6 + $sheet->championRow() - 1))
                ->getBorders()->getBottom()->getBorderStyle())
            ->toBe(Border::BORDER_THIN);

        unlink($path);
    });

    it('keeps the seed, the name, the bye and the fight number', function () {
        $championship = championshipWithBrackets(['-66' => 8]);
        app(FightOrderScheduler::class)->schedule($championship);

        $category = $championship->ageCategories()->first()->weightCategories()->first();
        [$page, $path] = workbookFor(new BracketSheet($category->refresh()));

        $numbers = collect($page->getMergeCells())
            ->map(fn (string $range) => (string) $page->getCell(explode(':', $range)[0])->getValue())
            ->filter(fn (string $value) => str_starts_with($value, 'No. '));

        expect($numbers)->toHaveCount(7)
            ->and((string) $page->getCell('A6')->getValue())->not->toBe('')
            ->and((string) $page->getCell('B6')->getValue())->not->toBe('');

        unlink($path);
    });
});

describe('the bracket on screen', function () {
    it('ends at a champion rather than at the final', function () {
        [$category] = categoryWithAthletes(8, '-screen');
        app(BracketGenerator::class)->generate($category);
        decideEveryBout($category->refresh());

        Livewire::test(Bracket::class, ['weightCategory' => $category->refresh()])
            ->assertSee('Champion')
            ->assertSee('bkt__round--champion', false)
            ->assertSee('Athlete 1');
    });

    it('says the winner is undecided while the final is unplayed', function () {
        [$category] = categoryWithAthletes(4, '-open');
        app(BracketGenerator::class)->generate($category);

        Livewire::test(Bracket::class, ['weightCategory' => $category->refresh()])
            ->assertSee('bkt__round--champion', false)
            ->assertSee('To be decided');
    });

    /**
     * A round with one slot in it — the final — has nothing below to reach,
     * and a vertical hung off the bottom of it points at nothing.
     */
    it('hangs a vertical only where there is a pair to join', function () {
        [$category] = categoryWithAthletes(8, '-vertical');
        app(BracketGenerator::class)->generate($category);

        Livewire::test(Bracket::class, ['weightCategory' => $category->refresh()])
            ->assertSee('.bkt__slot:nth-child(odd):not(:last-child)::before', false);
    });

    /** Every round keeps its width, and the container scrolls rather than cuts. */
    it('makes room for every round and the champion', function (int $athletes, int $rounds) {
        [$category] = categoryWithAthletes($athletes, "-w{$athletes}");
        app(BracketGenerator::class)->generate($category);

        Livewire::test(Bracket::class, ['weightCategory' => $category->refresh()])
            ->assertSee('overflow-x-auto', false)
            // Rounds plus the champion, at the column width the stylesheet sets.
            ->assertSee('min-width: '.(($rounds + 1) * 17).'rem', false);
    })->with([
        'two' => [2, 1],
        'eight' => [8, 3],
        'thirty-two' => [32, 5],
    ]);

    /** Recording a result and sending to a mat are untouched by the drawing. */
    it('still records a result and advances the winner', function () {
        [$category] = categoryWithAthletes(4, '-record');
        app(BracketGenerator::class)->generate($category);

        $bout = $category->bouts()->where('round', 1)->orderBy('position_in_round')->firstOrFail();

        Livewire::test(Bracket::class, ['weightCategory' => $category->refresh()])
            ->call('recordResult', $bout->id, 'a')
            ->assertHasNoErrors();

        expect($bout->refresh()->winner_athlete_id)->toBe($bout->athlete_a_id);

        // And the next round is holding them.
        expect($category->bouts()->where('round', 2)->first()->athlete_a_id)
            ->toBe($bout->athlete_a_id);
    });
});

/**
 * The nation beside the name, wherever the bracket is shown.
 *
 * One code table and one set of artwork behind all four: Noc resolves the
 * code, the screens fly the vectors and paper flies the rasters. A seat with
 * nobody in it has no nation, and a code the table does not know has no flag —
 * neither leaves a broken image behind.
 */
describe('the nation on a bracket', function () {
    beforeEach(function () {
        [$this->category] = categoryWithAthletes(4, '-flags');

        $nations = ['UZB', 'KAZ', 'ZZZ', 'JPN'];

        foreach ($this->category->athletes()->orderBy('draw_number')->get() as $index => $athlete) {
            $athlete->update(['noc_code' => $nations[$index]]);
        }

        app(BracketGenerator::class)->generate($this->category->refresh());
        $this->category->refresh();
    });

    it('flies a vector beside every athlete on the management bracket', function () {
        $html = Livewire::test(Bracket::class, ['weightCategory' => $this->category])->html();

        expect($html)->toContain('flags/uz.svg')
            ->toContain('flags/kz.svg')
            ->toContain('flags/jp.svg');
    });

    it('flies a vector beside every athlete on the venue bracket', function () {
        $html = $this->get(route('display.bracket', $this->category))->getContent();

        expect($html)->toContain('flags/uz.svg')
            ->toContain('flags/kz.svg')
            ->toContain('flags/jp.svg');
    });

    /** Paper cannot draw the vectors — see App\Support\PrintFlag. */
    it('flies a raster beside every athlete on the printed bracket', function () {
        $sheet = new BracketSheet($this->category);
        $html = view('exports.bracket', [
            'sheet' => $sheet,
            'scale' => app(BracketSheetWriter::class)->scale($sheet),
        ])->render();

        expect($html)->toContain('flags/print/uz.png')
            ->toContain('flags/print/kz.png')
            ->toContain('flags/print/jp.png')
            // And never the vectors, which Dompdf draws across the page.
            ->and($html)->not->toContain('flags/uz.svg');
    });

    it('flies nothing for a code the table does not know', function () {
        $sheet = new BracketSheet($this->category);
        $html = view('exports.bracket', [
            'sheet' => $sheet,
            'scale' => app(BracketSheetWriter::class)->scale($sheet),
        ])->render();

        // The code is printed; there is simply no artwork behind it.
        expect($html)->toContain('ZZZ')
            ->and($html)->not->toContain('flags/print/zz.png');
    });

    it('flies nothing on a bye or an empty seat', function () {
        [$short] = categoryWithAthletes(5, '-byeflags');
        app(BracketGenerator::class)->generate($short);

        $sheet = new BracketSheet($short->refresh());
        $html = view('exports.bracket', [
            'sheet' => $sheet,
            'scale' => app(BracketSheetWriter::class)->scale($sheet),
        ])->render();

        // Three byes, and every flag on the sheet belongs to somebody real.
        expect(substr_count($html, 'BYE'))->toBe(3)
            ->and(substr_count($html, 'class="seat-flag"'))->toBeLessThanOrEqual(5 + 7);
    });

    /**
     * Whole, never cut.
     *
     * The name used to be set to truncate, which in a bracket column narrow
     * enough turned "Cholpon Toktogulov" into "Cholpon Toktog…". A shortened
     * name is not the athlete's name: the column is the thing that gives way,
     * and it gives way by wrapping.
     */
    it('shows a long name in full rather than cutting it', function () {
        $athlete = $this->category->athletes()->orderBy('draw_number')->firstOrFail();
        $athlete->update(['fullname' => 'Cholpon Toktogulov Abdyrakhmanova']);

        $html = Livewire::test(Bracket::class, ['weightCategory' => $this->category->refresh()])->html();

        expect($html)->toContain('Cholpon Toktogulov Abdyrakhmanova');

        // And the rule that would have cut it is gone from the component.
        expect(file_get_contents(resource_path('views/components/athlete.blade.php')))
            ->not->toContain('truncate');
    });

    it('shows a long name in full on the venue bracket too', function () {
        $athlete = $this->category->athletes()->orderBy('draw_number')->firstOrFail();
        $athlete->update(['fullname' => 'Cholpon Toktogulov Abdyrakhmanova']);

        $html = $this->get(route('display.bracket', $this->category->refresh()))->getContent();

        expect($html)->toContain('Cholpon Toktogulov Abdyrakhmanova')
            ->and($html)->not->toContain('text-overflow: ellipsis');
    });

    /** Whoever went through, with their nation, on the line they went through on. */
    it('flies a raster beside the winner on the branch they won', function () {
        decideEveryBout($this->category);

        $sheet = new BracketSheet($this->category->refresh());
        $branch = collect($sheet->branches())->firstWhere('final', true);

        expect($branch['winner'])->not->toBe('')
            ->and($branch['winnerNoc'])->toBe('UZB');

        $html = view('exports.bracket', [
            'sheet' => $sheet,
            'scale' => app(BracketSheetWriter::class)->scale($sheet),
        ])->render();

        expect($html)->toContain('alt="UZB"');
    });

    /**
     * The worksheet flies none: OOXML floats a picture over the grid rather
     * than putting it in a cell, and every reader decides for itself how tall
     * a row is. The code goes beside the name instead — which is the thing a
     * spreadsheet can sort on anyway. See BracketSheetWriter.
     */
    it('keeps the code beside the name on the worksheet, and no picture', function () {
        [$page, $path] = workbookFor(new BracketSheet($this->category));

        expect((string) $page->getCell('B6')->getValue())->toContain('UZB');

        // One picture on the sheet, and it is the federation's mark rather
        // than a nation's. Four flags would be four more.
        $drawings = $page->getDrawingCollection();

        expect($drawings)->toHaveCount(1)
            ->and(collect($drawings)->pluck('name')->filter(
                fn (?string $name) => Noc::exists((string) $name)
            ))->toBeEmpty();

        unlink($path);
    });
});

/**
 * The number the running order gave a contest, on the bracket that contest is
 * read from. A fact about the schedule, not an action anybody takes.
 */
describe('fight numbers on a bracket', function () {
    beforeEach(function () {
        $this->championship = championshipWithBrackets(['-66' => 8]);
        app(FightOrderScheduler::class)->schedule($this->championship);

        $this->numbered = $this->championship->ageCategories()->first()
            ->weightCategories()->first()->refresh();
    });

    it('offers the saved number in the box on every numbered match', function () {
        $html = Livewire::test(Bracket::class, ['weightCategory' => $this->numbered])->html();

        foreach ($this->numbered->bouts()->whereNotNull('fight_number')->get() as $bout) {
            expect($html)->toContain('value="'.$bout->fight_number.'"')
                ->and($html)->toContain('wire:model="fightNumbers.'.$bout->id.'"');
        }
    });

    it('prints it on the venue bracket too', function () {
        $html = $this->get(route('display.bracket', $this->numbered))->getContent();

        foreach (range(1, 7) as $number) {
            expect($html)->toContain("No. {$number}");
        }
    });

    /** A schedule nobody has made yet has an empty box, not a number in it. */
    it('shows an empty box where the running order has not reached', function () {
        [$unscheduled] = categoryWithAthletes(4, '-unscheduled');
        app(BracketGenerator::class)->generate($unscheduled);

        $board = Livewire::test(Bracket::class, ['weightCategory' => $unscheduled->refresh()]);

        expect(collect($board->get('fightNumbers'))->filter())->toBeEmpty()
            ->and($board->html())->toContain('wire:model="fightNumbers.');
    });

    /** A walkover is not a contest, so it is not numbered and has no box. */
    it('gives a bye no box to type in', function () {
        [$byes] = categoryWithAthletes(5, '-byenumbers');
        app(BracketGenerator::class)->generate($byes);
        app(FightOrderScheduler::class)->schedule($byes->ageCategory->championship);

        $byeBout = $byes->bouts()->where('is_bye', true)->firstOrFail();

        expect($byeBout->fight_number)->toBeNull();

        $html = Livewire::test(Bracket::class, ['weightCategory' => $byes->refresh()])->html();

        expect($html)->not->toContain('wire:model="fightNumbers.'.$byeBout->id.'"')
            // And every real contest does have one.
            ->and(substr_count($html, 'wire:model="fightNumbers.'))
            ->toBe($byes->bouts()->where('is_bye', false)->count());
    });

    /**
     * Independent of what the reader may do: an official who cannot record a
     * result still needs to know which contest they are looking at.
     */
    it('shows the number to somebody who may not record a result', function () {
        $official = User::factory()->official()->create();

        $html = Livewire::actingAs($official)
            ->test(Bracket::class, ['weightCategory' => $this->numbered])
            ->html();

        // Read as text, with nothing to type into and nothing to press.
        expect($html)->toContain('No. 1')
            ->and($html)->not->toContain('wire:model="fightNumbers.')
            ->and($html)->not->toContain('>Win<');
    });
});

/**
 * One tree, two screens. The nodes differ — one has buttons on it — but the
 * geometry does not, because two connector implementations are two things to
 * keep in step and one of them always drifts.
 */
describe('the venue bracket', function () {
    beforeEach(function () {
        [$this->category] = categoryWithAthletes(8, '-venue');
        app(BracketGenerator::class)->generate($this->category);
        $this->category->refresh();
    });

    it('is drawn by the same geometry the management bracket uses', function () {
        $venue = $this->get(route('display.bracket', $this->category))->getContent();
        $management = Livewire::test(Bracket::class, ['weightCategory' => $this->category])->html();

        // The rule that carries every vertical in the tree, in both.
        $rule = '.bkt__slot:nth-child(odd):not(:last-child)::before';

        expect($venue)->toContain($rule)
            ->and($management)->toContain($rule);
    });

    it('ends at a champion the final connects to', function () {
        $html = $this->get(route('display.bracket', $this->category))->getContent();

        expect($html)->toContain('bkt__round--champion')
            ->toContain('To be decided')
            // The champion column is last, so the final gets its gutter and the
            // connector into the champion is drawn like every other.
            ->toContain('bkt__round--last');
    });

    it('names the champion once the final is decided', function () {
        decideEveryBout($this->category);

        $html = $this->get(route('display.bracket', $this->category->refresh()))->getContent();

        expect($html)->toContain('bkt__match--champion')
            ->and($html)->not->toContain('To be decided');
    });

    it('keeps its shape through byes and empty seats', function () {
        [$short] = categoryWithAthletes(5, '-venuebyes');
        app(BracketGenerator::class)->generate($short);

        $html = $this->get(route('display.bracket', $short->refresh()))->getContent();

        // Four opening slots, two semis, a final and a champion.
        expect(substr_count($html, 'class="bkt__slot"'))->toBe(8)
            ->and($html)->toContain('Bye');
    });

    it('scrolls rather than cutting a round off', function (int $athletes, int $rounds) {
        [$category] = categoryWithAthletes($athletes, "-v{$athletes}");
        app(BracketGenerator::class)->generate($category);

        $html = $this->get(route('display.bracket', $category->refresh()))->getContent();

        expect($html)->toContain('class="scroll"')
            ->toContain('min-width: '.(($rounds + 1) * 18).'rem');
    })->with([
        'two' => [2, 1],
        'eight' => [8, 3],
        'thirty-two' => [32, 5],
    ]);

    /** The screens keep themselves up to date without anybody touching them. */
    it('still refreshes on its own', function () {
        $html = $this->get(route('display.bracket', $this->category))->getContent();

        expect($html)->toContain('http-equiv="refresh"')
            ->toContain('refreshes automatically');
    });

    it('says so plainly when there is no draw to show', function () {
        [$undrawn] = categoryWithAthletes(4, '-nodraw');

        $this->get(route('display.bracket', $undrawn))
            ->assertOk()
            ->assertSee('has not been drawn yet');
    });
});

/**
 * matches() was public before the tree was ruled in half-seats. It answers the
 * way it used to, from the geometry that replaced it, so the two cannot drift.
 */
describe('the older way of asking for the tree', function () {
    it('answers one round, in whole seats', function () {
        $sheet = drawnSheet(8);

        expect($sheet->matches(1))->toHaveCount(4)
            ->and($sheet->matches(2))->toHaveCount(2)
            ->and($sheet->matches(3))->toHaveCount(1)
            ->and($sheet->matches(4))->toBe([]);
    });

    it('keeps the rows and spans a caller was written against', function () {
        $sheet = drawnSheet(8);

        expect(array_column($sheet->matches(1), 'row'))->toBe([0, 2, 4, 6])
            ->and(array_column($sheet->matches(1), 'span'))->toBe([2, 2, 2, 2])
            ->and(array_column($sheet->matches(2), 'row'))->toBe([0, 4])
            ->and(array_column($sheet->matches(2), 'span'))->toBe([4, 4])
            ->and(array_column($sheet->matches(3), 'row'))->toBe([0])
            ->and(array_column($sheet->matches(3), 'span'))->toBe([8]);
    });

    it('carries the same fight numbers the tree does', function () {
        $championship = championshipWithBrackets(['-66' => 8]);
        app(FightOrderScheduler::class)->schedule($championship);

        $sheet = new BracketSheet(
            $championship->ageCategories()->first()->weightCategories()->first()->refresh()
        );

        expect(array_column($sheet->matches(1), 'fight'))
            ->toBe(collect($sheet->branches())->where('round', 1)->pluck('fight')->all());
    });

    it('holds its shape and nothing more', function () {
        foreach (drawnSheet(4)->matches(1) as $match) {
            expect(array_keys($match))->toBe(['row', 'span', 'fight']);
        }
    });
});
