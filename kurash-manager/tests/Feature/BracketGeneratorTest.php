<?php

use App\Models\Bout;
use App\Services\BracketGenerator;
use App\Services\BracketHasResultsException;
use App\Support\BracketSeeding;

beforeEach(function () {
    $this->generator = app(BracketGenerator::class);
});

describe('bracket structure', function () {
    /**
     * The case the old system got wrong: bracket size was derived by three
     * different ladders, so 2 and 3 athletes produced a four-slot bracket with
     * a phantom bout. Every count from 2 to 64 is checked here.
     */
    it('builds a complete, correctly sized bracket', function (int $athleteCount) {
        [$category] = categoryWithAthletes($athleteCount);

        $result = $this->generator->generate($category);

        $size = BracketSeeding::size($athleteCount);
        $expectedBouts = $size - 1;   // a knockout bracket always has size-1 bouts

        expect($result['size'])->toBe($size)
            ->and($result['rounds'])->toBe(BracketSeeding::totalRounds($size))
            ->and($category->bouts()->count())->toBe($expectedBouts);

        // Exactly one final, and it is the only bout with no onward link.
        expect($category->bouts()->whereNull('next_bout_id')->count())->toBe(1);
    })->with(range(2, 64));

    it('gives every non-final bout a forward link and a slot', function (int $athleteCount) {
        [$category] = categoryWithAthletes($athleteCount);
        $this->generator->generate($category);

        $unlinked = $category->bouts()
            ->whereNotNull('next_bout_id')
            ->whereNull('next_bout_slot')
            ->count();

        expect($unlinked)->toBe(0);

        // Every next-round slot is claimed by exactly one feeder.
        $claims = $category->bouts()
            ->whereNotNull('next_bout_id')
            ->get()
            ->groupBy(fn (Bout $b) => $b->next_bout_id.$b->next_bout_slot);

        expect($claims->filter(fn ($group) => $group->count() > 1))->toBeEmpty();
    })->with([2, 3, 5, 8, 11, 16, 23, 32, 47, 64]);

    it('seats each athlete exactly once in round one', function (int $athleteCount) {
        [$category, $athletes] = categoryWithAthletes($athleteCount);
        $this->generator->generate($category);

        $seated = $category->bouts()->where('round', 1)->get()
            ->flatMap(fn (Bout $b) => [$b->athlete_a_id, $b->athlete_b_id])
            ->filter()
            ->values();

        expect($seated->sort()->values()->all())
            ->toBe($athletes->pluck('id')->sort()->values()->all());
    })->with([2, 3, 5, 8, 13, 16, 31, 32]);

    it('pairs the top seed against the bottom seed', function () {
        [$category, $athletes] = categoryWithAthletes(8);
        $this->generator->generate($category);

        $opener = $category->bouts()->where('round', 1)->where('position_in_round', 0)->first();

        expect($opener->athlete_a_id)->toBe($athletes[1]->id)
            ->and($opener->athlete_b_id)->toBe($athletes[8]->id);
    });

    it('refuses a category with no drawn athletes', function () {
        [$category] = categoryWithAthletes(0);
        $this->generator->generate($category);
    })->throws(RuntimeException::class, 'No athletes with a draw number');

    it('refuses a single-entrant category rather than inventing a bout', function () {
        [$category] = categoryWithAthletes(1);
        $this->generator->generate($category);
    })->throws(RuntimeException::class, 'only one athlete');
});

describe('byes', function () {
    it('creates exactly the byes the field implies', function (int $athleteCount, int $expectedByes) {
        [$category] = categoryWithAthletes($athleteCount);

        $result = $this->generator->generate($category);

        expect($result['byes'])->toBe($expectedByes)
            ->and($category->bouts()->where('is_bye', true)->count())->toBe($expectedByes);
    })->with([
        'full bracket of 8 has none' => [8, 0],
        'full bracket of 16 has none' => [16, 0],
        '3 in a 4-slot bracket' => [3, 1],
        '5 in an 8-slot bracket' => [5, 3],
        '6 in an 8-slot bracket' => [6, 2],
        '7 in an 8-slot bracket' => [7, 1],
        '9 in a 16-slot bracket' => [9, 7],
        '15 in a 16-slot bracket' => [15, 1],
    ]);

    it('advances every bye winner before anyone fights', function (int $athleteCount) {
        [$category] = categoryWithAthletes($athleteCount);
        $this->generator->generate($category);

        foreach ($category->bouts()->where('is_bye', true)->get() as $bye) {
            expect($bye->winner_athlete_id)->not->toBeNull();

            if ($bye->next_bout_id === null) {
                continue;
            }

            $next = Bout::find($bye->next_bout_id);
            expect($next->{"athlete_{$bye->next_bout_slot}_id"})->toBe($bye->winner_athlete_id);
        }
    })->with([3, 5, 6, 7, 9, 11, 15, 17, 33]);

    it('never marks a bout waiting on an undecided feeder as a bye', function () {
        // 5 athletes in an 8-slot bracket. Seeds 1, 2 and 3 get byes; the
        // 4 v 5 bout is real. The semi-final above it has one athlete present
        // and one still to be decided — that is pending, not a walkover.
        [$category] = categoryWithAthletes(5);
        $this->generator->generate($category);

        $waiting = $category->bouts()
            ->where('round', 2)
            ->get()
            ->first(fn (Bout $b) => $b->athlete_a_id === null || $b->athlete_b_id === null);

        expect($waiting)->not->toBeNull()
            ->and($waiting->is_bye)->toBeFalse()
            ->and($waiting->winner_athlete_id)->toBeNull()
            ->and($waiting->status)->toBe(Bout::STATUS_PENDING);
    });

    it('gives the top seed a walkover when the field is barely over a power of two', function () {
        [$category, $athletes] = categoryWithAthletes(9);
        $this->generator->generate($category);

        $opener = $category->bouts()->where('round', 1)->where('position_in_round', 0)->first();

        // Seed 1 v seed 16 in a 16-slot bracket: 16 does not exist.
        expect($opener->is_bye)->toBeTrue()
            ->and($opener->winner_athlete_id)->toBe($athletes[1]->id);
    });
});

describe('regeneration', function () {
    it('is safe to run twice before any result', function () {
        [$category] = categoryWithAthletes(8);

        $this->generator->generate($category);
        $first = $category->bouts()->pluck('id')->all();

        $this->generator->generate($category);
        $second = $category->bouts()->pluck('id')->all();

        expect($category->bouts()->count())->toBe(7)
            ->and(array_intersect($first, $second))->toBeEmpty();
    });

    it('refuses to discard decided bouts without confirmation', function () {
        [$category] = categoryWithAthletes(8);
        $this->generator->generate($category);
        runTournament($category);

        expect(fn () => $this->generator->generate($category))
            ->toThrow(BracketHasResultsException::class);

        // And nothing was destroyed on the way to refusing.
        expect($category->bouts()->whereNotNull('winner_athlete_id')->count())->toBe(7);
    });

    it('discards decided bouts when told to explicitly', function () {
        [$category] = categoryWithAthletes(8);
        $this->generator->generate($category);
        runTournament($category);

        $this->generator->generate($category, discardResults: true);

        expect($category->bouts()->whereNotNull('winner_athlete_id')->count())->toBe(0);
    });

    it('issues a fresh play code each generation so stale results cannot land', function () {
        [$category] = categoryWithAthletes(4);

        $this->generator->generate($category);
        $before = $category->bouts()->pluck('play_code')->all();

        $this->generator->generate($category);
        $after = $category->bouts()->pluck('play_code')->all();

        expect(array_intersect($before, $after))->toBeEmpty();
    });
});
