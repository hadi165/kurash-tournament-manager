<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\Bout;
use App\Models\BoutEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Records a result and carries the winner into the next round.
 *
 * This is the piece the original system was missing entirely. Its
 * applyResultToMatch() wrote the winner into the bout it belonged to and
 * stopped; copyWinnerInfo() only ran while the bracket was being generated,
 * before any fight had happened. The practical effect was that round two
 * onward stayed empty for the rest of the competition.
 */
class BoutAdvancer
{
    /**
     * @param  array{score_a?:float|null, score_b?:float|null}  $scores
     */
    public function recordResult(
        Bout $bout,
        int $winnerAthleteId,
        array $scores = [],
        string $winType = 'halal',
        ?User $user = null,
        string $source = 'operator',
    ): Bout {
        if (! in_array($winnerAthleteId, [$bout->athlete_a_id, $bout->athlete_b_id], true)) {
            throw new InvalidArgumentException(
                "Athlete {$winnerAthleteId} is not in bout {$bout->play_code}."
            );
        }

        if ($bout->athlete_a_id === null || $bout->athlete_b_id === null) {
            throw new InvalidArgumentException(
                "Bout {$bout->play_code} is not ready — one side is still empty."
            );
        }

        // Idempotent: a scoreboard that retries the same payload must not
        // advance the same athlete twice or rewrite the audit trail.
        if ($bout->winner_athlete_id === $winnerAthleteId
            && $bout->status === Bout::STATUS_COMPLETED
            && (float) $bout->score_a === (float) ($scores['score_a'] ?? $bout->score_a)
            && (float) $bout->score_b === (float) ($scores['score_b'] ?? $bout->score_b)) {
            return $bout;
        }

        return DB::transaction(function () use ($bout, $winnerAthleteId, $scores, $winType, $user, $source) {
            $before = $bout->only([
                'winner_athlete_id', 'score_a', 'score_b', 'win_type', 'status',
            ]);

            $isCorrection = $bout->winner_athlete_id !== null
                && $bout->winner_athlete_id !== $winnerAthleteId;

            // A correction invalidates everything the old winner went on to do.
            if ($isCorrection) {
                $this->unwind($bout, $user);
            }

            $bout->update([
                'winner_athlete_id' => $winnerAthleteId,
                'score_a' => $scores['score_a'] ?? null,
                'score_b' => $scores['score_b'] ?? null,
                'win_type' => $winType,
                'status' => Bout::STATUS_COMPLETED,
                'is_bye' => false,
                'frozen_snapshot' => $this->snapshot($bout, $winnerAthleteId),
            ]);

            BoutEvent::create([
                'bout_id' => $bout->id,
                'user_id' => $user?->id,
                'action' => $isCorrection ? 'result_corrected' : 'result_recorded',
                'source' => $source,
                'before' => $before,
                'after' => $bout->only(['winner_athlete_id', 'score_a', 'score_b', 'win_type', 'status']),
            ]);

            $this->advance($bout, $user);

            return $bout->refresh();
        });
    }

    /**
     * Put the winner into their slot in the next round, then check whether that
     * bout is now a walkover (its other feeder produced nobody).
     */
    public function advance(Bout $bout, ?User $user = null): ?Bout
    {
        if ($bout->next_bout_id === null || $bout->winner_athlete_id === null) {
            return null;
        }

        $next = Bout::find($bout->next_bout_id);

        if ($next === null) {
            return null;
        }

        $slot = "athlete_{$bout->next_bout_slot}_id";
        $next->update([$slot => $bout->winner_athlete_id]);

        BoutEvent::create([
            'bout_id' => $next->id,
            'user_id' => $user?->id,
            'action' => 'advanced',
            'source' => 'system',
            'after' => [$slot => $bout->winner_athlete_id, 'from_bout' => $bout->play_code],
        ]);

        return $this->promoteIfWalkover($next->refresh(), $user);
    }

    /**
     * A bout whose opponent slot can never be filled is a walkover, not a
     * fight. Only true once every feeder is resolved and produced nobody.
     */
    private function promoteIfWalkover(Bout $bout, ?User $user): Bout
    {
        if ($bout->isDecided()) {
            return $bout;
        }

        $present = $bout->athlete_a_id ?? $bout->athlete_b_id;
        $bothPresent = $bout->athlete_a_id !== null && $bout->athlete_b_id !== null;

        if ($present === null || $bothPresent) {
            return $bout;
        }

        $emptySlot = $bout->athlete_a_id === null ? 'a' : 'b';
        $feeder = $bout->previousBouts()->where('next_bout_slot', $emptySlot)->first();

        // A feeder that exists and has not been decided will still fill it.
        if ($feeder !== null && ! $feeder->isDecided()) {
            return $bout;
        }

        // A feeder that was decided should already have advanced its winner, so
        // reaching here means the branch genuinely produced nobody.
        $bout->update([
            'winner_athlete_id' => $present,
            'is_bye' => true,
            'win_type' => 'bye',
            'status' => Bout::STATUS_COMPLETED,
        ]);

        BoutEvent::create([
            'bout_id' => $bout->id,
            'user_id' => $user?->id,
            'action' => 'walkover',
            'source' => 'system',
            'after' => ['winner_athlete_id' => $present],
        ]);

        $this->advance($bout->refresh(), $user);

        return $bout;
    }

    /**
     * Clear every downstream consequence of this bout's previous winner, so a
     * corrected result cannot leave the old athlete standing in later rounds.
     */
    private function unwind(Bout $bout, ?User $user): void
    {
        $next = $bout->next_bout_id ? Bout::find($bout->next_bout_id) : null;

        while ($next !== null) {
            $slot = "athlete_{$bout->next_bout_slot}_id";

            $before = $next->only(['athlete_a_id', 'athlete_b_id', 'winner_athlete_id', 'status']);

            $next->update([
                $slot => null,
                'winner_athlete_id' => null,
                'score_a' => null,
                'score_b' => null,
                'win_type' => null,
                'is_bye' => false,
                'status' => Bout::STATUS_PENDING,
                'frozen_snapshot' => null,
            ]);

            BoutEvent::create([
                'bout_id' => $next->id,
                'user_id' => $user?->id,
                'action' => 'unwound',
                'source' => 'system',
                'before' => $before,
                'after' => ['reason' => "upstream bout {$bout->play_code} was corrected"],
            ]);

            $bout = $next;
            $next = $next->next_bout_id ? Bout::find($next->next_bout_id) : null;
        }
    }

    /**
     * Freeze who these athletes were at the moment the result was recorded.
     * Correcting a misspelled name later must not silently rewrite history on
     * a decided bout, which is exactly what the old duplicated columns did.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Bout $bout, int $winnerAthleteId): array
    {
        $describe = fn (?Athlete $a) => $a === null ? null : [
            'id' => $a->id,
            'ika_id' => $a->ika_id,
            'fullname' => $a->fullname,
            'noc_code' => $a->noc_code,
        ];

        return [
            'recorded_at' => now()->toIso8601String(),
            'athlete_a' => $describe($bout->athleteA),
            'athlete_b' => $describe($bout->athleteB),
            'winner_athlete_id' => $winnerAthleteId,
        ];
    }
}
