<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\Bout;
use App\Models\WeightCategory;
use App\Support\TournamentFormat;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Builds a round robin for one weight category: everybody meets everybody once.
 *
 * A sibling of BracketGenerator rather than a mode of it. A bracket is a tree
 * whose shape is decided before anybody fights, and a round robin is a
 * schedule of every pairing; forcing the second into the first would mean
 * inventing rounds nobody advances through and byes nobody receives, which is
 * exactly the fiction this class exists to avoid. Nothing here writes
 * next_bout_id, and nothing here creates a bout marked as a walkover.
 *
 * ── The circle method ────────────────────────────────────────────────────
 *
 * Athletes are laid out in two rows and one of them is held still while the
 * rest rotate around it. Each column of the two rows is a pairing, and after
 * n-1 turns of the circle every athlete has met every other exactly once.
 *
 *     round 1      round 2      round 3
 *     1 2 3 4      1 4 2 3      1 3 4 2      (1 is the fixed position)
 *     |x| |x|      |x| |x|      |x| |x|
 *     4 3          3 2          2 4
 *
 * An odd field gets a phantom seat to rotate against. Whoever is drawn against
 * it that round sits out — and that rest is *not written down as a contest*.
 * A bye row would be a bout nobody fights appearing in the fight order, the
 * exports and the standings, and a rest is none of those things.
 *
 * The rotation is deterministic: the same draw numbers give the same schedule
 * on every machine and every run, so a draw can be regenerated identically
 * after a correction rather than reshuffling the whole session.
 */
class RoundRobinGenerator
{
    /** The largest field this will build, matching the policy that offers it. */
    public const MAX_ATHLETES = TournamentFormatPolicy::SMALL_FIELD_MAX;

    /**
     * @return array{bouts:int, rounds:int, athletes:int, format:TournamentFormat}
     */
    public function generate(WeightCategory $category, bool $discardResults = false, bool $replacePublished = false): array
    {
        // The same protections a bracket has, for the same reasons: a published
        // draw is a document other people work from and a locked one is not
        // replaced without unlocking.
        if ($category->isDrawLocked()) {
            throw new DrawIsProtectedException(
                "The draw for {$category->label} is locked. Unlock it before drawing again."
            );
        }

        if ($category->isDrawPublished() && ! $replacePublished) {
            throw new DrawIsProtectedException(
                "The draw for {$category->label} is published. Withdraw it before drawing again."
            );
        }

        // The same guard the bracket keeps, for the same reason: a field short
        // of the numbers it was drawn from is a competition somebody has been
        // dropped out of without being told.
        $ineligible = $category->ineligibleNumberedAthletes()->get();

        if ($ineligible->isNotEmpty()) {
            throw new DrawEligibilityException(app(DrawEligibility::class)->refusal(
                $ineligible,
                __('The round robin for :class cannot be drawn.', ['class' => $category->label]),
            ));
        }

        $athletes = $category->drawnAthletes()->get();
        $count = $athletes->count();

        if ($count < 2) {
            throw new RuntimeException(
                "{$category->label} has fewer than two athletes — there is no round robin to draw."
            );
        }

        if ($count > self::MAX_ATHLETES) {
            throw new RuntimeException(
                "{$category->label} has {$count} athletes — a round robin is only drawn up to ".self::MAX_ATHLETES.'.'
            );
        }

        $decided = $category->bouts()->whereNotNull('winner_athlete_id')->where('is_bye', false)->count();

        if ($decided > 0 && ! $discardResults) {
            throw new BracketHasResultsException($decided);
        }

        $pairings = $this->schedule($athletes);
        $token = Str::lower(Str::random(4));

        return DB::transaction(function () use ($category, $count, $pairings, $token) {
            $category->bouts()->delete();

            $written = 0;

            foreach ($pairings as $round => $contests) {
                foreach ($contests as $position => [$a, $b]) {
                    Bout::create([
                        // The same shape a bracket's code has, and unique for
                        // the same reason: a late webhook from a discarded draw
                        // must not land on a freshly generated contest.
                        'play_code' => sprintf('%d-%02d-%03d-%s', $category->id, $round, $position, $token),
                        'championship_id' => $category->ageCategory->championship_id,
                        'age_category_id' => $category->age_category_id,
                        'weight_category_id' => $category->id,
                        'round' => $round,
                        'position_in_round' => $position,
                        'athlete_a_id' => $a->id,
                        'athlete_b_id' => $b->id,
                        // The draw number, so a sheet can say who was drawn
                        // where. It is not a seeding: nothing in a round robin
                        // is seeded, because everybody meets everybody.
                        'seed_a' => $a->draw_number,
                        'seed_b' => $b->draw_number,
                        // Left null on purpose. Nobody advances out of a round
                        // robin contest, and a forward link would make
                        // BoutAdvancer carry a winner into a contest that is
                        // somebody else's fixture.
                        'next_bout_id' => null,
                        'next_bout_slot' => null,
                        'status' => Bout::STATUS_PENDING,
                    ]);

                    $written++;
                }
            }

            $category->forceFill([
                'draw_generated_at' => now(),
                'draw_athlete_count' => $count,
                // A round robin has no bucket to round up to and nobody sits
                // out, so the two bracket figures are cleared rather than left
                // describing whatever was drawn here before.
                'draw_bucket_size' => null,
                'draw_bye_count' => 0,
                'draw_format' => TournamentFormat::RoundRobin->value,
                'draw_version' => $category->draw_version + 1,
                'draw_published_at' => null,
                'draw_placement_athlete_id' => null,
                'draw_placement_by' => null,
                'draw_placement_at' => null,
            ])->save();

            return [
                'bouts' => $written,
                'rounds' => count($pairings),
                'athletes' => $count,
                'format' => TournamentFormat::RoundRobin,
            ];
        });
    }

    /**
     * How many contests a field of this size produces: every unordered pair.
     *
     * Stated rather than counted so a caller can tell an administrator what
     * drawing will cost before it happens.
     */
    public static function contestsFor(int $athletes): int
    {
        return $athletes < 2 ? 0 : intdiv($athletes * ($athletes - 1), 2);
    }

    /**
     * How many rounds it takes.
     *
     * An even field pairs everybody every round and needs n-1 of them. An odd
     * field can only pair n-1 of its athletes at a time, so it needs n rounds
     * and somebody rests in each.
     */
    public static function roundsFor(int $athletes): int
    {
        if ($athletes < 2) {
            return 0;
        }

        return $athletes % 2 === 0 ? $athletes - 1 : $athletes;
    }

    /**
     * The whole schedule, round by round, as pairs of athletes.
     *
     * Round numbers start at one, to read the way every other round in this
     * system reads. Positions within a round start at zero, matching the
     * bracket's own convention and the unique index on the bouts table.
     *
     * @param  Collection<int, Athlete>  $athletes
     * @return array<int, list<array{0:Athlete, 1:Athlete}>>
     */
    public function schedule(Collection $athletes): array
    {
        // The draw order, which is the order the draw itself handed out — the
        // same list and the same ordering a bracket seats from.
        $field = $athletes->values()->all();
        $count = count($field);

        // The phantom the odd one out is paired against. Held as null so it can
        // never be mistaken for an athlete, and never written anywhere.
        $rest = null;

        if ($count % 2 === 1) {
            $field[] = $rest;
            $count++;
        }

        $fixed = array_shift($field);
        $rotating = $field;

        $schedule = [];
        $rounds = self::roundsFor($athletes->count());

        for ($round = 1; $round <= $rounds; $round++) {
            $contests = [];

            // The fixed position meets the head of the rotation.
            $pairs = [[$fixed, $rotating[0]]];

            // Then the rest of the circle folds in on itself: second against
            // last, third against second-last, and so on.
            $half = intdiv($count, 2);

            for ($i = 1; $i < $half; $i++) {
                $pairs[] = [$rotating[$i], $rotating[count($rotating) - $i]];
            }

            foreach ($pairs as $index => $pair) {
                // The pairing against the phantom is the rest. It is dropped
                // here and nothing is written for it.
                if ($pair[0] === null || $pair[1] === null) {
                    continue;
                }

                $contests[] = $this->balance($pair, $round, $index);
            }

            $schedule[$round] = $contests;

            // Turn the circle: the head goes to the back, everything else
            // shuffles up one. Written in two statements because `$a[] =
            // array_shift($a)` leaves the order the two halves are evaluated in
            // up to the engine.
            $head = array_shift($rotating);
            $rotating[] = $head;
        }

        return $schedule;
    }

    /**
     * Which of the pair takes the blue corner.
     *
     * The circle already balances everybody who rotates: an athlete moves
     * between the two halves of the circle as it turns, so their side changes
     * with them. The one exception is the athlete held still, who would
     * otherwise take the same corner in every contest they fight — the same
     * yakhtak all afternoon, decided by nothing but being first in the list.
     *
     * So only that pairing is turned around, and only on alternate rounds.
     * Swapping every pair instead would not spread the sides — it would move
     * the imbalance onto somebody else, because reversing a whole round
     * reverses the rotation's own alternation along with it.
     *
     * This decides sides, never who meets whom.
     *
     * @param  array{0:Athlete|null, 1:Athlete|null}  $pair
     * @return array{0:Athlete, 1:Athlete}
     */
    private function balance(array $pair, int $round, int $index): array
    {
        /** @var array{0:Athlete, 1:Athlete} $pair */
        return $index === 0 && $round % 2 === 0 ? [$pair[1], $pair[0]] : $pair;
    }
}
