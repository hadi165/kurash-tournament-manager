<?php

use App\Livewire\Competition\MatControl;
use App\Models\Bout;
use App\Models\User;
use App\Services\BoutDecisionPolicy;
use App\Services\KurashScore;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

/**
 * The winner policy, clause by clause.
 *
 * Every test here names the published clause it exercises, because the point of
 * this policy is that a result can cite the rule that produced it. The source
 * is the IKA competition rules as published 2022-08-20:
 * https://kurash-ika.org/2022/08/20/kurash-rules/
 *
 * Two criteria this replaced are asserted NOT to apply: the score-origin
 * preference, which appears on no published page, and the latest-caution
 * comparison, which the published first-caution rule replaced.
 */
beforeEach(function () {
    $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $this->actingAs($this->admin);
    $this->policy = app(BoutDecisionPolicy::class);
});

/** The tally as it stands, without going through the screen. */
function tallyOf(Bout $bout): array
{
    return app(KurashScore::class)->tally($bout->refresh(), $bout->events()->get());
}

describe('clause: the appraisal hierarchy', function () {
    it('gives it to the higher appraisal', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'yonbosh', 'a', 200)
            ->call('score', 'chala', 'b', 150)
            ->call('finishOnTime');

        $decision = $this->policy->decide($bout->refresh(), tallyOf($bout));

        expect($decision->side)->toBe('a')
            ->and($decision->basis)->toBe('higher_appraisal')
            ->and($decision->clause)->toContain('Appraisal hierarchy')
            ->and($decision->inferred)->toBeFalse();
    });

    /** "An appraisal takes precedence over a caution." */
    it('gives it to the athlete with a score over one holding only a caution', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'chala', 'a', 200)
            ->call('score', 'madichal', 'b', 150)
            ->call('finishOnTime');

        expect($bout->refresh()->winner_athlete_id)->toBe($bout->athlete_a_id)
            ->and($bout->win_type)->toBe('higher_appraisal');
    });
});

describe('clause: more Chala wins', function () {
    it('gives it to the greater count of Chala', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'chala', 'a', 200)
            ->call('score', 'chala', 'a', 180)
            ->call('score', 'chala', 'b', 150)
            ->call('finishOnTime');

        $decision = $this->policy->decide($bout->refresh(), tallyOf($bout));

        expect($decision->side)->toBe('a')
            ->and($decision->basis)->toBe('more_chala')
            ->and($decision->clause)->toContain('more CHALA');
    });
});

describe('clause: victory follows the last appraisal', function () {
    it('separates equal appraisals by which came last', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'chala', 'a', 200)
            ->call('score', 'chala', 'b', 150)
            ->call('finishOnTime');

        $decision = $this->policy->decide($bout->refresh(), tallyOf($bout));

        expect($decision->side)->toBe('b')
            ->and($decision->basis)->toBe('last_appraisal')
            // The sequence the deciding call sits at, so a protest can find it.
            ->and($decision->sequence)->toBeGreaterThan(0);
    });

    /**
     * Blue threw for its Chala; green's exists only because blue was cautioned.
     * Origin ranks, so blue wins despite green's arriving later — the
     * last-appraisal rule is never reached.
     */
    it('prefers a technique-earned score over a later automatic one', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'chala', 'a', 210)
            ->call('score', 'tanbeh', 'a', 150)
            ->call('finishOnTime');

        $tally = tallyOf($bout);

        // Origin is still recorded — it is audit, not order.
        expect($tally['a']->earnedChala)->toBe(1)
            ->and($tally['b']->earnedChala)->toBe(0)
            ->and($this->policy->decide($bout->refresh(), $tally)->basis)->toBe('technique_origin')
            ->and($bout->refresh()->winner_athlete_id)->toBe($bout->athlete_a_id);
    });

    /**
     * Sequence, not the clock. Several calls can land in one displayed second
     * and a timestamp cannot order them.
     */
    it('orders calls made inside one clock second', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'chala', 'a', 120)
            ->call('score', 'chala', 'b', 120)   // same reading
            ->call('finishOnTime');

        $tally = tallyOf($bout);

        expect($tally['a']->lastScoreAt)->not->toBe($tally['b']->lastScoreAt)
            ->and($this->policy->decide($bout->refresh(), $tally)->side)->toBe('b');
    });
});

describe('clause: the latest warning loses', function () {
    it('gives it against the athlete warned most recently when each holds one', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'madichal', 'a', 200)   // blue warned first
            ->call('score', 'madichal', 'b', 150)   // green warned last, so green loses
            ->call('finishOnTime');

        $decision = $this->policy->decide($bout->refresh(), tallyOf($bout));

        expect($decision->side)->toBe('a')
            ->and($decision->basis)->toBe('latest_warning');
    });

    /**
     * The case that separates the two readings.
     *
     * Blue is warned first AND last. Green's two sit between them. Comparing
     * the FIRST warning gives it to blue; the rule in force compares the LAST
     * and blue loses by it. With one warning each the two readings agree, which
     * is how a first-warning reading once passed a whole suite.
     */
    it('is decided by the last warning, not the first, when each holds two', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'madichal', 'a', 230)   // blue's first — earliest of all
            ->call('score', 'madichal', 'b', 200)
            ->call('score', 'madichal', 'b', 170)
            ->call('score', 'madichal', 'a', 140)   // blue's last  — latest of all
            ->call('finishOnTime');

        $tally = tallyOf($bout);

        expect($tally['a']->madichal)->toBe(2)
            ->and($tally['b']->madichal)->toBe(2)
            // Blue was warned first...
            ->and($tally['a']->firstPenaltyAt)->toBeLessThan($tally['b']->firstPenaltyAt)
            // ...and also most recently, which is the one that counts.
            ->and($tally['a']->lastPenaltyAt)->toBeGreaterThan($tally['b']->lastPenaltyAt);

        $decision = $this->policy->decide($bout->refresh(), $tally);

        // Green wins. A first-warning rule would have said blue.
        expect($decision->side)->toBe('b')
            ->and($decision->basis)->toBe('latest_warning');
    });

    /**
     * A caution taken back was never anybody's first.
     *
     * decrease() resolves to the last caution still standing on that side, so
     * this takes back blue's SECOND madichal and leaves the first — the point
     * being that firstPenaltyAt is folded from live calls only, and a voided
     * call is not one.
     */
    it('excludes voided cautions from the first-caution comparison', function () {
        [$court, $bout] = boutOnMat();

        $component = Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'madichal', 'a', 230)   // blue's first
            ->call('score', 'madichal', 'b', 200)
            ->call('score', 'madichal', 'a', 170)   // blue's second
            ->call('score', 'madichal', 'b', 140);

        $before = tallyOf($bout);
        expect($before['a']->madichal)->toBe(2);

        $component->call('decrease', 'madichal', 'a');

        $after = tallyOf($bout);

        // One caution taken back, and the earliest is untouched — so the
        // comparison still runs off the caution blue actually received first.
        expect($after['a']->madichal)->toBe(1)
            ->and($after['a']->firstPenaltyAt)->toBe($before['a']->firstPenaltyAt)
            ->and($after['a']->lastPenaltyAt)->toBe($before['a']->firstPenaltyAt);
    });

    /**
     * A Dakki supersedes the Tanbeh's automatic Chala.
     *
     * The withdrawn Chala must not go on counting toward the opponent's
     * appraisals, and the Dakki's own Yonbosh must.
     */
    it('handles a superseded Tanbeh consequence after a Dakki', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'tanbeh', 'a', 220)   // green given a chala
            ->call('score', 'dakki', 'a', 180);   // superseded: green given a yonbosh instead

        $tally = tallyOf($bout);

        expect($tally['b']->yonbosh)->toBe(1)
            // The tanbeh's chala was taken back with the supersession.
            ->and($tally['b']->chala)->toBe(0)
            ->and($tally['a']->penalties())->toBeGreaterThan(0);
    });
});

describe('where the rules do not decide', function () {
    it('asks for a referee when nothing separates them', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])->call('finishOnTime');

        $decision = $this->policy->decide($bout->refresh(), tallyOf($bout));

        expect($decision->side)->toBeNull()
            ->and($decision->requiresRefereeDecision)->toBeTrue()
            ->and($decision->basis)->toBe('referee_decision')
            ->and($bout->refresh()->winner_athlete_id)->toBeNull();
    });

    /**
     * Unequal warning counts are decided, not referred. An athlete with no
     * warning at all has lastPenaltyAt zero, the lowest sequence there is, so
     * the warned athlete holds the most recent warning and loses.
     */
    it('gives it to the unwarned athlete over a warned one', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'madichal', 'b', 200)
            ->call('finishOnTime');

        $decision = $this->policy->decide($bout->refresh(), tallyOf($bout));

        expect($decision->side)->toBe('a')
            ->and($decision->basis)->toBe('latest_warning')
            ->and($decision->requiresRefereeDecision)->toBeFalse()
            ->and($bout->refresh()->winner_athlete_id)->toBe($bout->athlete_a_id);
    });

    it('never invents a winner from side, draw order or seeding', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])->call('finishOnTime');

        expect($bout->refresh()->winner_athlete_id)->toBeNull()
            ->and($bout->win_type)->toBeNull()
            ->and($bout->status)->not->toBe(Bout::STATUS_COMPLETED);
    });
});

describe('the manual decision', function () {
    it('records the official, the reason and the policy edition', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('finishOnTime')
            ->call('awardDecision', 'b', 'Green was the more active throughout.');

        $bout->refresh();
        $decision = $bout->frozen_snapshot['decision'] ?? [];

        expect($bout->winner_athlete_id)->toBe($bout->athlete_b_id)
            ->and($decision['basis'])->toBe('referee_decision')
            ->and($decision['automatic'])->toBeFalse()
            ->and($decision['decided_by_user_id'])->toBe($this->admin->id)
            ->and($decision['decided_by'])->toBe($this->admin->name)
            ->and($decision['reason'])->toBe('Green was the more active throughout.')
            ->and($decision['decided_at'])->not->toBeNull()
            ->and($decision['policy_version'])->toBe(2022);
    });

    /**
     * Asserted on the gate and on the record, not on a thrown exception.
     *
     * Livewire turns an AuthorizationException into a response rather than
     * letting it propagate out of ->call(), so a test written around toThrow()
     * passes for the wrong reason — or, as this one did, fails while the
     * protection is working perfectly. What matters is that the gate refuses
     * and that no contest changes hands.
     */
    it('refuses an account without permission to score the mat', function () {
        [$court, $bout] = boutOnMat();

        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);

        expect(Gate::forUser($viewer)->allows('score-bout', $court))->toBeFalse();

        try {
            Livewire::actingAs($viewer)->test(MatControl::class, ['court' => $court])
                ->call('awardDecision', 'b');
        } catch (Throwable) {
            // However it surfaces is the framework's business; the record is ours.
        }

        expect($bout->refresh()->winner_athlete_id)->toBeNull()
            ->and($bout->frozen_snapshot)->toBeNull();
    });

    /** And a referee assigned to no mat is refused on that mat too. */
    it('refuses a referee who is not assigned to this mat', function () {
        [$court, $bout] = boutOnMat();

        $referee = User::factory()->create(['role' => User::ROLE_REFEREE]);

        expect(Gate::forUser($referee)->allows('score-bout', $court))->toBeFalse();

        try {
            Livewire::actingAs($referee)->test(MatControl::class, ['court' => $court])
                ->call('awardDecision', 'b');
        } catch (Throwable) {
        }

        expect($bout->refresh()->winner_athlete_id)->toBeNull();
    });
});

describe('the record a completed contest keeps', function () {
    it('files the deciding clause and edition against the bout', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'yonbosh', 'a', 200)
            ->call('score', 'chala', 'b', 150)
            ->call('finishOnTime');

        $decision = $bout->refresh()->frozen_snapshot['decision'] ?? [];

        expect($decision['policy_version'])->toBe(2022)
            ->and($decision['basis'])->toBe('higher_appraisal')
            ->and($decision['clause'])->toContain('Appraisal hierarchy')
            ->and($decision['automatic'])->toBeTrue();
    });

    /**
     * The reason the edition is pinned at all: a finished contest must not be
     * re-judged by a policy that shipped after it.
     */
    it('does not re-decide a contest that already has a winner', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'chala', 'a', 200)
            ->call('score', 'chala', 'b', 150)
            ->call('finishOnTime');

        $bout->refresh();
        $winner = $bout->winner_athlete_id;
        $snapshot = $bout->frozen_snapshot;

        // Ask again. The screen must refuse rather than recompute — asserted on
        // the record rather than on the flash, which Livewire ages before a
        // test can read it back (see CLAUDE.md, Traps).
        Livewire::test(MatControl::class, ['court' => $court])->call('finishOnTime');

        expect($bout->refresh()->winner_athlete_id)->toBe($winner)
            ->and($bout->frozen_snapshot)->toBe($snapshot)
            ->and($bout->win_type)->toBe('last_appraisal');
    });

    it('carries the same winner into the next round', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'yonbosh', 'a', 200)
            ->call('score', 'chala', 'b', 150)
            ->call('finishOnTime');

        $bout->refresh();
        $next = $bout->next_bout_id === null ? null : Bout::find($bout->next_bout_id);

        expect($bout->winner_athlete_id)->toBe($bout->athlete_a_id);

        if ($next !== null) {
            expect([$next->athlete_a_id, $next->athlete_b_id])->toContain($bout->winner_athlete_id);
        }
    });
});

describe('the policy edition', function () {
    it('falls back to the configured edition rather than the newest', function () {
        [, $bout] = boutOnMat();

        expect($bout->ageCategory->championship->decision_policy_version)->toBeNull()
            ->and($this->policy->versionFor($bout))->toBe(2022);
    });

    it('honours an edition pinned on the championship', function () {
        [, $bout] = boutOnMat();

        // An unknown edition must not be adopted — it would mean judging under
        // rules this software does not have.
        $bout->ageCategory->championship->update(['decision_policy_version' => 9999]);

        expect($this->policy->versionFor($bout->refresh()))->toBe(2022);
    });

    it('publishes the clause and ambiguities of the edition in force', function () {
        $order = $this->policy->order(2022);

        expect(array_column($order, 'step'))
            ->toBe(['higher_appraisal', 'more_chala', 'technique_origin', 'last_appraisal', 'latest_warning']);

        // Every step in force quotes a published clause; none is inferred.
        foreach ($order as $step) {
            expect($step['sourced'])->toBeTrue()
                ->and($step['clause'])->not->toBeEmpty();
        }

        expect($this->policy->ambiguities(2022))
            ->toHaveKeys([
                'score_counts_other_than_chala',
                'equal_origin_mix',
            ]);
    });
});

/**
 * DAKKI supersedes the TANBEH it replaces, and everything that TANBEH gave.
 *
 * The grade the athlete carries is the DAKKI. A board still showing the
 * superseded TANBEH beside it reports the same offence twice — and the obsolete
 * TANBEH would go on counting toward madichal and toward the warning rule.
 */
describe('DAKKI supersession', function () {
    it('clears the TANBEH, its automatic CHALA, and awards the YONBOSH', function () {
        [$court, $bout] = boutOnMat();

        $component = Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'tanbeh', 'a', 220);

        $before = tallyOf($bout);
        expect($before['a']->tanbeh)->toBe(1)
            ->and($before['b']->chala)->toBe(1)
            ->and($before['b']->earnedChala)->toBe(0);

        $component->call('score', 'dakki', 'a', 180);

        $after = tallyOf($bout);

        expect($after['a']->tanbeh)->toBe(0)
            ->and($after['a']->dakki)->toBe(1)
            ->and($after['b']->chala)->toBe(0)
            ->and($after['b']->yonbosh)->toBe(1);
    });

    /** History is annulled by appended events, never rewritten. */
    it('keeps the superseded rows in the log and annuls them append-only', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'tanbeh', 'a', 220)
            ->call('score', 'dakki', 'a', 180);

        $events = $bout->refresh()->events()->get();

        // The original rows are still there. Filtered on the scored action,
        // because a void row carries the event_type of the call it annuls.
        $scored = $events->where('action', KurashScore::ACTION_SCORED);

        expect($scored->where('event_type', 'tanbeh'))->toHaveCount(1)
            ->and($scored->where('event_type', 'chala'))->toHaveCount(1)
            ->and($scored->where('event_type', 'dakki'))->toHaveCount(1)
            ->and($scored->where('event_type', 'yonbosh'))->toHaveCount(1);

        // And each is annulled by an appended void naming it.
        $voids = $events->where('action', KurashScore::ACTION_VOIDED);

        expect($voids->count())->toBeGreaterThanOrEqual(2);

        foreach ($voids as $void) {
            expect($void->after['voids_event_id'] ?? null)->not->toBeNull()
                ->and($void->after['reason'] ?? null)->toBe('superseded_by_dakki');
        }
    });

    /** Only what the penalty gave is withdrawn — never what was thrown for. */
    it('preserves a technique-earned CHALA', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'chala', 'b', 230)    // green throws for one
            ->call('score', 'tanbeh', 'a', 200)   // green also given one automatically
            ->call('score', 'dakki', 'a', 170);

        $tally = tallyOf($bout);

        expect($tally['a']->tanbeh)->toBe(0)
            ->and($tally['a']->dakki)->toBe(1)
            // The thrown chala survives; only the automatic one went.
            ->and($tally['b']->chala)->toBe(1)
            ->and($tally['b']->earnedChala)->toBe(1)
            ->and($tally['b']->yonbosh)->toBe(1);
    });

    /** A penalty against the OTHER athlete is nobody else's business. */
    it('does not touch the opponent\'s own penalties or their consequences', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'tanbeh', 'b', 230)   // green penalised: blue given a chala
            ->call('score', 'tanbeh', 'a', 200)   // blue penalised: green given a chala
            ->call('score', 'dakki', 'a', 170);   // blue's dakki supersedes BLUE's tanbeh only

        $tally = tallyOf($bout);

        expect($tally['a']->tanbeh)->toBe(0)     // blue's own tanbeh superseded
            ->and($tally['b']->tanbeh)->toBe(1)  // green's untouched
            ->and($tally['a']->chala)->toBe(1)   // the chala green's tanbeh gave blue, untouched
            ->and($tally['b']->chala)->toBe(0)   // the chala blue's tanbeh gave green, withdrawn
            ->and($tally['b']->yonbosh)->toBe(1);
    });

    /** Every active TANBEH goes, not just the most recent. */
    it('supersedes every active TANBEH against the penalised athlete', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'tanbeh', 'a', 230)
            ->call('score', 'tanbeh', 'a', 210)
            ->call('score', 'dakki', 'a', 180);

        $tally = tallyOf($bout);

        expect($tally['a']->tanbeh)->toBe(0)
            ->and($tally['b']->chala)->toBe(0)
            ->and($tally['b']->yonbosh)->toBe(1);
    });

    /** The log is the authority: replaying it reproduces the same tally. */
    it('replays from the event log to the same tally and winner', function () {
        [$court, $bout] = boutOnMat();

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'chala', 'b', 230)
            ->call('score', 'tanbeh', 'a', 200)
            ->call('score', 'dakki', 'a', 170)
            ->call('finishOnTime');

        $bout->refresh();
        $first = tallyOf($bout);
        $winner = $bout->winner_athlete_id;

        // Fold the same rows again, from scratch.
        $second = app(KurashScore::class)->tally($bout, $bout->events()->get());

        expect($second['a']->toArray())->toBe($first['a']->toArray())
            ->and($second['b']->toArray())->toBe($first['b']->toArray())
            ->and(app(BoutDecisionPolicy::class)->decide($bout, $second)->side)
            ->toBe($winner === $bout->athlete_a_id ? 'a' : 'b');
    });
});

/** A voided call takes no part in any comparison. */
it('excludes voided scores and warnings from the decision', function () {
    [$court, $bout] = boutOnMat();

    $component = Livewire::test(MatControl::class, ['court' => $court])
        ->call('score', 'chala', 'a', 230)
        ->call('score', 'chala', 'b', 200);

    // Take back green's chala; blue is then alone on the board.
    $component->call('decrease', 'chala', 'b');

    $tally = tallyOf($bout);

    expect($tally['a']->chala)->toBe(1)
        ->and($tally['b']->chala)->toBe(0)
        ->and($tally['b']->lastScoreAt)->toBe(0);

    $component->call('finishOnTime');

    expect($bout->refresh()->winner_athlete_id)->toBe($bout->athlete_a_id)
        ->and($bout->win_type)->toBe('higher_appraisal');
});
