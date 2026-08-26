<?php

use App\Livewire\Competition\MatControl;
use App\Models\Bout;
use App\Models\User;
use App\Services\KurashScore;
use Livewire\Livewire;

/**
 * When jazzo may be offered.
 *
 * The rule: half the contest gone and NOTHING on the board — no active score
 * and no active penalty, on either side.
 *
 * The bug these pin: eligibility asked only whether anybody had scored, so a
 * contest in which an athlete was carrying a madichal reached the halfway mark
 * looking empty and was offered jazzo. Madichal is the case that showed it —
 * it transfers nothing, so it leaves no score behind. Girrom and dakki were
 * hidden from the same fault only because they end the contest or hand over a
 * score on the way.
 */
beforeEach(function () {
    $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $this->actingAs($this->admin);
    $this->scorer = app(KurashScore::class);
});

/** Is jazzo due, asked of the domain at a reading past the halfway mark. */
function jazzoDue(Bout $bout): bool
{
    $scorer = app(KurashScore::class);
    $bout->refresh();
    $tally = $scorer->tally($bout, $bout->events()->get());

    // One second inside the threshold, so only eligibility is under test.
    return $scorer->jazzoIsDue($bout, $tally, $scorer->jazzoAt($bout) - 1);
}

it('is due on an empty board at the threshold', function () {
    [, $bout] = boutOnMat();

    expect(jazzoDue($bout))->toBeTrue();
});

it('is not due before the threshold, however empty the board', function () {
    [, $bout] = boutOnMat();

    $tally = $this->scorer->tally($bout, $bout->events()->get());

    expect($this->scorer->jazzoIsDue($bout, $tally, $this->scorer->jazzoAt($bout) + 1))->toBeFalse()
        // The board half of the rule still says yes; only the clock says no.
        ->and($this->scorer->jazzoBoardIsClear($bout, $tally))->toBeTrue();
});

describe('an active score blocks it', function () {
    it('blocks on a technique-earned score', function (string $call) {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])->call('score', $call, 'a', 200);

        expect(jazzoDue($bout))->toBeFalse();
    })->with(['chala', 'yonbosh']);

    /** The chala an opponent's tanbeh hands over is still a score on the board. */
    it('blocks on an automatically generated score', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])->call('score', 'tanbeh', 'b', 200);

        $tally = $this->scorer->tally($bout->refresh(), $bout->events()->get());

        expect($tally['a']->chala)->toBe(1)
            ->and($tally['a']->earnedChala)->toBe(0)
            ->and(jazzoDue($bout))->toBeFalse();
    });
});

describe('an active penalty blocks it', function () {
    /**
     * Every supported penalty, including the two that leave no score behind.
     * Madichal is the regression: it transfers nothing, so under the old
     * score-only test the board read as empty.
     */
    it('blocks on any active penalty', function (string $call) {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])->call('score', $call, 'a', 200);

        expect(jazzoDue($bout))->toBeFalse();
    })->with(['tanbeh', 'dakki', 'girrom', 'madichal']);

    /** Named on its own, because it is the one the old rule let through. */
    it('blocks on a madichal, which puts no score on the board', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])->call('score', 'madichal', 'a', 200);

        $tally = $this->scorer->tally($bout->refresh(), $bout->events()->get());

        // Nobody has scored — which is exactly why the old test passed it.
        expect($tally['a']->hasScored())->toBeFalse()
            ->and($tally['b']->hasScored())->toBeFalse()
            // And the board is not clear, because a penalty is on it.
            ->and($tally['a']->hasAnyActiveCall())->toBeTrue()
            ->and(jazzoDue($bout))->toBeFalse();
    });

    it('blocks on a penalty against either side', function (string $side) {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])->call('score', 'madichal', $side, 200);

        expect(jazzoDue($bout))->toBeFalse();
    })->with(['a', 'b']);
});

describe('annulled records do not block it', function () {
    it('is due again once the only score is taken back', function () {
        [$court, $bout] = boutOnMat();

        $component = Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'chala', 'a', 200);

        expect(jazzoDue($bout))->toBeFalse();

        $component->call('decrease', 'chala', 'a');

        expect(jazzoDue($bout))->toBeTrue();
    });

    /**
     * Voiding the tanbeh takes its automatic chala with it through
     * parent_event_id, so the board is genuinely blank again — not merely free
     * of penalties.
     */
    it('is due again once the only penalty is taken back, consequence and all', function () {
        [$court, $bout] = boutOnMat();

        $component = Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'tanbeh', 'a', 200);

        expect(jazzoDue($bout))->toBeFalse();

        $component->call('decrease', 'tanbeh', 'a');

        $tally = $this->scorer->tally($bout->refresh(), $bout->events()->get());

        expect($tally['a']->tanbeh)->toBe(0)
            ->and($tally['b']->chala)->toBe(0)
            ->and(jazzoDue($bout))->toBeTrue();
    });

    it('is due again once a madichal is taken back', function () {
        [$court, $bout] = boutOnMat();

        $component = Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'madichal', 'a', 200);

        expect(jazzoDue($bout))->toBeFalse();

        $component->call('decrease', 'madichal', 'a');

        expect(jazzoDue($bout))->toBeTrue();
    });
});

describe('a mixed board', function () {
    it('stays blocked while any active record remains', function () {
        [$court, $bout] = boutOnMat();

        $component = Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'madichal', 'a', 220)
            ->call('score', 'madichal', 'a', 200);

        expect(jazzoDue($bout))->toBeFalse();

        // One of the two taken back — the other still stands.
        $component->call('decrease', 'madichal', 'a');

        $tally = $this->scorer->tally($bout->refresh(), $bout->events()->get());

        expect($tally['a']->madichal)->toBe(1)
            ->and(jazzoDue($bout))->toBeFalse();

        // Now the board really is empty.
        $component->call('decrease', 'madichal', 'a');

        expect(jazzoDue($bout))->toBeTrue();
    });

    it('stays blocked when a score is annulled but a penalty is not', function () {
        [$court, $bout] = boutOnMat();

        $component = Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'chala', 'a', 220)
            ->call('score', 'madichal', 'b', 200);

        $component->call('decrease', 'chala', 'a');

        $tally = $this->scorer->tally($bout->refresh(), $bout->events()->get());

        expect($tally['a']->hasScored())->toBeFalse()
            ->and($tally['b']->madichal)->toBe(1)
            ->and(jazzoDue($bout))->toBeFalse();
    });
});

describe('the mat screen', function () {
    /** The UI must not offer what the domain refuses. */
    it('does not offer jazzo while a madichal stands', function () {
        [$court, $bout] = boutOnMat();

        $component = Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'madichal', 'a', 200);

        expect($component->viewData('jazzoBoardIsClear'))->toBeFalse();

        // And refuses it even if the browser asks anyway.
        $component->call('callJazzo', 10);

        expect($bout->refresh()->jazzo_called_at)->toBeNull();
    });

    it('offers and records jazzo on an empty board', function () {
        [$court, $bout] = boutOnMat();

        $component = Livewire::test(MatControl::class, ['court' => $court]);

        expect($component->viewData('jazzoBoardIsClear'))->toBeTrue();

        $component->call('callJazzo', 10);

        $bout->refresh();

        expect($bout->jazzo_called_at)->not->toBeNull()
            ->and($bout->isInJazzo())->toBeTrue()
            // Still audited as before.
            ->and($bout->events()->where('action', KurashScore::ACTION_JAZZO)->count())->toBe(1);
    });

    /** Already called, so not due again — unchanged behaviour. */
    it('does not offer jazzo twice', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])->call('callJazzo', 10);

        expect(jazzoDue($bout))->toBeFalse();
    });
});
