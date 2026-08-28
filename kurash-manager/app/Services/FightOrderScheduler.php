<?php

namespace App\Services;

use App\Models\Bout;
use App\Models\Championship;
use App\Support\DisplayCache;
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
 * ── The order the federation numbers in ──────────────────────────────────
 *
 * One sequence for the whole championship, starting at 1 and with no gaps:
 *
 *   round by round     every class's first round is fought before any class's
 *                      second, so the classes progress together rather than
 *                      one running to its final while another has not started
 *   lightest first     within a round the classes follow the scale — -60
 *                      before -66 before -73, and "+100" after "-100"
 *   as it was drawn    within a class the contests keep the order the draw
 *                      gave them, by position_in_round
 *
 * "Lightest" is a question about kilograms, and it is asked of the class's own
 * limit — see WeightLimit. Sorting classes by id gives the order somebody
 * typed them in; sorting the labels as text puts "-100" ahead of "-60".
 *
 * ── What a round is, in each format ──────────────────────────────────────
 *
 * Both formats have rounds and they are not the same thing:
 *
 *   knockout      one stage of the bracket — the first stage, then the next,
 *                 on through the semi-finals to the final
 *   round robin   one complete playing round, a matchday: everybody not
 *                 sitting out has exactly one contest in it, and every
 *                 pairing of that matchday carries the same round number
 *
 * A championship running both aligns them by that number, so round 2 of a
 * bracket and round 2 of a round robin are fought in the same part of the day.
 * Classes are not the same depth — a field of four is done in two rounds while
 * a field of sixteen has four — so a class with nothing to fight in a round
 * simply does not appear in it.
 *
 * Nothing is lifted out of its round, a final included: a final is fed by
 * contests in earlier rounds, so numbering by round has already placed it
 * after all of them.
 *
 * ── What takes no number ─────────────────────────────────────────────────
 *
 * A bracket's walkover. Nobody steps onto a mat for one, so numbering it would
 * leave a hole in the sequence that an operator has to explain.
 *
 * The odd athlete's rest in a round robin needs no skipping here, because it
 * was never written down: RoundRobinGenerator pairs the odd one out against a
 * phantom and drops the pairing rather than storing a contest nobody fights.
 * A round robin therefore has no bye rows at all.
 *
 * ── Rest is reported, not arranged ───────────────────────────────────────
 *
 * Two things the old workflow never checked are guaranteed by that order
 * alone: a bout is always numbered after both of the bouts that feed it, and
 * every contest is numbered exactly once.
 *
 * A third — that whoever advances gets a rest — is not, and is deliberately
 * not arranged for. The order above is fixed by the competition rules, and an
 * official reading the printed sheet has to be able to work out the next
 * number from it. Where it leaves somebody short of rest, and the closing
 * rounds always do because there are too few contests left to sit between a
 * semi-final and its final, restViolations() and unattainableRest() say so and
 * the organisers schedule a break. Shuffling contests until the shortfall left
 * the screen would hide it rather than fix it, and would buy that at the price
 * of a running order nobody can predict. See unattainableRest() for the rest
 * no order at all could have delivered.
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
     * Round by round, lightest class first within a round, and the drawn
     * order within a class — the class docblock says why each of those is the
     * way round it is, and what a round means in each format.
     *
     * Rebuilt from nothing on every run, so the same draws always produce the
     * same sequence and a class added late does not shift the numbers already
     * on somebody's printed sheet by a different amount than a rerun would.
     *
     * @param  int|null  $minimumRest  the rest the report is measured against —
     *                                 it does not move anything; null takes the
     *                                 configured kurash.round_robin.minimum_rest
     * @return array{scheduled:int, violations:int, unattainable:int}
     */
    public function schedule(Championship $championship, ?int $minimumRest = null): array
    {
        $minimumRest ??= self::configuredRest();

        $bouts = $this->schedulableBouts($championship);

        if ($bouts->isEmpty()) {
            return ['scheduled' => 0, 'violations' => 0, 'unattainable' => 0];
        }

        $ordered = $this->runningOrder($bouts);

        DB::transaction(function () use ($championship, $ordered) {
            // Clear first: fight_number has no uniqueness constraint, but a
            // partial renumber that left old values behind would read as a
            // valid order and send the wrong bout to the mat.
            $championship->bouts()->update(['fight_number' => null]);

            foreach ($ordered as $index => $bout) {
                Bout::whereKey($bout->id)->update(['fight_number' => $index + 1]);
            }
        });

        // Both writes above go through the query builder, which does not fire
        // model events, so BoutObserver never sees them. The running order is
        // the whole content of one venue screen and half of another, and
        // without this the hall keeps showing the previous order until the
        // five-minute backstop expires.
        DisplayCache::bump($championship->id);

        return [
            'scheduled' => $ordered->count(),
            'violations' => $this->restViolations($championship, $minimumRest)->count(),
            // Told apart from the violations above on purpose: one is a rest
            // the day's order does not leave, which an administrator can put a
            // break into, and this is a rest that no order at all could have
            // delivered, which only a longer session would fix.
            'unattainable' => $this->unattainableRest($championship, $minimumRest)->count(),
        ];
    }

    public function clear(Championship $championship): void
    {
        $championship->bouts()->update(['fight_number' => null]);

        // As in schedule(): a bulk update fires no model events, and an
        // emptied running order that the hall cannot see is worse than a
        // wrong one, because nothing on screen suggests it has changed.
        DisplayCache::bump($championship->id);
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
     * The whole championship in the order it is numbered.
     *
     * Every round in turn, and within a round every class in turn. Position in
     * this collection is the fight number, less one.
     *
     * @param  Collection<int, Bout>  $bouts
     * @return Collection<int, Bout>
     */
    private function runningOrder(Collection $bouts): Collection
    {
        $weights = $this->weightOrder($bouts);

        $ordered = collect();

        // sortKeys() and not the order the rows arrived in: round is an integer
        // column, and a database free to return them in any order is free to
        // return round 2 first. A round no class has a contest in cannot
        // appear, because the rounds come from the contests themselves.
        foreach ($bouts->groupBy('round')->sortKeys() as $round) {
            $ordered = $ordered->concat($this->lightestFirst($round, $weights));
        }

        return $ordered->values();
    }

    /**
     * One round's contests: lightest class first, and within a class the order
     * the draw gave them.
     *
     * @param  Collection<int, Bout>  $bouts
     * @param  array<int, array{float, int, int, int}>  $weights
     * @return Collection<int, Bout>
     */
    private function lightestFirst(Collection $bouts, array $weights): Collection
    {
        return $bouts->sortBy(fn (Bout $bout): array => [
            // A contest whose class has gone — an edit mid-competition — sorts
            // last rather than crashing the numbering of the classes that are
            // still there.
            ...($weights[$bout->weight_category_id] ?? [INF, 1, PHP_INT_MAX, PHP_INT_MAX]),
            $bout->position_in_round,
        ])->values();
    }

    /**
     * Where each class sits on the scale, resolved once for the whole run.
     *
     * Keyed by class rather than asked per bout: a bracket of thirty-two asks
     * the same question thirty-one times, and the answer parses a label.
     *
     * @param  Collection<int, Bout>  $bouts
     * @return array<int, array{float, int, int, int}>
     */
    private function weightOrder(Collection $bouts): array
    {
        $keys = [];

        foreach ($bouts as $bout) {
            $category = $bout->weightCategory;

            if ($category === null || isset($keys[$category->id])) {
                continue;
            }

            $keys[$category->id] = [
                ...$category->weightLimit()->sortKey(),
                // Two classes can name the same limit — a men's -63 and a
                // women's -63 run on the same day. Settled by the order they
                // are displayed in and then by id, so the running order comes
                // out the same on every run instead of following whatever the
                // database happened to return.
                $category->sort_order,
                $category->id,
            ];
        }

        return $keys;
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
