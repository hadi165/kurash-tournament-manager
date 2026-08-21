<?php

namespace App\Services;

use App\Models\Bout;
use App\Models\BoutEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Writing to a contest's log.
 *
 * Every rule that turns one referee press into more than one row lives here, so
 * the mat screen stays a set of buttons and the consequences of a call cannot
 * differ depending on which screen made it. KurashScore reads the log; this
 * writes it; BoutAdvancer decides the bout and carries the winner forward.
 *
 * Three things make a call more than a single row:
 *
 *   a tanbeh gives the opponent a chala
 *   a dakki gives the opponent a yonbosh, and takes back the automatic chala
 *     the tanbeh it supersedes had given them
 *   taking a penalty back takes its consequences with it
 *
 * Every generated row names the row that caused it in parent_event_id, so none
 * of this has to be re-derived later by guessing which chala probably came from
 * which tanbeh.
 */
class BoutScorer
{
    /**
     * Award a call to, or against, one side.
     *
     * @param  string  $call  one of KurashScore::CALLS
     * @param  string  $side  'a' (blue) or 'b' (green) — the athlete scoring,
     *                        or for a penalty the athlete committing it
     * @return BoutEvent the row the referee's press produced, not its consequences
     */
    public function record(
        Bout $bout,
        string $call,
        string $side,
        ?int $clock = null,
        ?User $user = null,
        string $source = 'operator',
        ?string $origin = null,
    ): BoutEvent {
        return DB::transaction(function () use ($bout, $call, $side, $clock, $user, $source, $origin) {
            // A score a referee calls was thrown for; a penalty is a judgement
            // rather than a technique. Either can be overridden by a caller
            // replaying a log or driving this from hardware.
            $origin ??= in_array($call, KurashScore::SCORES, true)
                ? KurashScore::ORIGIN_TECHNIQUE
                : KurashScore::ORIGIN_MANUAL;

            $event = $this->append($bout, $call, $side, $clock, $user, $source, $origin);

            $this->applyConsequences($bout, $event, $call, $side, $clock, $user, $source);

            return $event;
        });
    }

    /**
     * The automatic awards a penalty carries to the other side.
     *
     * Girrom and madichal transfer nothing: girrom ends the contest by itself,
     * and madichal is a count that ends it on the third without ever touching
     * a score.
     */
    private function applyConsequences(
        Bout $bout,
        BoutEvent $event,
        string $call,
        string $side,
        ?int $clock,
        ?User $user,
        string $source,
    ): void {
        $opponent = KurashScore::opposite($side);

        if ($call === KurashScore::TANBEH) {
            $this->append(
                $bout, KurashScore::CHALA, $opponent, $clock, $user, $source,
                KurashScore::ORIGIN_AUTO_FROM_T, $event
            );

            // Only if a federation has asked for it; off by default.
            $this->escalateTanbeh($bout, $event, $side, $clock, $user, $source);

            return;
        }

        if ($call === KurashScore::DAKKI) {
            // Order matters. The automatic chala this dakki supersedes is taken
            // back first, so a board watched between the two writes never shows
            // the opponent holding both the chala and the yonbosh for the same
            // offence.
            $this->withdrawAutomaticChala($bout, $event, $side, $opponent, $user, $source);

            $this->append(
                $bout, KurashScore::YONBOSH, $opponent, $clock, $user, $source,
                KurashScore::ORIGIN_AUTO_FROM_D, $event
            );
        }
    }

    /**
     * Take back the chala this side's earlier tanbeh handed the opponent.
     *
     * Scoped by parentage, which is the whole reason parent_event_id is
     * recorded: only a chala that exists *because* of a tanbeh against this
     * athlete is withdrawn. A chala the opponent threw for is left exactly
     * where it is — the rule replaces what the penalty gave, not what was
     * earned.
     */
    private function withdrawAutomaticChala(
        Bout $bout,
        BoutEvent $cause,
        string $side,
        string $opponent,
        ?User $user,
        string $source,
    ): void {
        $scorer = app(KurashScore::class);
        $events = $bout->events()->get();

        // The tanbeh rows against this side that still stand.
        $tanbehIds = array_column(
            array_filter(
                $scorer->liveCalls($events, $bout),
                fn (array $c): bool => $c['call'] === KurashScore::TANBEH && $c['side'] === $side
            ),
            'id'
        );

        if ($tanbehIds === []) {
            return;
        }

        $automatic = array_filter(
            $scorer->liveCalls($events, $bout),
            fn (array $c): bool => $c['call'] === KurashScore::CHALA
                && $c['side'] === $opponent
                && $c['origin'] === KurashScore::ORIGIN_AUTO_FROM_T
                && in_array($c['parent_id'], $tanbehIds, true)
        );

        foreach ($automatic as $chala) {
            $this->void(
                $bout, $chala, $user, $source,
                reason: 'superseded_by_dakki',
                parent: $cause,
            );
        }
    }

    /**
     * Turn accumulated tanbeh into a dakki, where a federation runs that rule.
     *
     * kurash.tanbeh_for_dakki is zero by default, which means they do not
     * accumulate at all — the rules this system is written against stop at the
     * chala each tanbeh gives. A championship sanctioned under an edition that
     * escalates sets the count in config and gets it without a code change.
     */
    private function escalateTanbeh(
        Bout $bout,
        BoutEvent $cause,
        string $side,
        ?int $clock,
        ?User $user,
        string $source,
    ): void {
        $threshold = (int) config('kurash.tanbeh_for_dakki', 0);

        if ($threshold <= 0) {
            return;
        }

        $tally = app(KurashScore::class)->tally($bout, $bout->events()->get());

        // Exactly at the threshold, and only once: a fourth tanbeh under a
        // three-tanbeh edition is a fourth tanbeh, not a second dakki.
        if ($tally[$side]->tanbeh !== $threshold) {
            return;
        }

        $dakki = $this->append(
            $bout, KurashScore::DAKKI, $side, $clock, $user, $source,
            KurashScore::ORIGIN_AUTO_FROM_T, $cause
        );

        $this->applyConsequences($bout, $dakki, KurashScore::DAKKI, $side, $clock, $user, $source);
    }

    /**
     * Take back one call, and everything that call caused.
     *
     * The row stays. An annulment is appended naming it, which is what lets a
     * protest an hour later see that the call was made and withdrawn — a row
     * deleted from the table can only show that it never happened.
     *
     * @param  array{id:int, call:string, side:string|null, origin:string, sequence:int, clock:int|null, parent_id:int|null, athlete_id:int|null}  $call
     */
    public function void(
        Bout $bout,
        array $call,
        ?User $user = null,
        string $source = 'operator',
        string $reason = 'taken_back',
        ?BoutEvent $parent = null,
    ): BoutEvent {
        return DB::transaction(function () use ($bout, $call, $user, $source, $reason, $parent) {
            // Children are annulled by KurashScore when it reads the log, not
            // by a second row here: one annulment naming the cause is the whole
            // record, and writing one per consequence would let the two
            // disagree if a rule changed underneath them.
            return BoutEvent::createInSequence([
                'bout_id' => $bout->id,
                'user_id' => $user?->id,
                'competitor_side' => $call['side'] === null ? null : KurashScore::colourOf($call['side']),
                'event_type' => $call['call'],
                'entry_action' => KurashScore::ENTRY_REMOVE,
                'action' => KurashScore::ACTION_VOIDED,
                'source' => $source,
                'origin' => $call['origin'],
                'parent_event_id' => $parent?->id,
                'after' => [
                    'voids_event_id' => $call['id'],
                    'call' => $call['call'],
                    'athlete_id' => $call['athlete_id'],
                    'reason' => $reason,
                ],
            ]);
        });
    }

    /** One row on the log, in sequence. */
    private function append(
        Bout $bout,
        string $call,
        string $side,
        ?int $clock,
        ?User $user,
        string $source,
        string $origin,
        ?BoutEvent $parent = null,
    ): BoutEvent {
        $athleteId = $side === 'a' ? $bout->athlete_a_id : $bout->athlete_b_id;

        return BoutEvent::createInSequence([
            'bout_id' => $bout->id,
            'user_id' => $user?->id,
            'competitor_side' => KurashScore::colourOf($side),
            'event_type' => $call,
            'entry_action' => KurashScore::ENTRY_ADD,
            'action' => KurashScore::ACTION_SCORED,
            'source' => $source,
            'origin' => $origin,
            'parent_event_id' => $parent?->id,
            // athlete_id is written into the payload as well as being derivable
            // from the side, because a bout can be corrected and re-drawn and
            // the log has to say who it was at the time.
            'after' => [
                'call' => $call,
                'athlete_id' => $athleteId,
                'clock' => $clock,
            ],
        ]);
    }
}
