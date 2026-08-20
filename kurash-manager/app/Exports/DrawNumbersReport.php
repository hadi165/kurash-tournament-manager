<?php

namespace App\Exports;

use App\Models\Athlete;
use App\Models\WeightCategory;
use App\Support\BracketSeeding;
use App\Support\Noc;
use Illuminate\Database\Eloquent\Collection;

/**
 * The draw numbers, as they were drawn.
 *
 * The confirmed weigh-in list is the sheet the numbers are written *onto*, and
 * leaves the column blank on purpose. This is the other half: what came back
 * off it, in draw order, so the number an athlete holds can be checked against
 * the paper without reading a bracket.
 *
 * Ordered by draw number rather than by name, because that is the order it is
 * read aloud in and the order the bracket seats them in.
 */
class DrawNumbersReport implements HasTotal, Report
{
    public function __construct(private readonly WeightCategory $category) {}

    public function title(): string
    {
        return 'Draw Numbers';
    }

    public function filename(): string
    {
        return 'Draw-Numbers-'.$this->category->exportName();
    }

    public function meta(): array
    {
        $championship = $this->category->ageCategory->championship;
        $drawn = $this->drawn();

        return array_filter([
            'Competition' => $championship->title,
            'Category' => $this->category->ageCategory->name,
            'Gender / Weight Category' => $this->category->exportName(),
            // The stored figures where a draw has been generated, so the sheet
            // says what the bracket was built from rather than what today's
            // entry list would compute.
            'Bracket' => $this->category->draw_bucket_size
                ? 'Bracket of '.$this->category->draw_bucket_size
                : BracketSeeding::phaseName($drawn->count()),
            'Drawn on' => $this->category->draw_generated_at?->format('j M Y H:i'),
        ]);
    }

    public function headings(): array
    {
        return ['Draw No.', "Athlete's Name", "Athlete's ID (IKA)", 'NOC', 'Country', 'Source'];
    }

    public function rows(): array
    {
        return array_values($this->drawn()->map(fn (Athlete $a) => [
            $a->draw_number,
            $a->fullname,
            $a->ika_id,
            Noc::normalise($a->noc_code),
            $a->noc_name,
            // How the number was arrived at: drawn at random, typed in by hand,
            // or brought in from a file. A protest asks this first.
            match ($a->draw_number_source) {
                'random' => 'Random draw',
                'manual' => 'Entered by hand',
                'import' => 'Imported',
                default => '',
            },
        ])->all());
    }

    public function total(): array
    {
        return ['label' => 'Athletes drawn', 'value' => $this->drawn()->count()];
    }

    /**
     * Everybody holding a number, in the order they hold them.
     *
     * @return Collection<int, Athlete>
     */
    private function drawn(): Collection
    {
        return $this->category->drawnAthletes()->get();
    }
}
