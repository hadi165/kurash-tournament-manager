<?php

namespace App\Exports;

use App\Models\Athlete;
use App\Models\Championship;
use App\Models\WeightCategory;
use App\Services\MedalTable;
use App\Support\Noc;

/**
 * Results by weight category — specification §9.1 and §9.2.
 *
 * The medallists for every decided class in one table, which is what goes to
 * the medal ceremony and into the archive.
 */
class ResultsReport implements Report
{
    public function __construct(
        private readonly Championship $championship,
        private readonly MedalTable $medals,
    ) {}

    public function title(): string
    {
        return 'Results by Weight Category';
    }

    public function filename(): string
    {
        return 'Results-'.str($this->championship->title)->slug();
    }

    public function meta(): array
    {
        return [
            'Competition' => $this->championship->title,
            'Location' => $this->championship->location ?? '—',
        ];
    }

    public function headings(): array
    {
        return ['Category', 'Gender / Weight Category', 'Gold', 'NOC', 'Silver', 'NOC', 'Bronze', 'Bronze'];
    }

    public function rows(): array
    {
        $categories = WeightCategory::query()
            ->whereHas('ageCategory', fn ($q) => $q->where('championship_id', $this->championship->id))
            ->with('ageCategory')
            ->get()
            ->sortBy(fn (WeightCategory $c) => [$c->ageCategory->sort_order, $c->sort_order, $c->label]);

        $rows = [];

        foreach ($categories as $category) {
            $podium = $this->medals->forCategory($category);

            // An undecided class is left out rather than printed with blanks:
            // a results sheet listing a class with no medallist reads as an
            // error to whoever receives it.
            if (! $podium['decided']) {
                continue;
            }

            $name = fn (?Athlete $a) => $a?->fullname;
            $noc = fn (?Athlete $a) => Noc::normalise($a?->noc_code);

            $rows[] = [
                $category->ageCategory->name,
                $category->exportName(),
                $name($podium['gold']),
                $noc($podium['gold']),
                $name($podium['silver']),
                $noc($podium['silver']),
                // A bracket without semi-finals awards fewer than two bronzes.
                $this->bronzeLabel($podium['bronze'], 0),
                $this->bronzeLabel($podium['bronze'], 1),
            ];
        }

        return $rows;
    }

    /** @param  list<Athlete>  $bronze */
    private function bronzeLabel(array $bronze, int $index): ?string
    {
        $athlete = $bronze[$index] ?? null;

        return $athlete === null ? null : $athlete->fullname.' ('.Noc::normalise($athlete->noc_code).')';
    }
}
