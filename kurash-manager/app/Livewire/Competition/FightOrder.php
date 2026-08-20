<?php

namespace App\Livewire\Competition;

use App\Jobs\PushBoutToScoreboard;
use App\Models\Bout;
use App\Models\Championship;
use App\Services\FightOrderScheduler;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

class FightOrder extends Component
{
    public Championship $championship;

    public int $minimumRest = FightOrderScheduler::DEFAULT_REST;

    public bool $hideCompleted = false;

    /** Which competition the running order is being read for. */
    #[Url]
    public string $gender = '';

    #[Url]
    public string $ageCategory = '';

    public function mount(Championship $championship): void
    {
        $this->championship = $championship;
    }

    public function schedule(): void
    {
        Gate::authorize('manage-competition');

        $result = app(FightOrderScheduler::class)->schedule($this->championship, $this->minimumRest);

        if ($result['scheduled'] === 0) {
            session()->flash('error', __('Nothing to schedule — draw the brackets first.'));

            return;
        }

        session()->flash('status', $result['violations'] === 0
            ? __(':count bouts scheduled.', ['count' => $result['scheduled']])
            : __(':count bouts scheduled, but :violations have less than the requested rest.', [
                'count' => $result['scheduled'],
                'violations' => $result['violations'],
            ]));
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
            // Filtered in the query, not in the row: a running order narrowed
            // to the women's classes must not carry the men's bouts in the
            // payload with the rows merely hidden.
            ->when($this->gender !== '', fn ($q) => $q->whereHas(
                'weightCategory',
                fn ($category) => $category->where('gender', $this->gender)
            ))
            ->when($this->ageCategory !== '', fn ($q) => $q->where('age_category_id', $this->ageCategory))
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
            'ageCategories' => $this->championship->ageCategories()->orderBy('sort_order')->get(),
        ]);
    }
}
