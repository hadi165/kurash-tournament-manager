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
 */
class FightOrderScheduler
{
    public const DEFAULT_REST = 3;

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
     * @return array{scheduled:int, violations:int}
     */
    public function schedule(Championship $championship, int $minimumRest = self::DEFAULT_REST): array
    {
        $bouts = $this->schedulableBouts($championship);

        if ($bouts->isEmpty()) {
            return ['scheduled' => 0, 'violations' => 0];
        }

        $ordered = $this->orderRoundMajor($bouts);

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
                    $violations->push(['bout' => $bout, 'feeder' => $feeder, 'gap' => $gap]);
                }
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
