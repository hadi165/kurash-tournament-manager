<?php

namespace App\Services;

use App\Models\Bout;
use App\Models\BoutEvent;
use App\Support\ScoreTally;
use Illuminate\Support\Collection;

/**
 * Kurash scoring, derived from a bout's event log rather than held in columns.
 *
 * The rules this encodes:
 *
 *   halal    ends the contest immediately, whatever the clock says
 *   yonbosh  half a score; the configured number of them make a halal
 *   chala    the smallest score, and it never accumulates into a yonbosh
 *   tanbeh   a warning against an athlete; enough of them become dakki, and
 *            dakki awards the contest to their opponent
 *
 * Nothing here writes. Deciding a bout is BoutAdvancer's job, because that is
 * what also carries the winner into the next round — this class only says what
 * the log adds up to.
 */
class KurashScore
{
    public const CALLS = ['halal', 'yonbosh', 'chala', 'tanbeh'];

    /** Actions written to bout_events by the mat screen. */
    public const ACTION_SCORED = 'scored';

    public const ACTION_VOIDED = 'score_voided';

    public const ACTION_STOPPAGE = 'stoppage';

    /**
     * Fold a bout's scoring events into one tally per side.
     *
     * @param  Collection<int, BoutEvent>|null  $events  already-loaded events, to save a query
     * @return array{a: ScoreTally, b: ScoreTally}
     */
    public function tally(Bout $bout, ?Collection $events = null): array
    {
        $events ??= $bout->events()->get();

        $a = new ScoreTally;
        $b = new ScoreTally;

        foreach ($this->liveCalls($events) as $call) {
            if ($call['athlete_id'] === $bout->athlete_a_id) {
                $a = $a->with($call['call']);
            } elseif ($call['athlete_id'] === $bout->athlete_b_id) {
                $b = $b->with($call['call']);
            }
        }

        return ['a' => $a, 'b' => $b];
    }

    /**
     * The scoring calls that still stand — every `scored` event that no
     * `score_voided` event has since annulled.
     *
     * Voiding appends rather than deletes. A protest an hour later needs to see
     * that a call was made and taken back, which a row removed from the table
     * cannot show.
     *
     * @param  Collection<int, BoutEvent>  $events
     * @return list<array{id:int, call:string, athlete_id:int|null, clock:int|null}>
     */
    public function liveCalls(Collection $events): array
    {
        $voided = $events
            ->where('action', self::ACTION_VOIDED)
            ->pluck('after.voids_event_id')
            ->filter()
            ->all();

        $voided = array_flip(array_map('intval', $voided));

        $calls = [];

        foreach ($events->where('action', self::ACTION_SCORED) as $event) {
            if (isset($voided[$event->id])) {
                continue;
            }

            $after = $event->after ?? [];
            $call = is_string($after['call'] ?? null) ? $after['call'] : '';

            if (! in_array($call, self::CALLS, true)) {
                continue;
            }

            $calls[] = [
                'id' => (int) $event->id,
                'call' => $call,
                'athlete_id' => isset($after['athlete_id']) ? (int) $after['athlete_id'] : null,
                'clock' => isset($after['clock']) ? (int) $after['clock'] : null,
            ];
        }

        return $calls;
    }

    /**
     * Who has already won, if anyone, without the clock having to run out.
     *
     * Returns null while the contest is still live. Dakki is checked first: an
     * athlete who has collected enough tanbeh loses even if they are ahead on
     * scores.
     *
     * @param  array{a: ScoreTally, b: ScoreTally}  $tally
     * @return array{winner_athlete_id:int, win_type:string}|null
     */
    public function decisiveOutcome(Bout $bout, array $tally): ?array
    {
        if ($bout->athlete_a_id === null || $bout->athlete_b_id === null) {
            return null;
        }

        if ($tally['a']->isDakki()) {
            return ['winner_athlete_id' => $bout->athlete_b_id, 'win_type' => 'dakki'];
        }

        if ($tally['b']->isDakki()) {
            return ['winner_athlete_id' => $bout->athlete_a_id, 'win_type' => 'dakki'];
        }

        foreach (['a' => $bout->athlete_a_id, 'b' => $bout->athlete_b_id] as $side => $athleteId) {
            if ($tally[$side]->isDecisive()) {
                return [
                    'winner_athlete_id' => $athleteId,
                    // Two yonbosh are a halal by the rules, but the record
                    // should say how it was actually reached.
                    'win_type' => $tally[$side]->halal > 0 ? 'halal' : 'yonbosh',
                ];
            }
        }

        return null;
    }

    /**
     * Who wins when time runs out.
     *
     * Null means the contest is level on both yonbosh and chala, which the
     * software will not break for you — that is a referee decision, and the
     * mat screen asks for one rather than picking a side.
     *
     * @param  array{a: ScoreTally, b: ScoreTally}  $tally
     * @return array{winner_athlete_id:int, win_type:string}|null
     */
    public function outcomeOnTime(Bout $bout, array $tally): ?array
    {
        if ($decisive = $this->decisiveOutcome($bout, $tally)) {
            return $decisive;
        }

        if ($bout->athlete_a_id === null || $bout->athlete_b_id === null) {
            return null;
        }

        if ($tally['a']->beats($tally['b'])) {
            return ['winner_athlete_id' => $bout->athlete_a_id, 'win_type' => $this->winTypeFor($tally['a'])];
        }

        if ($tally['b']->beats($tally['a'])) {
            return ['winner_athlete_id' => $bout->athlete_b_id, 'win_type' => $this->winTypeFor($tally['b'])];
        }

        return null;
    }

    /** How a contest decided on the clock was won. */
    private function winTypeFor(ScoreTally $tally): string
    {
        return match (true) {
            $tally->yonbosh > 0 => 'yonbosh',
            $tally->chala > 0 => 'chala',
            default => 'decision',
        };
    }

    /** Seconds a contest in this bout's weight class runs for. */
    public function boutSeconds(Bout $bout): int
    {
        // A bout always belongs to a weight class — the column is not nullable
        // — but the class's gender is only set when the federation records one.
        $gender = $bout->weightCategory->gender ?? 'X';

        /** @var array<string, int> $byGender */
        $byGender = config('kurash.bout_seconds', []);

        return $byGender[$gender] ?? ($byGender['X'] ?? 240);
    }
}
