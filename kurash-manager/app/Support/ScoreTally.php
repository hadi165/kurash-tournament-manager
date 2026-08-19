<?php

namespace App\Support;

/**
 * What one athlete has been awarded in a contest so far.
 *
 * Built by folding that bout's scoring events, never stored as a column — so
 * voiding a mistaken call and recomputing gives exactly the tally that would
 * have existed had the call never been made.
 */
final readonly class ScoreTally
{
    public function __construct(
        public int $halal = 0,
        public int $yonbosh = 0,
        public int $chala = 0,
        public int $tanbeh = 0,
    ) {}

    /**
     * Has this athlete scored enough to end the contest outright?
     *
     * A halal ends it on the spot, and the configured number of yonbosh add up
     * to one. Chala never accumulates into anything larger however many are
     * awarded — that is the rule the encoding below has to respect too.
     */
    public function isDecisive(): bool
    {
        return $this->halal > 0
            || $this->yonbosh >= (int) config('kurash.yonbosh_for_halal', 2);
    }

    /** Have this athlete's warnings become dakki, awarding the contest against them? */
    public function isDakki(): bool
    {
        return $this->tanbeh >= (int) config('kurash.tanbeh_for_dakki', 3);
    }

    /**
     * A single number for the scoreboard column and the display screens.
     *
     * Yonbosh in the whole part, chala in the tenths. This is for showing only:
     * nothing compares two contests on it, because ten chala would read as one
     * yonbosh and chala must never add up to one. Ordering goes through
     * ScoreTally::beats(), which compares the counts themselves. Chala is
     * clamped at 9 so the encoding cannot overflow into the yonbosh place.
     */
    public function points(): float
    {
        if ($this->halal > 0) {
            return 10.0;
        }

        return $this->yonbosh + min($this->chala, 9) / 10;
    }

    /**
     * Does this tally beat the other on scores alone?
     *
     * Yonbosh first, then chala. Returns false when they are level, which is a
     * genuine outcome — a level contest is decided by the referees, not by this
     * method inventing a winner.
     */
    public function beats(self $other): bool
    {
        if ($this->yonbosh !== $other->yonbosh) {
            return $this->yonbosh > $other->yonbosh;
        }

        return $this->chala > $other->chala;
    }

    public function isLevelWith(self $other): bool
    {
        return $this->yonbosh === $other->yonbosh && $this->chala === $other->chala;
    }

    /** @return array{halal:int, yonbosh:int, chala:int, tanbeh:int} */
    public function toArray(): array
    {
        return [
            'halal' => $this->halal,
            'yonbosh' => $this->yonbosh,
            'chala' => $this->chala,
            'tanbeh' => $this->tanbeh,
        ];
    }

    public function with(string $call): self
    {
        return new self(
            halal: $this->halal + ($call === 'halal' ? 1 : 0),
            yonbosh: $this->yonbosh + ($call === 'yonbosh' ? 1 : 0),
            chala: $this->chala + ($call === 'chala' ? 1 : 0),
            tanbeh: $this->tanbeh + ($call === 'tanbeh' ? 1 : 0),
        );
    }
}
