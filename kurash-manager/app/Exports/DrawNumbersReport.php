<?php

namespace App\Exports;

use App\Models\Athlete;
use App\Models\WeightCategory;
use App\Support\BracketSeeding;
use App\Support\Noc;
use Illuminate\Database\Eloquent\Collection;

/**
 * The entry list, carrying the draw number each athlete holds.
 *
 * The confirmed weigh-in list is the sheet the numbers are written *onto*, and
 * leaves the column blank on purpose. This is the other half: what came back
 * off it.
 *
 * Read down the accreditation numbers, not the draw numbers. This is a
 * register — somebody looking for one athlete on it knows the number on their
 * card, not where the draw put them — so the draw numbers on it come out in no
 * order at all, which is the point. The order is Athlete::entryOrder(), the
 * same comparator the screen sorts by, so the list and its export cannot
 * disagree.
 *
 * Everybody registered in the class appears, drawn or not: a register that
 * silently omits whoever has no number yet is a register nobody can count
 * heads against. An athlete without one shows a dash.
 *
 * Read-only in the strongest sense — nothing here draws, numbers or writes.
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
            'Draw numbers given' => $drawn->count().' of '.$this->category->athletes()->count(),
            'Drawn on' => $this->category->draw_generated_at?->format('j M Y H:i'),
        ]);
    }

    public function headings(): array
    {
        // The accreditation number leads, because that is what the list is read
        // down. "Draw No." is the athlete's position in the bracket and is
        // named that everywhere it appears.
        return ["Athlete's ID (IKA)", "Athlete's Name", 'NOC', 'Country', 'Draw No.'];
    }

    public function rows(): array
    {
        return array_values($this->entrants()->map(fn (Athlete $a) => [
            $a->ika_id,
            $a->fullname,
            Noc::normalise($a->noc_code),
            $a->noc_name,
            // The saved number and nothing else: this sheet reports the draw,
            // it does not make one.
            $a->draw_number ?? '—',
        ])->all());
    }

    public function total(): array
    {
        return ['label' => 'Athletes', 'value' => $this->entrants()->count()];
    }

    /**
     * Everybody in the class, in the order a register is read.
     *
     * @return Collection<int, Athlete>
     */
    private function entrants(): Collection
    {
        return $this->category->athletes()
            ->get()
            ->sortBy(fn (Athlete $athlete) => $athlete->entryOrder())
            ->values();
    }

    /**
     * Everybody holding a number.
     *
     * @return Collection<int, Athlete>
     */
    private function drawn(): Collection
    {
        return $this->category->numberedAthletes()->get();
    }
}
