<?php

namespace App\Support;

use App\Services\KurashScore;

/**
 * What one athlete stands at in a contest.
 *
 * Built by folding that bout's event log, never stored as a column — so taking
 * back a mistaken call and recomputing gives exactly the tally that would have
 * existed had the call never been made.
 *
 * Scores (khalol, yonbosh, chala) are what this athlete was awarded. Penalties
 * (tanbeh, dakki, girrom, madichal) are what was awarded against them: a
 * penalty is recorded on the side that committed it, and whatever it transfers
 * to the opponent arrives as a separate award on the opponent's side. Neither
 * count is ever inferred from the other.
 */
final readonly class ScoreTally
{
    public function __construct(
        public int $khalol = 0,
        public int $yonbosh = 0,
        public int $chala = 0,
        public int $tanbeh = 0,
        public int $dakki = 0,
        public int $girrom = 0,
        public int $madichal = 0,
        /**
         * The part of the above this athlete earned with a technique, rather
         * than was handed by the opponent's penalties.
         *
         * Kept apart because a contest level at time is decided partly on it:
         * two athletes on one yonbosh each are not level if one threw for it
         * and the other was given it when their opponent collected a dakki.
         */
        public int $earnedYonbosh = 0,
        public int $earnedChala = 0,
        /**
         * Sequence number of the most recent penalty against this athlete, or
         * zero for an athlete who has taken none.
         *
         * The last tie-break in the rules is the latest warning: level on
         * everything else, whoever was warned most recently loses. Zero sorting
         * ahead of every real sequence number is what makes "no penalty at all"
         * beat "a penalty, but an early one" without a special case.
         */
        public int $lastPenaltyAt = 0,
    ) {}

    /**
     * Has this athlete scored enough to end the contest outright?
     *
     * A khalol ends it on the spot, and the configured number of yonbosh add up
     * to one — however each yonbosh was reached, because a yonbosh conceded
     * through the opponent's dakki is a yonbosh on the board. Chala never
     * accumulates into anything larger however many are awarded.
     */
    public function isDecisive(): bool
    {
        return $this->khalol > 0
            || $this->yonbosh >= (int) config('kurash.yonbosh_for_khalol', 2);
    }

    /**
     * Have this athlete's own penalties ended the contest against them?
     *
     * Girrom is immediate. Madichal accumulates to the configured count and
     * transfers nothing on the way there.
     */
    public function isDefeated(): bool
    {
        return $this->girrom > 0
            || $this->madichal >= (int) config('kurash.madichal_for_defeat', 3);
    }

    /** How this athlete's penalties ended it, for the record. */
    public function defeatType(): ?string
    {
        return match (true) {
            $this->girrom > 0 => KurashScore::GIRROM,
            $this->madichal >= (int) config('kurash.madichal_for_defeat', 3) => KurashScore::MADICHAL,
            default => null,
        };
    }

    /**
     * A single number for the scoreboard column and the display screens.
     *
     * Yonbosh in the whole part, chala in the tenths. This is for showing only:
     * nothing compares two contests on it, because ten chala would read as one
     * yonbosh and chala must never add up to one. Ordering goes through
     * compareTo(), which compares the counts themselves. Chala is clamped at 9
     * so the encoding cannot overflow into the yonbosh place.
     */
    public function points(): float
    {
        if ($this->khalol > 0) {
            return 10.0;
        }

        return $this->yonbosh + min($this->chala, 9) / 10;
    }

    /**
     * Rank this tally against the other one, for a contest that reached time.
     *
     * The order the rules give, each step only reached when the one above it
     * was level:
     *
     *   1. yonbosh          the higher count
     *   2. chala            the higher count
     *   3. score origin     the athlete whose scores were earned with a
     *                       technique, over one holding the same numbers
     *                       because their opponent was penalised
     *   4. latest warning   whoever was warned most recently loses
     *
     * Returning zero is a genuine outcome, not a failure to decide: a contest
     * level all the way down is a referee decision, and this method will not
     * invent a winner to avoid asking for one.
     */
    public function compareTo(self $other): int
    {
        if ($this->yonbosh !== $other->yonbosh) {
            return $this->yonbosh <=> $other->yonbosh;
        }

        if ($this->chala !== $other->chala) {
            return $this->chala <=> $other->chala;
        }

        if ($this->earnedYonbosh !== $other->earnedYonbosh) {
            return $this->earnedYonbosh <=> $other->earnedYonbosh;
        }

        if ($this->earnedChala !== $other->earnedChala) {
            return $this->earnedChala <=> $other->earnedChala;
        }

        // Reversed on purpose: the *lower* sequence number wins, and zero — no
        // penalty at all — beats every real one.
        if ($this->lastPenaltyAt !== $other->lastPenaltyAt) {
            return $other->lastPenaltyAt <=> $this->lastPenaltyAt;
        }

        return 0;
    }

    /** Does this tally beat the other one outright? */
    public function beats(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function isLevelWith(self $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    /** Any score at all on the board for this athlete — what jazzo asks about. */
    public function hasScored(): bool
    {
        return $this->khalol > 0 || $this->yonbosh > 0 || $this->chala > 0;
    }

    /** Total penalties of every grade, for the mat screen's summary line. */
    public function penalties(): int
    {
        return $this->tanbeh + $this->dakki + $this->girrom + $this->madichal;
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'khalol' => $this->khalol,
            'yonbosh' => $this->yonbosh,
            'chala' => $this->chala,
            'tanbeh' => $this->tanbeh,
            'dakki' => $this->dakki,
            'girrom' => $this->girrom,
            'madichal' => $this->madichal,
        ];
    }

    /**
     * Fold one live event into the tally.
     *
     * @param  string  $call  one of KurashScore::CALLS
     * @param  string  $origin  KurashScore::ORIGIN_* — how the award came about
     * @param  int  $sequence  the event's per-bout sequence number
     */
    public function with(string $call, string $origin = KurashScore::ORIGIN_MANUAL, int $sequence = 0): self
    {
        $is = fn (string $c): int => $call === $c ? 1 : 0;
        $earned = $origin === KurashScore::ORIGIN_TECHNIQUE;
        $isPenalty = in_array($call, KurashScore::PENALTIES, true);

        return new self(
            khalol: $this->khalol + $is(KurashScore::KHALOL),
            yonbosh: $this->yonbosh + $is(KurashScore::YONBOSH),
            chala: $this->chala + $is(KurashScore::CHALA),
            tanbeh: $this->tanbeh + $is(KurashScore::TANBEH),
            dakki: $this->dakki + $is(KurashScore::DAKKI),
            girrom: $this->girrom + $is(KurashScore::GIRROM),
            madichal: $this->madichal + $is(KurashScore::MADICHAL),
            earnedYonbosh: $this->earnedYonbosh + ($earned ? $is(KurashScore::YONBOSH) : 0),
            earnedChala: $this->earnedChala + ($earned ? $is(KurashScore::CHALA) : 0),
            // Penalties arrive in sequence order, so the last one to be folded
            // is the most recent — but max() rather than assignment, so a
            // caller folding out of order cannot corrupt the tie-break.
            lastPenaltyAt: $isPenalty ? max($this->lastPenaltyAt, $sequence) : $this->lastPenaltyAt,
        );
    }
}
