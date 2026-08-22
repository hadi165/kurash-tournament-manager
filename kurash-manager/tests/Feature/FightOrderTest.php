<?php

use App\Livewire\Competition\FightOrder;
use App\Livewire\Competition\MatControl;
use App\Models\Athlete;
use App\Models\Bout;
use App\Models\Championship;
use App\Models\Court;
use App\Models\User;
use App\Models\WeightCategory;
use App\Services\BoutAdvancer;
use App\Services\BracketGenerator;
use App\Services\FightOrderScheduler;
use Livewire\Livewire;

beforeEach(function () {
    $this->scheduler = app(FightOrderScheduler::class);
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->viewer = User::factory()->create(['role' => 'viewer']);
});

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

    it('interleaves the weight classes within a round', function () {
        $championship = championshipWithBrackets(['-66' => 8, '-73' => 8]);
        $this->scheduler->schedule($championship);

        $firstFour = $championship->bouts()
            ->whereNotNull('fight_number')
            ->orderBy('fight_number')
            ->limit(4)
            ->pluck('weight_category_id')
            ->all();

        // Alternating classes, so no athlete's category runs back to back.
        expect($firstFour[0])->not->toBe($firstFour[1])
            ->and($firstFour[1])->not->toBe($firstFour[2]);
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

        expect($this->scheduler->schedule($championship))->toBe(['scheduled' => 0, 'violations' => 0]);
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
