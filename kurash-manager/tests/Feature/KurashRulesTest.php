<?php

use App\Livewire\Competition\MatControl;
use App\Livewire\Competition\Scoreboard;
use App\Models\Bout;
use App\Models\User;
use App\Services\KurashScore;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The rules, as the federation states them, checked one at a time.
 *
 * MatControlTest covers the screen — that a press reaches the log and the log
 * reaches a result. This covers what the rules actually say, including the
 * three places the answer cannot be read off a counter: which chala a dakki
 * supersedes, which side earned its scores, and who was warned last.
 */
beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->actingAs($this->admin);
});

/** Press the mat screen's buttons in order and return the resulting tally. */
function scoreCalls(array $calls): array
{
    [$court, $bout] = boutOnMat();

    $component = Livewire::test(MatControl::class, ['court' => $court]);

    foreach ($calls as [$call, $side]) {
        $component->call('score', $call, $side, 120);
    }

    $bout->refresh();

    return [$bout, app(KurashScore::class)->tally($bout, $bout->events()->get())];
}

describe('the event log', function () {
    it('numbers every row in sequence within its bout', function () {
        [$bout] = scoreCalls([['chala', 'a'], ['tanbeh', 'b'], ['yonbosh', 'a']]);

        $sequences = $bout->events()->orderBy('id')->pluck('sequence_number')->all();

        // Four rows, not three: the tanbeh against green wrote the chala it
        // gave blue as a row of its own.
        expect($sequences)->toBe([1, 2, 3, 4]);
    });

    it('names the side, the call, the action and the origin on every row', function () {
        [$bout] = scoreCalls([['yonbosh', 'a']]);

        $event = $bout->events()->firstOrFail();

        expect($event->competitor_side)->toBe('blue')
            ->and($event->event_type)->toBe('yonbosh')
            ->and($event->entry_action)->toBe(KurashScore::ENTRY_ADD)
            ->and($event->origin)->toBe(KurashScore::ORIGIN_TECHNIQUE)
            ->and($event->user_id)->toBe($this->admin->id);
    });

    /**
     * The link that makes the dakki rule possible. Without it, "the chala that
     * came from that tanbeh" would have to be guessed at from timing.
     */
    it('ties an automatic award to the penalty that caused it', function () {
        [$bout] = scoreCalls([['tanbeh', 'a']]);

        $tanbeh = $bout->events()->where('event_type', 'tanbeh')->firstOrFail();
        $chala = $bout->events()->where('event_type', 'chala')->firstOrFail();

        expect($chala->parent_event_id)->toBe($tanbeh->id)
            ->and($chala->competitor_side)->toBe('green')
            ->and($chala->origin)->toBe(KurashScore::ORIGIN_AUTO_FROM_T);
    });

    it('takes a penalty back together with what it gave the opponent', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'tanbeh', 'a', 200)
            ->call('decrease', 'tanbeh', 'a');

        $tally = app(KurashScore::class)->tally($bout->refresh(), $bout->events()->get());

        expect($tally['a']->tanbeh)->toBe(0)
            // The chala went with it. A board left holding it would be showing
            // a score no sequence of calls could produce.
            ->and($tally['b']->chala)->toBe(0)
            // And nothing was deleted: both rows and the annulment stand.
            ->and($bout->events()->count())->toBe(3);
    });

    /**
     * The whole promise of an event-sourced contest: the tally is a function of
     * the log, so replaying the log reproduces it exactly.
     */
    it('recalculates the same tally from the history alone', function () {
        [$bout, $tally] = scoreCalls([
            ['chala', 'b'], ['tanbeh', 'a'], ['dakki', 'a'], ['yonbosh', 'b'], ['madichal', 'b'],
        ]);

        $replayed = app(KurashScore::class)->tally($bout, $bout->events()->get());

        expect($replayed['a']->toArray())->toBe($tally['a']->toArray())
            ->and($replayed['b']->toArray())->toBe($tally['b']->toArray());
    });
});

describe('automatic awards', function () {
    it('supersedes only the automatic chala, never an earned one', function () {
        [, $tally] = scoreCalls([
            ['chala', 'b'],      // green throws for one
            ['chala', 'b'],      // and another
            ['tanbeh', 'a'],     // blue penalised — green given a third
            ['dakki', 'a'],      // supersedes it
        ]);

        expect($tally['b']->chala)->toBe(2)
            ->and($tally['b']->earnedChala)->toBe(2)
            ->and($tally['b']->yonbosh)->toBe(1)
            ->and($tally['b']->earnedYonbosh)->toBe(0);
    });

    it('takes back every automatic chala the penalised side had handed over', function () {
        [, $tally] = scoreCalls([
            ['tanbeh', 'a'],
            ['tanbeh', 'a'],
            ['dakki', 'a'],
        ]);

        expect($tally['b']->chala)->toBe(0)
            ->and($tally['b']->yonbosh)->toBe(1);
    });

    /**
     * Blue's tanbeh gave green a chala; green's dakki must not take it away.
     * The rule replaces what a side's *own* penalties conceded.
     */
    it('does not let one side\'s dakki cancel the other side\'s automatic chala', function () {
        [, $tally] = scoreCalls([
            ['tanbeh', 'a'],     // blue penalised — green given a chala
            ['dakki', 'b'],      // green penalised — blue given a yonbosh
        ]);

        expect($tally['b']->chala)->toBe(1)
            ->and($tally['a']->yonbosh)->toBe(1);
    });
});

describe('the tie-break at time', function () {
    /**
     * Both hold one yonbosh and nothing else. Blue threw for theirs; green was
     * handed theirs when blue was penalised. No counter on the board separates
     * them, which is exactly why origin is recorded.
     */
    /**
     * A Yonbosh thrown for beats a Yonbosh handed over by the opponent's Dakki.
     *
     * The federation's ruling. Origin ranks below count and above recency, so
     * green's later automatic Yonbosh does not defeat blue's earlier thrown
     * one — the last-appraisal rule is never reached.
     */
    it('prefers a technique-earned score over a later automatic one', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'yonbosh', 'a', 200)  // blue throws for one
            ->call('score', 'dakki', 'a', 180)    // blue penalised: green given one, later
            ->call('finishOnTime');

        $bout->refresh();
        $tally = app(KurashScore::class)->tally($bout, $bout->events()->get());

        expect($tally['a']->earnedYonbosh)->toBe(1)
            ->and($tally['b']->earnedYonbosh)->toBe(0)
            // Blue threw for theirs, so blue wins despite green's coming last.
            ->and($bout->winner_athlete_id)->toBe($bout->athlete_a_id)
            ->and($bout->win_type)->toBe('technique_origin');
    });

    /**
     * Level on appraisals with one warning each: the athlete holding the most
     * recent warning loses. Blue was warned first, green last, so green loses.
     */
    it('falls to the latest warning when everything else is level', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'madichal', 'a', 200)   // blue warned first
            ->call('score', 'madichal', 'b', 150)   // green warned last
            ->call('finishOnTime');

        $bout->refresh();

        expect($bout->winner_athlete_id)->toBe($bout->athlete_a_id)
            ->and($bout->win_type)->toBe('latest_warning');
    });

    /**
     * An unwarned athlete beats a warned one: with lastPenaltyAt zero, the
     * warned athlete necessarily holds the most recent warning.
     */
    it('gives it to the unwarned athlete', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'madichal', 'b', 230)
            ->call('finishOnTime');

        expect($bout->refresh()->winner_athlete_id)->toBe($bout->athlete_a_id)
            ->and($bout->win_type)->toBe('latest_warning');
    });

    /**
     * Equal on value and on count, so clause (b) decides it: victory follows
     * the last appraisal. Origin is not consulted.
     */
    it('falls to the last appraisal when the counts are level', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'chala', 'a', 200)
            ->call('score', 'chala', 'b', 190)
            ->call('finishOnTime');

        expect($bout->refresh()->winner_athlete_id)->toBe($bout->athlete_b_id)
            ->and($bout->win_type)->toBe('last_appraisal');
    });

    /**
     * Value before recency. Green scored last and still loses, because a
     * yonbosh is worth more than a chala however late the chala came.
     */
    it('lets a higher score beat a later one', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'yonbosh', 'a', 220)
            ->call('score', 'chala', 'b', 100)
            ->call('finishOnTime');

        expect($bout->refresh()->winner_athlete_id)->toBe($bout->athlete_a_id)
            ->and($bout->win_type)->toBe('higher_appraisal');
    });

    /**
     * Value before count, too: one yonbosh beats a pile of chala, because
     * chala never accumulates into anything larger.
     */
    it('lets one yonbosh beat any number of chala', function () {
        [$court, $bout] = boutOnMat();

        $component = Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'yonbosh', 'a', 220);

        foreach (range(1, 5) as $i) {
            $component->call('score', 'chala', 'b', 200 - $i * 10);
        }

        $component->call('finishOnTime');

        expect($bout->refresh()->winner_athlete_id)->toBe($bout->athlete_a_id);
    });

    /**
     * Blue's yonbosh came from green's dakki, and green has nothing. The
     * relationship between a penalty and the score it generates has to survive
     * into the winner calculation.
     */
    it('gives it to the athlete holding the yonbosh the opponent conceded', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'dakki', 'b', 200)
            ->call('finishOnTime');

        expect($bout->refresh()->winner_athlete_id)->toBe($bout->athlete_a_id);
    });

    it('still refuses to invent a winner when there is nothing to separate them', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('finishOnTime')
            ->assertSet('awaitingDecision', true);

        expect($bout->refresh()->winner_athlete_id)->toBeNull();
    });
});

describe('declaring a winner by hand', function () {
    it('gives the contest to blue and records that a referee did', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'yonbosh', 'b', 200)   // green is ahead
            ->call('declareWinner', 'a');

        $bout->refresh();

        expect($bout->winner_athlete_id)->toBe($bout->athlete_a_id)
            // Not dressed up as a scored result: the record says a referee
            // overrode the log, because a result nobody can explain from the
            // calls is worse than no result at all.
            ->and($bout->win_type)->toBe('manual');
    });

    it('gives the contest to green', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])->call('declareWinner', 'b');

        expect($bout->refresh()->winner_athlete_id)->toBe($bout->athlete_b_id);
    });
});

describe('jazzo', function () {
    it('stops the contest at half time when neither athlete has scored', function () {
        [$court, $bout] = boutOnMat();

        $half = app(KurashScore::class)->jazzoAt($bout);

        Livewire::test(MatControl::class, ['court' => $court])->call('callJazzo', $half);

        $bout->refresh();

        expect($bout->isInJazzo())->toBeTrue()
            ->and($bout->clock_running)->toBeFalse()
            ->and($bout->events()->where('action', KurashScore::ACTION_JAZZO)->count())->toBe(1);
    });

    it('leaves a contest alone when something has been scored', function () {
        [$court, $bout] = boutOnMat();

        $half = app(KurashScore::class)->jazzoAt($bout);

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'chala', 'a', $half + 10)
            ->call('callJazzo', $half);

        expect($bout->refresh()->isInJazzo())->toBeFalse();
    });

    /**
     * The browser holds the clock, so a tampered reading is the obvious attack.
     * The halfway mark is checked again on the server.
     */
    it('refuses to stop a contest that is not yet at half time', function () {
        [$court, $bout] = boutOnMat();

        $seconds = app(KurashScore::class)->boutSeconds($bout);

        Livewire::test(MatControl::class, ['court' => $court])->call('callJazzo', $seconds);

        expect($bout->refresh()->isInJazzo())->toBeFalse();
    });

    it('carries on from where the clock stopped rather than restarting it', function () {
        [$court, $bout] = boutOnMat();

        $half = app(KurashScore::class)->jazzoAt($bout);

        $component = Livewire::test(MatControl::class, ['court' => $court])->call('callJazzo', $half);

        $component->call('resume', $half);

        $bout->refresh();

        expect($bout->isInJazzo())->toBeFalse()
            ->and($bout->clock_running)->toBeTrue()
            ->and($bout->clock_seconds_left)->toBe($half)
            ->and($bout->events()->where('action', KurashScore::ACTION_RESUMED)->count())->toBe(1);
    });

    it('does not stop the same contest twice at the same mark', function () {
        [$court, $bout] = boutOnMat();

        $half = app(KurashScore::class)->jazzoAt($bout);

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('callJazzo', $half)
            ->call('resume', $half)
            ->call('callJazzo', $half - 5);

        expect($bout->refresh()->isInJazzo())->toBeFalse()
            ->and($bout->events()->where('action', KurashScore::ACTION_JAZZO)->count())->toBe(1);
    });
});

describe('contest length', function () {
    it('takes the length from the age category when it has one', function () {
        [$court, $bout] = boutOnMat();

        $bout->ageCategory->update(['bout_seconds' => 180]);

        expect(app(KurashScore::class)->boutSeconds($bout->refresh()))->toBe(180);
    });

    it('falls back to the configured default for the gender', function () {
        [$court, $bout] = boutOnMat();

        $bout->ageCategory->update(['bout_seconds' => null]);
        $bout->weightCategory->update(['gender' => 'F']);

        expect(app(KurashScore::class)->boutSeconds($bout->refresh()))
            ->toBe((int) config('kurash.bout_seconds.F'));
    });

    it('puts half of the category length where jazzo falls due', function () {
        [$court, $bout] = boutOnMat();

        $bout->ageCategory->update(['bout_seconds' => 200]);

        expect(app(KurashScore::class)->jazzoAt($bout->refresh()))->toBe(100);
    });
});

describe('the clock between contests', function () {
    /**
     * A mat that has just run a four minute senior contest must not open a
     * three minute cadet one showing four.
     */
    it('starts every new contest from the top of its own clock', function () {
        [$court, $bout] = boutOnMat(4);

        $component = Livewire::test(MatControl::class, ['court' => $court]);

        // Run the first one down and finish it.
        $component->call('publishClock', 12, true)->call('score', 'khalol', 'a', 12);

        $next = $court->championship->bouts()->readyToFight()->whereNull('court_id')->firstOrFail();
        $next->ageCategory->update(['bout_seconds' => 180]);

        $component->call('bringOn', $next->id)->assertDispatched('bout-changed');

        $next->refresh();

        expect($next->clock_seconds_left)->toBe(180)
            ->and($next->clock_running)->toBeFalse()
            ->and($next->jazzo_called_at)->toBeNull();
    });

    it('puts the clock back to the top on request', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('publishClock', 30, true)
            ->call('resetClock');

        $bout->refresh();

        expect($bout->clock_seconds_left)->toBe(app(KurashScore::class)->boutSeconds($bout))
            ->and($bout->clock_running)->toBeFalse();
    });
});

describe('what the boards show', function () {
    it('puts jazzo on the wall board in the middle of the contest', function () {
        [$court, $bout] = boutOnMat();

        $half = app(KurashScore::class)->jazzoAt($bout);

        Livewire::test(MatControl::class, ['court' => $court])->call('callJazzo', $half);

        Livewire::test(Scoreboard::class, ['court' => $court->refresh()])
            ->assertSet('court.id', $court->id)
            ->assertSee('JAZZO')
            ->assertSee('Half time · no score', escape: false);
    });

    it('takes jazzo off the board once the contest resumes', function () {
        [$court, $bout] = boutOnMat();

        $half = app(KurashScore::class)->jazzoAt($bout);

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('callJazzo', $half)
            ->call('resume', $half);

        Livewire::test(Scoreboard::class, ['court' => $court->refresh()])->assertDontSee('JAZZO');
    });

    it('names the winner, the country and how it was won', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])->call('score', 'girrom', 'a', 140);

        $bout->refresh();

        Livewire::test(Scoreboard::class, ['court' => $court->refresh()])
            ->assertSee('WINNER')
            ->assertSee($bout->athleteB->fullname)
            ->assertSee($bout->athleteB->noc_name)
            // The reason, not just the name: a hall watching a contest end
            // should not have to work out why it ended.
            ->assertSee(Str::upper(__('Opponent received G')));
    });

    it('names both yakhtak rather than a corner', function () {
        [$court] = boutOnMat();

        Livewire::test(Scoreboard::class, ['court' => $court])
            ->assertSee('Yakhtak Blue')
            ->assertSee('Yakhtak Green')
            ->assertDontSee('Blue corner')
            ->assertDontSee('Green corner');
    });

    it('carries a counter for every call the rules can produce', function () {
        [$court] = boutOnMat();

        $board = Livewire::test(Scoreboard::class, ['court' => $court]);

        foreach (['Girrom', 'Yonbosh', 'Chala', 'Dakki', 'Tanbeh', 'Madichal'] as $word) {
            $board->assertSee($word);
        }
    });

    /**
     * A kurash contest as this system runs it is a single round, and a dot
     * labelled "Period 1" that could never become a Period 2 was furniture
     * claiming to be information.
     */
    it('shows no period marker', function () {
        [$court] = boutOnMat();

        Livewire::test(Scoreboard::class, ['court' => $court])->assertDontSee('Period 1');
    });
});

describe('undoing the call that ended a contest', function () {
    /**
     * The one undo the mat screen could not do before. A decisive call takes
     * the contest off this screen, so "take back the last call" had nothing
     * left to act on.
     */
    it('puts the contest back on the mat and takes back the khalol', function () {
        [$court, $bout] = boutOnMat();

        $component = Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'chala', 'b', 200)
            ->call('score', 'khalol', 'a', 150);

        expect($bout->refresh()->winner_athlete_id)->toBe($bout->athlete_a_id);

        $component->call('reopen');

        $bout->refresh();
        $tally = app(KurashScore::class)->tally($bout, $bout->events()->get());

        expect($bout->winner_athlete_id)->toBeNull()
            ->and($bout->status)->toBe(Bout::STATUS_ON_COURT)
            ->and($tally['a']->khalol)->toBe(0)
            // The board it had a moment earlier, not a blank one.
            ->and($tally['b']->chala)->toBe(1);
    });

    it('takes the old winner back out of the next round', function () {
        [$court, $bout] = boutOnMat(4);

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'khalol', 'a', 150)
            ->call('reopen');

        $next = Bout::findOrFail($bout->refresh()->next_bout_id);
        $slot = "athlete_{$bout->next_bout_slot}_id";

        expect($next->{$slot})->toBeNull();
    });

    it('leaves the record of both the call and its withdrawal', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'girrom', 'a', 150)
            ->call('reopen');

        $bout->refresh();

        expect($bout->events()->where('action', 'result_recorded')->count())->toBe(1)
            ->and($bout->events()->where('action', 'result_cleared')->count())->toBe(1)
            ->and($bout->events()->where('action', KurashScore::ACTION_VOIDED)->count())->toBe(1);
    });
});

describe('the worked examples from the rules', function () {
    /**
     * §10.2. Blue T=1 C=1, Green T=1 C=1, and Blue was warned most recently.
     * Blue loses.
     *
     * Both chala are automatic — each is the consequence of the other's tanbeh
     * — so nothing separates the two until the order of events does.
     */
    it('loses the contest for the athlete warned most recently', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'tanbeh', 'b', 200)   // green warned first: blue given a chala
            ->call('score', 'tanbeh', 'a', 150)   // blue warned last: green given a chala
            ->call('finishOnTime');

        $bout->refresh();
        $tally = app(KurashScore::class)->tally($bout, $bout->events()->get());

        expect($tally['a']->chala)->toBe(1)
            ->and($tally['b']->chala)->toBe(1)
            ->and($tally['a']->tanbeh)->toBe(1)
            ->and($tally['b']->tanbeh)->toBe(1)
            ->and($bout->winner_athlete_id)->toBe($bout->athlete_b_id);
    });

    /**
     * §10.4. Both hold one Chala; blue threw for theirs and green's exists only
     * because blue was warned. Origin ranks, so blue wins — despite carrying
     * the Tanbeh and despite green's Chala arriving later.
     */
    it('prefers a technique-earned chala over a conceded one', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'chala', 'a', 210)    // blue throws for one
            ->call('score', 'tanbeh', 'a', 150)   // and is warned: green given one, later
            ->call('finishOnTime');

        $bout->refresh();
        $tally = app(KurashScore::class)->tally($bout, $bout->events()->get());

        expect($tally['a']->chala)->toBe(1)
            ->and($tally['b']->chala)->toBe(1)
            ->and($tally['a']->earnedChala)->toBe(1)
            ->and($tally['b']->earnedChala)->toBe(0)
            // Origin decides it: blue threw for theirs, so green's later
            // automatic chala does not defeat it.
            ->and($bout->winner_athlete_id)->toBe($bout->athlete_a_id)
            ->and($bout->win_type)->toBe('technique_origin');
    });
});

describe('the priority table', function () {
    /**
     * The whole reason the hierarchy is configuration rather than a chain of
     * comparisons: a federation moving a value between rule editions changes
     * one file, and the winner calculation moves with it.
     */
    it('decides on the configured hierarchy rather than on a hard-coded order', function () {
        [$court, $bout] = boutOnMat();

        // Invert the two scoring values: chala now outranks yonbosh.
        config()->set('kurash.score_priority.chala', 90);

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'yonbosh', 'a', 220)
            ->call('score', 'chala', 'b', 100)
            ->call('finishOnTime');

        expect($bout->refresh()->winner_athlete_id)->toBe($bout->athlete_b_id)
            ->and($bout->win_type)->toBe('higher_appraisal');
    });
});

describe('the winner screen', function () {
    it('turns the whole board blue when blue wins', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])->call('score', 'khalol', 'a', 140);

        Livewire::test(Scoreboard::class, ['court' => $court->refresh()])
            ->assertSet('court.id', $court->id)
            ->assertSee('class="board -won -won-blue"', escape: false);
    });

    it('turns the whole board green when green wins', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])->call('score', 'khalol', 'b', 140);

        Livewire::test(Scoreboard::class, ['court' => $court->refresh()])
            ->assertSee('class="board -won -won-green"', escape: false);
    });

    it('leaves the board unwon while the contest is live', function () {
        [$court] = boutOnMat();

        // The class attribute, not the word: the stylesheet that defines the
        // winner colours is on the page whether or not anybody has won.
        Livewire::test(Scoreboard::class, ['court' => $court])
            ->assertDontSee('class="board -won', escape: false);
    });

    it('states the reason in the words the rules use', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'madichal', 'a', 200)
            ->call('score', 'madichal', 'a', 180)
            ->call('score', 'madichal', 'a', 160);

        Livewire::test(Scoreboard::class, ['court' => $court->refresh()])
            ->assertSee(Str::upper(__('Madichal limit reached')));
    });
});
