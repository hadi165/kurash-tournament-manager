<?php

/**
 * The round-robin table.
 *
 * Everything here is derived from the contests rather than stored beside them,
 * so the tests that matter most are the ones that change a result after the
 * fact: correcting, reopening and voiding must all produce exactly the table
 * that would have existed had the mistake never been made.
 *
 * The tie-break chain is configuration (config/kurash.php, round_robin), and
 * these assert the chain as configured: wins, points, head-to-head, mini
 * table, match time — which ships disabled — and then an explicit referee
 * decision, which is an outcome and not a failure.
 */

use App\Models\Athlete;
use App\Models\Bout;
use App\Models\User;
use App\Models\WeightCategory;
use App\Services\BoutAdvancer;
use App\Services\DrawGenerator;
use App\Services\MedalTable;
use App\Services\RoundRobinStandings;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->actingAs($this->admin);
});

/** A drawn round robin and its athletes, in draw order. */
function roundRobin(int $count, string $label = '-table'): array
{
    [$category] = categoryWithAthletes($count, $label."-{$count}");
    app(DrawGenerator::class)->generate($category);

    return [$category->refresh(), $category->drawnAthletes()->get()->values()];
}

/** Give the contest between these two to the first of them. */
function decideBetween(WeightCategory $category, Athlete $winner, Athlete $loser, string $winType = 'khalol'): Bout
{
    $bout = $category->bouts()->get()->first(
        fn (Bout $b) => in_array($winner->id, [$b->athlete_a_id, $b->athlete_b_id], true)
            && in_array($loser->id, [$b->athlete_a_id, $b->athlete_b_id], true)
    );

    expect($bout)->not->toBeNull("no contest between {$winner->fullname} and {$loser->fullname}");

    return app(BoutAdvancer::class)->recordResult(
        bout: $bout,
        winnerAthleteId: $winner->id,
        winType: $winType,
        user: User::first(),
        source: 'operator',
    );
}

/** @return array<int, array<string, mixed>> keyed by athlete id */
function roundRobinTable(WeightCategory $category): array
{
    $table = app(RoundRobinStandings::class)->forCategory($category->refresh());

    $rows = [];

    foreach ($table['rows'] as $row) {
        $rows[$row['athlete']->id] = $row;
    }

    return $rows;
}

describe('what the table counts', function () {
    it('counts a win as one point and a loss as none', function () {
        [$category, $a] = roundRobin(3, '-points');

        decideBetween($category, $a[0], $a[1]);
        decideBetween($category, $a[0], $a[2]);
        decideBetween($category, $a[1], $a[2]);

        $rows = roundRobinTable($category);

        expect($rows[$a[0]->id]['wins'])->toBe(2)
            ->and($rows[$a[0]->id]['losses'])->toBe(0)
            ->and($rows[$a[0]->id]['points'])->toBe(2)
            ->and($rows[$a[0]->id]['played'])->toBe(2)
            ->and($rows[$a[1]->id]['wins'])->toBe(1)
            ->and($rows[$a[1]->id]['points'])->toBe(1)
            ->and($rows[$a[2]->id]['wins'])->toBe(0)
            ->and($rows[$a[2]->id]['points'])->toBe(0)
            ->and($rows[$a[2]->id]['losses'])->toBe(2);
    });

    it('ranks the field and awards the medals when the group is finished', function () {
        [$category, $a] = roundRobin(3, '-ranked');

        decideBetween($category, $a[0], $a[1]);
        decideBetween($category, $a[0], $a[2]);
        decideBetween($category, $a[1], $a[2]);

        $table = app(RoundRobinStandings::class)->forCategory($category->refresh());
        $rows = roundRobinTable($category);

        expect($table['complete'])->toBeTrue()
            ->and($table['contests'])->toBe(['total' => 3, 'decided' => 3, 'pending' => 0])
            ->and($rows[$a[0]->id]['rank'])->toBe(1)
            ->and($rows[$a[0]->id]['medal'])->toBe('gold')
            ->and($rows[$a[1]->id]['medal'])->toBe('silver')
            ->and($rows[$a[2]->id]['medal'])->toBe('bronze')
            ->and($rows[$a[0]->id]['state'])->toBe(RoundRobinStandings::STATE_FINAL);
    });

    /** Two athletes rank two athletes. There is no third place to award. */
    it('awards no bronze in a group of two', function () {
        [$category, $a] = roundRobin(2, '-pair');

        decideBetween($category, $a[0], $a[1]);

        $rows = roundRobinTable($category);

        expect($rows[$a[0]->id]['medal'])->toBe('gold')
            ->and($rows[$a[1]->id]['medal'])->toBe('silver')
            ->and(collect($rows)->pluck('medal')->filter()->values()->all())->toBe(['gold', 'silver']);
    });

    /**
     * A group still being fought is ranked but not decided, and nobody is
     * given a medal on a table that can still change.
     */
    it('ranks an unfinished group provisionally and awards nothing', function () {
        [$category, $a] = roundRobin(4, '-partial');

        decideBetween($category, $a[0], $a[1]);
        decideBetween($category, $a[0], $a[2]);

        $table = app(RoundRobinStandings::class)->forCategory($category->refresh());
        $rows = roundRobinTable($category);

        expect($table['complete'])->toBeFalse()
            ->and($table['contests']['pending'])->toBe(4)
            ->and($rows[$a[0]->id]['wins'])->toBe(2)
            ->and($rows[$a[0]->id]['state'])->toBe(RoundRobinStandings::STATE_PROVISIONAL)
            ->and(collect($rows)->pluck('medal')->filter())->toBeEmpty()
            ->and(app(MedalTable::class)->forCategory($category)['decided'])->toBeFalse();
    });

    /** Somebody who never stepped out still fought, as far as the table is concerned. */
    it('counts a walkover as a win', function () {
        [$category, $a] = roundRobin(3, '-walkover');

        decideBetween($category, $a[0], $a[1], 'walkover');
        decideBetween($category, $a[0], $a[2]);
        decideBetween($category, $a[1], $a[2]);

        $rows = roundRobinTable($category);

        expect($rows[$a[0]->id]['wins'])->toBe(2)
            ->and($rows[$a[0]->id]['played'])->toBe(2)
            ->and($rows[$a[1]->id]['losses'])->toBe(1);
    });

    /** A disqualification decides a contest like any other result. */
    it('counts a disqualification against the athlete who took it', function () {
        [$category, $a] = roundRobin(3, '-girrom');

        decideBetween($category, $a[0], $a[1], 'girrom');
        decideBetween($category, $a[0], $a[2]);
        decideBetween($category, $a[1], $a[2]);

        $rows = roundRobinTable($category);

        expect($rows[$a[0]->id]['wins'])->toBe(2)
            ->and($rows[$a[1]->id]['losses'])->toBe(1)
            ->and($rows[$a[1]->id]['wins'])->toBe(1);
    });
});

describe('breaking a tie', function () {
    /**
     * Two athletes level on wins are separated by the contest between them,
     * which is the only tie-break the rules state without ambiguity.
     */
    it('separates two tied athletes on their own contest', function () {
        [$category, $a] = roundRobin(4, '-h2h');

        // a1 and a2 finish on two wins each; a3 and a4 on one each.
        decideBetween($category, $a[0], $a[1]);
        decideBetween($category, $a[0], $a[2]);
        decideBetween($category, $a[3], $a[0]);
        decideBetween($category, $a[1], $a[2]);
        decideBetween($category, $a[1], $a[3]);
        decideBetween($category, $a[2], $a[3]);

        $rows = roundRobinTable($category);

        expect($rows[$a[0]->id]['wins'])->toBe(2)
            ->and($rows[$a[1]->id]['wins'])->toBe(2)
            // a1 beat a2, so a1 places above them.
            ->and($rows[$a[0]->id]['rank'])->toBe(1)
            ->and($rows[$a[1]->id]['rank'])->toBe(2)
            // And the same rule settles the pair below.
            ->and($rows[$a[2]->id]['rank'])->toBe(3)
            ->and($rows[$a[3]->id]['rank'])->toBe(4)
            ->and($rows[$a[0]->id]['medal'])->toBe('gold')
            ->and($rows[$a[2]->id]['medal'])->toBe('bronze')
            ->and(app(RoundRobinStandings::class)->forCategory($category)['unresolved'])->toBe([]);
    });

    /**
     * Three athletes in a circle cannot be separated by the contest between
     * them, because there is no such contest — and the mini table over the
     * three is the same circle again. The table says so instead of inventing
     * an order.
     */
    it('asks for a decision when three athletes are in a circle', function () {
        [$category, $a] = roundRobin(3, '-circle');

        decideBetween($category, $a[0], $a[1]);
        decideBetween($category, $a[1], $a[2]);
        decideBetween($category, $a[2], $a[0]);

        $table = app(RoundRobinStandings::class)->forCategory($category->refresh());
        $rows = roundRobinTable($category);

        expect($table['complete'])->toBeTrue()
            ->and($table['unresolved'])->toHaveCount(1)
            ->and($table['unresolved'][0])->toHaveCount(3);

        foreach ($a as $athlete) {
            expect($rows[$athlete->id]['wins'])->toBe(1)
                ->and($rows[$athlete->id]['state'])->toBe(RoundRobinStandings::STATE_NEEDS_DECISION)
                // No medal is handed out over a tie nobody has broken.
                ->and($rows[$athlete->id]['medal'])->toBeNull()
                ->and($rows[$athlete->id]['rank'])->toBe(1);
        }

        expect(app(MedalTable::class)->forCategory($category)['decided'])->toBeFalse();
    });

    /**
     * Three tied on wins, but not in a circle: the mini table over the
     * contests they fought against each other separates them.
     */
    it('separates three tied athletes on a mini table', function () {
        [$category, $a] = roundRobin(4, '-mini');

        // a1, a2, a3 finish on two wins; a4 on none.
        decideBetween($category, $a[0], $a[1]);
        decideBetween($category, $a[0], $a[3]);
        decideBetween($category, $a[1], $a[2]);
        decideBetween($category, $a[1], $a[3]);
        decideBetween($category, $a[2], $a[0]);
        decideBetween($category, $a[2], $a[3]);

        $table = app(RoundRobinStandings::class)->forCategory($category->refresh());
        $rows = roundRobinTable($category);

        // Each of the three beat one of the others, so the mini table is a
        // circle too and the decision is a referee's.
        expect($rows[$a[0]->id]['wins'])->toBe(2)
            ->and($rows[$a[1]->id]['wins'])->toBe(2)
            ->and($rows[$a[2]->id]['wins'])->toBe(2)
            ->and($rows[$a[3]->id]['wins'])->toBe(0)
            ->and($table['unresolved'])->toHaveCount(1)
            ->and($table['unresolved'][0])->toHaveCount(3)
            // The athlete nobody was tied with is still ranked normally.
            ->and($rows[$a[3]->id]['state'])->toBe(RoundRobinStandings::STATE_FINAL)
            ->and($rows[$a[3]->id]['rank'])->toBe(4);
    });

    /** The rule ships off, and a tie that reaches it goes to the referee. */
    it('leaves the match-time tie-break disabled', function () {
        expect(config('kurash.round_robin.match_time'))->toBe('disabled');

        [$category, $a] = roundRobin(3, '-timing');

        decideBetween($category, $a[0], $a[1]);
        decideBetween($category, $a[1], $a[2]);
        decideBetween($category, $a[2], $a[0]);

        // Even with a time recorded against every contest, the tie stands.
        $category->bouts()->update(['decided_seconds_remaining' => 100]);

        expect(app(RoundRobinStandings::class)->forCategory($category->refresh())['unresolved'])
            ->toHaveCount(1);
    });
});

describe('changing a result afterwards', function () {
    /**
     * The reason nothing is stored: a correction has to produce the table that
     * would have existed had the first result never been recorded.
     */
    it('recomputes the table when a result is corrected', function () {
        [$category, $a] = roundRobin(3, '-corrected');

        decideBetween($category, $a[0], $a[1]);
        decideBetween($category, $a[0], $a[2]);
        decideBetween($category, $a[1], $a[2]);

        expect(roundRobinTable($category)[$a[0]->id]['wins'])->toBe(2);

        // The first contest went the other way after all.
        decideBetween($category, $a[1], $a[0]);

        $rows = roundRobinTable($category);

        expect($rows[$a[0]->id]['wins'])->toBe(1)
            ->and($rows[$a[1]->id]['wins'])->toBe(2)
            ->and($rows[$a[1]->id]['rank'])->toBe(1)
            ->and($rows[$a[1]->id]['medal'])->toBe('gold');
    });

    it('drops the medals when a result is reopened', function () {
        [$category, $a] = roundRobin(3, '-reopened');

        decideBetween($category, $a[0], $a[1]);
        decideBetween($category, $a[0], $a[2]);
        $last = decideBetween($category, $a[1], $a[2]);

        expect(roundRobinTable($category)[$a[0]->id]['medal'])->toBe('gold');

        app(BoutAdvancer::class)->clearResult($last, $this->admin);

        $table = app(RoundRobinStandings::class)->forCategory($category->refresh());

        expect($table['complete'])->toBeFalse()
            ->and($table['contests']['pending'])->toBe(1)
            ->and(collect($table['rows'])->pluck('medal')->filter())->toBeEmpty();
    });

    /**
     * The knockout's forward walk must never run over these rows. A round
     * robin contest feeds nothing, so correcting one changes the table and
     * touches no other contest at all.
     */
    it('carries nobody forward when a round-robin result changes', function () {
        [$category, $a] = roundRobin(4, '-noadvance');

        foreach ($category->bouts()->get() as $bout) {
            expect($bout->next_bout_id)->toBeNull();
        }

        decideBetween($category, $a[0], $a[1]);
        $others = $category->bouts()->whereNull('winner_athlete_id')->get();

        // Correcting it leaves every other contest exactly as it was: same
        // athletes, no winner, nobody promoted into a slot.
        decideBetween($category, $a[1], $a[0]);

        foreach ($others as $before) {
            $after = Bout::find($before->id);

            expect($after->athlete_a_id)->toBe($before->athlete_a_id)
                ->and($after->athlete_b_id)->toBe($before->athlete_b_id)
                ->and($after->winner_athlete_id)->toBeNull()
                ->and($after->is_bye)->toBeFalse();
        }
    });

    /** An athlete who leaves the class is not ranked; their opponents keep the wins. */
    it('keeps the opponents of a withdrawn athlete whole', function () {
        [$category, $a] = roundRobin(3, '-withdrawn');

        decideBetween($category, $a[0], $a[1]);
        decideBetween($category, $a[0], $a[2]);
        decideBetween($category, $a[1], $a[2]);

        // Withdrawn after the fact: the draw number is taken back, which is
        // what removes them from the field.
        $a[2]->forceFill(['draw_number' => null])->save();

        $rows = roundRobinTable($category);

        expect($rows)->toHaveCount(2)
            ->and(isset($rows[$a[2]->id]))->toBeFalse()
            ->and($rows[$a[0]->id]['wins'])->toBe(2)
            ->and($rows[$a[1]->id]['wins'])->toBe(1);
    });
});
