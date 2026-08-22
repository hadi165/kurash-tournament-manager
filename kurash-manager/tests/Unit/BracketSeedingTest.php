<?php

use App\Support\BracketSeeding;

/**
 * The federation publishes the first-round order for every bracket it runs,
 * top of the sheet to bottom, and the order within each pair decides which
 * athlete wears blue. These tests pin all five tables verbatim, and then check
 * that what they describe is a structurally sound draw — because a table
 * transcribed with two rows swapped would still look like a bracket.
 */
describe('bracket size', function () {
    it('is the next power of two', function (int $athletes, int $expected) {
        expect(BracketSeeding::size($athletes))->toBe($expected);
    })->with([
        [1, 1], [2, 2], [3, 4], [4, 4], [5, 8], [7, 8], [8, 8],
        [9, 16], [16, 16], [17, 32], [32, 32], [33, 64], [64, 64],
    ]);

    it('rejects a field larger than the maximum bracket', function () {
        BracketSeeding::size(BracketSeeding::MAX_SIZE + 1);
    })->throws(InvalidArgumentException::class);

    it('reports no bracket for an empty field', function () {
        expect(BracketSeeding::size(0))->toBe(0);
    });
});

describe('seed order', function () {
    /**
     * The official tables, transcribed from the rules. Written out as pairs
     * rather than as a flat list because that is the form the sheet is read in
     * — and because the pair order is itself a rule: the first seed of each
     * pair takes the blue yakhtak.
     */
    it('matches the federation table exactly', function (int $size, array $expected) {
        expect(BracketSeeding::firstRoundPairs($size))->toBe($expected);
    })->with([
        'x/2' => [2, [[1, 2]]],
        'x/4' => [4, [[1, 4], [2, 3]]],
        'x/8' => [8, [[1, 8], [5, 4], [3, 6], [7, 2]]],
        'x/16' => [16, [
            [1, 16], [9, 8], [5, 12], [13, 4],
            [3, 14], [11, 6], [7, 10], [15, 2],
        ]],
        'x/32' => [32, [
            [1, 32], [16, 17], [9, 24], [8, 25],
            [5, 28], [12, 21], [13, 20], [4, 29],
            [3, 30], [14, 19], [11, 22], [6, 27],
            [7, 26], [10, 23], [15, 18], [31, 2],
        ]],
    ]);

    it('flattens to the same order the pairs describe', function (int $size) {
        $flat = [];

        foreach (BracketSeeding::firstRoundPairs($size) as [$top, $bottom]) {
            $flat[] = $top;
            $flat[] = $bottom;
        }

        expect(BracketSeeding::order($size))->toBe($flat);
    })->with([2, 4, 8, 16, 32]);

    it('rejects a size that is not a power of two', function () {
        BracketSeeding::order(6);
    })->throws(InvalidArgumentException::class);

    it('uses every seed exactly once', function (int $size) {
        $order = BracketSeeding::order($size);

        expect($order)->toHaveCount($size);
        expect(array_unique($order))->toHaveCount($size);
        expect(min($order))->toBe(1);
        expect(max($order))->toBe($size);
    })->with([2, 4, 8, 16, 32, 64, 128]);

    it('pairs every seed with its complement in round one', function (int $size) {
        foreach (BracketSeeding::firstRoundPairs($size) as [$a, $b]) {
            expect($a + $b)->toBe($size + 1);
        }
    })->with([2, 4, 8, 16, 32, 64]);

    it('keeps the top two seeds apart until the final', function (int $size) {
        // Seeds 1 and 2 must sit in opposite halves of the draw.
        $order = BracketSeeding::order($size);
        $half = $size / 2;

        $positionOfOne = array_search(1, $order, true);
        $positionOfTwo = array_search(2, $order, true);

        expect($positionOfOne < $half)->not->toBe($positionOfTwo < $half);
    })->with([4, 8, 16, 32, 64]);

    it('keeps the top four seeds in four different quarters', function (int $size) {
        $order = BracketSeeding::order($size);
        $perQuarter = $size / 4;
        $quarters = [];

        foreach ([1, 2, 3, 4] as $seed) {
            $quarters[] = intdiv((int) array_search($seed, $order, true), $perQuarter);
        }

        expect(array_unique($quarters))->toHaveCount(4);
    })->with([8, 16, 32]);

    /**
     * The real test of a transcribed table: the top eight seeds must land in
     * eight different eighths, or two of them meet a round earlier than the
     * draw intends and the table is wrong however plausible it looks.
     */
    it('keeps the top eight seeds in eight different eighths', function (int $size) {
        $order = BracketSeeding::order($size);
        $perEighth = $size / 8;
        $eighths = [];

        foreach (range(1, 8) as $seed) {
            $eighths[] = intdiv((int) array_search($seed, $order, true), $perEighth);
        }

        expect(array_unique($eighths))->toHaveCount(8);
    })->with([16, 32]);
});

describe('bracket level', function () {
    /**
     * What a schedule calls the draw. Measured off the field rather than
     * assumed: every weight class used to be read against a fixed sixteen, so
     * a three-athlete class showed as a tenth full instead of as an x/4.
     */
    it('names the draw a field of this size opens at', function (int $athletes, string $level) {
        expect(BracketSeeding::level($athletes))->toBe($level);
    })->with([
        [2, 'x/2'],
        [3, 'x/4'], [4, 'x/4'],
        [5, 'x/8'], [8, 'x/8'],
        [9, 'x/16'], [16, 'x/16'],
        [17, 'x/32'], [32, 'x/32'],
    ]);

    it('has no level for a field that cannot fight', function () {
        expect(BracketSeeding::level(1))->toBe('—')
            ->and(BracketSeeding::level(0))->toBe('—');
    });

    /** The stages §17 tabulates, checked against the phase the draw opens at. */
    it('opens at the stage the rules tabulate', function (int $athletes, string $phase) {
        expect(BracketSeeding::phaseName($athletes))->toBe($phase);
    })->with([
        [2, 'Final'],
        [3, 'Semi Final'], [4, 'Semi Final'],
        [5, '1/4 Final'], [8, '1/4 Final'],
        [9, '1/8 Final'], [16, '1/8 Final'],
        [17, '1/16 Final'], [32, '1/16 Final'],
    ]);
});

describe('round arithmetic', function () {
    it('counts rounds as log2 of the bracket', function (int $size, int $rounds) {
        expect(BracketSeeding::totalRounds($size))->toBe($rounds);
    })->with([[2, 1], [4, 2], [8, 3], [16, 4], [32, 5], [64, 6]]);

    it('halves the field each round', function () {
        expect(BracketSeeding::boutsInRound(16, 1))->toBe(8)
            ->and(BracketSeeding::boutsInRound(16, 2))->toBe(4)
            ->and(BracketSeeding::boutsInRound(16, 3))->toBe(2)
            ->and(BracketSeeding::boutsInRound(16, 4))->toBe(1);
    });
});
