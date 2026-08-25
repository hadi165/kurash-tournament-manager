<?php

namespace App\Livewire\Operator;

use App\Models\Championship;
use App\Models\WeightCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The weight categories an operator can present.
 *
 * Every filter narrows a query that was already narrowed by authorisation, so
 * no combination of them can widen what comes back. Nothing about an
 * unpublished draw is loaded — not a name, not a pairing — only the fact that
 * the category exists and is waiting on an admin.
 */
class Draws extends Component
{
    /**
     * The competition being presented.
     *
     * A choice rather than a search box: an operator standing at a draw knows
     * which championship they are running and should not have to spell it, and
     * a typed word that matches nothing looks exactly like a competition with
     * no published draws.
     */
    #[Url]
    public string $championship = '';

    #[Url]
    public string $gender = '';

    #[Url]
    public string $ageCategory = '';

    #[Url]
    public string $status = '';

    public function mount(): void
    {
        Gate::authorize('draw.view_published');
    }

    public function render(): View
    {
        /** @var Collection<int, WeightCategory> $categories */
        $categories = WeightCategory::query()
            ->whereHas('ageCategory.championship', fn ($q) => $q->whereNull('archived_at'))
            ->when($this->gender !== '', fn ($q) => $q->where('gender', $this->gender))
            ->when($this->ageCategory !== '', fn ($q) => $q->where('age_category_id', $this->ageCategory))
            ->when($this->championship !== '', fn ($q) => $q->whereHas(
                'ageCategory',
                fn ($age) => $age->where('championship_id', $this->championship)
            ))
            ->when($this->status === 'published', fn ($q) => $q->whereNotNull('draw_published_at'))
            ->when($this->status === 'waiting', fn ($q) => $q->whereNull('draw_published_at'))
            ->with(['ageCategory.championship'])
            ->withCount('bouts')
            // How many rounds a round robin runs to. Read as an aggregate over
            // the contests that exist rather than derived from the athlete
            // count, so the figure describes the draw that was generated.
            ->withMax('bouts', 'round')
            ->orderBy('age_category_id')
            ->orderBy('sort_order')
            ->get();

        return view('livewire.operator.draws', [
            'categories' => $categories,
            'ageCategories' => $categories->pluck('ageCategory')->unique('id')->sortBy('name')->values(),
            // Every competition still running, whether or not it has a draw to
            // present yet: a list that hid the ones with nothing published
            // would answer "which competitions are there" with "the ones I
            // already have something for".
            'championships' => Championship::query()
                ->whereNull('archived_at')
                ->orderByDesc('starts_on')
                ->orderBy('title')
                ->get(['id', 'title', 'starts_on']),
        ]);
    }
}
