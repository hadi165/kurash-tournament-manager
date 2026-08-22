<?php

use App\Livewire\Competition\MatControl;
use App\Livewire\Competition\Scoreboard;
use App\Models\Bout;
use App\Models\User;
use Livewire\Livewire;

/**
 * The buzzer a contest ends on.
 *
 * What can be proved here is that both screens carry the bell, that it knows
 * which contest is on them and whether it has been won, and that it is quiet
 * when a championship has turned it off. Whether a browser actually sounds it
 * is the browser's own business, and the part of this that lives in Alpine.
 */
beforeEach(function () {
    $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $this->actingAs($this->admin);
});

/** Both screens hear it, from one component reading one state. */
it('carries the bell on the wall board', function () {
    [$court, $bout] = boutOnMat();

    Livewire::test(Scoreboard::class, ['court' => $court])
        ->assertSee('finishBell')
        ->assertSee('match-end.wav');
});

it('carries the bell on the mat screen', function () {
    [$court] = boutOnMat();

    Livewire::test(MatControl::class, ['court' => $court])
        ->assertSee('finishBell')
        ->assertSee('match-end.wav');
});

/**
 * The file is on the venue's own machine. At match time there may be no route
 * off the hall's network, and a buzzer that has to be fetched is a buzzer that
 * does not sound.
 */
it('ships the sound rather than fetching it', function () {
    $configured = config('scoreboard.finish_sound');

    expect(is_file(public_path($configured)))->toBeTrue()
        ->and($configured)->not->toStartWith('http');
});

/**
 * A board opened while a result is already up has not just seen a contest end.
 * It is handed the decided bout as already sounded, which is what keeps it
 * quiet until something changes.
 */
it('marks a contest already decided as already sounded', function () {
    [$court, $bout] = boutOnMat();

    $bout->update([
        'status' => Bout::STATUS_COMPLETED,
        'winner_athlete_id' => $bout->athlete_a_id,
        'win_type' => 'khalol',
    ]);

    $html = Livewire::test(Scoreboard::class, ['court' => $court->refresh()])->html();

    expect($html)->toContain('decided: true')
        ->and($html)->toContain('bout: '.$bout->id);
});

it('leaves a live contest unsounded', function () {
    [$court, $bout] = boutOnMat();

    $html = Livewire::test(Scoreboard::class, ['court' => $court])->html();

    expect($html)->toContain('decided: false')
        ->and($html)->toContain('bout: '.$bout->id);
});

/** A mat with nothing on it has no contest to ring for. */
it('has no contest to ring for on an empty mat', function () {
    [$court, $bout] = boutOnMat();
    $bout->update(['court_id' => null]);

    expect(Livewire::test(Scoreboard::class, ['court' => $court->refresh()])->html())
        ->toContain('bout: null');
});

/** Turned off by configuration, and then nothing is rendered at all. */
it('is silent when the championship runs without one', function () {
    config(['scoreboard.finish_sound' => '']);

    [$court] = boutOnMat();

    Livewire::test(Scoreboard::class, ['court' => $court])
        ->assertDontSee('finishBell');
});
