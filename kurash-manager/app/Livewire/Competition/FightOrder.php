<?php

namespace App\Livewire\Competition;

use App\Jobs\PushBoutToScoreboard;
use App\Livewire\Concerns\ScopesToCompetition;
use App\Models\Bout;
use App\Models\Championship;
use App\Services\FightOrderScheduler;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Component;

class FightOrder extends Component
{
    use ScopesToCompetition;

    public Championship $championship;

    public int $minimumRest = FightOrderScheduler::DEFAULT_REST;

    public bool $hideCompleted = false;

    public function mount(Championship $championship): void
    {
        $this->championship = $championship;

        // The configured rest is the starting point; the field on the screen
        // stays the override for this session. Read in mount because a
        // property default may not call a function.
        $this->minimumRest = FightOrderScheduler::configuredRest();
    }

    public function schedule(): void
    {
        Gate::authorize('manage-competition');

        $result = app(FightOrderScheduler::class)->schedule($this->championship, $this->minimumRest);

        if ($result['scheduled'] === 0) {
            session()->flash('error', __('Nothing to schedule — draw the brackets first.'));

            return;
        }

        $status = $result['violations'] === 0
            ? __(':count bouts scheduled.', ['count' => $result['scheduled']])
            : __(':count bouts scheduled, but :violations have less than the requested rest.', [
                'count' => $result['scheduled'],
                'violations' => $result['violations'],
            ]);

        // The shortfall no order could fix, said out loud: an administrator
        // reading only the violation count would go looking for a better
        // order that does not exist.
        if ($result['unattainable'] > 0) {
            $status .= ' '.trans_choice(
                '{1}For :count athlete the requested rest is arithmetically out of reach in a session this size.'
                .'|[2,*]For :count athletes the requested rest is arithmetically out of reach in a session this size.',
                $result['unattainable'],
                ['count' => $result['unattainable']],
            );
        }

        session()->flash('status', $status);
    }

    public function clear(): void
    {
        Gate::authorize('manage-competition');

        app(FightOrderScheduler::class)->clear($this->championship);
        session()->flash('status', __('Running order cleared.'));
    }

    public function move(int $boutId, string $direction): void
    {
        Gate::authorize('manage-competition');

        $bout = $this->championship->bouts()->findOrFail($boutId);

        if (! app(FightOrderScheduler::class)->move($bout, $direction)) {
            session()->flash('error', __('Cannot move that bout — it would end up before one that feeds it.'));
        }
    }

    public function sendToMat(int $boutId, int $courtId): void
    {
        Gate::authorize('manage-competition');

        $bout = $this->championship->bouts()->findOrFail($boutId);

        if (! $bout->isReadyToFight()) {
            session()->flash('error', __('That bout is not ready — both athletes must be known.'));

            return;
        }

        $court = $this->championship->courts()->where('is_active', true)->find($courtId);

        if ($court === null) {
            session()->flash('error', __('That mat is not available.'));

            return;
        }

        $bout->update(['court_id' => $court->id, 'status' => Bout::STATUS_ON_COURT]);
        PushBoutToScoreboard::dispatch($bout->id);

        session()->flash('status', __('Fight :n sent to :mat.', ['n' => $bout->fight_number, 'mat' => $court->label()]));
    }

    public function render(): View
    {
        $scheduler = app(FightOrderScheduler::class);

        $bouts = $this->championship->bouts()
            ->whereNotNull('fight_number')
            ->when($this->hideCompleted, fn ($q) => $q->where('status', '!=', Bout::STATUS_COMPLETED))
            ->tap(fn ($q) => $this->scopeBouts($q))
            ->with(['athleteA', 'athleteB', 'winner', 'weightCategory.ageCategory', 'court'])
            ->orderBy('fight_number')
            ->get();

        // Phase names need the bracket depth of the bout's own weight class.
        $roundsByCategory = $this->championship->bouts()
            ->selectRaw('weight_category_id, MAX(round) as total')
            ->groupBy('weight_category_id')
            ->pluck('total', 'weight_category_id');

        return view('livewire.competition.fight-order', [
            'bouts' => $bouts,
            'roundsByCategory' => $roundsByCategory,
            'courts' => $this->championship->courts()->where('is_active', true)->orderBy('number')->get(),
            'violations' => $scheduler->restViolations($this->championship, $this->minimumRest),
            'unscheduled' => $this->championship->bouts()->whereNull('fight_number')->where('is_bye', false)->count(),
        ]);
    }
}
