<?php

namespace App\Exports;

use App\Models\Bout;
use App\Models\Championship;
use App\Support\Noc;

/**
 * The running order for a whole championship — specification §8.1.
 *
 * One row per competitor rather than per bout, because the required columns
 * include Color: the federation's sheets list the blue corner and the green
 * corner as separate lines sharing a fight number, and the table officials read
 * down that column.
 */
class FightOrderReport implements Report
{
    /**
     * @param  string|null  $gender  'M' or 'F' to print one competition's order
     *                               on its own. The men's and women's classes
     *                               run as separate competitions, and a table
     *                               official working one of them should not have
     *                               to read past the other.
     */
    public function __construct(
        private readonly Championship $championship,
        private readonly ?string $gender = null,
    ) {}

    public function title(): string
    {
        return $this->gender === null
            ? 'Fight Order'
            : 'Fight Order — '.$this->competition();
    }

    public function filename(): string
    {
        $name = 'Fight-Order-'.str($this->championship->title)->slug();

        return $this->gender === null ? $name : $name.'-'.str($this->competition())->slug();
    }

    public function meta(): array
    {
        return array_filter([
            'Competition' => $this->championship->title,
            'Location' => $this->championship->location ?? '—',
            // Stated on the sheet, not only in the filename: a printout that
            // leaves the room has to say which competition it is.
            'Classes' => $this->gender === null ? 'Men and women' : $this->competition(),
        ]);
    }

    /** "Men" or "Women", the way the sheet names the competition. */
    private function competition(): string
    {
        return match ($this->gender) {
            'M' => 'Men',
            'F' => 'Women',
            default => 'Men and women',
        };
    }

    public function headings(): array
    {
        return ['Fight No.', 'Gender / Weight Category', 'Phase', 'Color', 'Athlete', 'NOC', 'Mat', 'Winner'];
    }

    public function rows(): array
    {
        $bouts = $this->championship->bouts()
            ->whereNotNull('fight_number')
            // Scoped in the query: a men's sheet does not fetch the women's
            // bouts and drop them while writing.
            ->when($this->gender !== null, fn ($q) => $q->whereHas(
                'weightCategory',
                fn ($category) => $category->where('gender', $this->gender)
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

            // Read through the foreign keys: a corner is empty until the bout
            // feeding it is decided, so these relations are genuinely optional.
            $corners = [
                ['Blue', $bout->athlete_a_id === null ? null : $bout->athleteA],
                ['Green', $bout->athlete_b_id === null ? null : $bout->athleteB],
            ];

            foreach ($corners as [$colour, $athlete]) {
                $rows[] = [
                    $bout->fight_number,
                    $bout->weightCategory->exportName(),
                    $bout->phase($totalRounds),
                    $colour,
                    $athlete !== null ? $athlete->fullname : ($bout->is_bye ? 'BYE' : '—'),
                    Noc::normalise($athlete?->noc_code),
                    $bout->court?->label(),
                    // Marked on the winner's own line, so scanning the Winner
                    // column down the page reads as a list of results.
                    $athlete !== null && $bout->winner_athlete_id === $athlete->id ? 'WIN' : '',
                ];
            }
        }

        return $rows;
    }
}
