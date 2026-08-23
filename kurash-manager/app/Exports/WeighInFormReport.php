<?php

namespace App\Exports;

use App\Models\Athlete;
use App\Models\WeightCategory;
use App\Support\BracketSeeding;
use App\Support\Noc;
use Illuminate\Database\Eloquent\Collection;

/**
 * The weigh-in sheet for one class — specification §5.3.
 *
 * Printed before the scale is opened and handed to the weigh-in referee, who
 * writes each reading onto it by hand. So it lists everyone entered in the
 * class rather than everyone who has already passed: it used to be filtered to
 * `weighin_status = pass`, which made the sheet the referee needed come out
 * blank, because at the moment it is wanted nobody has been weighed.
 *
 * The same sheet is the record afterwards, which is why Weight and Result fill
 * in as the scale runs. Result is not decoration: this sheet is what the
 * executive team writes draw numbers onto, and an athlete who missed the
 * weight must not be drafted onto a draw because the paper did not say so.
 */
class WeighInFormReport implements Report
{
    public function __construct(private readonly WeightCategory $category) {}

    public function title(): string
    {
        return 'Weigh-in Form';
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
        return ["Athlete's Name", "Athlete's ID (IKA)", 'NOC', 'Country', 'Bracket Title', 'Weight', 'Result'];
    }

    public function rows(): array
    {
        $bracketTitle = BracketSeeding::phaseName($this->eligible()->count());

        return array_values($this->entered()->map(fn (Athlete $a) => [
            $a->fullname,
            $a->ika_id,
            Noc::normalise($a->noc_code),
            $a->noc_name,
            $bracketTitle,
            // Blank until somebody has stood on the scale — which is the whole
            // point of the sheet, and the space the referee writes in.
            $a->weighin_kg === null ? '' : rtrim(rtrim(number_format((float) $a->weighin_kg, 2, '.', ''), '0'), '.'),
            match ($a->weighin_status) {
                'pass' => 'Passed',
                'fail' => 'Failed',
                default => '',
            },
        ])->all());
    }

    /**
     * Everyone entered in the class, in the order a delegation reads.
     *
     * @return Collection<int, Athlete>
     */
    private function entered(): Collection
    {
        return $this->category->athletes()
            ->orderBy('noc_code')
            ->orderBy('fullname')
            ->get();
    }

    /**
     * Those a bracket could still be built from: everyone except the athletes
     * who have already missed the weight.
     *
     * Before the scale opens that is the whole entry list, which is the
     * bracket the class expects; afterwards it is the bracket it will get.
     *
     * @return Collection<int, Athlete>
     */
    private function eligible(): Collection
    {
        return $this->entered()->reject(fn (Athlete $a) => $a->weighin_status === 'fail');
    }
}
