<?php

use App\Livewire\Competition\MatControl;
use App\Models\Bout;
use App\Models\Court;
use App\Models\User;
use App\Services\KurashScore;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->viewer = User::factory()->create(['role' => 'viewer']);
});

describe('access', function () {
    it('sends guests to the login page', function () {
        [$court] = boutOnMat();

        $this->get(route('mats.live', $court))->assertRedirect(route('login'));
    });

    it('lets a viewer watch but not score', function () {
        [$court, $bout] = boutOnMat();

        $this->actingAs($this->viewer);

        $this->get(route('mats.live', $court))->assertOk();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'khalol', 'a')
            ->assertForbidden();

        expect($bout->refresh()->winner_athlete_id)->toBeNull();
    });
});

describe('scoring', function () {
    beforeEach(fn () => $this->actingAs($this->admin));

    it('ends the contest on a khalol', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'khalol', 'a', 180);

        $bout->refresh();

        expect($bout->winner_athlete_id)->toBe($bout->athlete_a_id)
            ->and($bout->win_type)->toBe('khalol')
            ->and($bout->status)->toBe(Bout::STATUS_COMPLETED);
    });

    it('makes two yonbosh a khalol', function () {
        [$court, $bout] = boutOnMat();

        $component = Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'yonbosh', 'b', 200);

        // One is not enough — the contest is still live.
        expect($bout->refresh()->winner_athlete_id)->toBeNull();

        $component->call('score', 'yonbosh', 'b', 150);

        expect($bout->refresh()->winner_athlete_id)->toBe($bout->athlete_b_id)
            ->and($bout->win_type)->toBe('yonbosh');
    });

    it('never lets chala add up to a yonbosh', function () {
        [$court, $bout] = boutOnMat();

        $component = Livewire::test(MatControl::class, ['court' => $court]);

        foreach (range(1, 6) as $i) {
            $component->call('score', 'chala', 'a', 200 - $i);
        }

        expect($bout->refresh()->winner_athlete_id)->toBeNull();

        // But one yonbosh to the other side still leads on the clock.
        $component->call('score', 'yonbosh', 'b', 100)->call('finishOnTime');

        expect($bout->refresh()->winner_athlete_id)->toBe($bout->athlete_b_id);
    });

    it('gives the opponent a chala for every tanbeh', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'tanbeh', 'a', 200)
            ->call('score', 'tanbeh', 'a', 190);

        $tally = app(KurashScore::class)->tally($bout->refresh(), $bout->events()->get());

        // Tanbeh accumulates against blue and hands green a chala each time.
        // Chala never adds up, so the contest is still live.
        expect($tally['a']->tanbeh)->toBe(2)
            ->and($tally['b']->chala)->toBe(2)
            ->and($tally['b']->earnedChala)->toBe(0)
            ->and($bout->winner_athlete_id)->toBeNull();
    });

    it('gives the opponent a yonbosh for a dakki', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'dakki', 'a', 200);

        $tally = app(KurashScore::class)->tally($bout->refresh(), $bout->events()->get());

        expect($tally['a']->dakki)->toBe(1)
            ->and($tally['b']->yonbosh)->toBe(1)
            ->and($tally['b']->earnedYonbosh)->toBe(0)
            ->and($bout->winner_athlete_id)->toBeNull();
    });

    /**
     * The rule the whole event log exists for. A dakki supersedes the tanbeh
     * before it, so the chala that tanbeh handed the opponent goes back — but
     * a chala the opponent threw for is untouched, and no counter on the board
     * can tell the two apart. Only the log can.
     */
    it('takes back the automatic chala a dakki supersedes and keeps the earned one', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'chala', 'b', 220)     // green throws for one
            ->call('score', 'tanbeh', 'a', 200)    // blue penalised: green given one
            ->call('score', 'dakki', 'a', 180);    // superseded

        $tally = app(KurashScore::class)->tally($bout->refresh(), $bout->events()->get());

        expect($tally['b']->yonbosh)->toBe(1)
            ->and($tally['b']->chala)->toBe(1)
            ->and($tally['b']->earnedChala)->toBe(1)
            ->and($bout->winner_athlete_id)->toBeNull();
    });

    it('makes two dakki a khalol for the opponent', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'dakki', 'a', 200)
            ->call('score', 'dakki', 'a', 150);

        // Two conceded yonbosh are two yonbosh: however they were reached, they
        // add up to a khalol.
        expect($bout->refresh()->winner_athlete_id)->toBe($bout->athlete_b_id)
            ->and($bout->win_type)->toBe('yonbosh');
    });

    it('awards the contest to the opponent on a girrom', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'girrom', 'a', 200);

        expect($bout->refresh()->winner_athlete_id)->toBe($bout->athlete_b_id)
            ->and($bout->win_type)->toBe('girrom');
    });

    it('lets a girrom beat a lead on scores', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'yonbosh', 'a', 210)
            ->call('score', 'girrom', 'a', 200);

        expect($bout->refresh()->winner_athlete_id)->toBe($bout->athlete_b_id)
            ->and($bout->win_type)->toBe('girrom');
    });

    it('ends the contest on the third madichal and transfers nothing before it', function () {
        [$court, $bout] = boutOnMat();

        $component = Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'madichal', 'a', 200)
            ->call('score', 'madichal', 'a', 190);

        $tally = app(KurashScore::class)->tally($bout->refresh(), $bout->events()->get());

        // Two is a count and nothing else — the opponent has been given
        // nothing, and the contest is still live.
        expect($tally['a']->madichal)->toBe(2)
            ->and($tally['b']->yonbosh)->toBe(0)
            ->and($tally['b']->chala)->toBe(0)
            ->and($bout->winner_athlete_id)->toBeNull();

        $component->call('score', 'madichal', 'a', 120);

        expect($bout->refresh()->winner_athlete_id)->toBe($bout->athlete_b_id)
            ->and($bout->win_type)->toBe('madichal');
    });

    it('does not escalate tanbeh into dakki unless a federation asks for it', function () {
        [$court, $bout] = boutOnMat();

        $component = Livewire::test(MatControl::class, ['court' => $court]);

        foreach (range(1, 5) as $i) {
            $component->call('score', 'tanbeh', 'a', 200 - $i * 10);
        }

        $tally = app(KurashScore::class)->tally($bout->refresh(), $bout->events()->get());

        expect($tally['a']->dakki)->toBe(0)
            ->and($bout->winner_athlete_id)->toBeNull();

        // Turned on, the configured tanbeh becomes a dakki and the opponent is
        // given the yonbosh that comes with it.
        config()->set('kurash.tanbeh_for_dakki', 3);

        [$court2, $bout2] = boutOnMat();
        $second = Livewire::test(MatControl::class, ['court' => $court2]);

        foreach (range(1, 3) as $i) {
            $second->call('score', 'tanbeh', 'a', 200 - $i * 10);
        }

        $tally = app(KurashScore::class)->tally($bout2->refresh(), $bout2->events()->get());

        expect($tally['a']->dakki)->toBe(1)
            ->and($tally['b']->yonbosh)->toBe(1);
    });
});

describe('time', function () {
    beforeEach(fn () => $this->actingAs($this->admin));

    it('decides on yonbosh first, then chala', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'chala', 'a', 190)
            ->call('score', 'chala', 'a', 170)
            ->call('score', 'yonbosh', 'b', 120)
            ->call('finishOnTime');

        expect($bout->refresh()->winner_athlete_id)->toBe($bout->athlete_b_id)
            // The more valuable appraisal, which is the published hierarchy.
            ->and($bout->win_type)->toBe('higher_appraisal');
    });

    it('falls to chala when yonbosh are level', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'yonbosh', 'a', 190)
            ->call('score', 'yonbosh', 'b', 180)
            ->call('score', 'chala', 'a', 60)
            ->call('finishOnTime');

        expect($bout->refresh()->winner_athlete_id)->toBe($bout->athlete_a_id)
            // Level on yonbosh, so the published "more Chala wins" clause is
            // what separated them, and that is what the record says.
            ->and($bout->win_type)->toBe('more_chala');
    });

    /**
     * Level on value and on count, so the published last-appraisal clause
     * decides it. Origin is not consulted at any point.
     */
    it('gives an equal contest to the athlete who scored last', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'chala', 'a', 190)
            ->call('score', 'chala', 'b', 150)
            ->call('finishOnTime');

        expect($bout->refresh()->winner_athlete_id)->toBe($bout->athlete_b_id)
            ->and($bout->win_type)->toBe('last_appraisal');
    });

    /**
     * The software must not invent a winner. A contest with nothing to
     * separate the two at all is the referees' to give, and the screen has to
     * say so.
     */
    it('asks for a referee decision when there is nothing to separate them', function () {
        [$court, $bout] = boutOnMat();

        $component = Livewire::test(MatControl::class, ['court' => $court])
            ->call('finishOnTime')
            ->assertSet('awaitingDecision', true);

        expect($bout->refresh()->winner_athlete_id)->toBeNull();

        $component->call('awardDecision', 'b');

        expect($bout->refresh()->winner_athlete_id)->toBe($bout->athlete_b_id)
            // Renamed from the legacy 'decision' on 2026-08-26 so the value
            // names the rule that produced it. Historical rows keep 'decision'
            // and still render.
            ->and($bout->win_type)->toBe('referee_decision');
    });

    it('decides a scoreless contest by referee decision only', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('finishOnTime')
            ->assertSet('awaitingDecision', true);

        expect($bout->refresh()->winner_athlete_id)->toBeNull();
    });
});

describe('taking a call back', function () {
    beforeEach(fn () => $this->actingAs($this->admin));

    it('annuls the call without deleting the record of it', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'yonbosh', 'a', 200)
            ->call('voidLast');

        $tally = app(KurashScore::class)->tally($bout->refresh(), $bout->events()->get());

        expect($tally['a']->yonbosh)->toBe(0)
            // Both the call and its annulment are still on the record.
            ->and($bout->events()->where('action', KurashScore::ACTION_SCORED)->count())->toBe(1)
            ->and($bout->events()->where('action', KurashScore::ACTION_VOIDED)->count())->toBe(1);
    });

    /**
     * A mistaken second yonbosh would otherwise have already ended the contest,
     * so taking it back has to leave a bout that can still be fought.
     */
    it('takes back only the most recent call that still stands', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'chala', 'a', 200)
            ->call('score', 'yonbosh', 'a', 180)
            ->call('voidLast');

        $tally = app(KurashScore::class)->tally($bout->refresh(), $bout->events()->get());

        expect($tally['a']->yonbosh)->toBe(0)
            ->and($tally['a']->chala)->toBe(1);
    });
});

describe('the mat', function () {
    beforeEach(fn () => $this->actingAs($this->admin));

    it('carries the winner into the next round', function () {
        [$court, $bout] = boutOnMat(4);

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'khalol', 'a', 100);

        $bout->refresh();
        $next = Bout::find($bout->next_bout_id);

        expect($next)->not->toBeNull()
            ->and([$next->athlete_a_id, $next->athlete_b_id])->toContain($bout->winner_athlete_id);
    });

    it('brings the next contest on once the mat is clear', function () {
        [$court, $bout] = boutOnMat(4);

        $component = Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'khalol', 'a', 100);

        $waiting = Bout::where('championship_id', $court->championship_id)
            ->readyToFight()
            ->firstOrFail();

        $component->call('bringOn', $waiting->id);

        expect($waiting->refresh()->court_id)->toBe($court->id)
            ->and($waiting->status)->toBe(Bout::STATUS_ON_COURT);
    });

    it('refuses to bring one on while a contest is still running', function () {
        [$court] = boutOnMat(4);

        $waiting = Bout::where('championship_id', $court->championship_id)
            ->readyToFight()
            ->where('status', '!=', Bout::STATUS_ON_COURT)
            ->firstOrFail();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('bringOn', $waiting->id);

        expect($waiting->refresh()->court_id)->toBeNull();
    });
});

/**
 * What the operator is told about the controls.
 *
 * The mat screen is worked at speed by somebody who is watching the contest
 * rather than the screen, so what each call does to the score is written on
 * the control that makes it rather than left to a rulebook beside the desk.
 */
describe('the controls say what they do', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
    });

    it('names the clock control for what it does, and gives its shortcut', function () {
        [$court] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->assertSee('Pause')
            ->assertSee('Start')
            ->assertSee('Shortcut: space');
    });

    /**
     * Full time is not something anybody should have to press. The clock
     * reaching zero is the event, and the rules decide from there.
     */
    it('no longer asks the operator to end a contest that ran out of time', function () {
        [$court] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->assertDontSee('Time — decide');
    });

    /** The rule each call applies, on the control that applies it. */
    it('says what every call does to the score', function () {
        [$court] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->assertSee('Ends the contest at once')          // khalol
            ->assertSee('make a khalol')                     // yonbosh
            ->assertSee('never add up to a yonbosh')         // chala
            ->assertSee('Hands the opponent a chala')        // tanbeh
            ->assertSee('Hands the opponent a yonbosh')      // dakki
            ->assertSee('the opponent wins')                 // girrom
            ->assertSee('no score passes to the opponent');  // madichal
    });

    /** Read from the rules rather than written into the sentence twice. */
    it('takes the thresholds it quotes from the rules themselves', function () {
        config(['kurash.yonbosh_for_khalol' => 3, 'kurash.madichal_for_defeat' => 4]);

        [$court] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->assertSee('3 of these make a khalol')
            ->assertSee('4 of these ends the contest');
    });
});

/**
 * The record a result is validated from.
 *
 * A referee reviewing a decision is asking about a contest that has already
 * left the mat — which is exactly when the log used to disappear, because it
 * was rendered from the bout being scored rather than from the bout in view.
 */
describe('the call log after the contest', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
    });

    it('keeps the whole exchange on screen once the contest is decided', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'chala', 'a', 200)
            ->call('score', 'tanbeh', 'b', 180)
            ->call('score', 'yonbosh', 'a', 120)
            ->call('score', 'khalol', 'a', 90)   // ends it
            ->assertSee('Call log')
            // Lower case in the markup: the table capitalises with CSS, so
            // asserting the rendered word would be asserting the stylesheet.
            ->assertSee('chala')
            ->assertSee('tanbeh')
            ->assertSee('yonbosh')
            ->assertSee('khalol');

        expect($bout->refresh()->isDecided())->toBeTrue();
    });

    /** Every call in the order it was made, with the clock it was made at. */
    it('lists them in the order they happened', function () {
        [$court] = boutOnMat();

        $log = Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'chala', 'a', 200)
            ->call('score', 'girrom', 'b', 150)  // ends it
            ->viewData('log');

        expect($log)->not->toBeEmpty()
            ->and($log->pluck('sequence_number')->all())
            ->toBe($log->pluck('sequence_number')->sortDesc()->values()->all());
    });

    /**
     * A tanbeh hands the opponent a chala, and the log has to show both — the
     * call and what the rules did with it — or a protest cannot be settled.
     */
    it('shows the award a penalty caused, not only the penalty', function () {
        [$court] = boutOnMat();

        $log = Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'tanbeh', 'a', 200)
            ->call('score', 'khalol', 'b', 100)
            ->viewData('log');

        $origins = $log->pluck('origin')->filter()->unique()->all();

        expect($origins)->toContain('AUTO_FROM_T')
            ->and($origins)->toContain('TECHNIQUE');
    });

    it('has nothing to show on a mat that has run nothing', function () {
        $court = Court::factory()->create();

        Livewire::test(MatControl::class, ['court' => $court])
            ->assertDontSee('Call log');
    });
});

/** The mat's own queue, and the one press that puts a contest on. */
describe('the mat schedule', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
    });

    it('says which division and class each waiting contest belongs to', function () {
        [$court, $bout] = boutOnMat();
        $bout->update(['court_id' => null, 'status' => Bout::STATUS_SCHEDULED]);

        Livewire::test(MatControl::class, ['court' => $court])
            ->assertSee($bout->weightCategory->ageCategory->name)
            ->assertSee($bout->weightCategory->label);
    });

    /** A queue that stops without saying so reads as the end of the queue. */
    it('counts what it could not fit on screen', function () {
        [$court, $bout] = boutOnMat();
        $championship = $court->championship;

        $bout->update(['court_id' => null, 'status' => Bout::STATUS_SCHEDULED]);

        expect(Livewire::test(MatControl::class, ['court' => $court])->viewData('stillWaiting'))
            ->toBe(0);
    });

    it('marks whether a waiting contest is already this mat\'s', function () {
        [$court, $bout] = boutOnMat();
        $bout->update(['status' => Bout::STATUS_SCHEDULED]);   // still assigned here

        Livewire::test(MatControl::class, ['court' => $court])
            ->assertSee('This mat');
    });
});
