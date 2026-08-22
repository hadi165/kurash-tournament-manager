<?php

namespace App\Support;

/**
 * What the weigh-in rules said about one reading.
 *
 * Carries the reason and the range as well as the answer, because "rejected"
 * on its own is not something an official can act on at the scale — they need
 * to know whether the athlete was over or under, and by how much.
 */
final readonly class WeightVerdict
{
    public function __construct(
        public bool $accepted,
        public WeightRange $range,
        public ?string $reason = null,
    ) {}

    /** The value written to athletes.weighin_status. */
    public function status(): string
    {
        return $this->accepted ? 'pass' : 'fail';
    }
}
