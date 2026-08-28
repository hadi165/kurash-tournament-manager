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
         * Two rules read this. The dakki rule takes back the automatic chala a
         * superseded tanbeh gave and leaves an earned one standing; and the
         * decision policy ranks a technique-earned appraisal above an automatic
         * one of equal value and count.
         *
         * Its place in the order has been wrong in both directions: once
         * ranked above count, where it decided contests before the count rule
         * was reached, and once removed entirely, which let a later automatic
         * score beat an earlier thrown one. The federation has settled it —
         * below count, above recency. See BoutDecisionPolicy.
         */
        public int $earnedYonbosh = 0,
        public int $earnedChala = 0,
        /**
         * Sequence number of the most recent penalty against this athlete, or
         * zero for an athlete who has taken none.
         *
         * This is what the warning rule reads: the athlete holding the most
         * recent active warning loses. Zero means no warning at all and is the
         * lowest sequence there is, so an unwarned athlete beats a warned one
         * without a special case.
         *
         * A reading of "cautioned first wins" shipped briefly and is gone. It
         * agrees with this rule whenever each athlete holds one warning and
         * disagrees from the second onward — see firstPenaltyAt below, which is
         * kept for the audit trail and decides nothing.
         */
        public int $lastPenaltyAt = 0,
        /**
         * Sequence number of the EARLIEST penalty still standing against this
         * athlete, or zero for one who has taken none.
         *
         * Kept for the audit trail and for screens that want to show when an
         * athlete's trouble started. It decides nothing: the federation's rule
         * is the LATEST warning, which is lastPenaltyAt above.
         */
        public int $firstPenaltyAt = 0,
        /**
         * Sequence number of the most recent score to this athlete, or zero
         * for one who has not scored.
         *
         * The chronological tie-break: two athletes level on the value of what
         * they hold, on how they earned it and on how many, are separated by
         * who scored last. Recorded as the log's own sequence rather than a
         * clock reading, because two calls inside the same second have an
         * order and a timestamp cannot express it.
         */
        public int $lastScoreAt = 0,
    ) {}

    /**
     * What one call is worth, from the central table.
     *
     * Nothing outside this class reads config('kurash.score_priority') — an
     * unknown call is worth nothing rather than throwing, so a rules edition
     * that adds a call cannot take a live board down before its value is set.
     */
    public static function priorityOf(?string $call): int
    {
        if ($call === null) {
            return 0;
        }

        /** @var array<string, int> $table */
        $table = config('kurash.score_priority', []);

        return (int) ($table[$call] ?? 0);
    }

    /**
     * The most valuable score this athlete holds, or null for none.
     *
     * A contest is decided on this before it is decided on counts, which is
     * what makes a single yonbosh beat any number of chala.
     */
    public function topScore(): ?string
    {
        $held = array_filter([
            KurashScore::KHALOL => $this->khalol,
            KurashScore::YONBOSH => $this->yonbosh,
            KurashScore::CHALA => $this->chala,
        ]);

        if ($held === []) {
            return null;
        }

        // Ordered by the table rather than by the order they are listed above,
        // so moving a value in config moves this with it.
        uksort($held, fn (string $a, string $b): int => self::priorityOf($b) <=> self::priorityOf($a));

        return array_key_first($held);
    }

    public function topPriority(): int
    {
        return self::priorityOf($this->topScore());
    }

    /** How many of one call this athlete holds. */
    public function count(string $call): int
    {
        return match ($call) {
            KurashScore::KHALOL => $this->khalol,
            KurashScore::YONBOSH => $this->yonbosh,
            KurashScore::CHALA => $this->chala,
            KurashScore::TANBEH => $this->tanbeh,
            KurashScore::DAKKI => $this->dakki,
            KurashScore::GIRROM => $this->girrom,
            KurashScore::MADICHAL => $this->madichal,
            default => 0,
        };
    }

    /** How many of one call this athlete earned with a technique. */
    public function earned(string $call): int
    {
        return match ($call) {
            KurashScore::YONBOSH => $this->earnedYonbosh,
            KurashScore::CHALA => $this->earnedChala,
            // A khalol is never generated by a rule, so every one is earned.
            KurashScore::KHALOL => $this->khalol,
            default => 0,
        };
    }

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
     * yonbosh and chala must never add up to one. Ordering is
     * BoutDecisionPolicy's, and compares the counts themselves. Chala is
     * clamped at 9
     * so the encoding cannot overflow into the yonbosh place.
     */
    public function points(): float
    {
        if ($this->khalol > 0) {
            return 10.0;
        }

        return $this->yonbosh + min($this->chala, 9) / 10;
    }

    /*
     |--------------------------------------------------------------------------
     | Facts, not order
     |--------------------------------------------------------------------------
     |
     | compareTo(), beats() and isLevelWith() used to live here and decided who
     | won a contest that reached time. They are gone, and deliberately: the
     | order they walked was partly this project's invention rather than the
     | federation's, and a generic tally object is the wrong place to hold
     | competition policy — nothing about it can say which edition of the rules
     | it is applying, or cite the clause that decided the contest.
     |
     | App\Services\BoutDecisionPolicy answers that now. This class supplies the
     | facts it asks for — top score, counts, lastScoreAt, firstPenaltyAt — and
     | states no preference between them.
     |
     | earned() stays, because origin is still recorded and still shown in the
     | audit trail. It is no longer consulted by anything that ranks athletes.
     */

    /** Any score at all on the board for this athlete. */
    public function hasScored(): bool
    {
        return $this->khalol > 0 || $this->yonbosh > 0 || $this->chala > 0;
    }

    /**
     * Anything at all against or for this athlete — what jazzo asks about.
     *
     * Scores AND penalties. Jazzo stops a contest in which nothing has
     * happened, and a tanbeh is something happening: an athlete already
     * carrying one has a contest with a record in it, whether or not that
     * record put a number on the board.
     *
     * hasScored() alone was the test until 2026-08-26, which let jazzo be
     * offered over a board showing a madichal — a penalty that transfers
     * nothing, so it reached the halfway mark looking like an empty contest.
     * Girrom and dakki were only hidden from the same bug because they end the
     * contest or hand over a score on their way.
     *
     * Reads the folded tally, so annulled and superseded calls are already
     * absent: KurashScore::liveCalls() never yields them.
     */
    public function hasAnyActiveCall(): bool
    {
        return $this->hasScored() || $this->penalties() > 0;
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
            // Calls arrive in sequence order, so the last one to be folded is
            // the most recent — but max() rather than assignment, so a caller
            // folding out of order cannot corrupt either tie-break.
            lastPenaltyAt: $isPenalty ? max($this->lastPenaltyAt, $sequence) : $this->lastPenaltyAt,
            lastScoreAt: $isPenalty ? $this->lastScoreAt : max($this->lastScoreAt, $sequence),
            // min() over the penalties seen so far, with zero meaning "none
            // yet" rather than "sequence zero" — so the first real penalty
            // replaces it and no later one ever can. Only live calls are
            // folded, so a voided caution never becomes somebody's first.
            firstPenaltyAt: $isPenalty && ($this->firstPenaltyAt === 0 || $sequence < $this->firstPenaltyAt)
                ? $sequence
                : $this->firstPenaltyAt,
        );
    }
}
