<?php

namespace App\Support;

/**
 * The band of weights one category will actually accept, tolerance included.
 *
 * A value object rather than two loose floats, because every caller that asks
 * whether a weight passes also wants to tell the athlete what would have — and
 * a pair of numbers passed around separately is how the two come to disagree.
 */
final readonly class WeightRange
{
    public function __construct(
        /** Inclusive lower bound, tolerance already applied. Null means none. */
        public ?float $min,
        /**
         * Inclusive upper bound, tolerance already added. Null means an open
         * class with no ceiling.
         */
        public ?float $max,
        /** The grace allowed either side of the nominal bounds, in kilograms. */
        public float $tolerance = 0.5,
        /** The nominal lower bound, before the tolerance was subtracted. */
        public ?float $nominalMin = null,
        /** The class's own limit, before the tolerance was added. */
        public ?float $nominalMax = null,
    ) {}

    public function admits(float $kg): bool
    {
        if ($this->min !== null && $kg < $this->min) {
            return false;
        }

        return $this->max === null || $kg <= $this->max;
    }

    /** Is this weight under the class rather than over it? */
    public function isUnder(float $kg): bool
    {
        return $this->min !== null && $kg < $this->min;
    }

    /** Is this weight over the class rather than under it? */
    public function isOver(float $kg): bool
    {
        return $this->max !== null && $kg > $this->max;
    }

    /**
     * "55.50 – 60.50 kg", or the half-open forms for the two ends of a
     * division. Printed on the weigh-in screen beside a rejection, so an
     * official can say what the athlete needed rather than only that they
     * missed — and it names what is actually accepted, tolerance included,
     * because that is the number the athlete has to get under.
     */
    public function label(): string
    {
        $format = fn (float $kg): string => rtrim(rtrim(number_format($kg, 3, '.', ''), '0'), '.');

        return match (true) {
            $this->min !== null && $this->max !== null => "{$format($this->min)} – {$format($this->max)} kg",
            $this->max !== null => "up to {$format($this->max)} kg",
            $this->min !== null => "{$format($this->min)} kg and above",
            default => 'any weight',
        };
    }
}
