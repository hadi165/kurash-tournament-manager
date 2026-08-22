<?php

use App\Models\Bout;
use App\Models\BoutEvent;
use App\Models\User;
use App\Services\BoutAdvancer;
use App\Services\BracketGenerator;
use App\Services\MedalTable;
use App\Support\BracketSeeding;

beforeEach(function () {
    $this->generator = app(BracketGenerator::class);
    $this->advancer = app(BoutAdvancer::class);
    $this->medals = app(MedalTable::class);
});

describe('a tournament runs to completion', function () {
    /**
     * The acceptance test the PHP suite already proves for 8 and 3 athletes.
     * With "lower draw number always wins", standard seeding means the podium
     * is fully determined: seed 1 gold, seed 2 silver, seeds 3 and 4 bronze.
     */
    it('produces the podium standard seeding implies', function (int $athleteCount) {
        [$category, $athletes] = categoryWithAthletes($athleteCount);
        $this->generator->generate($category);

        runTournament($category);

        $podium = $this->medals->forCategory($category);

        expect($podium['decided'])->toBeTrue()
            ->and($podium['gold']->id)->toBe($athletes[1]->id)
            ->and($podium['silver']->id)->toBe($athletes[2]->id);

        // Bronze goes to the semi-final losers, which under this rule are
        // seeds 3 and 4 whenever the bracket is big enough to have semis.
        $bronzeDraws = collect($podium['bronze'])->pluck('draw_number')->sort()->values()->all();

        expect($bronzeDraws)->toBe($athleteCount >= 4 ? [3, 4] : [3]);
    })->with([3, 4, 5, 6, 7, 8, 12, 16, 24, 32, 48, 64]);

    it('leaves no bout undecided and no slot empty', function (int $athleteCount) {
        [$category] = categoryWithAthletes($athleteCount);
        $this->generator->generate($category);
        runTournament($category);

        expect($category->bouts()->whereNull('winner_athlete_id')->count())->toBe(0);

        // Every bout beyond round one was filled by advancement.
        $unfilled = $category->bouts()
            ->where('round', '>', 1)
            ->where(fn ($q) => $q->whereNull('athlete_a_id')->orWhereNull('athlete_b_id'))
            ->where('is_bye', false)
            ->count();

        expect($unfilled)->toBe(0);
    })->with([2, 3, 5, 8, 11, 16, 23, 32, 64]);

    it('fights exactly one contested bout per eliminated athlete', function (int $athleteCount) {
        [$category] = categoryWithAthletes($athleteCount);
        $result = $this->generator->generate($category);

        $fought = runTournament($category);

        // Every bout eliminates one athlete, so a field of N needs N-1
        // eliminations. Byes account for the difference.
        expect($fought + $result['byes'])->toBe(BracketSeeding::size($athleteCount) - 1)
            ->and($fought)->toBe($athleteCount - 1);
    })->with([2, 3, 5, 7, 8, 9, 16, 17, 32]);

    it('records medals per NOC across the whole championship', function () {
        [$categoryA, $athletesA] = categoryWithAthletes(4, '-66');
        $this->generator->generate($categoryA);

        // Force a known nationality split: seed 1 UZB, everyone else KAZ.
        $athletesA[1]->update(['noc_code' => 'UZB']);
        foreach ([2, 3, 4] as $draw) {
            $athletesA[$draw]->update(['noc_code' => 'KAZ']);
        }

        runTournament($categoryA);

        $standings = $this->medals->standings($categoryA->ageCategory->championship_id);

        expect($standings->first()['noc_code'])->toBe('UZB')
            ->and($standings->first()['gold'])->toBe(1)
            ->and($standings->firstWhere('noc_code', 'KAZ')['silver'])->toBe(1)
            ->and($standings->firstWhere('noc_code', 'KAZ')['bronze'])->toBe(2);
    });
});

describe('advancement', function () {
    it('carries a winner into the next round immediately', function () {
        [$category, $athletes] = categoryWithAthletes(8);
        $this->generator->generate($category);

        $opener = $category->bouts()->where('round', 1)->where('position_in_round', 0)->first();
        $semi = Bout::find($opener->next_bout_id);

        expect($semi->athlete_a_id)->toBeNull();

        $this->advancer->recordResult($opener, $athletes[1]->id);

        expect($semi->refresh()->athlete_a_id)->toBe($athletes[1]->id);
    });

    it('refuses a winner who is not in the bout', function () {
        [$category, $athletes] = categoryWithAthletes(8);
        $this->generator->generate($category);

        $opener = $category->bouts()->where('round', 1)->where('position_in_round', 0)->first();

        expect(fn () => $this->advancer->recordResult($opener, $athletes[5]->id))
            ->toThrow(InvalidArgumentException::class, 'is not in bout');
    });

    it('refuses a bout whose other side is still empty', function () {
        // 5 athletes in an 8-slot bracket: seed 1 takes a bye into the semi,
        // where the opponent slot waits on the undecided 4 v 5 bout. Seed 1 is
        // seated, so this exercises the readiness check rather than the
        // "athlete is not in this bout" check.
        [$category, $athletes] = categoryWithAthletes(5);
        $this->generator->generate($category);

        $semi = $category->bouts()
            ->where('round', 2)
            ->get()
            ->first(fn (Bout $b) => $b->athlete_a_id === $athletes[1]->id && $b->athlete_b_id === null);

        expect($semi)->not->toBeNull();

        expect(fn () => $this->advancer->recordResult($semi, $athletes[1]->id))
            ->toThrow(InvalidArgumentException::class, 'not ready');
    });

    it('stops at the final without looking for a next bout', function () {
        [$category, $athletes] = categoryWithAthletes(4);
        $this->generator->generate($category);
        runTournament($category);

        $final = $category->bouts()->whereNull('next_bout_id')->first();

        expect($final->winner_athlete_id)->toBe($athletes[1]->id)
            ->and($final->round)->toBe(2);
    });
});

describe('idempotency and corrections', function () {
    /**
     * The scoreboard vendor may retry. The old code had no protection: a
     * repeated payload would re-run the whole write path.
     */
    it('ignores a repeated identical result', function () {
        [$category, $athletes] = categoryWithAthletes(8);
        $this->generator->generate($category);

        $opener = $category->bouts()->where('round', 1)->where('position_in_round', 0)->first();

        $this->advancer->recordResult($opener, $athletes[1]->id, ['score_a' => 10, 'score_b' => 0]);
        $eventsAfterFirst = BoutEvent::count();

        $this->advancer->recordResult($opener->refresh(), $athletes[1]->id, ['score_a' => 10, 'score_b' => 0]);

        expect(BoutEvent::count())->toBe($eventsAfterFirst);
    });

    it('unwinds the bracket when a result is corrected', function () {
        [$category, $athletes] = categoryWithAthletes(8);
        $this->generator->generate($category);
        runTournament($category);

        // Seed 1 won everything. Reverse their opening bout in favour of seed 8.
        $opener = $category->bouts()->where('round', 1)->where('position_in_round', 0)->first();
        $semi = Bout::find($opener->next_bout_id);
        $final = Bout::find($semi->next_bout_id);

        expect($final->winner_athlete_id)->toBe($athletes[1]->id);

        $this->advancer->recordResult($opener, $athletes[8]->id, ['score_a' => 0, 'score_b' => 10]);

        // Seed 1 must no longer be standing anywhere downstream.
        expect($semi->refresh()->athlete_a_id)->toBe($athletes[8]->id)
            ->and($semi->winner_athlete_id)->toBeNull()
            ->and($final->refresh()->winner_athlete_id)->toBeNull()
            ->and($final->athlete_a_id)->toBeNull();

        expect($this->medals->forCategory($category)['decided'])->toBeFalse();
    });

    it('lets the tournament be replayed after a correction', function () {
        [$category, $athletes] = categoryWithAthletes(8);
        $this->generator->generate($category);
        runTournament($category);

        $opener = $category->bouts()->where('round', 1)->where('position_in_round', 0)->first();
        $this->advancer->recordResult($opener, $athletes[8]->id);

        runTournament($category);

        $podium = $this->medals->forCategory($category);

        // Seed 8 now occupies seed 1's path, and the lower-draw rule sends
        // seed 2 through to take gold.
        expect($podium['decided'])->toBeTrue()
            ->and($podium['gold']->id)->toBe($athletes[2]->id);
    });
});

describe('audit trail', function () {
    it('logs who recorded each result and what changed', function () {
        $user = User::factory()->create();
        [$category, $athletes] = categoryWithAthletes(4);
        $this->generator->generate($category);

        $opener = $category->bouts()->where('round', 1)->where('position_in_round', 0)->first();
        $this->advancer->recordResult($opener, $athletes[1]->id, ['score_a' => 10, 'score_b' => 0], 'khalol', $user, 'scoreboard');

        $event = BoutEvent::where('bout_id', $opener->id)->where('action', 'result_recorded')->first();

        expect($event)->not->toBeNull()
            ->and($event->user_id)->toBe($user->id)
            ->and($event->source)->toBe('scoreboard')
            ->and($event->before['winner_athlete_id'])->toBeNull()
            ->and($event->after['winner_athlete_id'])->toBe($athletes[1]->id);
    });

    it('distinguishes a correction from an original result', function () {
        [$category, $athletes] = categoryWithAthletes(4);
        $this->generator->generate($category);

        $opener = $category->bouts()->where('round', 1)->where('position_in_round', 0)->first();
        $this->advancer->recordResult($opener, $athletes[1]->id);
        $this->advancer->recordResult($opener->refresh(), $athletes[4]->id);

        expect(BoutEvent::where('bout_id', $opener->id)->where('action', 'result_corrected')->exists())->toBeTrue();
    });

    it('freezes who the athletes were when the result was recorded', function () {
        [$category, $athletes] = categoryWithAthletes(4);
        $this->generator->generate($category);

        $opener = $category->bouts()->where('round', 1)->where('position_in_round', 0)->first();
        $this->advancer->recordResult($opener, $athletes[1]->id);

        // Correcting a misspelled name must not rewrite a decided bout.
        $athletes[1]->update(['fullname' => 'Corrected Name']);

        expect($opener->refresh()->frozen_snapshot['athlete_a']['fullname'])->toBe('Athlete 1');
    });
});
