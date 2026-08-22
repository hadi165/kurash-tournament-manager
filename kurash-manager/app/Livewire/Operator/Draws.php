<?php

namespace App\Livewire\Operator;

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
    #[Url]
    public string $search = '';

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
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';

                $q->where(fn ($inner) => $inner
                    ->where('label', 'like', $term)
                    ->orWhereHas('ageCategory', fn ($age) => $age->where('name', 'like', $term))
                    ->orWhereHas('ageCategory.championship', fn ($champ) => $champ->where('title', 'like', $term)));
            })
            ->when($this->status === 'published', fn ($q) => $q->whereNotNull('draw_published_at'))
            ->when($this->status === 'waiting', fn ($q) => $q->whereNull('draw_published_at'))
            ->with(['ageCategory.championship'])
            ->withCount('bouts')
            ->orderBy('age_category_id')
            ->orderBy('sort_order')
            ->get();

        return view('livewire.operator.draws', [
            'categories' => $categories,
            'ageCategories' => $categories->pluck('ageCategory')->unique('id')->sortBy('name')->values(),
        ]);
    }
}
