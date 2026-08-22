<?php

namespace App\Livewire\Competition;

use App\Models\Bout;
use App\Models\BoutEvent;
use App\Models\Court;
use App\Services\BoutAdvancer;
use App\Services\BoutScorer;
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
 * answer — a contest level all the way down the tie-break at time, and whether
 * a call was a mistake — are the only two that stop and ask.
 *
 * Every write here is behind score-bout rather than manage-competition: a
 * referee holds it and reaches nothing else, an admin holds it alongside the
 * rest. What a call then means is BoutScorer's, and what the log adds up to is
 * KurashScore's — this class is the screen, and no rule is written twice.
 *
 * The clock is the mat's, not this application's. It runs in the browser and
 * the operator's press carries the reading with it, which is also what keeps a
 * paused tab from silently drifting away from the contest in front of them.
 */
class MatControl extends Component
{
    public Court $court;

    /** Set when time expired level all the way down and a decision is owed. */
    public bool $awaitingDecision = false;

    /**
     * Which buzzer this mat ends a contest on.
     *
     * Chosen here rather than in the mat's settings because the person who
     * needs to tell this mat from the one beside it is the person sitting at
     * it, and they will want to change it after hearing both.
     */
    public string $finishSound = '';

    public function updatedFinishSound(string $value): void
    {
        Gate::authorize('score-bout', $this->court);

        $choices = config('scoreboard.finish_sounds');

        // Only one of the offered files, and nothing else: this ends up in a
        // src attribute on the wall board.
        if (! is_array($choices) || ! isset($choices[$value])) {
            $this->finishSound = (string) $this->court->finishSound();

            return;
        }

        $this->court->update(['finish_sound' => $value]);
        $this->court->refresh();
    }

    public function mount(Court $court): void
    {
        // The mat named in the URL is authorised before it is accepted. The
        // route's own gate can only ask whether this account works mats at
        // all; this asks whether it works *this* one, which is the question a
        // referee typing another mat's number is trying to skip.
        Gate::authorize('mat.view', $court);

        $this->court = $court->load('championship');
        $this->finishSound = (string) $court->finishSound();
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
            ->with(['athleteA', 'athleteB', 'ageCategory', 'weightCategory.ageCategory', 'events'])
            ->where('status', Bout::STATUS_ON_COURT)
            ->whereNull('winner_athlete_id')
            ->orderBy('fight_number')
            ->first();
    }

    /**
     * The contest this mat has just finished, while it is still standing here.
     *
     * Only exposed so it can be reopened. A khalol pressed on the wrong side
     * ends the contest instantly and takes it off the mat screen, and without
     * this the only way back would be through the bracket — which asks for a
     * different winner, not for the mistake to be undone.
     */
    #[Computed]
    public function justDecided(): ?Bout
    {
        if ($this->bout() !== null) {
            return null;
        }

        return $this->court->bouts()
            ->with(['athleteA', 'athleteB', 'events'])
            ->where('status', Bout::STATUS_COMPLETED)
            ->whereNotNull('winner_athlete_id')
            ->orderByDesc('updated_at')
            ->first();
    }

    /*
     |--------------------------------------------------------------------------
     | Calls
     |--------------------------------------------------------------------------
     */

    /**
     * Award a call to, or against, one side.
     *
     * A score goes to the athlete who made it; a penalty is recorded against
     * the athlete who committed it, and whatever it hands the opponent is
     * BoutScorer's to write. Nothing about tanbeh giving a chala or dakki
     * giving a yonbosh is decided here — a rule this screen implemented would
     * be a rule the next screen could implement differently.
     */
    public function score(string $call, string $side, ?int $clock = null): void
    {
        Gate::authorize('score-bout', $this->court);

        if (! in_array($call, KurashScore::CALLS, true) || ! in_array($side, ['a', 'b'], true)) {
            return;
        }

        $bout = $this->bout();

        if ($bout === null || ! $bout->isReadyToFight()) {
            session()->flash('error', __('No contest is on this mat.'));

            return;
        }

        $reading = $this->sanitiseClock($clock, $bout);

        app(BoutScorer::class)->record(
            bout: $bout,
            call: $call,
            side: $side,
            clock: $reading,
            user: auth()->user(),
        );

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
     * Take one off a counter.
     *
     * The referee's minus. It resolves to the most recent call of that kind on
     * that side which still stands — not to whichever row is last in the table
     * — so pressing minus under blue's tanbeh takes back a tanbeh against blue
     * and the chala it gave green, and leaves everything else alone.
     */
    public function decrease(string $call, string $side): void
    {
        Gate::authorize('score-bout', $this->court);

        if (! in_array($call, KurashScore::CALLS, true) || ! in_array($side, ['a', 'b'], true)) {
            return;
        }

        $bout = $this->bout();

        if ($bout === null) {
            return;
        }

        $scorer = app(KurashScore::class);
        $last = $scorer->lastLiveCall($bout->events, $bout, $call, $side);

        if ($last === null) {
            session()->flash('error', __('There is no :call on that side to take back.', [
                'call' => __(ucfirst($call)),
            ]));

            return;
        }

        app(BoutScorer::class)->void($bout, $last, auth()->user());

        unset($this->bout);
        $this->awaitingDecision = false;

        session()->flash('status', __(':call taken back.', ['call' => __(ucfirst($call))]));
    }

    /**
     * Take back the most recent call that still stands, whichever side it was.
     *
     * Appends an annulment rather than deleting the row: a protest an hour
     * later needs to see that the call was made and taken back. The tally is
     * recomputed from what remains, so an undo cannot leave a score behind
     * that no sequence of calls could have produced.
     */
    public function voidLast(): void
    {
        Gate::authorize('score-bout', $this->court);

        $bout = $this->bout();

        if ($bout === null) {
            return;
        }

        // Only calls a referee made. An automatic chala is undone by taking
        // back the tanbeh that caused it, not by itself — undoing a consequence
        // while its cause stands would leave a board no sequence of calls could
        // produce.
        $live = array_values(array_filter(
            app(KurashScore::class)->liveCalls($bout->events, $bout),
            fn (array $c): bool => $c['parent_id'] === null,
        ));

        $last = $live === [] ? null : $live[count($live) - 1];

        if ($last === null) {
            session()->flash('error', __('There is nothing to take back.'));

            return;
        }

        app(BoutScorer::class)->void($bout, $last, auth()->user());

        unset($this->bout);
        $this->awaitingDecision = false;

        session()->flash('status', __(':call taken back.', ['call' => __(ucfirst($last['call']))]));
    }

    /*
     |--------------------------------------------------------------------------
     | The clock
     |--------------------------------------------------------------------------
     */

    /**
     * Publish the clock so the wall scoreboard can show it.
     *
     * Called when the operator starts or stops it, and again on every scoring
     * call. Not once a second — the stored value is an anchor the reading
     * sides derive from, so writing it more often would buy nothing.
     */
    public function publishClock(int $secondsLeft, bool $running): void
    {
        Gate::authorize('score-bout', $this->court);

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

    /**
     * Put the clock back to the top for the contest on the mat.
     *
     * A new contest starts from its own division's length, and a mat that has
     * just run a four minute senior bout must not start a three minute cadet
     * one at four. Called when a bout is brought on, and available to the
     * operator by hand for a contest restarted from the beginning.
     */
    public function resetClock(): void
    {
        Gate::authorize('score-bout', $this->court);

        $bout = $this->bout();

        if ($bout === null) {
            return;
        }

        $this->rewind($bout);

        unset($this->bout);
        $this->dispatch('bout-changed', seconds: app(KurashScore::class)->boutSeconds($bout));

        session()->flash('status', __('Clock reset.'));
    }

    /** Record that the referee stopped the contest — tuxta. */
    public function stoppage(?int $clock = null): void
    {
        Gate::authorize('score-bout', $this->court);

        $bout = $this->bout();

        if ($bout === null) {
            return;
        }

        BoutEvent::createInSequence([
            'bout_id' => $bout->id,
            'user_id' => auth()->id(),
            'action' => KurashScore::ACTION_STOPPAGE,
            'entry_action' => KurashScore::ENTRY_ADD,
            'source' => 'operator',
            'after' => ['call' => 'tuxta', 'clock' => $this->sanitiseClock($clock, $bout)],
        ]);

        unset($this->bout);
    }

    /**
     * Jazzo — half the contest gone and neither athlete has scored.
     *
     * The browser notices, because the browser holds the clock, but it does not
     * decide: the halfway mark and the empty board are both checked again here
     * against the log. A reading typed into a request must not be able to halt
     * a contest that is being fought.
     */
    public function callJazzo(?int $clock = null): void
    {
        Gate::authorize('score-bout', $this->court);

        $bout = $this->bout();

        if ($bout === null) {
            return;
        }

        $scorer = app(KurashScore::class);
        $reading = $this->sanitiseClock($clock, $bout) ?? $bout->secondsRemaining($scorer->boutSeconds($bout));
        $tally = $scorer->tally($bout, $bout->events);

        if (! $scorer->jazzoIsDue($bout, $tally, $reading)) {
            return;
        }

        $bout->update([
            'jazzo_called_at' => now(),
            'jazzo_resumed_at' => null,
            'clock_seconds_left' => $reading,
            'clock_running' => false,
            'clock_updated_at' => now(),
        ]);

        BoutEvent::createInSequence([
            'bout_id' => $bout->id,
            'user_id' => auth()->id(),
            'action' => KurashScore::ACTION_JAZZO,
            'entry_action' => KurashScore::ENTRY_ADD,
            'source' => 'operator',
            'after' => ['call' => 'jazzo', 'clock' => $reading],
        ]);

        unset($this->bout);

        session()->flash('status', __('Jazzo — half time with nothing scored. The contest is stopped.'));
    }

    /**
     * Carry on after a jazzo.
     *
     * The clock picks up where it stopped rather than restarting: the contest
     * was halted, not re-run, and the athletes are owed the half they have not
     * fought yet.
     */
    public function resume(?int $clock = null): void
    {
        Gate::authorize('score-bout', $this->court);

        $bout = $this->bout();

        if ($bout === null || ! $bout->isInJazzo()) {
            return;
        }

        $reading = $this->sanitiseClock($clock, $bout) ?? $bout->clock_seconds_left;

        $bout->update([
            'jazzo_resumed_at' => now(),
            'clock_seconds_left' => $reading,
            'clock_running' => true,
            'clock_updated_at' => now(),
        ]);

        BoutEvent::createInSequence([
            'bout_id' => $bout->id,
            'user_id' => auth()->id(),
            'action' => KurashScore::ACTION_RESUMED,
            'entry_action' => KurashScore::ENTRY_ADD,
            'source' => 'operator',
            'after' => ['call' => 'resume', 'clock' => $reading],
        ]);

        unset($this->bout);
    }

    /*
     |--------------------------------------------------------------------------
     | Ending the contest
     |--------------------------------------------------------------------------
     */

    /**
     * Time has run out. Decide on the tally, or ask for a referee decision if
     * the two are level all the way down the tie-break.
     */
    public function finishOnTime(): void
    {
        Gate::authorize('score-bout', $this->court);

        $bout = $this->bout();

        if ($bout === null) {
            return;
        }

        $scorer = app(KurashScore::class);
        $tally = $scorer->tally($bout, $bout->events);
        $outcome = $scorer->outcomeOnTime($bout, $tally);

        if ($outcome === null) {
            $this->awaitingDecision = true;
            session()->flash('error', __('Level on scores, on how they were earned and on warnings — the referees decide this one.'));

            return;
        }

        $this->finalise($bout, $outcome['winner_athlete_id'], $outcome['win_type'], $tally);
    }

    /** The referees' call on a contest that finished level. */
    public function awardDecision(string $side): void
    {
        Gate::authorize('score-bout', $this->court);

        $this->declare($side, 'decision');
    }

    /**
     * Win Blue / Win Green — the referee gives it to a side outright.
     *
     * The one thing on this screen that overrides the log rather than adding to
     * it, so it says so in the record: the win type is `manual`, and the tally
     * that stood at the moment is written alongside it. A result nobody can
     * explain from the calls is worse than no result at all.
     */
    public function declareWinner(string $side): void
    {
        Gate::authorize('score-bout', $this->court);

        $this->declare($side, 'manual');
    }

    private function declare(string $side, string $winType): void
    {
        $bout = $this->bout();

        if ($bout === null || ! in_array($side, ['a', 'b'], true)) {
            return;
        }

        $winnerId = $side === 'a' ? $bout->athlete_a_id : $bout->athlete_b_id;

        if ($winnerId === null) {
            return;
        }

        $scorer = app(KurashScore::class);

        $this->finalise($bout, $winnerId, $winType, $scorer->tally($bout, $bout->events));
    }

    /**
     * End the contest as soon as the log says it is over, so the operator never
     * has to notice that a second yonbosh or a third madichal finished it.
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
            'type' => __(ucfirst($winType)),
        ]));
    }

    /**
     * Put the contest that just ended back on the mat, and take back the call
     * that ended it.
     *
     * The result is cleared first and everything downstream of it unwound, then
     * the last call a referee actually made is annulled — so a khalol pressed
     * on the wrong side leaves a live contest with the board it had a moment
     * earlier, rather than a live contest that is somehow still won.
     */
    public function reopen(): void
    {
        Gate::authorize('score-bout', $this->court);

        $bout = $this->justDecided();

        if ($bout === null) {
            session()->flash('error', __('There is no finished contest on this mat.'));

            return;
        }

        try {
            app(BoutAdvancer::class)->clearResult($bout, auth()->user());
        } catch (Throwable $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $bout->refresh()->load('events');

        // Only a call the referee made, never a consequence: an automatic
        // award is undone by taking back the penalty that caused it.
        $live = array_values(array_filter(
            app(KurashScore::class)->liveCalls($bout->events, $bout),
            fn (array $c): bool => $c['parent_id'] === null,
        ));

        if ($live !== []) {
            app(BoutScorer::class)->void(
                $bout, $live[count($live) - 1], auth()->user(), reason: 'reopened'
            );
        }

        $bout->update(['clock_running' => false, 'clock_updated_at' => now()]);

        unset($this->bout, $this->justDecided);
        $this->awaitingDecision = false;

        session()->flash('status', __('Contest reopened. The call that ended it has been taken back.'));
    }

    /*
     |--------------------------------------------------------------------------
     | Running the mat
     |--------------------------------------------------------------------------
     */

    /** Put the next contest waiting for this mat onto it. */
    public function bringOn(int $boutId): void
    {
        Gate::authorize('score-bout', $this->court);

        $bout = Bout::where('championship_id', $this->court->championship_id)
            // Loose, or already this mat's. A contest standing on another mat
            // belongs to whoever is running that mat, and pulling it across
            // from here would take it out from under them. Scoped in the query
            // so a forged id finds nothing rather than being caught after.
            ->where(fn ($q) => $q->whereNull('court_id')->orWhere('court_id', $this->court->id))
            ->whereKey($boutId)
            ->first();

        if ($bout === null) {
            session()->flash('error', __('That contest is not available to this mat.'));

            return;
        }

        if (! $bout->isReadyToFight()) {
            session()->flash('error', __('That contest is not ready — both athletes must be known.'));

            return;
        }

        if ($this->bout() !== null) {
            session()->flash('error', __('Finish the contest on this mat first.'));

            return;
        }

        $bout->update(['court_id' => $this->court->id, 'status' => Bout::STATUS_ON_COURT]);

        // A new contest starts from the top of its own division's clock. Left
        // to the previous bout's anchor, the wall board would open the next
        // contest showing whatever was left of the last one.
        $this->rewind($bout);

        unset($this->bout);

        // The browser holds the clock, so it has to be told — both that there is
        // a new contest and how long this one runs for, which is not the same
        // number from one division to the next.
        $this->dispatch('bout-changed', seconds: app(KurashScore::class)->boutSeconds($bout));

        session()->flash('status', __('Fight :n is on.', ['n' => $bout->fight_number ?? $bout->play_code]));
    }

    /**
     * Put a contest's clock back to the top and clear any jazzo standing
     * against it.
     *
     * Both together on purpose: a contest that begins again begins with its
     * whole time and with nothing already called at half of it.
     */
    private function rewind(Bout $bout): void
    {
        $bout->update([
            'clock_seconds_left' => app(KurashScore::class)->boutSeconds($bout),
            'clock_running' => false,
            'clock_updated_at' => now(),
            'jazzo_called_at' => null,
            'jazzo_resumed_at' => null,
        ]);
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
            'calls' => $bout !== null ? array_reverse($scorer->liveCalls($bout->events, $bout)) : [],
            'log' => $bout?->events->whereIn('action', [
                KurashScore::ACTION_SCORED,
                KurashScore::ACTION_VOIDED,
                KurashScore::ACTION_STOPPAGE,
                KurashScore::ACTION_JAZZO,
                KurashScore::ACTION_RESUMED,
            ])->sortByDesc('sequence_number')->values() ?? collect(),
            'boutSeconds' => $bout !== null ? $scorer->boutSeconds($bout) : 240,
            // The reading at which the browser should offer jazzo. Checked again
            // on the server when it does.
            'jazzoAt' => $bout !== null ? $scorer->jazzoAt($bout) : 0,
            'inJazzo' => (bool) $bout?->isInJazzo(),
            'anyScore' => $tally['a']->hasScored() || $tally['b']->hasScored(),
            // Read once here rather than per render pass in the view, where it
            // would be a query sitting inside the markup.
            'totalRounds' => $bout === null
                ? 0
                : (int) ($bout->weightCategory->bouts()->max('round') ?? $bout->round),
            'upNext' => $this->upNext(),
            // Offered here rather than in the mat's settings: whoever picks
            // this wants to hear both first, and they are sitting at the mat.
            'finishSounds' => config('scoreboard.finish_sounds'),
            'justDecided' => $this->justDecided(),
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
