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
 * Bronze — losers of the semi-finals (two, in a bracket that has semis)
 *
 * Same rule the original medal-helpers.php used, but reading forward links
 * instead of MAX(roundnumber) with a bare non-aggregated column in HAVING,
 * which SQLite tolerated and MySQL rejects outright.
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
        $bouts = $category->bouts()->with(['athleteA', 'athleteB', 'winner'])->get();

        $empty = ['decided' => false, 'gold' => null, 'silver' => null, 'bronze' => []];

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

        $bronze = array_values(
            $bouts
                ->where('round', $totalRounds - 1)
                ->filter(fn (Bout $b) => $b->isDecided() && $b->loserId() !== null)
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
     * @return Collection<int, array{noc_code:string, gold:int, silver:int, bronze:int, total:int}>
     */
    public function standings(int $championshipId): Collection
    {
        $tally = [];

        $categories = WeightCategory::whereHas(
            'ageCategory',
            fn ($q) => $q->where('championship_id', $championshipId)
        )->get();

        foreach ($categories as $category) {
            $podium = $this->forCategory($category);

            if (! $podium['decided']) {
                continue;
            }

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

        return collect($tally)
            ->map(fn (array $counts, string $noc) => [
                'noc_code' => $noc,
                'gold' => $counts['gold'] ?? 0,
                'silver' => $counts['silver'] ?? 0,
                'bronze' => $counts['bronze'] ?? 0,
                'total' => array_sum($counts),
            ])
            ->sortByDesc(fn ($row) => [$row['gold'], $row['silver'], $row['bronze']])
            ->values();
    }

    /** Phase label for a bout, e.g. "Semi Final". */
    public function phaseFor(Bout $bout, int $bracketSize): string
    {
        return $bout->phase(BracketSeeding::totalRounds($bracketSize));
    }
}
