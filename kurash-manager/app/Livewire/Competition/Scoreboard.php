<?php

namespace App\Livewire\Competition;

use App\Models\Bout;
use App\Models\Court;
use App\Services\KurashScore;
use App\Support\ScoreTally;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The wall scoreboard for one mat.
 *
 * Opened on its own — a projector, a second monitor, a tablet propped at the
 * scorers' table — and driven entirely by what the mat screen records. It has
 * no controls of its own on purpose: two places that can both change a score
 * is how a score gets changed twice.
 *
 * Unlike the venue display screens this one polls rather than being served
 * from cache. Those have a hall full of viewers and are rendered once per
 * change for all of them; a mat scoreboard has one viewer and has to be right
 * within a second of a call, which is the opposite trade.
 */
#[Layout('components.layouts.scoreboard')]
class Scoreboard extends Component
{
    public Court $court;

    public function mount(Court $court): void
    {
        // Checked whenever somebody is signed in, on this route and on the
        // public display one alike: a scoped account must not reach another
        // championship's mat by editing the id in either URL. Guests are left
        // to the display gate, which is what decides whether a hall screen is
        // public at all.
        if (auth()->check()) {
            Gate::authorize('scoreboard.select_court', $court);
        }

        $this->court = $court->load('championship');
    }

    /**
     * What is on the mat, or the contest most recently decided on it.
     *
     * Holding the finished contest is deliberate: the hall wants to see who
     * won for a moment after it ends, not an empty board the instant the
     * referee raises an arm.
     */
    private function bout(): ?Bout
    {
        return $this->court->bouts()
            ->with(['athleteA', 'athleteB', 'weightCategory', 'events'])
            ->whereIn('status', [Bout::STATUS_ON_COURT, Bout::STATUS_COMPLETED])
            ->orderByRaw('winner_athlete_id is not null')   // live contest first
            ->orderByDesc('updated_at')
            ->first();
    }

    /**
     * What this mat runs after the contest on the board.
     *
     * Scoped to bouts actually assigned here rather than to anything loose in
     * the fight order: the strip tells athletes and coaches standing at this
     * mat that they are up, and a bout that is going to another mat would send
     * them to the wrong place.
     */
    private function nextBout(?Bout $current): ?Bout
    {
        return $this->court->bouts()
            ->readyToFight()
            ->where('status', '!=', Bout::STATUS_ON_COURT)
            ->when($current, fn ($q) => $q->whereKeyNot($current->getKey()))
            ->whereNotNull('fight_number')
            ->with(['athleteA', 'athleteB', 'weightCategory'])
            ->orderBy('fight_number')
            ->first();
    }

    public function render(): View
    {
        $bout = $this->bout();
        $scorer = app(KurashScore::class);

        $tally = $bout !== null
            ? $scorer->tally($bout, $bout->events)
            : ['a' => new ScoreTally, 'b' => new ScoreTally];

        $seconds = $bout !== null ? $scorer->boutSeconds($bout) : 240;

        return view('livewire.competition.scoreboard', [
            'bout' => $bout,
            'tally' => $tally,
            'boutSeconds' => $seconds,
            // The phase name needs the size of the bracket this contest sits
            // in, which is the highest round number in its weight class.
            'totalRounds' => $bout === null
                ? 0
                : (int) ($bout->weightCategory->bouts()->max('round') ?? $bout->round),
            // The reading now, plus whether it is still counting, so the page
            // can run the clock locally between polls instead of stuttering
            // once a second.
            'secondsLeft' => $bout?->secondsRemaining($seconds) ?? $seconds,
            'clockRunning' => (bool) ($bout?->clock_running && ! $bout->isDecided()),
            'winner' => $bout?->isDecided() ? $bout->winner : null,
            'nextBout' => $this->nextBout($bout),
            // The board carries no controls for anybody, but an account that
            // may only read one should be told so rather than left to infer it
            // from an absence.
            'readOnly' => (bool) auth()->user()?->isScoreboardViewer(),
        ]);
    }
}
