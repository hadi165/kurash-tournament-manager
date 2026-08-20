<?php

namespace App\Livewire\Competition;

use App\Jobs\PushBoutToScoreboard;
use App\Models\Athlete;
use App\Models\Bout;
use App\Models\Court;
use App\Models\WeightCategory;
use App\Services\BoutAdvancer;
use App\Services\BracketGenerator;
use App\Services\BracketHasResultsException;
use App\Services\DrawIsProtectedException;
use App\Services\MedalTable;
use App\Support\BracketSeeding;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Component;
use Throwable;

/**
 * Draw, bracket and results for one weight class — the three screens the old
 * system spread across draw-select-weight, draw-reveal, bracket-view and
 * fight-order, connected by CSV files on disk. Here they are one screen backed
 * by one table.
 */
class Bracket extends Component
{
    public WeightCategory $weightCategory;

    /**
     * Athlete id => draw number. Seeded as strings, but the browser owns this
     * array once the page is live and an emptied number input hydrates back as
     * null, so both are handled in saveDraws().
     *
     * @var array<int, string|null>
     */
    public array $draws = [];

    public bool $confirmingRegenerate = false;

    public bool $confirmingDelete = false;

    /** Set when a draw that somebody may already be presenting would be replaced. */
    public bool $confirmingReplacePublished = false;

    public function mount(WeightCategory $weightCategory): void
    {
        $this->weightCategory = $weightCategory->load('ageCategory.championship');
        $this->syncDraws();
    }

    private function syncDraws(): void
    {
        $this->draws = $this->athletes()
            ->mapWithKeys(fn (Athlete $a) => [$a->id => (string) ($a->draw_number ?? '')])
            ->all();
    }

    /** @return Collection<int, Athlete> */
    private function athletes(): Collection
    {
        return $this->weightCategory->athletes()->orderBy('fullname')->get();
    }

    public function saveDraws(): void
    {
        Gate::authorize('manage-competition');

        $seen = [];

        foreach ($this->draws as $athleteId => $value) {
            if ($value === '' || $value === null) {
                continue;
            }

            if (! ctype_digit((string) $value) || (int) $value < 1) {
                session()->flash('error', __('Draw numbers must be whole numbers of 1 or more.'));

                return;
            }

            $number = (int) $value;

            if (isset($seen[$number])) {
                session()->flash('error', __('Draw number :n is used twice. Each athlete needs their own.', ['n' => $number]));

                return;
            }

            $seen[$number] = $athleteId;
        }

        // Cleared first, then written, and both inside one transaction. Draw
        // numbers are unique per category, so writing them one at a time in
        // place fails the moment two athletes swap: the first update tries to
        // take a number the second is still holding.
        //
        // Written by query rather than through the loaded models for the same
        // reason drawAtRandom() does: an Eloquent model only persists *dirty*
        // attributes, and these were loaded before the clear, so an athlete
        // keeping the number they already had would look unchanged and be left
        // at NULL.
        DB::transaction(function () use ($seen) {
            $this->weightCategory->athletes()->update(['draw_number' => null, 'draw_number_source' => null]);

            foreach ($seen as $number => $athleteId) {
                Athlete::whereKey($athleteId)->update([
                    'draw_number' => $number,
                    'draw_number_source' => 'manual',
                ]);
            }
        });

        $this->syncDraws();

        // Changing the numbers does not move anybody in a bracket that already
        // exists — the draw is what the bracket was built from, not something
        // it reads live. Saying so beats leaving an official to wonder why the
        // tree on screen did not move.
        session()->flash('status', $this->weightCategory->bouts()->exists()
            ? __('Draw numbers saved. Redraw the bracket for the new order to take effect.')
            : __('Draw numbers saved.'));
    }

    /** Assign draw numbers at random to everyone who passed the scale. */
    public function drawAtRandom(): void
    {
        Gate::authorize('manage-competition');

        $eligibleIds = $this->weightCategory->athletes()
            ->where('weighin_status', '!=', 'fail')
            ->pluck('id')
            ->shuffle();

        if ($eligibleIds->isEmpty()) {
            $this->drawFailed(__('Nobody in this class has passed the weigh-in.'));

            return;
        }

        DB::transaction(function () use ($eligibleIds) {
            // Clear first: draw numbers are unique per category, so reassigning
            // in place would collide with the numbers still held.
            $this->weightCategory->athletes()->update(['draw_number' => null, 'draw_number_source' => null]);

            // Write by query rather than through loaded models. An Eloquent
            // model only persists *dirty* attributes, and these were loaded
            // before the clear above — so any athlete drawn the same number
            // they already had would look unchanged and silently keep NULL.
            foreach ($eligibleIds->values() as $index => $id) {
                Athlete::whereKey($id)->update([
                    'draw_number' => $index + 1,
                    'draw_number_source' => 'random',
                ]);
            }
        });

        // The ceremony board reads this stamp to pace its reveal. The draw
        // itself is already committed above — what is paced is the telling of
        // it, never the drawing, so a hall watching position 4 appear is
        // watching a result that has been final for ten seconds.
        Cache::put(
            DrawCeremony::paceKey($this->weightCategory->id),
            ['at' => (int) now()->timestamp, 'per' => 3],
            now()->addHour(),
        );

        $this->syncDraws();
        session()->flash('status', __('Drew :count athlete(s) at random.', ['count' => $eligibleIds->count()]));

        $this->dispatch('draw-completed', mode: 'positions');
    }

    public function generate(bool $discardResults = false, bool $replacePublished = false): void
    {
        Gate::authorize('manage-competition');

        try {
            $result = app(BracketGenerator::class)->generate($this->weightCategory, $discardResults, $replacePublished);
        } catch (DrawIsProtectedException $e) {
            // A published draw is one other people have been told to work
            // from, so replacing it is a decision, not a click.
            $this->confirmingReplacePublished = ! $this->weightCategory->isDrawLocked();
            $this->drawFailed($e->getMessage());

            return;
        } catch (BracketHasResultsException $e) {
            $this->confirmingRegenerate = true;
            $this->drawFailed($e->getMessage());

            return;
        } catch (Throwable $e) {
            $this->drawFailed($e->getMessage());

            return;
        }

        $this->confirmingRegenerate = false;
        $this->confirmingReplacePublished = false;

        $this->dispatch('draw-completed', mode: 'bracket');

        session()->flash('status', __('Bracket drawn: :bouts bouts across :rounds rounds, :byes bye(s).', [
            'bouts' => $result['bouts'],
            'rounds' => $result['rounds'],
            'byes' => $result['byes'],
        ]));
    }

    /**
     * Tell the ceremony overlay the draw did not run.
     *
     * Flashed as well as dispatched: the overlay is decoration and the flash
     * is the record, so the message survives on the screen after the overlay
     * is dismissed.
     */
    private function drawFailed(string $message): void
    {
        session()->flash('error', $message);

        $this->dispatch('draw-failed', message: $message);
    }

    /**
     * Approve the drawn table for everybody else to see.
     *
     * Publication is what separates a table being worked on from one an
     * operator may present, so it is its own permission and its own decision —
     * generating a bracket deliberately does not publish it.
     */
    public function publishDraw(): void
    {
        Gate::authorize('draw.publish');

        if (! $this->weightCategory->hasDraw()) {
            session()->flash('error', __('There is no draw to publish yet.'));

            return;
        }

        $this->weightCategory->forceFill(['draw_published_at' => now()])->save();

        session()->flash('status', __('Draw published. Operators can present it now.'));
    }

    public function withdrawDraw(): void
    {
        Gate::authorize('draw.publish');

        $this->weightCategory->forceFill(['draw_published_at' => null])->save();

        session()->flash('status', __('Draw withdrawn. It is no longer available to operators.'));
    }

    /** Locked means not even an admin redraws it without unlocking first. */
    public function toggleDrawLock(): void
    {
        Gate::authorize('draw.publish');

        $locked = $this->weightCategory->isDrawLocked();

        $this->weightCategory->forceFill(['draw_locked_at' => $locked ? null : now()])->save();

        session()->flash('status', $locked ? __('Draw unlocked.') : __('Draw locked.'));
    }

    /**
     * Throw the drawn bracket away.
     *
     * Registration refuses to remove an athlete once their class has been
     * drawn, because deleting one out of a bracket leaves a tree with a hole
     * in it. That is the right refusal, but it needs a way out: this is it.
     * Draw numbers are deliberately kept, so the usual sequence — delete,
     * correct the entry list, redraw — costs nobody the draw they already
     * made.
     *
     * Deleted through the models rather than by one query, so the archived
     * championship guard fires: a closed competition's results are not
     * something a button should be able to erase.
     */
    public function deleteBracket(bool $discardResults = false): void
    {
        Gate::authorize('manage-competition');

        if (! $this->weightCategory->bouts()->exists()) {
            session()->flash('error', __('There is no bracket to delete.'));

            return;
        }

        // A contest being scored right now would vanish from under the mat
        // screen mid-bout, so that one is refused outright rather than
        // confirmed.
        if ($this->weightCategory->bouts()->where('status', Bout::STATUS_ON_COURT)->exists()) {
            session()->flash('error', __('A contest from this class is on a mat. Take it off the mat before deleting the bracket.'));

            return;
        }

        $decided = $this->weightCategory->bouts()
            ->whereNotNull('winner_athlete_id')
            ->where('is_bye', false)
            ->count();

        if ($decided > 0 && ! $discardResults) {
            $this->confirmingDelete = true;

            session()->flash('error', trans_choice(
                '{1}:count contest has been decided in this class. Deleting the bracket erases it.'
                .'|[2,*]:count contests have been decided in this class. Deleting the bracket erases them.',
                $decided,
                ['count' => $decided],
            ));

            return;
        }

        try {
            DB::transaction(function () {
                $this->weightCategory->bouts()->get()->each->delete();

                // The metadata described rows that no longer exist, and a
                // deleted draw is certainly not a published one.
                $this->weightCategory->forceFill([
                    'draw_generated_at' => null,
                    'draw_athlete_count' => null,
                    'draw_bucket_size' => null,
                    'draw_bye_count' => null,
                    'draw_published_at' => null,
                ])->save();
            });
        } catch (Throwable $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->confirmingDelete = false;
        $this->confirmingRegenerate = false;

        session()->flash('status', __('Bracket deleted. The draw numbers are kept, so this class can be drawn again.'));
    }

    /**
     * Put a bout on a mat and tell that mat's display about it.
     *
     * The push is queued: a scoreboard on a flaky venue network can take the
     * whole timeout to fail, and the official pressing this should not wait
     * for it, nor should the assignment fail because a display is unplugged.
     */
    public function sendToMat(int $boutId, int $courtId): void
    {
        Gate::authorize('manage-competition');

        $bout = $this->weightCategory->bouts()->findOrFail($boutId);

        if (! $bout->isReadyToFight()) {
            session()->flash('error', __('That bout is not ready — both athletes must be known.'));

            return;
        }

        $court = Court::where('championship_id', $this->weightCategory->ageCategory->championship_id)
            ->where('is_active', true)
            ->find($courtId);

        if ($court === null) {
            session()->flash('error', __('That mat is not available in this championship.'));

            return;
        }

        $bout->update(['court_id' => $court->id, 'status' => Bout::STATUS_ON_COURT]);

        PushBoutToScoreboard::dispatch($bout->id);

        session()->flash('status', __('Sent to :mat.', ['mat' => $court->label()]));
    }

    public function recordResult(int $boutId, string $side): void
    {
        Gate::authorize('manage-competition');

        $bout = $this->weightCategory->bouts()->findOrFail($boutId);
        $winnerId = $side === 'a' ? $bout->athlete_a_id : $bout->athlete_b_id;

        if ($winnerId === null) {
            session()->flash('error', __('That side has no athlete yet.'));

            return;
        }

        try {
            app(BoutAdvancer::class)->recordResult(
                bout: $bout,
                winnerAthleteId: $winnerId,
                winType: 'halal',
                user: auth()->user(),
                source: 'operator',
            );
        } catch (Throwable $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        session()->flash('status', __('Result recorded.'));
    }

    public function render(): View
    {
        $bouts = $this->weightCategory->bouts()
            ->with(['athleteA', 'athleteB', 'winner'])
            ->orderBy('round')
            ->orderBy('position_in_round')
            ->get();

        return view('livewire.competition.bracket', [
            'athletes' => $this->athletes(),
            'bouts' => $bouts,
            'rounds' => $bouts->groupBy('round'),
            'totalRounds' => (int) ($bouts->max('round') ?? 0),
            'podium' => app(MedalTable::class)->forCategory($this->weightCategory),
            'drawnCount' => $this->weightCategory->drawnAthletes()->count(),
            'courts' => Court::where('championship_id', $this->weightCategory->ageCategory->championship_id)
                ->where('is_active', true)
                ->orderBy('number')
                ->get(),
            'projectedSize' => $this->projectedSize(),
            // Read from the same query the generator counts, so the summary and
            // the draw can never disagree about how many are in the class.
            'drawSummary' => $this->drawSummary(),
        ]);
    }

    /**
     * What drawing now would produce.
     *
     * @return array{athletes:int, size:int, byes:int, firstRound:int}
     */
    private function drawSummary(): array
    {
        $athletes = $this->weightCategory->drawnAthletes()->count();
        $size = $athletes >= 2 ? BracketSeeding::size($athletes) : 0;
        $byes = max(0, $size - $athletes);

        return [
            'athletes' => $athletes,
            'size' => $size,
            'byes' => $byes,
            // Every first-round pair that is not a walkover.
            'firstRound' => $size > 0 ? max(0, intdiv($size, 2) - $byes) : 0,
        ];
    }

    private function projectedSize(): ?int
    {
        $drawn = $this->weightCategory->drawnAthletes()->count();

        return $drawn >= 2 ? BracketSeeding::size($drawn) : null;
    }
}
