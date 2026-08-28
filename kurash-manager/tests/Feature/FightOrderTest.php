<?php

use App\Livewire\Competition\FightOrder;
use App\Livewire\Competition\MatControl;
use App\Models\AgeCategory;
use App\Models\Athlete;
use App\Models\Bout;
use App\Models\Championship;
use App\Models\Court;
use App\Models\User;
use App\Models\WeightCategory;
use App\Services\BoutAdvancer;
use App\Services\BracketGenerator;
use App\Services\FightOrderScheduler;
use App\Services\RoundRobinGenerator;
use Livewire\Livewire;

beforeEach(function () {
    $this->scheduler = app(FightOrderScheduler::class);
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->viewer = User::factory()->create(['role' => 'viewer']);
});

/**
 * The running order as a list of class labels, one entry per numbered contest.
 *
 * The label rather than the id, because what the assertions are about is which
 * class runs before which — and an id says nothing about that on a screen.
 *
 * @return list<string>
 */
function labelsInOrder(Championship $championship, ?int $round = null): array
{
    return $championship->bouts()
        ->whereNotNull('fight_number')
        ->when($round !== null, fn ($query) => $query->where('round', $round))
        ->with('weightCategory')
        ->orderBy('fight_number')
        ->get()
        ->map(fn (Bout $bout) => (string) $bout->weightCategory?->label)
        ->all();
}

/**
 * One more class inside a division that already exists.
 *
 * categoryWithAthletes() makes a championship of its own each time, and the
 * running order is a question about several classes sharing one.
 */
function classWithAthletes(AgeCategory $ageCategory, string $label, int $count): WeightCategory
{
    $category = WeightCategory::factory()->create([
        'age_category_id' => $ageCategory->id,
        'label' => $label,
    ]);

    foreach (range(1, $count) as $draw) {
        Athlete::factory()->drawn($draw)->create([
            'championship_id' => $ageCategory->championship_id,
            'age_category_id' => $ageCategory->id,
            'weight_category_id' => $category->id,
            'fullname' => "{$label} #{$draw}",
        ]);
    }

    return $category->refresh();
}

describe('building the running order', function () {
    it('numbers every contested bout once, with no gaps', function () {
        $championship = championshipWithBrackets(['-66' => 8, '-73' => 8]);

        $result = $this->scheduler->schedule($championship);

        $numbers = $championship->bouts()->whereNotNull('fight_number')->pluck('fight_number')->sort()->values()->all();

        expect($result['scheduled'])->toBe(14)             // 7 bouts per 8-athlete bracket
            ->and($numbers)->toBe(range(1, 14));
    });

    /** Nobody steps onto a mat for a walkover, so it takes no slot. */
    it('leaves byes out of the running order', function () {
        $championship = championshipWithBrackets(['-66' => 5]);

        $this->scheduler->schedule($championship);

        expect($championship->bouts()->where('is_bye', true)->whereNotNull('fight_number')->count())->toBe(0)
            ->and($championship->bouts()->where('is_bye', false)->whereNull('fight_number')->count())->toBe(0);
    });

    /**
     * The property that matters most: a semi-final must never be called before
     * the quarter-final that feeds it. The old hand-typed CSV had nothing
     * preventing that.
     */
    it('never numbers a bout before one that feeds it', function (array $classes) {
        $championship = championshipWithBrackets($classes);
        $this->scheduler->schedule($championship);

        $bouts = $championship->bouts()->whereNotNull('fight_number')->with('previousBouts')->get();

        foreach ($bouts as $bout) {
            foreach ($bout->previousBouts as $feeder) {
                if ($feeder->fight_number === null) {
                    continue;   // a bye, which takes no slot
                }

                expect($feeder->fight_number)->toBeLessThan(
                    $bout->fight_number,
                    "Fight {$bout->fight_number} runs before its feeder {$feeder->fight_number}"
                );
            }
        }
    })->with([
        'one class' => [['-66' => 8]],
        'two classes' => [['-66' => 8, '-73' => 8]],
        'uneven classes' => [['-66' => 16, '-73' => 5, '-81' => 3]],
        'many small classes' => [['-60' => 3, '-66' => 4, '-73' => 2, '-81' => 6]],
    ]);

    it('runs every class round by round rather than one class to its final', function () {
        $championship = championshipWithBrackets(['-66' => 8, '-73' => 8]);
        $this->scheduler->schedule($championship);

        // The first eight fights are the eight first-round bouts, four from
        // each class — not one class's whole bracket.
        $firstEight = $championship->bouts()
            ->whereNotNull('fight_number')
            ->orderBy('fight_number')
            ->limit(8)
            ->pluck('round')
            ->unique()
            ->all();

        expect($firstEight)->toBe([1]);
    });

    /**
     * A round is one class at a time, lightest first — not the classes
     * interleaved. An operator runs a class's round off one draw sheet, and a
     * mat that alternates between three of them is three sheets on the table.
     */
    it('runs one class at a time within a round, lightest first', function () {
        // Declared heaviest first, so the order they were typed in is the
        // opposite of the order they must be fought in.
        $championship = championshipWithBrackets(['-73' => 8, '-66' => 8, '-60' => 8]);
        $this->scheduler->schedule($championship);

        expect(labelsInOrder($championship, round: 1))->toBe(array_merge(
            array_fill(0, 4, '-60'),
            array_fill(0, 4, '-66'),
            array_fill(0, 4, '-73'),
        ));
    });

    /**
     * The three ways of ordering classes that are wrong, in one championship:
     * by id gives whoever was typed in first, and by label as text puts "-100"
     * ahead of "-60" and "+100" ahead of both.
     */
    it('orders the classes by the weight limit they are named for', function () {
        $championship = championshipWithBrackets(['+100' => 4, '-100' => 4, '-60' => 4, '-73' => 4]);
        $this->scheduler->schedule($championship);

        expect(array_values(array_unique(labelsInOrder($championship, round: 1))))
            // The open class last: "+100" and "-100" name the same figure, and
            // the open one is the heavier of the two.
            ->toBe(['-60', '-73', '-100', '+100']);
    });

    it('reads a limit written the long way, and one written with a comma', function () {
        $championship = championshipWithBrackets(['+100 kg' => 4, '-60 kg' => 4, '67,5 kg' => 4]);
        $this->scheduler->schedule($championship);

        expect(array_values(array_unique(labelsInOrder($championship, round: 1))))
            ->toBe(['-60 kg', '67,5 kg', '+100 kg']);
    });

    /**
     * Classes are not the same depth: a class of four is finished in two
     * rounds while a class of sixteen has four. A round a class has nothing to
     * fight in is a round it does not appear in — not a gap in the numbering,
     * and not a reason to hold the deeper class back.
     */
    it('skips a class that has no contest in the round', function () {
        $championship = championshipWithBrackets(['-66' => 16, '-60' => 4]);
        $this->scheduler->schedule($championship);

        expect(labelsInOrder($championship, round: 1))
            ->toBe([...array_fill(0, 2, '-60'), ...array_fill(0, 8, '-66')])
            // The small class's final is a round-2 contest and is numbered
            // there, in weight order, like any other round-2 contest.
            ->and(labelsInOrder($championship, round: 2))
            ->toBe(['-60', ...array_fill(0, 4, '-66')])
            ->and(labelsInOrder($championship, round: 3))->toBe(['-66', '-66'])
            ->and(labelsInOrder($championship, round: 4))->toBe(['-66'])
            ->and($championship->bouts()->whereNotNull('fight_number')->count())->toBe(18);
    });

    /**
     * A round of a bracket is a stage of the bracket; a round of a round robin
     * is a matchday, one contest each for everybody not sitting out. They are
     * different things and they are run against each other by number, so the
     * two classes progress through the day together.
     */
    it('aligns a round robin matchday with the bracket stage of the same number', function () {
        $ageCategory = AgeCategory::factory()->create();

        $robin = classWithAthletes($ageCategory, '-60', 4);
        app(RoundRobinGenerator::class)->generate($robin);

        $bracket = classWithAthletes($ageCategory, '-73', 4);
        app(BracketGenerator::class)->generate($bracket);

        $championship = $ageCategory->championship->refresh();
        $this->scheduler->schedule($championship);

        // Six round-robin contests over three matchdays, and a bracket of two
        // semi-finals and a final.
        expect(labelsInOrder($championship))->toBe([
            '-60', '-60', '-73', '-73',     // matchday 1 · the two semi-finals
            '-60', '-60', '-73',            // matchday 2 · the final
            '-60', '-60',                   // matchday 3 · the bracket is done
        ]);
    });

    /**
     * The two things a matchday is: everybody in it once at most, and every
     * pairing in the class across the whole schedule exactly once.
     */
    it('numbers a matchday as one contest each and every pairing once', function () {
        $ageCategory = AgeCategory::factory()->create();
        app(RoundRobinGenerator::class)->generate(classWithAthletes($ageCategory, '-60', 5));

        $championship = $ageCategory->championship->refresh();
        $this->scheduler->schedule($championship);

        $ordered = $championship->bouts()->whereNotNull('fight_number')->orderBy('fight_number')->get();

        expect($ordered)->toHaveCount(10);           // every pair of five, once

        foreach ($ordered->groupBy('round') as $round => $matchday) {
            $competing = $matchday->flatMap(fn (Bout $bout) => [$bout->athlete_a_id, $bout->athlete_b_id]);

            expect($competing->duplicates())->toBeEmpty("round {$round} has somebody fighting twice")
                // Five athletes, so one of them sits out each matchday — and
                // that rest is never a numbered contest.
                ->and($competing)->toHaveCount(4);
        }

        $pairings = $ordered->map(fn (Bout $bout) => collect([$bout->athlete_a_id, $bout->athlete_b_id])->sort()->implode('-'));

        expect($pairings->duplicates())->toBeEmpty();
    });

    it('is safe to rebuild, leaving no stale numbers behind', function () {
        $championship = championshipWithBrackets(['-66' => 8]);
        $this->scheduler->schedule($championship);

        // A class added later must not leave the old numbering half-applied.
        $extra = WeightCategory::factory()->create([
            'age_category_id' => $championship->ageCategories()->first()->id,
            'label' => '-90',
        ]);
        foreach (range(1, 4) as $draw) {
            Athlete::factory()->drawn($draw)->create([
                'championship_id' => $championship->id,
                'age_category_id' => $extra->age_category_id,
                'weight_category_id' => $extra->id,
            ]);
        }
        app(BracketGenerator::class)->generate($extra->refresh());

        $this->scheduler->schedule($championship);

        $numbers = $championship->bouts()->whereNotNull('fight_number')->pluck('fight_number')->sort()->values()->all();

        expect($numbers)->toBe(range(1, 10));   // 7 + 3
    });

    it('reports nothing to do when no bracket has been drawn', function () {
        $championship = Championship::factory()->create();

        // The same shape as a run that scheduled something, so a caller never
        // has to know which path answered.
        expect($this->scheduler->schedule($championship))
            ->toBe(['scheduled' => 0, 'violations' => 0, 'unattainable' => 0]);
    });

    it('clears every fight number', function () {
        $championship = championshipWithBrackets(['-66' => 8]);
        $this->scheduler->schedule($championship);

        $this->scheduler->clear($championship);

        expect($championship->bouts()->whereNotNull('fight_number')->count())->toBe(0);
    });
});

describe('rest between bouts', function () {
    it('gives an athlete a gap between their own bouts when the field is large enough', function () {
        $championship = championshipWithBrackets(['-60' => 16, '-66' => 16, '-73' => 16, '-81' => 16]);

        $result = $this->scheduler->schedule($championship, minimumRest: 3);

        expect($result['violations'])->toBe(0);
    });

    /**
     * A property of tournaments rather than of this code: the last rounds have
     * too few bouts left to separate a semi-final from its final. With only two
     * classes there are just two finals to sit between four semis.
     *
     * The right behaviour is to report it, so the organisers schedule a break —
     * not to pretend the rest exists.
     */
    it('reports the unavoidable shortfall in the closing rounds', function () {
        $championship = championshipWithBrackets(['-66' => 16, '-73' => 16]);

        $this->scheduler->schedule($championship, minimumRest: 3);
        $violations = $this->scheduler->restViolations($championship, 3);

        expect($violations)->not->toBeEmpty();

        // …and every one of them is in the final, not scattered through the day.
        $totalRounds = (int) $championship->bouts()->max('round');

        foreach ($violations as $violation) {
            expect($violation['bout']->round)->toBe($totalRounds);
        }
    });

    /**
     * A single tiny bracket cannot give rest — there are not enough other
     * bouts to fill the gap. The scheduler should say so rather than pretend.
     */
    it('reports a violation when the draw is too small to give rest', function () {
        $championship = championshipWithBrackets(['-66' => 4]);

        $result = $this->scheduler->schedule($championship, minimumRest: 3);

        expect($result['violations'])->toBeGreaterThan(0);
    });

    it('measures rest past a bye, back to the last bout actually fought', function () {
        // 5 athletes: seeds 1, 2 and 3 receive byes, so the semi-finalist who
        // walked over has no previous contest and cannot be short of rest.
        $championship = championshipWithBrackets(['-66' => 5]);
        $this->scheduler->schedule($championship, minimumRest: 10);

        $violations = $this->scheduler->restViolations($championship, 10);

        // Every reported violation must reference a real contest, never a bye.
        foreach ($violations as $violation) {
            expect($violation['feeder']->is_bye)->toBeFalse();
        }
    });
});

describe('manual reordering', function () {
    it('swaps a bout with its neighbour', function () {
        $championship = championshipWithBrackets(['-66' => 8]);
        $this->scheduler->schedule($championship);

        $second = $championship->bouts()->where('fight_number', 2)->first();

        expect($this->scheduler->move($second, 'up'))->toBeTrue()
            ->and($second->refresh()->fight_number)->toBe(1);
    });

    /** Reordering must not let a bout jump ahead of one that feeds it. */
    it('refuses a swap that would put a bout before its feeder', function () {
        $championship = championshipWithBrackets(['-66' => 4]);
        $this->scheduler->schedule($championship);

        // In a 4-athlete bracket: fights 1 and 2 are the semis, 3 is the final.
        $final = $championship->bouts()->where('fight_number', 3)->first();

        expect($this->scheduler->move($final, 'up'))->toBeFalse()
            ->and($final->refresh()->fight_number)->toBe(3);
    });

    it('does nothing at the ends of the list', function () {
        $championship = championshipWithBrackets(['-66' => 4]);
        $this->scheduler->schedule($championship);

        $first = $championship->bouts()->where('fight_number', 1)->first();

        expect($this->scheduler->move($first, 'up'))->toBeFalse();
    });

    it('ignores a bout with no fight number', function () {
        $championship = championshipWithBrackets(['-66' => 4]);
        $bout = $championship->bouts()->first();

        expect($this->scheduler->move($bout, 'up'))->toBeFalse();
    });
});

describe('the fight order screen', function () {
    it('is readable by any signed-in user', function () {
        $championship = championshipWithBrackets(['-66' => 4]);

        $this->actingAs($this->viewer)
            ->get(route('fight-order.index', $championship))
            ->assertOk();
    });

    it('stops a viewer from building the order', function () {
        $championship = championshipWithBrackets(['-66' => 4]);

        $this->actingAs($this->viewer);

        Livewire::test(FightOrder::class, ['championship' => $championship])
            ->call('schedule')
            ->assertForbidden();

        expect($championship->bouts()->whereNotNull('fight_number')->count())->toBe(0);
    });

    it('builds and lists the order', function () {
        $championship = championshipWithBrackets(['-66' => 4]);

        $this->actingAs($this->admin);

        Livewire::test(FightOrder::class, ['championship' => $championship])
            ->call('schedule')
            ->assertOk()
            ->assertSee('-66');

        expect($championship->bouts()->whereNotNull('fight_number')->count())->toBe(3);
    });

    it('warns when brackets have not been drawn', function () {
        $championship = Championship::factory()->create();

        $this->actingAs($this->admin);

        // Asserted on what the operator actually sees. Livewire's test harness
        // ages the flash before session('error') can be read back, so checking
        // the rendered output is both more reliable and more meaningful.
        Livewire::test(FightOrder::class, ['championship' => $championship])
            ->call('schedule')
            ->assertSee('draw the brackets first');
    });

    it('sends a scheduled bout to a mat', function () {
        $championship = championshipWithBrackets(['-66' => 4]);
        $this->scheduler->schedule($championship);
        $court = Court::factory()->create(['championship_id' => $championship->id, 'number' => 1]);

        $bout = $championship->bouts()->where('fight_number', 1)->first();

        $this->actingAs($this->admin);

        Livewire::test(FightOrder::class, ['championship' => $championship])
            ->call('sendToMat', $bout->id, $court->id);

        expect($bout->refresh()->court_id)->toBe($court->id)
            ->and($bout->status)->toBe(Bout::STATUS_ON_COURT);
    });

    it('hides finished bouts on request', function () {
        $championship = championshipWithBrackets(['-66' => 4]);
        $this->scheduler->schedule($championship);

        $first = $championship->bouts()->where('fight_number', 1)->first();
        $first->update(['status' => Bout::STATUS_COMPLETED, 'winner_athlete_id' => $first->athlete_a_id]);

        $this->actingAs($this->admin);

        Livewire::test(FightOrder::class, ['championship' => $championship])
            ->set('hideCompleted', true)
            ->assertDontSee($first->athleteA->fullname);
    });
});

/**
 * A decided contest raises a question about the record — what was called, and
 * was it right. That is answered on the mat it was fought on, where the log
 * and the way back both live, so the running order links there.
 */
describe('reviewing a finished contest', function () {
    beforeEach(function () {
        // The mat screen authorises the mat on mount, so somebody has to be
        // holding it.
        $this->actingAs($this->admin);

        $this->championship = championshipWithBrackets(['-66' => 4]);
        app(FightOrderScheduler::class)->schedule($this->championship);

        $this->court = Court::factory()->create(['championship_id' => $this->championship->id]);
        $this->bout = $this->championship->bouts()->where('fight_number', 1)->first();
    });

    it('offers no review of a contest still to be fought', function () {
        Livewire::test(FightOrder::class, ['championship' => $this->championship])
            ->assertDontSee('Review');
    });

    it('links a decided contest to the mat it was fought on', function () {
        $this->bout->update(['court_id' => $this->court->id]);

        app(BoutAdvancer::class)->recordResult(
            bout: $this->bout->refresh(),
            winnerAthleteId: $this->bout->athlete_a_id,
            winType: 'khalol',
            user: $this->admin,
            source: 'operator',
        );

        Livewire::test(FightOrder::class, ['championship' => $this->championship])
            ->assertSee('Review')
            ->assertSee(
                route('mats.live', ['court' => $this->court->id, 'review' => $this->bout->id]),
                false,
            );
    });

    /** A contest decided without ever reaching a mat has no mat to review on. */
    it('offers nothing for a contest that never went to a mat', function () {
        app(BoutAdvancer::class)->recordResult(
            bout: $this->bout,
            winnerAthleteId: $this->bout->athlete_a_id,
            winType: 'khalol',
            user: $this->admin,
            source: 'operator',
        );

        Livewire::test(FightOrder::class, ['championship' => $this->championship])
            ->assertDontSee('Review');
    });

    /** The mat shows the contest it was asked for, not the last one it ran. */
    it('opens the contest the running order asked for', function () {
        $bouts = $this->championship->bouts()->orderBy('fight_number')->take(2)->get();

        foreach ($bouts as $bout) {
            $bout->update(['court_id' => $this->court->id]);

            app(BoutAdvancer::class)->recordResult(
                bout: $bout->refresh(),
                winnerAthleteId: $bout->athlete_a_id,
                winType: 'khalol',
                user: $this->admin,
                source: 'operator',
            );
        }

        $older = $bouts->first();

        // The older of the two, not the one this mat finished last. There is
        // no call log here because the result was recorded rather than
        // refereed — what a log looks like is MatControlTest's business.
        Livewire::test(MatControl::class, ['court' => $this->court, 'review' => $older->id])
            ->assertSee($older->athleteA->fullname)
            ->assertSee('Reopen contest')
            ->assertViewHas('reviewing', fn ($bout) => $bout->is($older));
    });

    /** An id from another mat is not something this one will show. */
    it('refuses to review a contest from a different mat', function () {
        $elsewhere = Court::factory()->create(['championship_id' => $this->championship->id]);

        $this->bout->update(['court_id' => $elsewhere->id]);

        app(BoutAdvancer::class)->recordResult(
            bout: $this->bout->refresh(),
            winnerAthleteId: $this->bout->athlete_a_id,
            winType: 'khalol',
            user: $this->admin,
            source: 'operator',
        );

        Livewire::test(MatControl::class, ['court' => $this->court, 'review' => $this->bout->id])
            ->assertDontSee('Reopen contest');
    });
});
