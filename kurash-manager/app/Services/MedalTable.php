<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\Bout;
use App\Models\WeightCategory;
use App\Support\BracketSeeding;
use Illuminate\Support\Collection;

/**
 * Derives the podium from the bracket.
 *
 * Gold   — winner of the final
 * Silver — loser of the final
 * Bronze — losers of the semi-finals (two, in a bracket that has semis),
 *          the champion's beaten semi-finalist first
 *
 * Same rule the original medal-helpers.php used, but reading forward links
 * instead of MAX(roundnumber) with a bare non-aggregated column in HAVING,
 * which SQLite tolerated and MySQL rejects outright. *
 * That is the knockout's podium. A class drawn as a round robin has no final
 * to win and no semi-finals to lose, so its podium is the standings table's to
 * derive and this class asks RoundRobinStandings for it — one podium shape
 * either way, so every screen and export that already renders one renders the
 * other. A class of one athlete has a podium only once an administrator has
 * placed them: being unopposed is not a result, and nothing here infers one.
 */
class MedalTable
{
    /**
     * @return array{
     *     decided: bool,
     *     gold: ?Athlete, silver: ?Athlete, bronze: list<Athlete>
     * }
     */
    public function forCategory(WeightCategory $category): array
    {
        $empty = ['decided' => false, 'gold' => null, 'silver' => null, 'bronze' => []];

        // Dispatched on what the class was drawn as, never on what its athlete
        // count would suggest today — a class that has grown since it was drawn
        // still has the podium its own draw produced.
        if ($category->isRoundRobin()) {
            return app(RoundRobinStandings::class)->podiumFor($category);
        }

        if ($category->isPlacement()) {
            $placed = $category->placedAthlete;

            return $placed === null
                ? $empty
                : ['decided' => true, 'gold' => $placed, 'silver' => null, 'bronze' => []];
        }

        // Uses what the caller loaded, if it did. summary() walks every class in
        // a championship, and asking the database for each one's contests
        // separately is the difference between four queries and fifty on a
        // screen that shows the standings beside everything else.
        $bouts = $category->relationLoaded('bouts')
            ? $category->bouts
            : $category->bouts()->with(['athleteA', 'athleteB', 'winner'])->get();

        if ($bouts->isEmpty()) {
            return $empty;
        }

        $totalRounds = (int) $bouts->max('round');
        $final = $bouts->firstWhere('round', $totalRounds);

        if ($final === null || ! $final->isDecided()) {
            return $empty;
        }

        $byId = fn (?int $id) => $id === null ? null : $bouts
            ->flatMap(fn (Bout $b) => [$b->athleteA, $b->athleteB])
            ->filter()
            ->firstWhere('id', $id);

        // The two bronzes are not interchangeable on a results sheet: the
        // first is whoever the champion put out, the second whoever the
        // runner-up did. Left in the order the rows came back, the same
        // podium printed twice could list them either way round.
        $bronze = array_values(
            $bouts
                ->where('round', $totalRounds - 1)
                ->filter(fn (Bout $b) => $b->isDecided() && $b->loserId() !== null)
                ->sortBy(fn (Bout $b) => [
                    $b->winner_athlete_id === $final->winner_athlete_id ? 0 : 1,
                    (int) $b->position_in_round,
                ])
                ->map(fn (Bout $b) => $byId($b->loserId()))
                ->filter()
                ->all()
        );

        return [
            'decided' => true,
            'gold' => $final->winner,
            'silver' => $byId($final->loserId()),
            'bronze' => $bronze,
        ];
    }

    /**
     * Medal counts per NOC across a whole championship, ordered the way a
     * standings table is: gold first, then silver, then bronze.
     *
     * @param  string|null  $competition  'M' or 'F' to count one competition's
     *                                    podiums on their own, as a men's or
     *                                    women's medal table does.
     * @return Collection<int, array{noc_code:string, gold:int, silver:int, bronze:int, total:int}>
     */
    public function standings(int $championshipId, ?string $competition = null): Collection
    {
        return $this->summary($championshipId, $competition)['standings'];
    }

    /**
     * The standings and how much of the championship they are drawn from.
     *
     * One pass rather than two. Deriving a podium is a query per weight class,
     * and a caller that wants both the table and "9 of 12 classes decided" —
     * the dashboard does — would otherwise walk every class twice to learn two
     * facts that the same loop already has in hand.
     *
     * `total` counts every weight class in scope, decided or not, so the pair
     * reads as progress rather than as a bare number of podiums.
     *
     * @param  string|null  $competition  As for standings().
     * @return array{
     *     standings: Collection<int, array{noc_code:string, gold:int, silver:int, bronze:int, total:int}>,
     *     decided: int,
     *     total: int
     * }
     */
    public function summary(int $championshipId, ?string $competition = null): array
    {
        $tally = [];
        $decided = 0;

        $categories = WeightCategory::whereHas(
            'ageCategory',
            fn ($q) => $q->where('championship_id', $championshipId)
                ->when($competition !== null, fn ($division) => $division->where('gender', $competition))
        )
            // Everything forCategory() reads, fetched once for the whole
            // championship: the contests and the three athletes each one names,
            // plus the sole athlete a placement's podium consists of.
            ->with(['bouts.athleteA', 'bouts.athleteB', 'bouts.winner', 'placedAthlete'])
            ->get();

        foreach ($categories as $category) {
            $podium = $this->forCategory($category);

            if (! $podium['decided']) {
                continue;
            }

            $decided++;

            foreach (['gold', 'silver'] as $medal) {
                if ($podium[$medal] !== null) {
                    $noc = $podium[$medal]->noc_code;
                    $tally[$noc][$medal] = ($tally[$noc][$medal] ?? 0) + 1;
                }
            }

            foreach ($podium['bronze'] as $athlete) {
                $tally[$athlete->noc_code]['bronze'] = ($tally[$athlete->noc_code]['bronze'] ?? 0) + 1;
            }
        }

        $standings = collect($tally)
            ->map(fn (array $counts, string $noc) => [
                'noc_code' => $noc,
                'gold' => $counts['gold'] ?? 0,
                'silver' => $counts['silver'] ?? 0,
                'bronze' => $counts['bronze'] ?? 0,
                'total' => array_sum($counts),
            ])
            ->sortByDesc(fn ($row) => [$row['gold'], $row['silver'], $row['bronze']])
            ->values();

        return [
            'standings' => $standings,
            'decided' => $decided,
            'total' => $categories->count(),
        ];
    }

    /** Phase label for a bout, e.g. "Semi Final". */
    public function phaseFor(Bout $bout, int $bracketSize): string
    {
        return $bout->phase(BracketSeeding::totalRounds($bracketSize));
    }
}
