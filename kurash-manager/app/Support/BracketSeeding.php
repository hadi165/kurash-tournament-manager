<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Single-elimination seeding, as the federation draws it.
 *
 * This is the one source of truth for bracket shape. Draw generation, the
 * bracket screen, the draw ceremony, the presentation, the PDF and Excel
 * exports and match generation all read it — because the original system
 * derived bracket size by three different rules in three places
 * (bracket-helpers.php started its ladder at 2, the API at 4), so a
 * two-athlete category rendered as a two-slot tree and generated a four-slot
 * bracket. Nothing outside this class is allowed an opinion about which seed
 * sits where.
 *
 * ORDER carries the official first-round pairings, top of the sheet to bottom,
 * for every size the federation runs. Two things about them are load-bearing
 * and neither can be derived:
 *
 *   - the order of the rows, which is the order the sheet is read in and the
 *     order bouts are numbered in
 *   - the order *within* each pair, which is which athlete wears blue and
 *     which wears green
 *
 * A recursive construction produces the same pairings as unordered sets, but
 * not the same rows and not the same colours, which is why these are written
 * out rather than computed. Sizes beyond the table fall back to the
 * construction, so a 64-athlete field still draws rather than failing.
 */
final class BracketSeeding
{
    public const MAX_SIZE = 128;

    /**
     * The official first-round pairings, by bracket size.
     *
     * Read top to bottom. The first seed of each pair takes the blue yakhtak.
     *
     * @var array<int, list<array{int, int}>>
     */
    public const OFFICIAL_PAIRS = [
        2 => [[1, 2]],

        4 => [[1, 4], [2, 3]],

        8 => [[1, 8], [5, 4], [3, 6], [7, 2]],

        16 => [
            [1, 16], [9, 8], [5, 12], [13, 4],
            [3, 14], [11, 6], [7, 10], [15, 2],
        ],

        32 => [
            [1, 32], [16, 17], [9, 24], [8, 25],
            [5, 28], [12, 21], [13, 20], [4, 29],
            [3, 30], [14, 19], [11, 22], [6, 27],
            [7, 26], [10, 23], [15, 18], [31, 2],
        ],
    ];

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
     * Derived from the pairings rather than the other way round, so there is
     * exactly one place a seed order is written down and the flattened list
     * cannot drift away from the pairs the sheet is drawn from.
     *
     * @return list<int>
     */
    public static function order(int $size): array
    {
        self::assertPowerOfTwo($size);

        if ($size === 1) {
            return [1];
        }

        $order = [];

        foreach (self::firstRoundPairs($size) as [$top, $bottom]) {
            $order[] = $top;
            $order[] = $bottom;
        }

        return $order;
    }

    /**
     * Round-one pairings as [blueSeed, greenSeed] tuples, top of the sheet
     * first.
     *
     * @return list<array{int, int}>
     */
    public static function firstRoundPairs(int $size): array
    {
        if ($size < 2) {
            return [];
        }

        self::assertPowerOfTwo($size);

        return self::OFFICIAL_PAIRS[$size] ?? self::constructedPairs($size);
    }

    /**
     * Pairings for a size the official table does not cover.
     *
     * Each round doubles the bracket by placing every existing seed next to its
     * complement, which is what keeps 1 and 2 apart until the final:
     *
     *   [1]  →  [1, 2]  →  [1, 4, 2, 3]  →  [1, 8, 4, 5, 2, 7, 3, 6]
     *
     * Only reached above 32, which is past anything the federation schedules.
     * A field that large still has to draw rather than fail, but the rows it
     * produces are this construction's and not the federation's.
     *
     * @return list<array{int, int}>
     */
    private static function constructedPairs(int $size): array
    {
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

        $pairs = [];

        // Chunked rather than indexed: the loop doubles a power-of-two list so
        // every chunk is a full pair, and saying so this way is provable rather
        // than merely true.
        foreach (array_chunk($order, 2) as $pair) {
            if (count($pair) === 2) {
                $pairs[] = [$pair[0], $pair[1]];
            }
        }

        return $pairs;
    }

    private static function assertPowerOfTwo(int $size): void
    {
        if ($size < 1 || ($size & ($size - 1)) !== 0) {
            throw new InvalidArgumentException("Bracket size must be a power of two, got {$size}.");
        }
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

    /**
     * How a field of this size is written on a schedule — x/2 through x/32.
     *
     * The bracket level, not the phase: x/8 is an eight-slot draw opening at
     * the quarter-finals. Derived from the field rather than assumed, because
     * measuring every weight class against a fixed sixteen is what made a
     * three-athlete class read as a tenth full instead of as a four-draw.
     */
    public static function level(int $athleteCount): string
    {
        $size = self::size($athleteCount);

        return $size < 2 ? '—' : "x/{$size}";
    }

    /** Number of bouts in a given round of a bracket. */
    public static function boutsInRound(int $size, int $round): int
    {
        return intdiv($size, 2 ** $round);
    }
}
