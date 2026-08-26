<?php

namespace App\Services;

use App\Models\Bout;
use App\Models\BoutEvent;
use App\Support\BoutDecision;
use App\Support\ScoreTally;
use Illuminate\Support\Collection;

/**
 * Kurash scoring, derived from a bout's event log rather than held in columns.
 *
 * The rules this encodes:
 *
 *   KHALOL    ends the contest immediately, whatever the clock says
 *   YONBOSH   half a score; the configured number of them make a khalol
 *   CHALA     the smallest score, and it never accumulates into a yonbosh
 *
 *   TANBEH    the smallest penalty — the opponent is given a chala
 *   DAKKI     the next one up — the opponent is given a yonbosh, and the
 *             automatic chala the superseded tanbeh handed them is taken back.
 *             A chala the opponent earned with a technique is untouched: the
 *             rule replaces what the penalty gave, not what was thrown for
 *   GIRROM    the contest is awarded to the opponent on the spot
 *   MADICHAL  transfers nothing; the configured count of them loses the contest
 *
 * Nothing here writes. Applying a call is BoutScorer's job and deciding a bout
 * is BoutAdvancer's, because that is what also carries the winner into the next
 * round — this class only says what the log adds up to.
 */
class KurashScore
{
    public const KHALOL = 'khalol';

    public const YONBOSH = 'yonbosh';

    public const CHALA = 'chala';

    public const TANBEH = 'tanbeh';

    public const DAKKI = 'dakki';

    public const GIRROM = 'girrom';

    public const MADICHAL = 'madichal';

    /** Awarded to an athlete. */
    public const SCORES = [self::KHALOL, self::YONBOSH, self::CHALA];

    /** Awarded against an athlete. */
    public const PENALTIES = [self::TANBEH, self::DAKKI, self::GIRROM, self::MADICHAL];

    /** Everything a referee can call, in the order the mat screen lays them out. */
    public const CALLS = [
        self::KHALOL, self::YONBOSH, self::CHALA,
        self::TANBEH, self::DAKKI, self::GIRROM, self::MADICHAL,
    ];

    /*
     | Where a score came from.
     |
     | The specification calls this the event's `source`; it is stored in
     | `origin` because `source` already means the channel that entered the row
     | — operator, scoreboard, system — which is a different question and still
     | worth asking. Two rules depend on this one: a dakki removes the automatic
     | chala a tanbeh gave and leaves an earned one standing, and a contest
     | level at time is decided partly on which side earned its scores.
     */
    public const ORIGIN_TECHNIQUE = 'TECHNIQUE';

    public const ORIGIN_MANUAL = 'MANUAL';

    public const ORIGIN_AUTO_FROM_T = 'AUTO_FROM_T';

    public const ORIGIN_AUTO_FROM_D = 'AUTO_FROM_D';

    /** Actions written to bout_events by the mat screen. */
    public const ACTION_SCORED = 'scored';

    public const ACTION_VOIDED = 'score_voided';

    public const ACTION_STOPPAGE = 'stoppage';

    public const ACTION_JAZZO = 'jazzo';

    public const ACTION_RESUMED = 'resumed';

    /** The specification's `action` axis: what this row does to the tally. */
    public const ENTRY_ADD = 'ADD';

    public const ENTRY_REMOVE = 'REMOVE';

    public const ENTRY_CORRECT = 'CORRECT';

    /** Side 'a' wears the blue yakhtak, side 'b' the green one. */
    public const SIDE_COLOUR = ['a' => 'blue', 'b' => 'green'];

    public static function colourOf(string $side): string
    {
        return self::SIDE_COLOUR[$side] ?? 'blue';
    }

    public static function sideOf(?string $colour): ?string
    {
        return array_flip(self::SIDE_COLOUR)[$colour] ?? null;
    }

    public static function opposite(string $side): string
    {
        return $side === 'a' ? 'b' : 'a';
    }

    /**
     * Fold a bout's live events into one tally per side.
     *
     * @param  Collection<int, BoutEvent>|null  $events  already-loaded events, to save a query
     * @return array{a: ScoreTally, b: ScoreTally}
     */
    public function tally(Bout $bout, ?Collection $events = null): array
    {
        $events ??= $bout->events()->get();

        $a = new ScoreTally;
        $b = new ScoreTally;

        foreach ($this->liveCalls($events, $bout) as $call) {
            if ($call['side'] === 'a') {
                $a = $a->with($call['call'], $call['origin'], $call['sequence']);
            } elseif ($call['side'] === 'b') {
                $b = $b->with($call['call'], $call['origin'], $call['sequence']);
            }
        }

        return ['a' => $a, 'b' => $b];
    }

    /**
     * The calls that still stand, in the order they were made.
     *
     * Two things annul a call. It may have been taken back directly, which
     * appends a `score_voided` row naming it; or the penalty that caused it may
     * have been taken back, in which case it goes with its parent. The second
     * is why parent_event_id exists: an automatic chala is a consequence of a
     * tanbeh, and a tanbeh the referee withdraws must not leave its consequence
     * behind on the board.
     *
     * Voiding appends rather than deletes. A protest an hour later needs to see
     * that a call was made and taken back, which a row removed from the table
     * cannot show.
     *
     * @param  Collection<int, BoutEvent>  $events
     * @return list<array{id:int, call:string, side:string|null, origin:string, sequence:int, clock:int|null, parent_id:int|null, athlete_id:int|null}>
     */
    public function liveCalls(Collection $events, ?Bout $bout = null): array
    {
        $ordered = $events->sortBy([['sequence_number', 'asc'], ['id', 'asc']])->values();

        $voided = [];

        foreach ($ordered->where('action', self::ACTION_VOIDED) as $event) {
            $target = $event->after['voids_event_id'] ?? null;

            if ($target !== null) {
                $voided[(int) $target] = true;
            }
        }

        $calls = [];

        foreach ($ordered->where('action', self::ACTION_SCORED) as $event) {
            $call = $this->callOf($event);

            if ($call === null) {
                continue;
            }

            $parentId = $event->parent_event_id === null ? null : (int) $event->parent_event_id;

            // A consequence dies with its cause. The log is walked in order, so
            // a parent is always classified before the child that names it.
            if (isset($voided[$event->id]) || ($parentId !== null && isset($voided[$parentId]))) {
                $voided[(int) $event->id] = true;

                continue;
            }

            $calls[] = [
                'id' => (int) $event->id,
                'call' => $call,
                'side' => $this->sideFor($event, $bout),
                'origin' => $this->originOf($event, $call),
                'sequence' => (int) ($event->sequence_number ?? $event->id),
                'clock' => isset($event->after['clock']) ? (int) $event->after['clock'] : null,
                'parent_id' => $parentId,
                'athlete_id' => $this->athleteIdOf($event, $bout),
            ];
        }

        return $calls;
    }

    /**
     * The most recent live call of a given kind on a given side.
     *
     * What "decrease this counter by one" resolves to — a referee pressing the
     * minus on a side's tanbeh means the last tanbeh that still stands there,
     * not whichever row happens to be last in the table.
     *
     * @param  Collection<int, BoutEvent>  $events
     * @return array{id:int, call:string, side:string|null, origin:string, sequence:int, clock:int|null, parent_id:int|null, athlete_id:int|null}|null
     */
    public function lastLiveCall(Collection $events, Bout $bout, string $call, string $side): ?array
    {
        $matching = array_values(array_filter(
            $this->liveCalls($events, $bout),
            fn (array $c): bool => $c['call'] === $call && $c['side'] === $side
        ));

        return $matching === [] ? null : $matching[count($matching) - 1];
    }

    /**
     * Which side an event belongs to.
     *
     * The column is the authority for anything recorded under the current rule
     * model. Rows written before it existed carry the athlete id in their
     * payload instead, and are resolved against the bout — so an archived
     * contest still tallies rather than silently reading as nil-nil.
     */
    private function sideFor(BoutEvent $event, ?Bout $bout): ?string
    {
        if ($event->competitor_side !== null) {
            return self::sideOf($event->competitor_side);
        }

        $athleteId = isset($event->after['athlete_id']) ? (int) $event->after['athlete_id'] : null;

        if ($athleteId === null || $bout === null) {
            return null;
        }

        return match ($athleteId) {
            $bout->athlete_a_id => 'a',
            $bout->athlete_b_id => 'b',
            default => null,
        };
    }

    private function athleteIdOf(BoutEvent $event, ?Bout $bout): ?int
    {
        if (isset($event->after['athlete_id'])) {
            return (int) $event->after['athlete_id'];
        }

        return match ($this->sideFor($event, $bout)) {
            'a' => $bout?->athlete_a_id,
            'b' => $bout?->athlete_b_id,
            default => null,
        };
    }

    /** The call this event records, or null if it is not a recognised one. */
    private function callOf(BoutEvent $event): ?string
    {
        $call = $event->event_type
            ?? (is_string($event->after['call'] ?? null) ? $event->after['call'] : null);

        $call = $call === null ? null : strtolower($call);

        // Rows written before the correction still say halal. The bout table
        // was rewritten by migration; a payload string is left as it was
        // recorded and translated on the way out, because rewriting what an
        // operator's screen said at the time is what an audit trail must not do.
        if ($call === 'halal') {
            $call = self::KHALOL;
        }

        return in_array($call, self::CALLS, true) ? $call : null;
    }

    /**
     * Where this award came from.
     *
     * An unmarked row predates the distinction. A score on one is treated as
     * earned, because before automatic awards existed every score on the board
     * had been thrown for.
     */
    private function originOf(BoutEvent $event, string $call): string
    {
        if ($event->origin !== null) {
            return $event->origin;
        }

        return in_array($call, self::SCORES, true) ? self::ORIGIN_TECHNIQUE : self::ORIGIN_MANUAL;
    }

    /**
     * Who has already won, if anyone, without the clock having to run out.
     *
     * Checked in the order the rules end a contest. Penalties come first: an
     * athlete who has collected girrom or the configured madichal count loses
     * even while they are ahead on scores, and the record has to say that is
     * why. Girrom before madichal because it is immediate and a contest can
     * carry both.
     *
     * Returns null while the contest is still live.
     *
     * Delegated to BoutDecisionPolicy so a contest ended mid-round files the
     * same kind of record as one decided at time: which edition was applied and
     * which clause ended it. A terminal outcome is not a tie-break, but it is
     * still a ruling, and a result sheet that can cite one and not the other is
     * only half a record.
     *
     * @param  array{a: ScoreTally, b: ScoreTally}  $tally
     * @return array{winner_athlete_id:int, win_type:string, decision:BoutDecision}|null
     */
    public function decisiveOutcome(Bout $bout, array $tally): ?array
    {
        if ($bout->athlete_a_id === null || $bout->athlete_b_id === null) {
            return null;
        }

        $decision = app(BoutDecisionPolicy::class)->terminalDecision($bout, $tally);

        if ($decision?->side === null) {
            return null;
        }

        return [
            'winner_athlete_id' => $decision->side === 'a' ? $bout->athlete_a_id : $bout->athlete_b_id,
            'win_type' => $decision->basis,
            'decision' => $decision,
        ];
    }

    /**
     * Who wins when time runs out.
     *
     * Delegated to BoutDecisionPolicy, which owns the order, the edition and
     * the clause. This method only translates the verdict into the shape the
     * completion path already speaks — and carries the verdict itself along, so
     * BoutAdvancer can file it against the bout rather than reconstructing it.
     *
     * Null still means no automatic winner, and still means the mat screen must
     * ask a referee rather than the software picking a side.
     *
     * @param  array{a: ScoreTally, b: ScoreTally}  $tally
     * @return array{winner_athlete_id:int, win_type:string, decision:BoutDecision}|null
     */
    public function outcomeOnTime(Bout $bout, array $tally): ?array
    {
        if ($bout->athlete_a_id === null || $bout->athlete_b_id === null) {
            return null;
        }

        $decision = app(BoutDecisionPolicy::class)->decide($bout, $tally);

        if ($decision->side === null) {
            return null;
        }

        return [
            'winner_athlete_id' => $decision->side === 'a' ? $bout->athlete_a_id : $bout->athlete_b_id,
            'win_type' => $decision->basis,
            'decision' => $decision,
        ];
    }

    /**
     * The verdict on its own, for a caller that wants the reasoning without
     * the winner — the mat screen asking what it should show an official when
     * the rules do not decide.
     *
     * @param  array{a: ScoreTally, b: ScoreTally}  $tally
     */
    public function decisionOnTime(Bout $bout, array $tally): BoutDecision
    {
        return app(BoutDecisionPolicy::class)->decide($bout, $tally);
    }

    /**
     * Why this athlete won, in the words the boards and the sheets use.
     *
     * Here rather than in a blade because it is a statement about the rules,
     * and two screens that phrased it differently would be two screens
     * disagreeing about what happened.
     */
    public static function victoryReason(?string $winType): ?string
    {
        $message = match ($winType) {
            self::KHALOL => 'Win by KHALOL',
            self::YONBOSH => 'Win by Y',
            self::CHALA => 'Win by C',
            self::DAKKI => 'Opponent received D',
            self::GIRROM => 'Opponent received G',
            self::MADICHAL => 'Madichal limit reached',

            // The current vocabulary. Each names the rule that decided it, so a
            // result sheet says which clause separated the two athletes.
            'higher_appraisal' => 'Win by higher appraisal',
            'more_chala' => 'Win by more Chala',
            'last_appraisal' => 'Win by last appraisal',
            'first_caution' => 'Win by first caution',
            'referee_decision' => 'Referee decision',

            /*
             | Legacy values, kept readable and never written again.
             |
             | 'technique' recorded the undocumented origin tie-break removed on
             | 2026-08-26; 'warnings' recorded the latest-caution rule that the
             | published first-caution rule replaced; 'latest_score' is what
             | 'last_appraisal' is now called. Bouts completed under those
             | editions keep their stored value and keep reading as what they
             | were decided by — re-labelling a finished result would be
             | rewriting the record.
             */
            'technique' => 'Win by technique (rules edition before 2026-08-26)',
            'latest_score' => 'Win by latest score',
            'warnings' => 'Win by penalty (rules edition before 2026-08-26)',
            'decision' => 'Referee decision',

            'manual' => 'Manual referee decision',
            'bye' => 'Bye',
            null => null,
            default => 'Win by score',
        };

        if ($message === null) {
            return null;
        }

        // A translation file can return an array for a key, which is not
        // something a board can print. The untranslated wording is a better
        // answer than a fatal one.
        $translated = __($message);

        return is_string($translated) ? $translated : $message;
    }

    /**
     * Seconds a contest in this bout's weight class runs for.
     *
     * The age category is asked first — it is where cadet, junior and senior
     * are actually distinguished, and where the championship's own screen sets
     * the length. The configured default, keyed on the weight class's gender,
     * is the fallback for a category nobody has given a length to.
     */
    public function boutSeconds(Bout $bout): int
    {
        // Read off the bout's own division rather than through its weight
        // class: age_category_id is not nullable, so this is the one path that
        // is always there, and a weight class edited mid-competition cannot
        // change how long a contest already on a mat runs for.
        $configured = $bout->ageCategory->bout_seconds;

        if ($configured !== null && $configured > 0) {
            return (int) $configured;
        }

        // A bout always belongs to a weight class — the column is not nullable
        // — but the class's gender is only set when the federation records one.
        $gender = $bout->weightCategory->gender ?? 'X';

        /** @var array<string, int> $byGender */
        $byGender = config('kurash.bout_seconds', []);

        return $byGender[$gender] ?? ($byGender['X'] ?? 240);
    }

    /**
     * The reading at which jazzo falls due — half the contest, by default.
     *
     * Expressed against the clock's own countdown rather than as elapsed time,
     * because that is the number the mat screen and the board both hold.
     */
    public function jazzoAt(Bout $bout): int
    {
        $fraction = (float) config('kurash.jazzo_at_fraction', 0.5);

        return (int) round($this->boutSeconds($bout) * max(0.0, min(1.0, $fraction)));
    }

    /**
     * Is this contest at the halfway mark with nothing on the board?
     *
     * Both halves are checked here rather than trusted from the browser: the
     * clock is the mat's, but whether a contest may be stopped is the rules',
     * and a tampered reading must not be able to halt a contest that is being
     * fought.
     *
     * @param  array{a: ScoreTally, b: ScoreTally}  $tally
     */
    public function jazzoIsDue(Bout $bout, array $tally, int $secondsLeft): bool
    {
        if ($bout->jazzo_called_at !== null || $bout->isDecided()) {
            return false;
        }

        if ($tally['a']->hasScored() || $tally['b']->hasScored()) {
            return false;
        }

        return $secondsLeft <= $this->jazzoAt($bout);
    }
}
