<?php

namespace App\Exports;

use App\Models\Athlete;
use App\Models\WeightCategory;
use App\Support\BracketSeeding;
use App\Support\Noc;
use Illuminate\Database\Eloquent\Collection;

/**
 * The confirmed weigh-in list for one class — specification §5.3.
 *
 * This is the sheet the executive team writes draw numbers onto, so the Draw
 * No. column is deliberately blank even for athletes who already have one. The
 * paper copy is the input to the draw, not a report of it.
 */
class ConfirmedWeighInReport implements Report
{
    public function __construct(private readonly WeightCategory $category) {}

    public function title(): string
    {
        return 'Confirmed Weigh-in List';
    }

    public function filename(): string
    {
        // "Male -91", exactly as the specification names it.
        return $this->category->exportName();
    }

    public function meta(): array
    {
        $championship = $this->category->ageCategory->championship;

        return [
            'Competition' => $championship->title,
            'Category' => $this->category->ageCategory->name,
            'Gender / Weight Category' => $this->category->exportName(),
            'Bracket Title' => BracketSeeding::phaseName($this->eligible()->count()),
        ];
    }

    public function headings(): array
    {
        return ["Athlete's Name", "Athlete's ID (IKA)", 'NOC', 'Country', 'Bracket Title', 'Draw No.'];
    }

    public function rows(): array
    {
        $eligible = $this->eligible();
        $bracketTitle = BracketSeeding::phaseName($eligible->count());

        return array_values($eligible->map(fn (Athlete $a) => [
            $a->fullname,
            $a->ika_id,
            Noc::normalise($a->noc_code),
            $a->noc_name,
            $bracketTitle,
            '',     // left blank on purpose — see the class comment
        ])->all());
    }

    /**
     * Only athletes who made the scale. Someone who failed the weigh-in is not
     * drawn, so printing them here would invite them onto the sheet.
     *
     * @return Collection<int, Athlete>
     */
    private function eligible(): Collection
    {
        return $this->category->athletes()
            ->where('weighin_status', 'pass')
            ->orderBy('noc_code')
            ->orderBy('fullname')
            ->get();
    }
}
