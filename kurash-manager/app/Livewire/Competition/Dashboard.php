<?php

namespace App\Livewire\Competition;

use App\Models\Championship;
use App\Services\DashboardSnapshot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The first screen after signing in, and the desk's command centre.
 *
 * One championship at a time. The screen this replaced summarised every
 * championship ever run, which meant the competition happening in the hall
 * appeared somewhere down a list, and each of the others cost the same handful
 * of queries to render something nobody was going to read.
 *
 * It answers, in this order: what needs attention, what is on each mat, what is
 * called next, how far registration and the competition have got, what has been
 * decided, and which screen to put on the projector. The full tables stay on
 * their own screens — this page is what an operator glances at between
 * contests, not somewhere to work from.
 */
class Dashboard extends Component
{
    /**
     * Which championship is on screen, carried in the URL.
     *
     * In the URL so the desk can keep the right competition on a bookmark and
     * a second screen, and so a link to "the dashboard for this championship"
     * exists at all. Browser-owned like every Livewire property, so it is
     * resolved through Championship::open() and never trusted as a key.
     */
    #[Url(as: 'championship', except: null)]
    public ?int $selected = null;

    public function render(): View
    {
        $open = $this->openChampionships();
        $championship = $this->currentChampionship($open);

        if ($championship === null) {
            return view('livewire.competition.dashboard', [
                'championship' => null,
                'openChampionships' => $open,
            ])->title(__('Dashboard'));
        }

        $snapshot = app(DashboardSnapshot::class);

        return view('livewire.competition.dashboard', [
            'championship' => $championship,
            'openChampionships' => $open,
            'status' => $snapshot->status($championship),
            'attention' => $snapshot->attention($championship),
            'comingUp' => $snapshot->comingUp($championship),
            'hasRunningOrder' => $snapshot->hasRunningOrder($championship),
            'workflow' => $snapshot->athleteWorkflow($championship),
            'progress' => $snapshot->boutProgress($championship),
            'medals' => $snapshot->medalSnapshot($championship),
            'counts' => $snapshot->counts($championship),
        ])->title(__('Dashboard'));
    }

    /**
     * Every championship still being run.
     *
     * Archived ones are excluded throughout. An archive is a finished record
     * that nothing may write to — see ArchivedChampionshipGuard — so a screen
     * whose whole purpose is "what do I do next" has nothing to say about one.
     *
     * @return Collection<int, Championship>
     */
    private function openChampionships(): Collection
    {
        return Championship::open()
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The championship to show: the chosen one if it is a real open
     * championship, otherwise the one the desk most likely means.
     *
     * @param  Collection<int, Championship>  $open
     */
    private function currentChampionship(Collection $open): ?Championship
    {
        if ($this->selected !== null) {
            $chosen = $open->firstWhere('id', $this->selected);

            if ($chosen !== null) {
                return $chosen;
            }

            // Archived since the link was made, deleted, or simply never
            // existed. Falling back beats an error page: the operator asked for
            // the dashboard, not for that particular championship.
            $this->selected = null;
        }

        return $this->mostRelevant();
    }

    /**
     * The championship a desk opening this screen cold almost certainly wants.
     *
     * Running today first, because that is the one in the hall. Then the most
     * recently started, which is the one whose medals are still being printed.
     * Only then the nearest upcoming, and finally anything at all, so a
     * championship created without dates is still reachable rather than
     * stranding the screen on an empty state that says there are none.
     */
    private function mostRelevant(): ?Championship
    {
        $today = today();

        $running = Championship::open()
            ->whereDate('starts_on', '<=', $today)
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))
            ->orderByDesc('starts_on')
            ->first();

        if ($running !== null) {
            return $running;
        }

        $recent = Championship::open()
            ->whereDate('starts_on', '<=', $today)
            ->orderByDesc('starts_on')
            ->first();

        if ($recent !== null) {
            return $recent;
        }

        $upcoming = Championship::open()
            ->whereDate('starts_on', '>', $today)
            ->orderBy('starts_on')
            ->first();

        return $upcoming ?? Championship::open()->orderByDesc('id')->first();
    }
}
