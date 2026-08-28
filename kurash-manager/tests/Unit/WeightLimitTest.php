<?php

use App\Models\WeightCategory;
use App\Support\WeightLimit;

/**
 * Where a class sits on the scale, read off the name the federation gives it.
 *
 * The running order is numbered lightest class first, so this is the answer to
 * "which of these two runs first" — and the two obvious ways of asking it are
 * both wrong. By id it is whoever was typed in first. By label as text, "-100"
 * comes before "-60", which puts the heaviest athletes of the day on the first
 * mat of the morning.
 */
describe('reading a class label', function () {
    it('takes the figure the class is named for', function (string $label, float $kg) {
        expect(WeightLimit::fromLabel($label)?->kg)->toBe($kg);
    })->with([
        'the usual form' => ['-66', 66.0],
        'the unit spelled out' => ['-66 kg', 66.0],
        'no space before the unit' => ['-66kg', 66.0],
        'an open class' => ['+100 kg', 100.0],
        'the sign written last' => ['100+', 100.0],
        'no sign at all' => ['66', 66.0],
        'a half-kilogram class' => ['-67.5', 67.5],
        // Written this way everywhere the decimal separator is a comma, and
        // stopping at the separator would turn 67,5 kg into a 67 kg class.
        'a decimal comma' => ['67,5 kg', 67.5],
        // A class written as the band it accepts is ordered by its ceiling,
        // which is the second of the two figures and not the first.
        'a band' => ['60-66 kg', 66.0],
    ]);

    /** The one thing the sign is read for. */
    it('tells an open class from a bounded one', function (string $label, bool $open) {
        expect(WeightLimit::fromLabel($label)?->open)->toBe($open);
    })->with([
        ['-100', false],
        ['100', false],
        ['+100', true],
        ['100+', true],
    ]);

    /**
     * A label with no figure in it is not a mistake to guess at — the stored
     * bounds are asked next. See WeightCategory::weightLimit().
     */
    it('gives no answer for a label with no figure in it', function (string $label) {
        expect(WeightLimit::fromLabel($label))->toBeNull();
    })->with(['Open', 'Absolute', '', 'kg']);

    it('gives no answer for no label at all', function () {
        expect(WeightLimit::fromLabel(null))->toBeNull();
    });
});

describe('putting classes in order', function () {
    it('sorts lightest first, whatever the labels look like as text', function () {
        $labels = ['+100', '-60', '-100', '-73', '-66'];

        $sorted = collect($labels)
            ->sortBy(fn (string $label) => WeightLimit::fromLabel($label)?->sortKey())
            ->values()
            ->all();

        expect($sorted)->toBe(['-60', '-66', '-73', '-100', '+100']);
    });

    /**
     * Same figure, two classes, and a class nothing could be read from at all.
     * The open class is the heavier of the pair, and the unknown one sorts
     * last: an unreadable label is a configuration mistake, and it costs less
     * at the end of the day than at the start of it.
     */
    it('ranks an open class above its bounded twin, and an unknown one last', function () {
        $limits = [
            'unknown' => new WeightLimit(null),
            'open 100' => new WeightLimit(100.0, open: true),
            'up to 100' => new WeightLimit(100.0),
        ];

        expect(array_keys(collect($limits)->sortBy(fn (WeightLimit $l) => $l->sortKey())->all()))
            ->toBe(['up to 100', 'open 100', 'unknown']);
    });
});

/**
 * The label answers this wherever it can, because it is the only column that
 * is always there and it is what min_kg and max_kg are derived from. These are
 * the classes it cannot answer for.
 */
describe('a class the label does not describe', function () {
    it('falls back to the bounds the class was configured with', function (
        ?float $min,
        ?float $max,
        ?float $kg,
        bool $open,
    ) {
        $limit = (new WeightCategory(['label' => 'Absolute', 'min_kg' => $min, 'max_kg' => $max]))->weightLimit();

        expect($limit->kg)->toBe($kg)
            ->and($limit->open)->toBe($open);
    })->with([
        'a ceiling' => [60.0, 66.0, 66.0, false],
        // A floor and no ceiling is what an open class *is*, whatever it was
        // named — so it still sorts above the bounded class below it.
        'a floor and no ceiling' => [100.0, null, 100.0, true],
        'neither' => [null, null, null, false],
    ]);
});
