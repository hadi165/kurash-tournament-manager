<?php

/**
 * The register, and the running order written onto it by hand.
 *
 * Two rules hold everything here together. The saved draw_number and the
 * generated bouts are the only source of truth — opening a list, a bracket or
 * an export reads them and never writes. And a number is either drawn or
 * typed: nothing on a screen numbers a contest by itself.
 */

use App\Exports\BracketSheet;
use App\Exports\DrawNumbersReport;
use App\Livewire\Competition\Bracket;
use App\Livewire\Competition\Entries;
use App\Models\Athlete;
use App\Models\Bout;
use App\Models\BoutEvent;
use App\Models\User;
use App\Models\WeightCategory;
use App\Services\BoutAdvancer;
use App\Services\BracketGenerator;
use App\Services\FightOrderScheduler;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->actingAs($this->admin);
});

/** The whole tournament state, as a fingerprint nothing read-only may change. */
function tournamentState(mixed $category): array
{
    return [
        'draws' => $category->athletes()->orderBy('id')->pluck('draw_number', 'id')->all(),
        'bouts' => $category->bouts()->orderBy('id')
            ->get(['id', 'athlete_a_id', 'athlete_b_id', 'winner_athlete_id', 'fight_number', 'next_bout_id', 'next_bout_slot'])
            ->map->only(['athlete_a_id', 'athlete_b_id', 'winner_athlete_id', 'fight_number', 'next_bout_id', 'next_bout_slot'])
            ->all(),
    ];
}

describe('the draw number on the register', function () {
    it('is blank before anybody has been drawn', function () {
        $category = weighedClass(4);
        $category->athletes()->update(['draw_number' => null]);

        $rows = (new DrawNumbersReport($category->refresh()))->rows();

        expect($rows)->toHaveCount(4)
            ->and(array_column($rows, 4))->each->toBe('—');
    });

    it('is the number the draw saved, once it has been saved', function () {
        $category = weighedClass(6);
        app(BracketGenerator::class)->generate($category);

        $saved = $category->athletes()->pluck('draw_number', 'ika_id')->all();
        $rows = (new DrawNumbersReport($category->refresh()))->rows();

        foreach ($rows as $row) {
            expect($row[4])->toBe($saved[$row[0]]);
        }
    });

    /** The list, the bracket and the presentation all read the one number. */
    it('is the same number the bracket seats them on', function () {
        $category = weighedClass(8);
        app(BracketGenerator::class)->generate($category);

        $listed = collect((new DrawNumbersReport($category->refresh()))->rows())
            ->mapWithKeys(fn (array $row) => [$row[1] => $row[4]]);

        foreach ((new BracketSheet($category->refresh()))->seats() as $seat) {
            if ($seat['bye']) {
                continue;
            }

            expect($listed[$seat['name']])->toBe($seat['seed']);
        }
    });

    it('never invents a number of its own', function () {
        $category = weighedClass(5);
        $category->athletes()->update(['draw_number' => null]);

        $before = tournamentState($category->refresh());

        (new DrawNumbersReport($category->refresh()))->rows();
        $this->get(route('exports.draw-numbers', ['weightCategory' => $category, 'format' => 'pdf']))->assertOk();
        $this->get(route('exports.draw-numbers', ['weightCategory' => $category, 'format' => 'xlsx']))->assertOk();

        expect(tournamentState($category->refresh()))->toBe($before);
    });
});

describe('the order a register is read in', function () {
    /** A class whose accreditation order and draw order are deliberately not the same. */
    beforeEach(function () {
        $this->category = weighedClass(4);

        $order = [['IKA004', 1], ['IKA002', 2], ['IKA010', 3], ['IKA001', 4]];

        foreach ($this->category->athletes()->orderBy('id')->get() as $index => $athlete) {
            [$ika, $draw] = $order[$index];
            $athlete->update(['ika_id' => $ika, 'draw_number' => $draw]);
        }

        $this->category->refresh();
    });

    it('is by accreditation number, not by draw number', function () {
        $rows = (new DrawNumbersReport($this->category))->rows();

        expect(array_column($rows, 0))->toBe(['IKA001', 'IKA002', 'IKA004', 'IKA010'])
            // Which puts the draw numbers in no order at all, as it should.
            ->and(array_column($rows, 4))->toBe([4, 2, 1, 3]);
    });

    /** IKA2 before IKA10, which reading them as words would not give. */
    it('reads the digits as a number rather than as a word', function () {
        $athletes = $this->category->athletes()->get();

        $athletes[0]->update(['ika_id' => 'IKA2']);
        $athletes[1]->update(['ika_id' => 'IKA10']);
        $athletes[2]->update(['ika_id' => 'IKA1']);
        $athletes[3]->update(['ika_id' => 'IKA20']);

        $rows = (new DrawNumbersReport($this->category->refresh()))->rows();

        expect(array_column($rows, 0))->toBe(['IKA1', 'IKA2', 'IKA10', 'IKA20']);
    });

    /**
     * A missing or repeated accreditation number cannot reach the database —
     * the column is NOT NULL and unique within a championship — so these are
     * asserted on the comparator, which is where a legacy import or a hand-
     * edited row would meet them.
     */
    it('sends an athlete with no number to the foot of the list', function () {
        $numbered = new Athlete(['ika_id' => 'IKA004', 'fullname' => 'Aidos']);
        $numbered->id = 1;

        $missing = new Athlete(['ika_id' => null, 'fullname' => 'Aidos']);
        $missing->id = 2;

        expect($numbered->entryOrder()[0])->toBe(0)
            ->and($missing->entryOrder()[0])->toBe(1)
            ->and($numbered->entryOrder() < $missing->entryOrder())->toBeTrue();
    });

    it('settles two of the same number by name and then by id', function () {
        $make = function (string $name, int $id) {
            $athlete = new Athlete(['ika_id' => 'IKA007', 'fullname' => $name]);
            $athlete->id = $id;

            return $athlete;
        };

        $first = $make('Aidos', 9);
        $second = $make('Bekzod', 2);
        $twin = $make('Aidos', 11);

        expect($first->entryOrder() < $second->entryOrder())->toBeTrue()
            ->and($first->entryOrder() < $twin->entryOrder())->toBeTrue();
    });

    /** The screen and the sheet sort by one comparator, so they cannot differ. */
    it('is the order the draw-numbers card on the bracket screen uses', function () {
        $onScreen = Livewire::test(Bracket::class, ['weightCategory' => $this->category])
            ->viewData('athletes')
            ->pluck('ika_id')
            ->all();

        expect($onScreen)->toBe(array_column((new DrawNumbersReport($this->category))->rows(), 0));
    });
});

describe('the Bracket option on Entries and Draw', function () {
    beforeEach(function () {
        $this->category = weighedClass(8);
        app(BracketGenerator::class)->generate($this->category);
        $this->championship = $this->category->ageCategory->championship;
    });

    it('opens the saved bracket rather than a draw document', function () {
        Livewire::test(Entries::class, ['championship' => $this->championship])
            ->assertSee('Bracket')
            ->assertSee(route('exports.bracket-sheet', ['weightCategory' => $this->category, 'format' => 'pdf']), false)
            ->assertSee(route('exports.bracket-sheet', ['weightCategory' => $this->category, 'format' => 'xlsx']), false);
    });

    it('names the register by the number on it', function () {
        Livewire::test(Entries::class, ['championship' => $this->championship])
            ->assertSee('Draw No.')
            ->assertSee(route('exports.draw-numbers', ['weightCategory' => $this->category, 'format' => 'pdf']), false);
    });

    /** Nothing was taken away: the draw itself, and the old sheet, both remain. */
    it('leaves drawing and presenting where they were', function () {
        $this->category->forceFill(['draw_published_at' => now()])->save();

        Livewire::test(Entries::class, ['championship' => $this->championship])
            ->assertSee(route('bracket.show', $this->category), false);

        $this->get(route('exports.draw', ['weightCategory' => $this->category, 'format' => 'pdf']))->assertOk();
        $this->get(route('operator.draws.present', $this->category))->assertOk();
    });

    /** The rule the whole workflow rests on. */
    it('changes nothing by being opened or exported', function () {
        app(FightOrderScheduler::class)->schedule($this->championship);

        $before = tournamentState($this->category->refresh());

        Livewire::test(Entries::class, ['championship' => $this->championship])->html();
        Livewire::test(Bracket::class, ['weightCategory' => $this->category->refresh()])->html();

        foreach (['pdf', 'xlsx'] as $format) {
            $this->get(route('exports.bracket-sheet', ['weightCategory' => $this->category, 'format' => $format]))->assertOk();
        }

        $this->get(route('exports.draw-numbers', ['weightCategory' => $this->category, 'format' => 'pdf']))->assertOk();
        $this->get(route('display.bracket', $this->category))->assertOk();

        expect(tournamentState($this->category->refresh()))->toBe($before);
    });
});

describe('numbering a contest by hand', function () {
    beforeEach(function () {
        $this->category = weighedClass(8);
        app(BracketGenerator::class)->generate($this->category);
        $this->category->refresh();

        $this->bout = $this->category->bouts()->where('is_bye', false)
            ->orderBy('round')->orderBy('position_in_round')->firstOrFail();

        $this->board = fn () => Livewire::test(Bracket::class, ['weightCategory' => $this->category->refresh()]);
    });

    it('saves the number somebody types', function () {
        ($this->board)()
            ->set("fightNumbers.{$this->bout->id}", '12')
            ->call('setFightNumber', $this->bout->id);

        expect($this->bout->refresh()->fight_number)->toBe(12);
    });

    it('changes it when somebody types another', function () {
        $this->bout->update(['fight_number' => 4]);

        ($this->board)()
            ->set("fightNumbers.{$this->bout->id}", '9')
            ->call('setFightNumber', $this->bout->id);

        expect($this->bout->refresh()->fight_number)->toBe(9);
    });

    it('clears it when the box is emptied', function () {
        $this->bout->update(['fight_number' => 4]);

        ($this->board)()
            ->set("fightNumbers.{$this->bout->id}", '')
            ->call('setFightNumber', $this->bout->id);

        expect($this->bout->refresh()->fight_number)->toBeNull();
    });

    it('refuses anything that is not a place in a running order', function (string $typed) {
        ($this->board)()
            ->set("fightNumbers.{$this->bout->id}", $typed)
            ->call('setFightNumber', $this->bout->id);

        expect($this->bout->refresh()->fight_number)->toBeNull();
    })->with(['0', '-3', '2.5', 'twelve', '1e3', '70000', ' ']);

    /** Unique across the championship: "bout 14" is called across a session. */
    it('refuses a number another contest already holds', function () {
        $other = $this->category->bouts()->where('is_bye', false)
            ->whereKeyNot($this->bout->id)->firstOrFail();

        $other->update(['fight_number' => 5]);

        ($this->board)()
            ->set("fightNumbers.{$this->bout->id}", '5')
            ->call('setFightNumber', $this->bout->id);

        expect($this->bout->refresh()->fight_number)->toBeNull()
            ->and($other->refresh()->fight_number)->toBe(5);
    });

    it('counts another weight class as the same championship', function () {
        $sibling = WeightCategory::factory()->create([
            'age_category_id' => $this->category->age_category_id,
            'label' => '-73',
            'gender' => 'M',
        ]);

        foreach (range(1, 4) as $draw) {
            Athlete::factory()->drawn($draw)->create([
                'championship_id' => $this->category->ageCategory->championship_id,
                'age_category_id' => $this->category->age_category_id,
                'weight_category_id' => $sibling->id,
                'weighin_status' => 'pass',
            ]);
        }

        app(BracketGenerator::class)->generate($sibling->refresh());

        $sibling->bouts()->where('is_bye', false)->firstOrFail()->update(['fight_number' => 21]);

        ($this->board)()
            ->set("fightNumbers.{$this->bout->id}", '21')
            ->call('setFightNumber', $this->bout->id);

        expect($this->bout->refresh()->fight_number)->toBeNull();
    });

    it('lets a contest keep the number it already has', function () {
        $this->bout->update(['fight_number' => 7]);

        ($this->board)()
            ->set("fightNumbers.{$this->bout->id}", '7')
            ->call('setFightNumber', $this->bout->id);

        expect($this->bout->refresh()->fight_number)->toBe(7);
    });

    it('will not number a bye', function () {
        [$byes] = categoryWithAthletes(5, '-byenum');
        app(BracketGenerator::class)->generate($byes);

        $bye = $byes->bouts()->where('is_bye', true)->firstOrFail();

        Livewire::test(Bracket::class, ['weightCategory' => $byes->refresh()])
            ->set("fightNumbers.{$bye->id}", '3')
            ->call('setFightNumber', $bye->id);

        expect($bye->refresh()->fight_number)->toBeNull();
    });

    /**
     * A later round is numberable before its feeders are decided — that is the
     * rule the automatic scheduler already works to, because the order depends
     * on bracket position and not on who wins. What it is *not* is ready to
     * fight, and the two are different questions.
     */
    it('numbers a later round before its feeders are decided, without calling it ready', function () {
        $final = $this->category->bouts()->orderByDesc('round')->firstOrFail();

        ($this->board)()
            ->set("fightNumbers.{$final->id}", '30')
            ->call('setFightNumber', $final->id);

        expect($final->refresh()->fight_number)->toBe(30)
            ->and($final->isReadyToFight())->toBeFalse();
    });

    it('leaves a row on the contest saying who changed it', function () {
        ($this->board)()
            ->set("fightNumbers.{$this->bout->id}", '15')
            ->call('setFightNumber', $this->bout->id);

        $event = BoutEvent::where('bout_id', $this->bout->id)->where('action', 'fight_number_set')->firstOrFail();

        expect($event->user_id)->toBe($this->admin->id)
            ->and($event->before)->toBe(['fight_number' => null])
            ->and($event->after)->toBe(['fight_number' => 15]);
    });
});

describe('who may number a contest', function () {
    beforeEach(function () {
        $this->category = weighedClass(4);
        app(BracketGenerator::class)->generate($this->category);
        $this->bout = $this->category->bouts()->where('is_bye', false)->firstOrFail();
    });

    /** Hiding the box is not the same as refusing the request. */
    it('refuses an official who may read the bracket but not run it', function () {
        Livewire::actingAs(User::factory()->official()->create())
            ->test(Bracket::class, ['weightCategory' => $this->category->refresh()])
            ->set("fightNumbers.{$this->bout->id}", '3')
            ->call('setFightNumber', $this->bout->id)
            ->assertForbidden();

        expect($this->bout->refresh()->fight_number)->toBeNull();
    });

    it('refuses a referee', function () {
        Livewire::actingAs(User::factory()->create(['role' => 'referee']))
            ->test(Bracket::class, ['weightCategory' => $this->category->refresh()])
            ->set("fightNumbers.{$this->bout->id}", '3')
            ->call('setFightNumber', $this->bout->id)
            ->assertForbidden();

        expect($this->bout->refresh()->fight_number)->toBeNull();
    });

    /** A finished championship is a record, not a schedule. */
    it('refuses an archived championship', function () {
        $this->category->ageCategory->championship->forceFill(['archived_at' => now()])->save();

        Livewire::test(Bracket::class, ['weightCategory' => $this->category->refresh()])
            ->set("fightNumbers.{$this->bout->id}", '3')
            ->call('setFightNumber', $this->bout->id)
            ->assertForbidden();

        expect($this->bout->refresh()->fight_number)->toBeNull();
    });
});

describe('a number, once given, stays given', function () {
    beforeEach(function () {
        $this->category = weighedClass(8);
        app(BracketGenerator::class)->generate($this->category);
        $this->category->refresh();

        foreach ($this->category->bouts()->where('is_bye', false)->orderBy('round')->orderBy('position_in_round')->get() as $index => $bout) {
            $bout->update(['fight_number' => ($index + 1) * 10]);
        }
    });

    it('survives a result being recorded and a winner advancing', function () {
        $before = $this->category->bouts()->orderBy('id')->pluck('fight_number', 'id')->all();

        $bout = $this->category->bouts()->where('round', 1)->firstOrFail();

        Livewire::test(Bracket::class, ['weightCategory' => $this->category->refresh()])
            ->call('recordResult', $bout->id, 'a');

        expect($this->category->bouts()->orderBy('id')->pluck('fight_number', 'id')->all())->toBe($before)
            // And the winner went through the tournament's own advancement.
            ->and($bout->refresh()->nextBout->athlete_a_id ?? $bout->refresh()->nextBout->athlete_b_id)
            ->toBe($bout->athlete_a_id);
    });

    it('survives a correction', function () {
        $bout = $this->category->bouts()->where('round', 1)->firstOrFail();
        $board = Livewire::test(Bracket::class, ['weightCategory' => $this->category->refresh()]);

        $board->call('recordResult', $bout->id, 'a');
        $before = $this->category->bouts()->orderBy('id')->pluck('fight_number', 'id')->all();

        app(BoutAdvancer::class)->recordResult(
            bout: $bout->refresh(),
            winnerAthleteId: $bout->athlete_b_id,
            winType: 'khalol',
            user: $this->admin,
            source: 'operator',
        );

        expect($this->category->bouts()->orderBy('id')->pluck('fight_number', 'id')->all())->toBe($before);
    });

    /** The two workflows are separate, and only one of them renumbers. */
    it('is not touched by opening the bracket or exporting it', function () {
        $before = tournamentState($this->category->refresh());

        Livewire::test(Bracket::class, ['weightCategory' => $this->category->refresh()])->html();
        $this->get(route('exports.bracket-sheet', ['weightCategory' => $this->category, 'format' => 'pdf']))->assertOk();
        $this->get(route('exports.fight-order', ['championship' => $this->category->ageCategory->championship, 'format' => 'pdf']))->assertOk();

        expect(tournamentState($this->category->refresh()))->toBe($before);
    });

    /** What the sheets print is what the bouts hold. */
    it('is what the exports print', function () {
        $sheet = new BracketSheet($this->category->refresh());

        $printed = collect($sheet->branches())->pluck('fight', 'round');
        $saved = $this->category->bouts()->whereNotNull('fight_number')->pluck('fight_number');

        expect($printed->filter())->not->toBeEmpty();

        foreach ($saved as $number) {
            expect(collect($sheet->branches())->pluck('fight')->contains("No. {$number}"))->toBeTrue();
        }
    });
});

describe('the bracket at every stage', function () {
    /** Nothing about a stage changes what the export can do. */
    it('exports in both formats from the first bout to the last', function () {
        $category = weighedClass(8);
        app(BracketGenerator::class)->generate($category);

        $advancer = app(BoutAdvancer::class);
        $stages = 0;

        while (true) {
            foreach (['pdf', 'xlsx'] as $format) {
                $this->get(route('exports.bracket-sheet', [
                    'weightCategory' => $category->refresh(),
                    'format' => $format,
                ]))->assertOk();
            }

            $stages++;

            $bout = $category->bouts()->whereNull('winner_athlete_id')->where('is_bye', false)
                ->whereNotNull('athlete_a_id')->whereNotNull('athlete_b_id')
                ->orderBy('round')->orderBy('position_in_round')->first();

            if (! $bout instanceof Bout) {
                break;
            }

            $advancer->recordResult(
                bout: $bout, winnerAthleteId: $bout->athlete_a_id,
                winType: 'khalol', user: $this->admin, source: 'operator',
            );
        }

        // Opening round, quarters, semis, final and the finished bracket.
        expect($stages)->toBe(8)
            ->and((new BracketSheet($category->refresh()))->champion())->not->toBe('');
    });

    /** Every round is on the sheet, decided or not. */
    it('keeps the rounds that have not happened yet', function () {
        $category = weighedClass(8);
        app(BracketGenerator::class)->generate($category);

        $sheet = new BracketSheet($category->refresh());

        expect($sheet->branches())->toHaveCount(7)
            ->and(collect($sheet->branches())->where('round', 3))->toHaveCount(1)
            ->and(collect($sheet->branches())->pluck('winner')->filter())->toBeEmpty();
    });
});

/**
 * Numbering a class on request, and saving a page of boxes at once.
 *
 * Numbering is offered beside publication rather than done at draw time: a
 * draw and a running order are two decisions, and a class can be drawn days
 * before anybody knows which mat it runs on.
 *
 * Saving is one button for the class. A bracket of thirty-two is thirty-one
 * boxes, and an operator laying out an order edits a run of them at once.
 */
describe('numbering a whole class', function () {
    beforeEach(function () {
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->admin);

        $this->category = weighedClass(4);
        app(BracketGenerator::class)->generate($this->category);

        $this->board = fn () => Livewire::test(Bracket::class, ['weightCategory' => $this->category->refresh()]);
    });

    /** The draw itself must leave numbering alone. */
    it('leaves a fresh draw unnumbered', function () {
        expect($this->category->bouts()->whereNotNull('fight_number')->count())->toBe(0);
    });

    it('numbers every contest when asked', function () {
        ($this->board)()->call('numberContests');

        $contests = $this->category->bouts()->where('is_bye', false)->get();

        expect($contests)->not->toBeEmpty()
            ->and($contests->whereNull('fight_number'))->toBeEmpty();
    });

    it('leaves byes unnumbered', function () {
        [$byes] = categoryWithAthletes(5, '-bye5');
        app(BracketGenerator::class)->generate($byes);

        Livewire::test(Bracket::class, ['weightCategory' => $byes->refresh()])->call('numberContests');

        expect($byes->bouts()->where('is_bye', true)->whereNotNull('fight_number')->count())->toBe(0);
    });

    it('numbers round by round', function () {
        ($this->board)()->call('numberContests');

        $rounds = $this->category->bouts()->where('is_bye', false)
            ->orderBy('fight_number')->pluck('round')->all();

        expect($rounds)->toBe(collect($rounds)->sort()->values()->all());
    });

    /** Pressing it twice must not renumber anything. */
    it('fills gaps and keeps numbers already given', function () {
        $bout = $this->category->bouts()->where('is_bye', false)->firstOrFail();
        $bout->update(['fight_number' => 500]);

        ($this->board)()->call('numberContests');

        expect($bout->refresh()->fight_number)->toBe(500)
            ->and($this->category->bouts()->where('is_bye', false)->whereNull('fight_number')->count())->toBe(0);

        $snapshot = $this->category->bouts()->orderBy('id')->pluck('fight_number', 'id')->all();

        ($this->board)()->call('numberContests');

        expect($this->category->bouts()->orderBy('id')->pluck('fight_number', 'id')->all())->toBe($snapshot);
    });

    /** It appends, so an operator's printed order never shifts under them. */
    it('never moves a number another class already holds', function () {
        $sibling = WeightCategory::factory()->create([
            'age_category_id' => $this->category->age_category_id,
            'label' => '-95',
            'gender' => 'M',
        ]);

        foreach (range(1, 4) as $draw) {
            Athlete::factory()->drawn($draw)->create([
                'championship_id' => $this->category->ageCategory->championship_id,
                'age_category_id' => $this->category->age_category_id,
                'weight_category_id' => $sibling->id,
                'weighin_status' => 'pass',
            ]);
        }

        app(BracketGenerator::class)->generate($sibling->refresh());

        Livewire::test(Bracket::class, ['weightCategory' => $sibling->refresh()])->call('numberContests');
        $siblingNumbers = $sibling->bouts()->orderBy('id')->pluck('fight_number', 'id')->all();

        ($this->board)()->call('numberContests');

        expect($sibling->bouts()->orderBy('id')->pluck('fight_number', 'id')->all())->toBe($siblingNumbers)
            ->and($this->category->bouts()->where('is_bye', false)->min('fight_number'))
            ->toBeGreaterThan(max(array_filter($siblingNumbers)));
    });

    it('writes an audit row for each number it gives', function () {
        ($this->board)()->call('numberContests');

        $contests = $this->category->bouts()->where('is_bye', false)->count();

        expect(BoutEvent::whereIn('bout_id', $this->category->bouts()->pluck('id'))
            ->where('action', 'fight_number_set')->count())->toBe($contests);
    });

    it('refuses an account that may not run the competition', function () {
        Livewire::actingAs(User::factory()->official()->create())
            ->test(Bracket::class, ['weightCategory' => $this->category->refresh()])
            ->call('numberContests')
            ->assertForbidden();

        expect($this->category->bouts()->whereNotNull('fight_number')->count())->toBe(0);
    });

    it('refuses an archived championship', function () {
        $this->category->ageCategory->championship->forceFill(['archived_at' => now()])->save();

        ($this->board)()->call('numberContests')->assertForbidden();

        expect($this->category->bouts()->whereNotNull('fight_number')->count())->toBe(0);
    });
});

describe('saving a page of fight numbers', function () {
    beforeEach(function () {
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->admin);

        $this->category = weighedClass(4);
        app(BracketGenerator::class)->generate($this->category);
        $this->contests = $this->category->bouts()->where('is_bye', false)->orderBy('id')->get();

        $this->board = fn () => Livewire::test(Bracket::class, ['weightCategory' => $this->category->refresh()]);
    });

    it('offers one save for the class, not one per contest', function () {
        $html = ($this->board)()->html();

        expect($html)->toContain('wire:click="saveFightNumbers"')
            ->and($html)->not->toContain('wire:click="setFightNumber')
            ->and($html)->not->toContain('wire:blur');
    });

    it('saves every edited box in one press', function () {
        $component = ($this->board)();

        foreach ($this->contests as $i => $bout) {
            $component->set("fightNumbers.{$bout->id}", (string) (20 + $i));
        }

        $component->call('saveFightNumbers');

        foreach ($this->contests as $i => $bout) {
            expect($bout->refresh()->fight_number)->toBe(20 + $i);
        }
    });

    it('saves only the boxes that were edited', function () {
        $first = $this->contests->first();
        $others = $this->contests->skip(1)->pluck('fight_number', 'id')->all();

        ($this->board)()
            ->set("fightNumbers.{$first->id}", '31')
            ->call('saveFightNumbers');

        expect($first->refresh()->fight_number)->toBe(31)
            ->and($this->category->bouts()->whereKeyNot($first->id)->where('is_bye', false)
                ->pluck('fight_number', 'id')->all())->toBe($others);
    });

    it('says so when nothing has been changed', function () {
        ($this->board)()->call('saveFightNumbers')->assertSee('Nothing to save');
    });

    /** One box failing must not look like all of them failing. */
    it('saves the good boxes and reports only the bad one', function () {
        [$good, $bad] = [$this->contests[0], $this->contests[1]];

        $component = ($this->board)()
            ->set("fightNumbers.{$good->id}", '61')
            ->set("fightNumbers.{$bad->id}", 'twelve')
            ->call('saveFightNumbers');

        $state = $component->get('fightNumberState');

        expect($good->refresh()->fight_number)->toBe(61)
            ->and($bad->refresh()->fight_number)->not->toBe('twelve')
            ->and($state[$good->id]['status'])->toBe('saved')
            ->and($state[$bad->id]['status'])->toBe('error')
            // And what was typed is still there to correct.
            ->and($component->get("fightNumbers.{$bad->id}"))->toBe('twelve');
    });

    it('shows no save button to an account that may not run the competition', function () {
        $html = Livewire::actingAs(User::factory()->official()->create())
            ->test(Bracket::class, ['weightCategory' => $this->category->refresh()])
            ->html();

        expect($html)->not->toContain('wire:click="saveFightNumbers"');
    });
});
