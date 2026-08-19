<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Standard single-elimination seeding.
 *
 * The original system hard-coded these orders in $firstRound / $secondRound
 * lookup tables for sizes 4, 8, 16, 32 and 64. Those tables were correct, but
 * they could not express a 2-slot bracket, and bracket size was derived by
 * three different rules in three places (bracket-helpers.php started its
 * ladder at 2, the API at 4) — so a two-athlete category rendered as a
 * two-slot tree and generated a four-slot bracket.
 *
 * The recursive construction below produces the identical pairings for every
 * size the old tables covered, and works for any power of two.
 */
final class BracketSeeding
{
    public const MAX_SIZE = 128;

    /**
     * Smallest power of two that seats this many athletes.
     * Computed by doubling rather than ceil(log()) to avoid the floating-point
     * edge where log(8, 2) comes back as 2.9999999.
     */
    public static function size(int $athleteCount): int
    {
        if ($athleteCount < 0) {
            throw new InvalidArgumentException('Athlete count cannot be negative.');
        }

        if ($athleteCount === 0) {
            return 0;
        }

        $size = 1;
        while ($size < $athleteCount) {
            $size *= 2;

            if ($size > self::MAX_SIZE) {
                throw new InvalidArgumentException(
                    "Bracket of {$athleteCount} athletes exceeds the maximum of ".self::MAX_SIZE.'.'
                );
            }
        }

        return $size;
    }

    /**
     * Seed positions in bracket order, top slot first.
     *
     * Each round doubles the bracket by placing every existing seed next to
     * its complement, which is what keeps 1 and 2 apart until the final:
     *
     *   [1]  →  [1, 2]  →  [1, 4, 2, 3]  →  [1, 8, 4, 5, 2, 7, 3, 6]
     *
     * @return list<int>
     */
    public static function order(int $size): array
    {
        if ($size < 1 || ($size & ($size - 1)) !== 0) {
            throw new InvalidArgumentException("Bracket size must be a power of two, got {$size}.");
        }

        $order = [1];

        while (count($order) < $size) {
            $complement = count($order) * 2 + 1;
            $next = [];

            foreach ($order as $seed) {
                $next[] = $seed;
                $next[] = $complement - $seed;
            }

            $order = $next;
        }

        return $order;
    }

    /**
     * Round-one pairings as [topSeed, bottomSeed] tuples.
     *
     * @return list<array{int, int}>
     */
    public static function firstRoundPairs(int $size): array
    {
        if ($size < 2) {
            return [];
        }

        // Paired explicitly rather than with array_chunk, so each element is a
        // two-element tuple by construction. order() always returns a
        // power-of-two length, so the second index is always present.
        $order = self::order($size);
        $pairs = [];

        for ($i = 0; $i < count($order); $i += 2) {
            $pairs[] = [$order[$i], $order[$i + 1]];
        }

        return $pairs;
    }

    public static function totalRounds(int $size): int
    {
        return $size < 2 ? 0 : (int) round(log($size, 2));
    }

    /**
     * The highest phase a field of this size opens at — the "Bracket Title" the
     * confirmed weigh-in list carries, so officials know what they are drawing
     * before the bracket exists. 2 athletes go straight to a Final, 5 to 8 open
     * at the quarter-finals, and so on.
     */
    public static function phaseName(int $athleteCount): string
    {
        $rounds = self::totalRounds(self::size($athleteCount));

        return match ($rounds) {
            0 => '—',
            1 => 'Final',
            2 => 'Semi Final',
            3 => '1/4 Final',
            4 => '1/8 Final',
            5 => '1/16 Final',
            6 => '1/32 Final',
            default => "Round of {$athleteCount}",
        };
    }

    /** Number of bouts in a given round of a bracket. */
    public static function boutsInRound(int $size, int $round): int
    {
        return intdiv($size, 2 ** $round);
    }
}
