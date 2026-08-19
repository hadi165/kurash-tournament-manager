<?php

use App\Livewire\Competition\MatControl;
use App\Models\Bout;
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
            ->call('score', 'halal', 'a')
            ->assertForbidden();

        expect($bout->refresh()->winner_athlete_id)->toBeNull();
    });
});

describe('scoring', function () {
    beforeEach(fn () => $this->actingAs($this->admin));

    it('ends the contest on a halal', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'halal', 'a', 180);

        $bout->refresh();

        expect($bout->winner_athlete_id)->toBe($bout->athlete_a_id)
            ->and($bout->win_type)->toBe('halal')
            ->and($bout->status)->toBe(Bout::STATUS_COMPLETED);
    });

    it('makes two yonbosh a halal', function () {
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

    it('awards the contest against an athlete who collects dakki', function () {
        [$court, $bout] = boutOnMat();

        $component = Livewire::test(MatControl::class, ['court' => $court]);

        foreach (range(1, config('kurash.tanbeh_for_dakki')) as $i) {
            $component->call('score', 'tanbeh', 'a', 200 - $i * 10);
        }

        $bout->refresh();

        expect($bout->winner_athlete_id)->toBe($bout->athlete_b_id)
            ->and($bout->win_type)->toBe('dakki');
    });

    it('lets dakki beat a lead on scores', function () {
        [$court, $bout] = boutOnMat();

        $component = Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'yonbosh', 'a', 210);

        foreach (range(1, config('kurash.tanbeh_for_dakki')) as $i) {
            $component->call('score', 'tanbeh', 'a', 200 - $i * 10);
        }

        expect($bout->refresh()->winner_athlete_id)->toBe($bout->athlete_b_id);
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
            ->and($bout->win_type)->toBe('yonbosh');
    });

    it('falls to chala when yonbosh are level', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'yonbosh', 'a', 190)
            ->call('score', 'yonbosh', 'b', 180)
            ->call('score', 'chala', 'a', 60)
            ->call('finishOnTime');

        expect($bout->refresh()->winner_athlete_id)->toBe($bout->athlete_a_id)
            ->and($bout->win_type)->toBe('yonbosh');
    });

    /**
     * The software must not invent a winner. A contest level on both scores is
     * the referees' to give, and the screen has to say so.
     */
    it('asks for a referee decision when the scores are level', function () {
        [$court, $bout] = boutOnMat();

        $component = Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'chala', 'a', 190)
            ->call('score', 'chala', 'b', 150)
            ->call('finishOnTime')
            ->assertSet('awaitingDecision', true);

        expect($bout->refresh()->winner_athlete_id)->toBeNull();

        $component->call('awardDecision', 'b');

        expect($bout->refresh()->winner_athlete_id)->toBe($bout->athlete_b_id)
            ->and($bout->win_type)->toBe('decision');
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
            ->call('score', 'halal', 'a', 100);

        $bout->refresh();
        $next = Bout::find($bout->next_bout_id);

        expect($next)->not->toBeNull()
            ->and([$next->athlete_a_id, $next->athlete_b_id])->toContain($bout->winner_athlete_id);
    });

    it('brings the next contest on once the mat is clear', function () {
        [$court, $bout] = boutOnMat(4);

        $component = Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'halal', 'a', 100);

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
