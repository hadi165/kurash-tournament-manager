<?php

namespace App\Exports;

use App\Models\Athlete;
use App\Models\Bout;
use App\Models\Championship;
use App\Support\Gender;
use App\Support\Noc;

/**
 * The running order for a whole championship — specification §8.1.
 *
 * One row per contest, the same columns in the same order as the screen it is
 * printed from. It used to be one row per competitor — the blue corner and the
 * green corner on separate lines sharing a fight number — which is how the
 * federation's own sheets are ruled, but it meant the sheet and the screen were
 * two different documents describing the same thing, and a table official
 * checking one against the other had to translate between them.
 */
class FightOrderReport implements Report
{
    /**
     * @param  string|null  $competition  'M' or 'F' to print one competition on
     *                                    its own. A table official working the
     *                                    women's classes should never have to
     *                                    read past the men's.
     */
    public function __construct(
        private readonly Championship $championship,
        private readonly ?string $competition = null,
    ) {}

    public function title(): string
    {
        return $this->competition === null
            ? 'Fight Order'
            : 'Fight Order — '.Gender::label($this->competition);
    }

    public function filename(): string
    {
        $name = 'Fight-Order-'.str($this->championship->title)->slug();

        return $this->competition === null
            ? $name
            : $name.'-'.str(Gender::label($this->competition))->slug();
    }

    public function meta(): array
    {
        return array_filter([
            'Competition' => $this->championship->title,
            'Location' => $this->championship->location ?? '—',
            // Stated on the sheet, not only in the filename: a printout that
            // leaves the room has to say which classes it covers.
            'Classes' => $this->competition === null
                ? 'All competitions'
                : Gender::label($this->competition),
        ]);
    }

    public function headings(): array
    {
        return ['No.', 'Category', 'Phase', 'Blue', 'Green', 'Mat', 'Winner'];
    }

    public function rows(): array
    {
        $bouts = $this->championship->bouts()
            ->whereNotNull('fight_number')
            // Scoped in the query: a men's sheet does not fetch the women's
            // bouts and drop them while writing.
            ->when($this->competition !== null, fn ($q) => $q->whereHas(
                'ageCategory',
                fn ($division) => $division->where('gender', $this->competition)
            ))
            ->with(['athleteA', 'athleteB', 'winner', 'weightCategory.ageCategory', 'court'])
            ->orderBy('fight_number')
            ->get();

        // Phase depends on how many rounds that class has, which differs
        // between weight classes in the same championship.
        $roundsByCategory = $this->championship->bouts()
            ->selectRaw('weight_category_id, MAX(round) AS total')
            ->groupBy('weight_category_id')
            ->pluck('total', 'weight_category_id');

        $rows = [];

        foreach ($bouts as $bout) {
            $totalRounds = (int) ($roundsByCategory[$bout->weight_category_id] ?? $bout->round);

            $rows[] = [
                $bout->fight_number,
                // The screen sets the division under the class; a sheet has one
                // line for it, so they run together.
                $bout->weightCategory->ageCategory->name.' '.$bout->weightCategory->label.' kg',
                $bout->phase($totalRounds),
                // Read through the foreign keys: a corner is empty until the
                // bout feeding it is decided, so these are genuinely optional.
                $this->competitor($bout->athlete_a_id === null ? null : $bout->athleteA, $bout->is_bye),
                $this->competitor($bout->athlete_b_id === null ? null : $bout->athleteB, $bout->is_bye),
                $bout->court?->label(),
                $this->competitor($bout->winner_athlete_id === null ? null : $bout->winner, false),
            ];
        }

        return $rows;
    }

    /** "Rustam Kamolov (UZB)", the way the screen sets a corner. */
    private function competitor(?Athlete $athlete, bool $isBye): string
    {
        if ($athlete === null) {
            return $isBye ? 'BYE' : '—';
        }

        $noc = Noc::normalise($athlete->noc_code);

        return $noc === null ? $athlete->fullname : $athlete->fullname.' ('.$noc.')';
    }
}
