<?php

namespace App\Services;

use App\Models\Bout;
use App\Models\Championship;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Assigns the running order of bouts across a whole championship.
 *
 * This replaces the last part of the old CSV workflow: draw-reveal wrote one
 * CSV per weight class, and fight-order-kurash.php then read them all back and
 * showed only the rows where somebody had typed a fight number into Excel by
 * hand. Nothing checked that an athlete had time to recover between bouts, and
 * nothing stopped a later round being numbered before the round that feeds it.
 *
 * Two properties are guaranteed here instead:
 *
 *  1. A bout is always numbered after both of the bouts that feed it. Without
 *     this the running order can call a semi-final before its quarter-final.
 *  2. There is a minimum number of bouts between a bout and each of its
 *     feeders, so whoever advances gets a rest. This is structural — it holds
 *     whoever wins, because it depends on bracket position, not on results.
 *
 * ── Two formats, two kinds of constraint ─────────────────────────────────
 *
 * Both of the guarantees above are about *feeders*, and a round robin has
 * none: nobody advances, every pairing is known before anybody fights, and any
 * order of the contests is a legal order. What a round robin has instead is an
 * athlete appearing in almost every round, so the rest a bracket gets for free
 * from its own shape has to be arranged here.
 *
 * So the rest rule is stated once, in terms of athletes rather than of
 * brackets: no athlete should fight again within the configured number of
 * bouts. In a knockout that is what the feeder chain already delivers and the
 * feeder check is kept, because it holds whoever wins and a shared-athlete
 * check could only see the athletes already known. In a round robin the
 * shared-athlete check is the whole of it.
 *
 * Where the arithmetic cannot deliver the rest — an athlete with four contests
 * in a session of ten cannot have three bouts between each of them — that is
 * reported as unattainable rather than quietly scheduled anyway. See
 * unattainableRest().
 */
class FightOrderScheduler
{
    public const DEFAULT_REST = 3;

    /**
     * The rest the configuration asks for, in bouts.
     *
     * Read here rather than at every call site, so the config knob the
     * round_robin block documents is actually connected to the scheduler that
     * promises to honour it.
     */
    public static function configuredRest(): int
    {
        return (int) config('kurash.round_robin.minimum_rest', self::DEFAULT_REST);
    }

    /**
     * Number every contested bout in the championship.
     *
     * Round-major: every first-round bout across all weight classes, then every
     * second round, and so on. That is what naturally separates an athlete's
     * bouts, and it also keeps the weight classes progressing together rather
     * than running one class to its final while another has not started.
     *
     * Within a round the classes are interleaved, so consecutive bouts are
     * usually different categories — easier to rotate across mats, and it
     * spreads each class's officials and coaches through the session.
     *
     * @param  int|null  $minimumRest  null takes the configured rest,
     *                                 kurash.round_robin.minimum_rest
     * @return array{scheduled:int, violations:int, unattainable:int}
     */
    public function schedule(Championship $championship, ?int $minimumRest = null): array
    {
        $minimumRest ??= self::configuredRest();

        $bouts = $this->schedulableBouts($championship);

        if ($bouts->isEmpty()) {
            return ['scheduled' => 0, 'violations' => 0, 'unattainable' => 0];
        }

        $ordered = $this->spaceSharedAthletes($this->orderRoundMajor($bouts), $minimumRest);

        DB::transaction(function () use ($championship, $ordered) {
            // Clear first: fight_number has no uniqueness constraint, but a
            // partial renumber that left old values behind would read as a
            // valid order and send the wrong bout to the mat.
            $championship->bouts()->update(['fight_number' => null]);

            foreach ($ordered as $index => $bout) {
                Bout::whereKey($bout->id)->update(['fight_number' => $index + 1]);
            }
        });

        return [
            'scheduled' => $ordered->count(),
            'violations' => $this->restViolations($championship, $minimumRest)->count(),
            // Told apart from the violations above on purpose: one is an order
            // that could be better, and this is a rest that no order could
            // have delivered. An administrator can act on the first.
            'unattainable' => $this->unattainableRest($championship, $minimumRest)->count(),
        ];
    }

    public function clear(Championship $championship): void
    {
        $championship->bouts()->update(['fight_number' => null]);
    }

    /**
     * Bouts that need a slot in the running order.
     *
     * Byes are excluded: nobody steps onto a mat for them. Bouts that are
     * already decided keep their number if they have one, but a completed
     * contest does not need rescheduling either.
     *
     * @return Collection<int, Bout>
     */
    private function schedulableBouts(Championship $championship): Collection
    {
        return $championship->bouts()
            ->where('is_bye', false)
            ->with('weightCategory')
            ->orderBy('round')
            ->orderBy('weight_category_id')
            ->orderBy('position_in_round')
            ->get();
    }

    /**
     * @param  Collection<int, Bout>  $bouts
     * @return Collection<int, Bout>
     */
    private function orderRoundMajor(Collection $bouts): Collection
    {
        $ordered = collect();

        foreach ($bouts->groupBy('round')->sortKeys() as $roundBouts) {
            $byCategory = $roundBouts
                ->groupBy('weight_category_id')
                ->map(fn (Collection $c) => $c->sortBy('position_in_round')->values())
                ->values();

            // Round-robin across the weight classes present in this round.
            $depth = $byCategory->max(fn (Collection $c) => $c->count()) ?? 0;

            for ($i = 0; $i < $depth; $i++) {
                foreach ($byCategory as $categoryBouts) {
                    if ($categoryBouts->has($i)) {
                        $ordered->push($categoryBouts[$i]);
                    }
                }
            }
        }

        return $ordered;
    }

    /**
     * Push an athlete's contests apart, where the order is free to be changed.
     *
     * Only round-robin contests are moved. A knockout bout may not be
     * reordered freely — it has to follow the bouts that feed it, and
     * orderRoundMajor has already placed it where that holds — but a round
     * robin has no such constraint, so any two of its contests may trade
     * places with each other.
     *
     * Greedy and bounded: walk the order, and where a contest would put an
     * athlete back on the mat too soon, look ahead for a round-robin contest
     * that fits there instead and swap the two. A swap can create a conflict
     * further down, which the same walk then repairs when it reaches it.
     *
     * This improves an order; it does not guarantee one. What cannot be
     * achieved at all is reported by unattainableRest() rather than hidden by
     * shuffling until the loop gives up.
     *
     * @param  Collection<int, Bout>  $ordered
     * @return Collection<int, Bout>
     */
    private function spaceSharedAthletes(Collection $ordered, int $minimumRest): Collection
    {
        $list = $ordered->values()->all();
        $count = count($list);

        // Feeder links resolved in memory before the walk: a bout has feeders
        // exactly when another bout points at it, and every candidate feeder
        // is in this very list (a bye feeding a bout is knockout by
        // definition, and knockout bouts are immovable on their format
        // alone). The alternative — previousBouts()->doesntExist() per
        // candidate — is a live query inside an O(n²) repair loop.
        $fed = [];

        foreach ($list as $bout) {
            if ($bout->next_bout_id !== null) {
                $fed[$bout->next_bout_id] = true;
            }
        }

        // Where each athlete was last placed, so the check is a lookup rather
        // than a scan back over the whole order.
        $lastAt = [];

        for ($i = 0; $i < $count; $i++) {
            if (! $this->tooSoon($list[$i], $i, $lastAt, $minimumRest)) {
                $this->remember($list[$i], $i, $lastAt);

                continue;
            }

            // Only a round robin's contests may be moved, and only into a
            // position another round-robin contest is holding.
            if ($this->isMovable($list[$i], $fed)) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if (! $this->isMovable($list[$j], $fed) || $this->tooSoon($list[$j], $i, $lastAt, $minimumRest)) {
                        continue;
                    }

                    [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
                    break;
                }
            }

            $this->remember($list[$i], $i, $lastAt);
        }

        return collect($list);
    }

    /**
     * A contest whose place in the order is not fixed by a feeder.
     *
     * @param  array<int, bool>  $fed  bout ids some other bout advances into
     */
    private function isMovable(Bout $bout, array $fed): bool
    {
        return $bout->next_bout_id === null
            && ! isset($fed[$bout->id])
            && ($bout->weightCategory?->isRoundRobin() ?? false);
    }

    /**
     * Would putting this contest here bring somebody back too soon?
     *
     * @param  array<int, int>  $lastAt
     */
    private function tooSoon(Bout $bout, int $at, array $lastAt, int $minimumRest): bool
    {
        foreach ([$bout->athlete_a_id, $bout->athlete_b_id] as $athlete) {
            if ($athlete !== null && isset($lastAt[$athlete]) && $at - $lastAt[$athlete] <= $minimumRest) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<int, int>  $lastAt */
    private function remember(Bout $bout, int $at, array &$lastAt): void
    {
        foreach ([$bout->athlete_a_id, $bout->athlete_b_id] as $athlete) {
            if ($athlete !== null) {
                $lastAt[$athlete] = $at;
            }
        }
    }

    /**
     * Athletes for whom the requested rest is arithmetically out of reach.
     *
     * An athlete with k contests inside a running order of N bouts cannot have
     * them spaced further apart than floor((N-1)/(k-1)) on average, whatever
     * order is chosen — the contests have to fit in the session. Where the
     * requested rest asks for more than that, no scheduler could deliver it,
     * and saying so is more use than a list of violations that reads like a
     * mistake somebody could correct.
     *
     * @return Collection<int, array{athlete_id:int, contests:int, total:int, best_possible:int, requested:int}>
     */
    public function unattainableRest(Championship $championship, int $minimumRest = self::DEFAULT_REST): Collection
    {
        $bouts = $championship->bouts()->where('is_bye', false)->get();
        $total = $bouts->count();

        $contests = [];

        foreach ($bouts as $bout) {
            foreach ([$bout->athlete_a_id, $bout->athlete_b_id] as $athlete) {
                if ($athlete !== null) {
                    $contests[$athlete] = ($contests[$athlete] ?? 0) + 1;
                }
            }
        }

        $unattainable = collect();

        foreach ($contests as $athlete => $count) {
            if ($count < 2 || $total < 2) {
                continue;
            }

            $best = intdiv($total - 1, $count - 1);

            if ($best <= $minimumRest) {
                $unattainable->push([
                    'athlete_id' => (int) $athlete,
                    'contests' => $count,
                    'total' => $total,
                    'best_possible' => $best,
                    'requested' => $minimumRest,
                ]);
            }
        }

        return $unattainable;
    }

    /**
     * Bouts scheduled too soon after one of their feeders, or — worse — before
     * it. Byes are skipped on the way up: an athlete who received a walkover
     * has not fought, so the rest clock starts at the bout before it.
     *
     * @return Collection<int, array{bout:Bout, feeder:Bout, gap:int}>
     */
    public function restViolations(Championship $championship, int $minimumRest = self::DEFAULT_REST): Collection
    {
        $bouts = $championship->bouts()
            ->whereNotNull('fight_number')
            ->with(['weightCategory', 'previousBouts.previousBouts'])
            ->get()
            ->keyBy('id');

        $violations = collect();

        foreach ($bouts as $bout) {
            foreach ($this->contestedFeeders($bout) as $feeder) {
                if ($feeder->fight_number === null) {
                    continue;
                }

                $gap = $bout->fight_number - $feeder->fight_number;

                if ($gap <= $minimumRest) {
                    $violations->push([
                        'bout' => $bout,
                        'feeder' => $feeder,
                        'gap' => $gap,
                        'reason' => 'feeder',
                    ]);
                }
            }
        }

        return $violations->concat($this->sharedAthleteViolations($bouts, $minimumRest));
    }

    /**
     * An athlete brought back to the mat too soon by the running order itself.
     *
     * Round-robin contests only. In a bracket the same athlete's two contests
     * are always joined by a feeder link, so the walk above has already found
     * them, and reporting them twice would double every count on the screen.
     *
     * @param  Collection<int, Bout>  $bouts  scheduled bouts, keyed by id
     * @return Collection<int, array{bout:Bout, feeder:Bout, gap:int, reason:string}>
     */
    private function sharedAthleteViolations(Collection $bouts, int $minimumRest): Collection
    {
        $ordered = $bouts
            ->filter(fn (Bout $b) => ! $b->is_bye && ($b->weightCategory?->isRoundRobin() ?? false))
            ->sortBy('fight_number')
            ->values();

        // The last contest each athlete was given, so each pair is reported
        // once — against the contest immediately before it and not against
        // every earlier one.
        $previous = [];
        $violations = collect();

        foreach ($ordered as $bout) {
            foreach ([$bout->athlete_a_id, $bout->athlete_b_id] as $athlete) {
                if ($athlete === null) {
                    continue;
                }

                $earlier = $previous[$athlete] ?? null;

                if ($earlier !== null) {
                    $gap = $bout->fight_number - $earlier->fight_number;

                    if ($gap <= $minimumRest) {
                        $violations->push([
                            'bout' => $bout,
                            'feeder' => $earlier,
                            'gap' => $gap,
                            'reason' => 'shared_athlete',
                        ]);
                    }
                }

                $previous[$athlete] = $bout;
            }
        }

        return $violations;
    }

    /**
     * Walk back past byes to the last bout somebody actually fought.
     *
     * @return Collection<int, Bout>
     */
    private function contestedFeeders(Bout $bout): Collection
    {
        return $bout->previousBouts->flatMap(function (Bout $feeder) {
            return $feeder->is_bye ? $this->contestedFeeders($feeder) : collect([$feeder]);
        });
    }

    /**
     * Move a bout one place earlier or later by swapping fight numbers with its
     * neighbour. Refuses a swap that would put a bout before one of its
     * feeders.
     */
    public function move(Bout $bout, string $direction): bool
    {
        if ($bout->fight_number === null) {
            return false;
        }

        $neighbour = $bout->championship->bouts()
            ->whereNotNull('fight_number')
            ->when(
                $direction === 'up',
                fn ($q) => $q->where('fight_number', '<', $bout->fight_number)->orderByDesc('fight_number'),
                fn ($q) => $q->where('fight_number', '>', $bout->fight_number)->orderBy('fight_number'),
            )
            ->first();

        if ($neighbour === null) {
            return false;
        }

        if (! $this->swapKeepsOrder($bout, $neighbour)) {
            return false;
        }

        DB::transaction(function () use ($bout, $neighbour) {
            $boutNumber = $bout->fight_number;

            $bout->update(['fight_number' => $neighbour->fight_number]);
            $neighbour->update(['fight_number' => $boutNumber]);
        });

        return true;
    }

    /** Neither bout may end up ahead of something that feeds it. */
    private function swapKeepsOrder(Bout $a, Bout $b): bool
    {
        return ! $this->feeds($a, $b) && ! $this->feeds($b, $a);
    }

    /** Does $earlier feed $later, directly or through a chain of byes? */
    private function feeds(Bout $earlier, Bout $later): bool
    {
        $cursor = $earlier;

        while ($cursor->next_bout_id !== null) {
            if ($cursor->next_bout_id === $later->id) {
                return true;
            }

            $cursor = Bout::find($cursor->next_bout_id);

            if ($cursor === null) {
                return false;
            }
        }

        return false;
    }
}
