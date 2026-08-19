<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\Bout;
use App\Models\WeightCategory;
use App\Support\BracketSeeding;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Builds the full bracket for one weight category.
 *
 * Differences from the original champion-create-table-kurash-api.php:
 *
 *  - Bracket size comes from one place (BracketSeeding), not three disagreeing
 *    ladders, so a 2- or 3-athlete category is finally correct.
 *  - Bouts are linked forward with next_bout_id, so advancing a winner later is
 *    one update rather than a reverse lookup that nothing ever performed.
 *  - Byes are resolved as part of generation, and only where the empty slot can
 *    never be filled — a slot still waiting on an undecided bout is pending,
 *    not a walkover.
 *  - The whole thing runs in a transaction, so a failure cannot leave a
 *    category with no bracket at all.
 */
class BracketGenerator
{
    /**
     * @return array{bouts:int, byes:int, rounds:int, size:int}
     */
    public function generate(WeightCategory $category, bool $discardResults = false): array
    {
        $athletes = $category->drawnAthletes()->get();

        if ($athletes->isEmpty()) {
            throw new RuntimeException("No athletes with a draw number in {$category->label}.");
        }

        $decided = $category->bouts()->whereNotNull('winner_athlete_id')->where('is_bye', false)->count();

        if ($decided > 0 && ! $discardResults) {
            throw new BracketHasResultsException($decided);
        }

        $byDrawNumber = $athletes->keyBy('draw_number');
        $size = BracketSeeding::size($athletes->count());

        // A single entrant has nothing to fight. Say so rather than inventing a
        // bracket, so the caller can award the category without a bout.
        if ($size < 2) {
            throw new RuntimeException("{$category->label} has only one athlete — no bracket to draw.");
        }

        $rounds = BracketSeeding::totalRounds($size);
        $token = Str::lower(Str::random(4));

        return DB::transaction(function () use ($category, $byDrawNumber, $size, $rounds, $token) {
            $category->bouts()->delete();

            $boutsByRound = $this->createBouts($category, $size, $rounds, $token);
            $this->linkRounds($boutsByRound, $rounds);
            $this->seatFirstRound($boutsByRound[1], $size, $byDrawNumber);

            $byes = $this->resolveWalkovers($boutsByRound, $rounds);

            return [
                'bouts' => array_sum(array_map('count', $boutsByRound)),
                'byes' => $byes,
                'rounds' => $rounds,
                'size' => $size,
            ];
        });
    }

    /**
     * @return array<int, list<Bout>> keyed by round number
     */
    private function createBouts(WeightCategory $category, int $size, int $rounds, string $token): array
    {
        $boutsByRound = [];

        for ($round = 1; $round <= $rounds; $round++) {
            $boutsByRound[$round] = [];

            for ($position = 0; $position < BracketSeeding::boutsInRound($size, $round); $position++) {
                // Appended rather than assigned at [$position]: the loop counts
                // up from zero, so the two are identical, and appending is what
                // makes this provably the list the callers below index into.
                $boutsByRound[$round][] = Bout::create([
                    // Deterministic per slot, plus a per-generation token so a
                    // late webhook from a discarded bracket cannot land on a
                    // freshly generated bout.
                    'play_code' => sprintf('%d-%02d-%03d-%s', $category->id, $round, $position, $token),
                    'championship_id' => $category->ageCategory->championship_id,
                    'age_category_id' => $category->age_category_id,
                    'weight_category_id' => $category->id,
                    'round' => $round,
                    'position_in_round' => $position,
                    'status' => Bout::STATUS_PENDING,
                ]);
            }
        }

        return $boutsByRound;
    }

    /**
     * Point every bout at the one its winner walks into. Position p in round r
     * feeds position p/2 in round r+1, taking slot a when p is even.
     *
     * @param  array<int, list<Bout>>  $boutsByRound
     */
    private function linkRounds(array $boutsByRound, int $rounds): void
    {
        for ($round = 1; $round < $rounds; $round++) {
            foreach ($boutsByRound[$round] as $position => $bout) {
                $bout->update([
                    'next_bout_id' => $boutsByRound[$round + 1][intdiv($position, 2)]->id,
                    'next_bout_slot' => $position % 2 === 0 ? 'a' : 'b',
                ]);
            }
        }
    }

    /**
     * @param  list<Bout>  $firstRound
     * @param  Collection<int, Athlete>  $byDrawNumber
     */
    private function seatFirstRound(array $firstRound, int $size, $byDrawNumber): void
    {
        foreach (BracketSeeding::firstRoundPairs($size) as $position => [$seedA, $seedB]) {
            $athleteA = $byDrawNumber->get($seedA);
            $athleteB = $byDrawNumber->get($seedB);

            $firstRound[$position]->update([
                'seed_a' => $seedA,
                'seed_b' => $seedB,
                'athlete_a_id' => $athleteA?->id,
                'athlete_b_id' => $athleteB?->id,
            ]);
        }
    }

    /**
     * Walk the bracket forward, promoting any athlete whose opponent slot can
     * never be filled.
     *
     * The "can never be filled" test is what stops a bout that is merely
     * waiting on an undecided feeder from being mistaken for a walkover.
     *
     * @param  array<int, list<Bout>>  $boutsByRound
     */
    private function resolveWalkovers(array $boutsByRound, int $rounds): int
    {
        // producesWinner[round][position] — will this bout ever yield someone?
        $producesWinner = [];

        foreach ($boutsByRound[1] as $position => $bout) {
            $producesWinner[1][$position] = $bout->athlete_a_id !== null || $bout->athlete_b_id !== null;
        }

        for ($round = 2; $round <= $rounds; $round++) {
            foreach ($boutsByRound[$round] as $position => $bout) {
                $producesWinner[$round][$position] =
                    ($producesWinner[$round - 1][$position * 2] ?? false)
                    || ($producesWinner[$round - 1][$position * 2 + 1] ?? false);
            }
        }

        $byes = 0;

        for ($round = 1; $round <= $rounds; $round++) {
            foreach ($boutsByRound[$round] as $position => $bout) {
                $bout->refresh();

                $slotAWillFill = $round === 1
                    ? $bout->athlete_a_id !== null
                    : ($producesWinner[$round - 1][$position * 2] ?? false);

                $slotBWillFill = $round === 1
                    ? $bout->athlete_b_id !== null
                    : ($producesWinner[$round - 1][$position * 2 + 1] ?? false);

                // Both sides live, or both dead: nothing to promote here.
                if ($slotAWillFill === $slotBWillFill) {
                    continue;
                }

                $winnerId = $slotAWillFill ? $bout->athlete_a_id : $bout->athlete_b_id;

                // The live side is filled by a bout that has not been fought
                // yet, so this is pending rather than a walkover.
                if ($winnerId === null) {
                    continue;
                }

                $bout->update([
                    'winner_athlete_id' => $winnerId,
                    'is_bye' => true,
                    'win_type' => 'bye',
                    'status' => Bout::STATUS_COMPLETED,
                ]);

                $this->advance($bout, $boutsByRound);
                $byes++;
            }
        }

        return $byes;
    }

    /** @param array<int, list<Bout>> $boutsByRound */
    private function advance(Bout $bout, array $boutsByRound): void
    {
        if ($bout->next_bout_id === null) {
            return;
        }

        $next = $boutsByRound[$bout->round + 1][intdiv($bout->position_in_round, 2)];
        $next->update(["athlete_{$bout->next_bout_slot}_id" => $bout->winner_athlete_id]);
    }
}
