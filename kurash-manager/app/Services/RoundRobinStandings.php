<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\Bout;
use App\Models\WeightCategory;
use App\Support\Noc;
use Illuminate\Support\Collection;

/**
 * The round-robin table, derived from the contests rather than kept alongside
 * them.
 *
 * Nothing here is stored. Wins, points and rank are computed from the bout
 * rows every time they are asked for, which is what makes correcting a result
 * — or reopening one, or voiding it — produce exactly the table that would
 * have existed had the mistake never been made. A cached column would have to
 * be invalidated by every one of those paths, and the one that was forgotten
 * would be the one that decided a medal.
 *
 * It is also why nothing here advances anybody. A round-robin contest feeds no
 * other contest: correcting one changes the table and nothing else, and
 * BoutAdvancer's forward walk must never run over these rows.
 *
 * ── The tie-break chain ───────────────────────────────────────────────────
 *
 * Configured in config/kurash.php under round_robin.tie_breaks, walked in the
 * order it is written there, and documented in full at that key. In short:
 * wins, then points, then the contest between two tied athletes, then a mini
 * table among three or more, then match time, then an explicit referee
 * decision.
 *
 * The last of those is not a tie-break. It is this service saying it cannot
 * separate the athletes, which is a real outcome and is reported as one — the
 * table will not invent an order to avoid asking for a decision.
 */
class RoundRobinStandings
{
    /** The athletes are level and nobody has broken it. */
    public const STATE_NEEDS_DECISION = 'needs_decision';

    /** Ranked, but the group is still being fought. */
    public const STATE_PROVISIONAL = 'provisional';

    /** Ranked, and the group is finished. */
    public const STATE_FINAL = 'final';

    /**
     * The table for one class.
     *
     * @return array{
     *     complete: bool,
     *     contests: array{total:int, decided:int, pending:int},
     *     rows: list<array{
     *         rank:int, athlete:Athlete, noc:string, played:int, wins:int,
     *         losses:int, points:int, decided_by:string, tied_with:list<int>,
     *         state:string, medal:string|null
     *     }>,
     *     unresolved: list<list<int>>,
     * }
     */
    public function forCategory(WeightCategory $category): array
    {
        $field = $category->numberedAthletes()->get()->keyBy('id');
        $contests = $this->contests($category);

        $decided = $contests->filter(fn (Bout $b) => $b->winner_athlete_id !== null);
        $complete = $field->count() > 1 && $decided->count() === $contests->count() && $contests->isNotEmpty();

        $stats = $this->tally($field, $decided);

        // Ordered, then walked to hand out ranks — the ordering and the
        // numbering are separate steps because athletes this service could not
        // separate share a rank rather than being given adjacent ones.
        $ids = [];

        foreach ($field as $id => $ignored) {
            $ids[] = (int) $id;
        }

        [$order, $unresolved] = $this->order($ids, $stats, $decided);

        /*
         | A decision is only *required* of a finished group.
         |
         | While contests are still to be fought, athletes level on everything
         | are simply level so far — the contests themselves are the tie-break
         | still to run, and a freshly drawn group is level on nothing at all.
         | Reporting it as "referee decision required" would put an amber
         | deadlock banner over every board in the hall before anybody had
         | fought. The rows stay provisional instead, which is what they are.
         */
        if (! $complete) {
            $unresolved = [];
        }

        return [
            'complete' => $complete,
            'contests' => [
                'total' => $contests->count(),
                'decided' => $decided->count(),
                'pending' => $contests->count() - $decided->count(),
            ],
            'rows' => $this->rows($order, $field, $stats, $unresolved, $complete),
            'unresolved' => $unresolved,
        ];
    }

    /**
     * The contests the table is computed from.
     *
     * Walkovers are contests: an athlete who was awarded one has a win on the
     * table, because the alternative is a group in which somebody's opponent
     * never appeared and the table pretends the fixture did not exist. A bout
     * missing an athlete on either side is excluded — it cannot be fought and
     * cannot be counted against anybody.
     *
     * @return Collection<int, Bout>
     */
    private function contests(WeightCategory $category): Collection
    {
        return $category->bouts()
            ->with(['athleteA', 'athleteB'])
            ->whereNotNull('athlete_a_id')
            ->whereNotNull('athlete_b_id')
            ->orderBy('round')
            ->orderBy('position_in_round')
            ->get();
    }

    /**
     * Wins, losses and points per athlete, over a given set of contests.
     *
     * Taken over a set rather than over the class, because the mini-table
     * tie-break is the same arithmetic run again across a subset of the
     * contests — one implementation, so the main table and the tie-break
     * cannot come to different conclusions about what a win is worth.
     *
     * @param  Collection<int, Athlete>  $field
     * @param  Collection<int, Bout>  $decided
     * @return array<int, array{played:int, wins:int, losses:int, points:int}>
     */
    private function tally(Collection $field, Collection $decided): array
    {
        $stats = [];

        foreach ($field as $id => $athlete) {
            $stats[(int) $id] = ['played' => 0, 'wins' => 0, 'losses' => 0, 'points' => 0];
        }

        $win = (int) config('kurash.round_robin.points.win', 1);
        $loss = (int) config('kurash.round_robin.points.loss', 0);

        foreach ($decided as $bout) {
            foreach ([(int) $bout->athlete_a_id, (int) $bout->athlete_b_id] as $id) {
                if (! isset($stats[$id])) {
                    // An athlete who fought here but is no longer in the field
                    // — withdrawn after the draw, or moved. Their contests
                    // still count for their opponents; they are not ranked.
                    continue;
                }

                $won = $bout->winner_athlete_id === $id;

                $stats[$id]['played']++;
                $stats[$id][$won ? 'wins' : 'losses']++;
                $stats[$id]['points'] += $won ? $win : $loss;
            }
        }

        return $stats;
    }

    /**
     * The field in finishing order, and the groups nobody could separate.
     *
     * @param  list<int>  $ids
     * @param  array<int, array{played:int, wins:int, losses:int, points:int}>  $stats
     * @param  Collection<int, Bout>  $decided
     * @return array{0: list<int>, 1: list<list<int>>}
     */
    private function order(array $ids, array $stats, Collection $decided): array
    {
        $unresolved = [];

        $sorted = $this->separate($ids, $stats, $decided, $unresolved);

        return [$sorted, $unresolved];
    }

    /**
     * Sort one set of athletes, breaking ties by walking the configured chain.
     *
     * @param  list<int>  $ids
     * @param  array<int, array{played:int, wins:int, losses:int, points:int}>  $stats
     * @param  Collection<int, Bout>  $decided
     * @param  list<list<int>>  $unresolved
     * @return list<int>
     */
    private function separate(array $ids, array $stats, Collection $decided, array &$unresolved, int $step = 0): array
    {
        if (count($ids) <= 1) {
            return $ids;
        }

        /** @var list<string> $chain */
        $chain = (array) config('kurash.round_robin.tie_breaks', []);

        // Off the end of the chain: level on everything the rules offer.
        if ($step >= count($chain)) {
            $unresolved[] = $ids;

            // Still returned in a fixed order — a table has to render — but
            // the group is reported, and the rows carry the state that says so.
            return $this->byDrawNumber($ids, $decided);
        }

        $rule = $chain[$step];

        $groups = $this->groupBy($ids, $rule, $stats, $decided);

        // This rule separated nobody: try the next one on the same set rather
        // than recursing into a group identical to the one we started with.
        if (count($groups) <= 1) {
            return $this->separate($ids, $stats, $decided, $unresolved, $step + 1);
        }

        $ordered = [];

        foreach ($groups as $group) {
            foreach ($this->separate($group, $stats, $decided, $unresolved, $step + 1) as $id) {
                $ordered[] = $id;
            }
        }

        return $ordered;
    }

    /**
     * Split a set into ranked groups by one rule, best group first.
     *
     * A rule that cannot apply — head-to-head across three athletes, match
     * time that is switched off — returns the set whole, and the caller moves
     * on to the next rule.
     *
     * @param  list<int>  $ids
     * @param  array<int, array{played:int, wins:int, losses:int, points:int}>  $stats
     * @param  Collection<int, Bout>  $decided
     * @return list<list<int>>
     */
    private function groupBy(array $ids, string $rule, array $stats, Collection $decided): array
    {
        $score = match ($rule) {
            'wins' => fn (int $id): int => $stats[$id]['wins'] ?? 0,
            'points' => fn (int $id): int => $stats[$id]['points'] ?? 0,

            // Only for exactly two. With three or more there is no single
            // contest between them, and the mini table below is what the rules
            // ask for instead.
            'head_to_head' => count($ids) === 2
                ? $this->headToHead($ids, $decided)
                : null,

            // The same arithmetic, over only the contests these athletes
            // fought against each other.
            'mini_table' => count($ids) > 2
                ? $this->miniTable($ids, $decided)
                : null,

            'match_time' => $this->matchTime($ids, $decided),

            // Not a rule that sorts. Reaching it means the table is asking for
            // a decision, which separate() records when the chain runs out.
            'referee' => null,

            default => null,
        };

        if ($score === null) {
            return [$ids];
        }

        $buckets = [];

        foreach ($ids as $id) {
            $buckets[(string) $score($id)][] = $id;
        }

        // Numeric descending: the higher score is the better placing in every
        // rule here, including match time, which is inverted where it is built.
        // PHP turns a numeric string key back into an int, so the callback
        // takes both — the comparison is numeric either way.
        uksort($buckets, fn (int|string $a, int|string $b): int => (float) $b <=> (float) $a);

        return array_values($buckets);
    }

    /**
     * The contest between two tied athletes: the winner of it places above.
     *
     * @param  list<int>  $ids
     * @param  Collection<int, Bout>  $decided
     * @return (callable(int): int)|null
     */
    private function headToHead(array $ids, Collection $decided): ?callable
    {
        [$first, $second] = $ids;

        $meeting = $decided->first(
            fn (Bout $b) => in_array($b->athlete_a_id, $ids, true) && in_array($b->athlete_b_id, $ids, true)
        );

        // They have not met, or their contest is not decided: this rule has
        // nothing to say and the next one is tried.
        if ($meeting === null || $meeting->winner_athlete_id === null) {
            return null;
        }

        $winner = $meeting->winner_athlete_id;

        // Guard against a meeting that somehow involves neither: the athletes
        // were filtered above, so this can only happen if the pair is malformed.
        if (! in_array($winner, [$first, $second], true)) {
            return null;
        }

        return fn (int $id): int => $id === $winner ? 1 : 0;
    }

    /**
     * The same standings, recomputed over the tied athletes alone.
     *
     * Only the contests they fought against each other count — which is what
     * makes it a mini table rather than a rerun of the group.
     *
     * @param  list<int>  $ids
     * @param  Collection<int, Bout>  $decided
     * @return (callable(int): int)|null
     */
    private function miniTable(array $ids, Collection $decided): ?callable
    {
        $between = $decided->filter(
            fn (Bout $b) => in_array($b->athlete_a_id, $ids, true) && in_array($b->athlete_b_id, $ids, true)
        );

        if ($between->isEmpty()) {
            return null;
        }

        $wins = array_fill_keys($ids, 0);

        foreach ($between as $bout) {
            if (isset($wins[$bout->winner_athlete_id])) {
                $wins[$bout->winner_athlete_id]++;
            }
        }

        // Every athlete level inside the mini table too: it has separated
        // nobody, and saying so lets the chain move on instead of pretending.
        if (count(array_unique($wins)) <= 1) {
            return null;
        }

        return fn (int $id): int => $wins[$id] ?? 0;
    }

    /**
     * The match-time tie-break, which is off unless a federation turns it on.
     *
     * See config/kurash.php: the IKA wording does not say whether it means the
     * fastest single win, the total or the average, and the three rank
     * athletes differently. It also refuses to run where any of the tied
     * athletes has a win with no recorded time — ranking on a column that is
     * null for half a group is worse than admitting the tie.
     *
     * Higher is better in the value returned, so the durations are inverted:
     * time *used* is what is compared, and less of it places higher.
     *
     * @param  list<int>  $ids
     * @param  Collection<int, Bout>  $decided
     * @return (callable(int): int)|null
     */
    private function matchTime(array $ids, Collection $decided): ?callable
    {
        $mode = (string) config('kurash.round_robin.match_time', 'disabled');

        if (! in_array($mode, ['fastest_win', 'total_time', 'average_time'], true)) {
            return null;
        }

        $used = [];

        foreach ($ids as $id) {
            /** @var list<int> $times */
            $times = [];

            foreach ($decided as $bout) {
                if ($bout->winner_athlete_id !== $id) {
                    continue;
                }

                $seconds = $this->secondsUsed($bout);

                // A win with no timing behind it. Every candidate reading of
                // the rule needs the number, so the whole rule stands down.
                if ($seconds === null) {
                    return null;
                }

                $times[] = $seconds;
            }

            if ($times === []) {
                return null;
            }

            $used[$id] = match ($mode) {
                'fastest_win' => min($times),
                'total_time' => array_sum($times),
                default => (int) round(array_sum($times) / count($times)),
            };
        }

        if (count(array_unique($used)) <= 1) {
            return null;
        }

        // Negated so that less time used sorts higher, without the caller
        // needing to know which direction each rule runs in.
        return fn (int $id): int => -$used[$id];
    }

    /**
     * How long a contest took, or null where it was never recorded.
     *
     * The stored reading is what the clock had *left*, so the time used is the
     * contest's own length minus it. A bout with no reading — anything fought
     * before the column existed, a walkover nobody stepped out for, a result
     * posted by a scoreboard with no clock behind it — answers null, and the
     * tie-break stands down rather than treating the gap as zero.
     */
    private function secondsUsed(Bout $bout): ?int
    {
        if ($bout->decided_seconds_remaining === null) {
            return null;
        }

        $length = app(KurashScore::class)->boutSeconds($bout);

        return max(0, $length - (int) $bout->decided_seconds_remaining);
    }

    /**
     * A fixed order for athletes nothing could separate.
     *
     * By draw number, which every athlete in a drawn class has and which does
     * not change — so the table renders the same way twice and a screenshot
     * taken before the referee decides still matches the one taken after.
     *
     * @param  list<int>  $ids
     * @param  Collection<int, Bout>  $decided
     * @return list<int>
     */
    private function byDrawNumber(array $ids, Collection $decided): array
    {
        $numbers = [];

        foreach ($decided as $bout) {
            foreach ([[$bout->athlete_a_id, $bout->athleteA], [$bout->athlete_b_id, $bout->athleteB]] as [$id, $athlete]) {
                if ($athlete !== null) {
                    $numbers[$id] = (int) ($athlete->draw_number ?? PHP_INT_MAX);
                }
            }
        }

        $sorted = $ids;

        usort($sorted, fn (int $a, int $b): int => [$numbers[$a] ?? PHP_INT_MAX, $a] <=> [$numbers[$b] ?? PHP_INT_MAX, $b]);

        return $sorted;
    }

    /**
     * The table's rows, in order, with ranks and medals attached.
     *
     * Athletes nobody could separate share a rank, and the ranks after them
     * skip — two athletes level on first are both first and the next is third,
     * which is how a placing table reads everywhere else.
     *
     * @param  list<int>  $order
     * @param  Collection<int, Athlete>  $field
     * @param  array<int, array{played:int, wins:int, losses:int, points:int}>  $stats
     * @param  list<list<int>>  $unresolved
     * @return list<array{rank:int, athlete:Athlete, noc:string, played:int, wins:int, losses:int, points:int, decided_by:string, tied_with:list<int>, state:string, medal:string|null}>
     */
    private function rows(array $order, Collection $field, array $stats, array $unresolved, bool $complete): array
    {
        /** @var array<int, string> $medals */
        $medals = (array) config('kurash.round_robin.medals', []);

        $tiedWith = [];

        foreach ($unresolved as $group) {
            foreach ($group as $id) {
                $tiedWith[$id] = array_values(array_diff($group, [$id]));
            }
        }

        $rows = [];
        $rank = 0;
        $seen = 0;
        $previous = null;

        foreach ($order as $id) {
            $athlete = $field->get($id);

            if ($athlete === null) {
                continue;
            }

            $seen++;

            // Athletes in the same unresolved group share the rank they
            // arrived at; everybody else takes the next one.
            $sharesWithPrevious = $previous !== null
                && isset($tiedWith[$id])
                && in_array($previous, $tiedWith[$id], true);

            if (! $sharesWithPrevious) {
                $rank = $seen;
            }

            $unranked = isset($tiedWith[$id]);

            $rows[] = [
                'rank' => $rank,
                'athlete' => $athlete,
                'noc' => (string) Noc::normalise($athlete->noc_code),
                'played' => $stats[$id]['played'] ?? 0,
                'wins' => $stats[$id]['wins'] ?? 0,
                'losses' => $stats[$id]['losses'] ?? 0,
                'points' => $stats[$id]['points'] ?? 0,
                'decided_by' => $unranked ? 'referee decision required' : '',
                'tied_with' => $tiedWith[$id] ?? [],
                'state' => match (true) {
                    $unranked => self::STATE_NEEDS_DECISION,
                    ! $complete => self::STATE_PROVISIONAL,
                    default => self::STATE_FINAL,
                },
                // No medal until the group is finished and the placing is not
                // waiting on somebody's decision. A medal handed out over an
                // unresolved tie is a medal handed to the wrong athlete.
                'medal' => $complete && ! $unranked ? ($medals[$rank] ?? null) : null,
            ];

            $previous = $id;
        }

        return $rows;
    }

    /**
     * The podium, in the shape MedalTable hands back for a bracket.
     *
     * Same keys, so a screen or an export that already knows how to render a
     * knockout podium renders this one without learning a second shape.
     *
     * @return array{decided: bool, gold: ?Athlete, silver: ?Athlete, bronze: list<Athlete>}
     */
    public function podiumFor(WeightCategory $category): array
    {
        $table = $this->forCategory($category);

        $empty = ['decided' => false, 'gold' => null, 'silver' => null, 'bronze' => []];

        if (! $table['complete']) {
            return $empty;
        }

        /*
         | Only a tie that touches the podium withholds it.
         |
         | Two athletes level on fourth place are the referee's problem, not
         | the medallists': ranks one to three are settled, and settled medals
         | held back over an argument about fourth would keep a decided class
         | off the medal table indefinitely. A tie *inside* the medal ranks
         | still withholds the whole podium, because handing out gold while
         | silver is undecided splits one decision into two announcements.
         */
        $medalRanks = array_keys((array) config('kurash.round_robin.medals', []));
        $podiumDepth = $medalRanks === [] ? 0 : max($medalRanks);

        foreach ($table['rows'] as $row) {
            if ($row['state'] === self::STATE_NEEDS_DECISION && $row['rank'] <= $podiumDepth) {
                return $empty;
            }
        }

        $byMedal = [];

        foreach ($table['rows'] as $row) {
            if ($row['medal'] !== null) {
                $byMedal[$row['medal']][] = $row['athlete'];
            }
        }

        return [
            'decided' => isset($byMedal['gold']),
            'gold' => $byMedal['gold'][0] ?? null,
            'silver' => $byMedal['silver'][0] ?? null,
            'bronze' => $byMedal['bronze'] ?? [],
        ];
    }
}
