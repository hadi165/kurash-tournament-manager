<?php

namespace App\Exports;

use App\Models\Athlete;
use App\Models\Championship;
use App\Models\WeightCategory;
use App\Support\BracketSeeding;

/**
 * Competition readiness at a glance — specification §6.2.
 *
 * One line per weight class showing how many made the scale, what bracket that
 * field needs, and whether the draw has been made. This is the sheet the
 * competition director works from on the morning of an event.
 */
class EntriesByWeightCategoryReport implements HasTotal, Report
{
    public function __construct(private readonly Championship $championship) {}

    public function title(): string
    {
        return 'Number of Entries by Weight Category';
    }

    public function filename(): string
    {
        return 'Entries-by-Weight-'.str($this->championship->title)->slug();
    }

    public function meta(): array
    {
        return ['Competition' => $this->championship->title];
    }

    public function headings(): array
    {
        return ['Category', 'Gender / Weight Category', 'Registered', 'Weighed in', 'Bracket', 'Draw Status'];
    }

    /** @var list<list<string|int|float|null>>|null */
    private ?array $memo = null;

    public function rows(): array
    {
        return $this->memo ??= $this->build();
    }

    /** @return list<list<string|int|float|null>> */
    private function build(): array
    {
        $categories = WeightCategory::query()
            ->whereHas('ageCategory', fn ($q) => $q->where('championship_id', $this->championship->id))
            ->with('ageCategory')
            ->withCount(['athletes', 'bouts'])
            ->get()
            ->sortBy(fn (WeightCategory $c) => [$c->ageCategory->sort_order, $c->sort_order, $c->label]);

        // Counted in one grouped query rather than as a withCount alias, so the
        // number arrives as an int on a known key instead of a dynamic
        // attribute nothing can check.
        $passed = Athlete::query()
            ->where('championship_id', $this->championship->id)
            ->where('weighin_status', 'pass')
            ->whereNotNull('weight_category_id')
            ->groupBy('weight_category_id')
            ->selectRaw('weight_category_id, COUNT(*) AS total')
            ->pluck('total', 'weight_category_id');

        $rows = [];

        foreach ($categories as $category) {
            $passedCount = (int) ($passed[$category->id] ?? 0);

            $rows[] = [
                $category->ageCategory->name,
                $category->exportName(),
                $category->athletes_count,
                $passedCount,
                BracketSeeding::phaseName($passedCount),
                // The specification fixes this vocabulary: Not Started / Done.
                $category->bouts_count > 0 ? 'Done' : 'Not Started',
            ];
        }

        return $rows;
    }

    public function total(): array
    {
        return [
            'label' => 'Total weighed in',
            'value' => array_sum(array_map(fn (array $row) => (int) $row[3], $this->rows())),
        ];
    }
}
