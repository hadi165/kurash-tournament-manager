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
use App\Services\MedalTable;
use App\Support\BracketSeeding;
use Illuminate\Database\Eloquent\Collection;
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

        foreach ($this->athletes() as $athlete) {
            $value = $this->draws[$athlete->id] ?? '';

            $athlete->update([
                'draw_number' => $value === '' ? null : (int) $value,
                'draw_number_source' => $value === '' ? null : 'manual',
            ]);
        }

        session()->flash('status', __('Draw numbers saved.'));
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
            session()->flash('error', __('Nobody in this class has passed the weigh-in.'));

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

        $this->syncDraws();
        session()->flash('status', __('Drew :count athlete(s) at random.', ['count' => $eligibleIds->count()]));
    }

    public function generate(bool $discardResults = false): void
    {
        Gate::authorize('manage-competition');

        try {
            $result = app(BracketGenerator::class)->generate($this->weightCategory, $discardResults);
        } catch (BracketHasResultsException $e) {
            $this->confirmingRegenerate = true;
            session()->flash('error', $e->getMessage());

            return;
        } catch (Throwable $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->confirmingRegenerate = false;

        session()->flash('status', __('Bracket drawn: :bouts bouts across :rounds rounds, :byes bye(s).', [
            'bouts' => $result['bouts'],
            'rounds' => $result['rounds'],
            'byes' => $result['byes'],
        ]));
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
        ]);
    }

    private function projectedSize(): ?int
    {
        $drawn = $this->weightCategory->drawnAthletes()->count();

        return $drawn >= 2 ? BracketSeeding::size($drawn) : null;
    }
}
