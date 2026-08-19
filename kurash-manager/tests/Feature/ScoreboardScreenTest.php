<?php

use App\Livewire\Competition\MatControl;
use App\Livewire\Competition\Scoreboard;
use App\Models\Bout;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->viewer = User::factory()->create(['role' => 'viewer']);
});

describe('access', function () {
    it('is closed to anonymous viewers unless public displays are on', function () {
        [$court] = boutOnMat();

        config()->set('display.public', false);
        $this->get(route('display.scoreboard', $court))->assertRedirect(route('login'));

        config()->set('display.public', true);
        $this->get(route('display.scoreboard', $court))->assertOk();
    });

    it('opens for a signed-in viewer', function () {
        [$court] = boutOnMat();

        $this->actingAs($this->viewer);

        $this->get(route('display.scoreboard', $court))->assertOk();
    });
});

describe('what it shows', function () {
    beforeEach(fn () => $this->actingAs($this->viewer));

    it('names both athletes and their tallies', function () {
        [$court, $bout] = boutOnMat();

        $this->actingAs($this->admin);
        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'yonbosh', 'a', 200)
            ->call('score', 'chala', 'b', 180);

        $component = Livewire::test(Scoreboard::class, ['court' => $court->refresh()]);

        $component->assertSee($bout->athleteA->fullname)
            ->assertSee($bout->athleteB->fullname);

        $tally = $component->viewData('tally');

        expect($tally['a']->yonbosh)->toBe(1)
            ->and($tally['b']->chala)->toBe(1);
    });

    it('names the bout this mat runs next', function () {
        [$court, $bout] = boutOnMat();

        // A second contest in the same draw, assigned here and given a number.
        $next = Bout::where('championship_id', $court->championship_id)
            ->readyToFight()
            ->whereKeyNot($bout->getKey())
            ->firstOrFail();

        $next->update(['court_id' => $court->id, 'fight_number' => 44, 'status' => Bout::STATUS_SCHEDULED]);

        Livewire::test(Scoreboard::class, ['court' => $court->refresh()])
            ->assertSee('No.44')
            ->assertSee($next->athleteA->fullname);
    });

    it('leaves the next strip off when the mat has nothing else to run', function () {
        [$court] = boutOnMat();

        Livewire::test(Scoreboard::class, ['court' => $court->refresh()])
            ->assertDontSee('No.44');
    });

    it('does not promise a bout that belongs to another mat', function () {
        [$court, $bout] = boutOnMat();

        $elsewhere = Bout::where('championship_id', $court->championship_id)
            ->readyToFight()
            ->whereKeyNot($bout->getKey())
            ->firstOrFail();

        $elsewhere->update(['court_id' => null, 'fight_number' => 44, 'status' => Bout::STATUS_SCHEDULED]);

        expect(Livewire::test(Scoreboard::class, ['court' => $court->refresh()])->viewData('nextBout'))->toBeNull();
    });

    it('says so when the mat is empty', function () {
        [$court, $bout] = boutOnMat();
        $bout->update(['court_id' => null, 'status' => Bout::STATUS_PENDING]);

        Livewire::test(Scoreboard::class, ['court' => $court->refresh()])
            ->assertSee('No contest on this mat');
    });

    /**
     * The hall wants to see who won for a moment after the arm goes up, not an
     * empty board the instant it does.
     */
    it('holds the finished contest on screen', function () {
        [$court, $bout] = boutOnMat();

        $this->actingAs($this->admin);
        Livewire::test(MatControl::class, ['court' => $court])->call('score', 'halal', 'a', 150);

        $component = Livewire::test(Scoreboard::class, ['court' => $court->refresh()]);

        expect($component->viewData('winner')?->id)->toBe($bout->refresh()->winner_athlete_id);

        // The board says WINNER across the middle columns rather than naming
        // the win type, which is how the federation's own scoreboard reads.
        $component->assertSee('WINNER');
    });
});

/**
 * The clock started as browser state on the mat screen. It is shared now, and
 * these are what say so — a scoreboard that cannot read the clock is a wall
 * decoration.
 */
describe('the shared clock', function () {
    it('reads what the mat screen published', function () {
        [$court, $bout] = boutOnMat();

        $this->actingAs($this->admin);

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('publishClock', 132, false);

        expect(Livewire::test(Scoreboard::class, ['court' => $court->refresh()])
            ->viewData('secondsLeft'))->toBe(132);

        expect($bout->refresh()->clock_running)->toBeFalse();
    });

    it('counts down from the anchor while it is running', function () {
        [$court, $bout] = boutOnMat();

        $bout->update([
            'clock_seconds_left' => 100,
            'clock_running' => true,
            'clock_updated_at' => now()->subSeconds(10),
        ]);

        expect(Livewire::test(Scoreboard::class, ['court' => $court->refresh()])
            ->viewData('secondsLeft'))->toBe(90);
    });

    it('holds still while it is stopped', function () {
        [$court, $bout] = boutOnMat();

        $bout->update([
            'clock_seconds_left' => 100,
            'clock_running' => false,
            'clock_updated_at' => now()->subSeconds(30),
        ]);

        expect(Livewire::test(Scoreboard::class, ['court' => $court->refresh()])
            ->viewData('secondsLeft'))->toBe(100);
    });

    it('never runs past zero', function () {
        [$court, $bout] = boutOnMat();

        $bout->update([
            'clock_seconds_left' => 5,
            'clock_running' => true,
            'clock_updated_at' => now()->subSeconds(600),
        ]);

        expect(Livewire::test(Scoreboard::class, ['court' => $court->refresh()])
            ->viewData('secondsLeft'))->toBe(0);
    });

    it('re-anchors on a scoring call so the wall cannot drift', function () {
        [$court, $bout] = boutOnMat();

        $this->actingAs($this->admin);

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'chala', 'a', 164);

        expect($bout->refresh()->clock_seconds_left)->toBe(164);
    });

    it('stops the clock once the contest is decided', function () {
        [$court, $bout] = boutOnMat();

        $bout->update(['clock_seconds_left' => 90, 'clock_running' => true, 'clock_updated_at' => now()]);

        $this->actingAs($this->admin);
        Livewire::test(MatControl::class, ['court' => $court])->call('score', 'halal', 'b', 88);

        expect(Livewire::test(Scoreboard::class, ['court' => $court->refresh()])
            ->viewData('clockRunning'))->toBeFalse();
    });

    it('refuses a clock reading from someone who cannot score', function () {
        [$court, $bout] = boutOnMat();

        $this->actingAs($this->viewer);

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('publishClock', 60, true)
            ->assertForbidden();

        expect($bout->refresh()->clock_seconds_left)->toBeNull();
    });
});
