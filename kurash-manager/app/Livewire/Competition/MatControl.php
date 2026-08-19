<?php

namespace App\Livewire\Competition;

use App\Models\Bout;
use App\Models\BoutEvent;
use App\Models\Court;
use App\Services\BoutAdvancer;
use App\Services\KurashScore;
use App\Support\ScoreTally;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;

/**
 * Live scoring for one mat.
 *
 * Operated at the edge of the tatami by someone who cannot look away from the
 * contest, so every action is one press and the screen never asks a question
 * mid-bout that it could have answered itself. The two it genuinely cannot
 * answer — a level contest at time, and whether a call was a mistake — are the
 * only two that stop and ask.
 *
 * The clock is the mat's, not this application's. It runs in the browser and
 * the operator's press carries the reading with it, which is also what keeps a
 * paused tab from silently drifting away from the contest in front of them.
 */
class MatControl extends Component
{
    public Court $court;

    /** Set when time expired with the scores level and a decision is owed. */
    public bool $awaitingDecision = false;

    public function mount(Court $court): void
    {
        $this->court = $court->load('championship');
    }

    /**
     * The contest currently on this mat.
     *
     * Undecided and assigned here — a bout that has been decided stays on the
     * mat row until the next one is sent, but it is no longer what this screen
     * is scoring.
     */
    #[Computed]
    public function bout(): ?Bout
    {
        return $this->court->bouts()
            ->with(['athleteA', 'athleteB', 'weightCategory.ageCategory', 'events'])
            ->where('status', Bout::STATUS_ON_COURT)
            ->whereNull('winner_athlete_id')
            ->orderBy('fight_number')
            ->first();
    }

    /** Award a call to one side. */
    public function score(string $call, string $side, ?int $clock = null): void
    {
        Gate::authorize('manage-competition');

        if (! in_array($call, KurashScore::CALLS, true) || ! in_array($side, ['a', 'b'], true)) {
            return;
        }

        $bout = $this->bout();

        if ($bout === null || ! $bout->isReadyToFight()) {
            session()->flash('error', __('No contest is on this mat.'));

            return;
        }

        $athleteId = $side === 'a' ? $bout->athlete_a_id : $bout->athlete_b_id;

        $reading = $this->sanitiseClock($clock, $bout);

        BoutEvent::create([
            'bout_id' => $bout->id,
            'user_id' => auth()->id(),
            'action' => KurashScore::ACTION_SCORED,
            'source' => 'operator',
            'after' => [
                'call' => $call,
                'athlete_id' => $athleteId,
                'clock' => $reading,
            ],
        ]);

        // Re-anchor the shared clock at the same moment, so a scoreboard on the
        // wall does not drift away from the calls it is showing.
        if ($reading !== null) {
            $bout->update(['clock_seconds_left' => $reading, 'clock_updated_at' => now()]);
        }

        unset($this->bout);
        $this->awaitingDecision = false;

        $this->settleIfDecided();
    }

    /**
     * Take back the most recent call that still stands.
     *
     * Appends an annulment rather than deleting the row: a protest an hour
     * later needs to see that the call was made and taken back. The tally is
     * recomputed from what remains, so an undo cannot leave a score behind
     * that no sequence of calls could have produced.
     */
    public function voidLast(): void
    {
        Gate::authorize('manage-competition');

        $bout = $this->bout();

        if ($bout === null) {
            return;
        }

        $live = app(KurashScore::class)->liveCalls($bout->events);
        $last = end($live);

        if ($last === false) {
            session()->flash('error', __('There is nothing to take back.'));

            return;
        }

        BoutEvent::create([
            'bout_id' => $bout->id,
            'user_id' => auth()->id(),
            'action' => KurashScore::ACTION_VOIDED,
            'source' => 'operator',
            'after' => [
                'voids_event_id' => $last['id'],
                'call' => $last['call'],
                'athlete_id' => $last['athlete_id'],
            ],
        ]);

        unset($this->bout);
        $this->awaitingDecision = false;

        session()->flash('status', __(':call taken back.', ['call' => ucfirst($last['call'])]));
    }

    /**
     * Publish the clock so the wall scoreboard can show it.
     *
     * Called when the operator starts or stops it, and again on every scoring
     * call. Not once a second — the stored value is an anchor the reading
     * sides derive from, so writing it more often would buy nothing.
     */
    public function publishClock(int $secondsLeft, bool $running): void
    {
        Gate::authorize('manage-competition');

        $bout = $this->bout();

        if ($bout === null) {
            return;
        }

        $bout->update([
            'clock_seconds_left' => max(0, min($secondsLeft, app(KurashScore::class)->boutSeconds($bout))),
            'clock_running' => $running,
            'clock_updated_at' => now(),
        ]);

        unset($this->bout);
    }

    /** Record that the referee stopped the contest — tuxta. */
    public function stoppage(?int $clock = null): void
    {
        Gate::authorize('manage-competition');

        $bout = $this->bout();

        if ($bout === null) {
            return;
        }

        BoutEvent::create([
            'bout_id' => $bout->id,
            'user_id' => auth()->id(),
            'action' => KurashScore::ACTION_STOPPAGE,
            'source' => 'operator',
            'after' => ['call' => 'tuxta', 'clock' => $this->sanitiseClock($clock, $bout)],
        ]);

        unset($this->bout);
    }

    /**
     * Time has run out. Decide on the tally, or ask for a referee decision if
     * the two are level on both yonbosh and chala.
     */
    public function finishOnTime(): void
    {
        Gate::authorize('manage-competition');

        $bout = $this->bout();

        if ($bout === null) {
            return;
        }

        $scorer = app(KurashScore::class);
        $tally = $scorer->tally($bout, $bout->events);
        $outcome = $scorer->outcomeOnTime($bout, $tally);

        if ($outcome === null) {
            $this->awaitingDecision = true;
            session()->flash('error', __('Level on yonbosh and chala — the referees decide this one.'));

            return;
        }

        $this->finalise($bout, $outcome['winner_athlete_id'], $outcome['win_type'], $tally);
    }

    /** The referees' call on a contest that finished level. */
    public function awardDecision(string $side): void
    {
        Gate::authorize('manage-competition');

        $bout = $this->bout();

        if ($bout === null || ! in_array($side, ['a', 'b'], true)) {
            return;
        }

        $winnerId = $side === 'a' ? $bout->athlete_a_id : $bout->athlete_b_id;

        if ($winnerId === null) {
            return;
        }

        $scorer = app(KurashScore::class);

        $this->finalise($bout, $winnerId, 'decision', $scorer->tally($bout, $bout->events));
    }

    /**
     * End the contest as soon as the log says it is over, so the operator never
     * has to notice that a second yonbosh or a third tanbeh finished it.
     */
    private function settleIfDecided(): void
    {
        $bout = $this->bout();

        if ($bout === null) {
            return;
        }

        $scorer = app(KurashScore::class);
        $tally = $scorer->tally($bout, $bout->events);
        $outcome = $scorer->decisiveOutcome($bout, $tally);

        if ($outcome !== null) {
            $this->finalise($bout, $outcome['winner_athlete_id'], $outcome['win_type'], $tally);
        }
    }

    /**
     * @param  array{a: ScoreTally, b: ScoreTally}  $tally
     */
    private function finalise(Bout $bout, int $winnerAthleteId, string $winType, array $tally): void
    {
        // Freeze the clock at the moment the arm went up, so the board shows
        // the time the contest was won rather than continuing to run down.
        $bout->update([
            'clock_seconds_left' => $bout->secondsRemaining(app(KurashScore::class)->boutSeconds($bout)),
            'clock_running' => false,
            'clock_updated_at' => now(),
        ]);

        try {
            app(BoutAdvancer::class)->recordResult(
                bout: $bout,
                winnerAthleteId: $winnerAthleteId,
                scores: [
                    'score_a' => $tally['a']->points(),
                    'score_b' => $tally['b']->points(),
                ],
                winType: $winType,
                user: auth()->user(),
                source: 'operator',
            );
        } catch (Throwable $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        unset($this->bout);
        $this->awaitingDecision = false;

        // Both sides are known by the time a contest can be finalised, so the
        // name is read straight off the winning athlete.
        $winner = $bout->athlete_a_id === $winnerAthleteId ? $bout->athleteA : $bout->athleteB;

        session()->flash('status', __(':name wins by :type.', [
            'name' => $winner->fullname,
            'type' => $winType,
        ]));
    }

    /** Put the next contest waiting for this mat onto it. */
    public function bringOn(int $boutId): void
    {
        Gate::authorize('manage-competition');

        $bout = Bout::where('championship_id', $this->court->championship_id)->findOrFail($boutId);

        if (! $bout->isReadyToFight()) {
            session()->flash('error', __('That contest is not ready — both athletes must be known.'));

            return;
        }

        if ($this->bout() !== null) {
            session()->flash('error', __('Finish the contest on this mat first.'));

            return;
        }

        $bout->update(['court_id' => $this->court->id, 'status' => Bout::STATUS_ON_COURT]);

        unset($this->bout);
        session()->flash('status', __('Fight :n is on.', ['n' => $bout->fight_number ?? $bout->play_code]));
    }

    /**
     * A clock reading the browser sent us, kept inside the contest's length.
     *
     * It is only ever a note on the record — nothing is decided by it — but a
     * nonsense value in the log is worse than none at all.
     */
    private function sanitiseClock(?int $clock, Bout $bout): ?int
    {
        if ($clock === null) {
            return null;
        }

        return max(0, min($clock, app(KurashScore::class)->boutSeconds($bout)));
    }

    public function render(): View
    {
        $bout = $this->bout();
        $scorer = app(KurashScore::class);

        $tally = $bout !== null
            ? $scorer->tally($bout, $bout->events)
            : ['a' => new ScoreTally, 'b' => new ScoreTally];

        return view('livewire.competition.mat-control', [
            'bout' => $bout,
            'tally' => $tally,
            'calls' => $bout !== null ? array_reverse($scorer->liveCalls($bout->events)) : [],
            'log' => $bout?->events->whereIn('action', [
                KurashScore::ACTION_SCORED,
                KurashScore::ACTION_VOIDED,
                KurashScore::ACTION_STOPPAGE,
            ])->sortByDesc('id')->values() ?? collect(),
            'boutSeconds' => $bout !== null ? $scorer->boutSeconds($bout) : 240,
            // Read once here rather than per render pass in the view, where it
            // would be a query sitting inside the markup.
            'totalRounds' => $bout === null
                ? 0
                : (int) ($bout->weightCategory->bouts()->max('round') ?? $bout->round),
            'upNext' => $this->upNext(),
        ]);
    }

    /**
     * What this mat is being asked to run next.
     *
     * Bouts already assigned here come first, then anything ready with a fight
     * number and no mat — so a mat can be worked from this screen alone when
     * the fight order is being followed loosely, which is what actually happens
     * once a session runs late.
     *
     * @return Collection<int, Bout>
     */
    private function upNext(): Collection
    {
        return Bout::where('championship_id', $this->court->championship_id)
            ->readyToFight()
            ->where(fn ($q) => $q->where('court_id', $this->court->id)->orWhereNull('court_id'))
            ->where('status', '!=', Bout::STATUS_ON_COURT)
            ->with(['athleteA', 'athleteB', 'weightCategory'])
            ->orderByRaw('fight_number is null')
            ->orderBy('fight_number')
            ->limit(6)
            ->get();
    }
}
