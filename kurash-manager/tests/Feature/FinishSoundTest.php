<?php

use App\Livewire\Competition\MatControl;
use App\Livewire\Competition\Scoreboard;
use App\Models\Bout;
use App\Models\Court;
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
        ->assertSee('match-end01.wav');
});

it('carries the bell on the mat screen', function () {
    [$court] = boutOnMat();

    Livewire::test(MatControl::class, ['court' => $court])
        ->assertSee('finishBell')
        ->assertSee('match-end01.wav');
});

/**
 * The files are on the venue's own machine. At match time there may be no
 * route off the hall's network, and a buzzer that has to be fetched is a
 * buzzer that does not sound.
 */
it('ships every sound rather than fetching it', function () {
    $choices = config('scoreboard.finish_sounds');

    expect($choices)->not->toBeEmpty();

    foreach (array_keys($choices) as $path) {
        expect(is_file(public_path($path)))->toBeTrue()
            ->and($path)->not->toStartWith('http');
    }

    expect(array_keys($choices))->toContain(config('scoreboard.finish_sound'));
});

/**
 * The board renders its own shell rather than the admin one, so it has to ask
 * for the script that defines the bell. It did not, and the board rendered
 * perfectly and stayed silent — the one failure nobody sees coming, which is
 * why it is asserted on the page rather than only in the component.
 */
it('loads the behaviour the board needs on the standalone board', function () {
    [$court] = boutOnMat();

    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
    $script = $manifest['resources/js/app.js']['file'];

    $this->get(route('display.scoreboard', $court))
        ->assertOk()
        ->assertSee($script, false)
        ->assertSee('finishBell');
});

describe('choosing a mat\'s buzzer', function () {
    /**
     * Held per mat, because two mats running side by side want to be told
     * apart by ear.
     */
    it('lets a mat be given its own', function () {
        [$court] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->set('finishSound', 'sounds/match-end02.wav');

        expect($court->refresh()->finishSound())->toBe('sounds/match-end02.wav');
    });

    it('sounds it on that mat\'s wall board too', function () {
        [$court] = boutOnMat();
        $court->update(['finish_sound' => 'sounds/match-end02.wav']);

        Livewire::test(Scoreboard::class, ['court' => $court->refresh()])
            ->assertSee('match-end02.wav')
            ->assertDontSee('match-end01.wav');
    });

    it('leaves another mat on its own', function () {
        [$mine] = boutOnMat();
        $theirs = Court::factory()->create(['championship_id' => $mine->championship_id]);

        Livewire::test(MatControl::class, ['court' => $mine])
            ->set('finishSound', 'sounds/match-end02.wav');

        expect($theirs->refresh()->finishSound())->toBe('sounds/match-end01.wav');
    });

    /** This ends up in a src attribute, so nothing but an offered file. */
    it('refuses a file that is not one of the offered ones', function () {
        [$court] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->set('finishSound', '../../.env');

        expect($court->refresh()->finish_sound)->toBeNull();
    });

    /** On unless somebody says otherwise, which is what it always did. */
    it('sounds by default', function () {
        [$court] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->assertSet('finishSoundEnabled', true);

        expect($court->finishSound())->toBe('sounds/match-end01.wav');
    });

    it('can be switched off for a mat', function () {
        [$court] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->set('finishSoundEnabled', false);

        expect($court->refresh()->finishSound())->toBeNull();
    });

    /**
     * Switched off renders no player and no prompt to enable one: a silent
     * player is not something anybody can tell from a broken one.
     */
    it('renders nothing at all on a mat switched off', function () {
        [$court] = boutOnMat();
        $court->update(['finish_sound_enabled' => false]);

        Livewire::test(Scoreboard::class, ['court' => $court->refresh()])
            ->assertDontSee('finishBell')
            ->assertDontSee('Tap anywhere');
    });

    /** Switching it off is not the same as forgetting which one it was. */
    it('keeps the chosen file while it is switched off', function () {
        [$court] = boutOnMat();
        $court->update(['finish_sound' => 'sounds/match-end02.wav']);

        Livewire::test(MatControl::class, ['court' => $court->refresh()])
            ->set('finishSoundEnabled', false)
            ->assertSet('finishSound', 'sounds/match-end02.wav')
            ->set('finishSoundEnabled', true);

        expect($court->refresh()->finishSound())->toBe('sounds/match-end02.wav');
    });

    it('leaves another mat sounding when one is switched off', function () {
        [$mine] = boutOnMat();
        $theirs = Court::factory()->create(['championship_id' => $mine->championship_id]);

        Livewire::test(MatControl::class, ['court' => $mine])
            ->set('finishSoundEnabled', false);

        expect($theirs->refresh()->finishSound())->toBe('sounds/match-end01.wav');
    });

    /**
     * A file dropped from the venue leaves the mats that chose it pointing at
     * nothing, so they fall back rather than falling silent.
     */
    it('falls back when the chosen file is no longer offered', function () {
        [$court] = boutOnMat();
        $court->forceFill(['finish_sound' => 'sounds/retired.wav'])->save();

        expect($court->refresh()->finishSound())->toBe('sounds/match-end01.wav');
    });
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

/**
 * The two attributes the buzzer actually watches. It reads them off the
 * element on its own clock rather than through a reactive expression, so if
 * they stop being rendered the sound stops with them and nothing else breaks
 * to say so.
 */
it('states what is on screen where the buzzer can read it', function () {
    [$court, $bout] = boutOnMat();

    $html = Livewire::test(Scoreboard::class, ['court' => $court])->html();

    expect($html)->toContain('data-bout="'.$bout->id.'"')
        ->and($html)->toContain('data-decided="0"');

    $bout->update([
        'status' => Bout::STATUS_COMPLETED,
        'winner_athlete_id' => $bout->athlete_a_id,
        'win_type' => 'khalol',
    ]);

    expect(Livewire::test(Scoreboard::class, ['court' => $court->refresh()])->html())
        ->toContain('data-decided="1"');
});

/**
 * Fetched from whatever served the page. asset() builds from APP_URL, which is
 * the address the server was started on: a board opened at 127.0.0.1 would be
 * sent to the LAN address for its buzzer, across an origin, from a host that
 * machine may not be able to reach at all.
 */
it('asks for the sound from the host the board came from', function () {
    [$court] = boutOnMat();

    expect(Livewire::test(Scoreboard::class, ['court' => $court])->html())
        ->toContain("src: '\\/sounds\\/match-end01.wav'");
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
    config(['scoreboard.finish_sound' => '', 'scoreboard.finish_sounds' => []]);

    [$court] = boutOnMat();

    Livewire::test(Scoreboard::class, ['court' => $court])
        ->assertDontSee('finishBell');
});
