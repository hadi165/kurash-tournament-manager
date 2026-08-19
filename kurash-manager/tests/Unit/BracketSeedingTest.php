<?php

use App\Support\BracketSeeding;

/**
 * The original system hard-coded seeding tables for sizes 4 through 64. These
 * tests pin the generated order against those known-good tables, so the
 * algorithmic version cannot silently drift from the pairings the federation
 * has always used.
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
    it('matches the hard-coded order the old system used', function (int $size, array $expected) {
        expect(BracketSeeding::order($size))->toBe($expected);
    })->with([
        'size 2' => [2, [1, 2]],
        'size 4' => [4, [1, 4, 2, 3]],
        'size 8' => [8, [1, 8, 4, 5, 2, 7, 3, 6]],
        'size 16' => [16, [1, 16, 8, 9, 4, 13, 5, 12, 2, 15, 7, 10, 3, 14, 6, 11]],
    ]);

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

    it('keeps the top four seeds in four different quarters', function () {
        $order = BracketSeeding::order(16);
        $quarters = [];

        foreach ([1, 2, 3, 4] as $seed) {
            $quarters[] = intdiv(array_search($seed, $order, true), 4);
        }

        expect(array_unique($quarters))->toHaveCount(4);
    });
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
