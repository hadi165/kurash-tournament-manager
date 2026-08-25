<?php

namespace App\Support;

use App\Models\Bout;
use App\Models\Court;

/**
 * One mat, and whatever is on it.
 *
 * A value object rather than an array because every reader asks the same four
 * questions of it and each of them has a rule behind it — "the clock is
 * stopped" is not simply `! clock_running`, and a free mat has no clock at all.
 * Left as an array those rules would be rewritten in the panel, the venue
 * screen and every test that touches either.
 */
final readonly class MatState
{
    public function __construct(
        public Court $court,
        /** The contest being fought here, or null when the mat is free. */
        public ?Bout $bout,
    ) {}

    /** Is a contest being fought on this mat right now? */
    public function isLive(): bool
    {
        return $this->bout !== null;
    }

    /** Is the contest on this mat stopped for jazzo? */
    public function isInJazzo(): bool
    {
        return $this->bout?->isInJazzo() ?? false;
    }

    /**
     * Is the clock stopped for some reason other than jazzo?
     *
     * A clock that has never been started is not a stopped clock. `false` is
     * the column default, so without the clock_updated_at check every contest
     * sent to a mat and not yet begun reported "Clock stopped" — which is how
     * all four mats of a fresh competition ended up flying a warning that meant
     * nothing, and a warning that is always on is one nobody reads.
     *
     * Jazzo is excluded because it is reported in its own right, and a mat
     * labelled both says one thing twice. A free mat has no clock at all.
     */
    public function clockStopped(): bool
    {
        return $this->bout !== null
            && $this->bout->clock_updated_at !== null
            && ! $this->bout->clock_running
            && ! $this->bout->isInJazzo();
    }
}
