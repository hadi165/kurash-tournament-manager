<?php

namespace App\Exports;

use App\Models\Athlete;
use App\Models\Bout;
use App\Models\WeightCategory;
use App\Support\BracketSeeding;
use App\Support\Noc;

/**
 * The drawn bracket for one class — specification §7.4.
 *
 * Every bout in the class, round by round, which is what gets pinned up beside
 * the mat. The winner column fills in as the day goes on, so the same sheet
 * serves as the draw before the session and the result after it.
 */
class DrawSheetReport implements Report
{
    public function __construct(private readonly WeightCategory $category) {}

    public function title(): string
    {
        return 'Draw Result';
    }

    public function filename(): string
    {
        // "Draw-Male -91", the pattern the specification fixes.
        return 'Draw-'.$this->category->exportName();
    }

    public function meta(): array
    {
        return [
            'Competition' => $this->category->ageCategory->championship->title,
            'Category' => $this->category->ageCategory->name,
            'Gender / Weight Category' => $this->category->exportName(),
        ];
    }

    public function headings(): array
    {
        return ['Phase', 'Fight No.', 'Blue', 'NOC', 'Green', 'NOC', 'Winner'];
    }

    public function rows(): array
    {
        $bouts = $this->category->bouts()
            ->with(['athleteA', 'athleteB', 'winner'])
            ->orderBy('round')
            ->orderBy('position_in_round')
            ->get();

        if ($bouts->isEmpty()) {
            return [];
        }

        $totalRounds = (int) $bouts->max('round');

        $rows = [];

        foreach ($bouts as $bout) {
            // Read through the foreign keys: a slot is empty until the bout
            // feeding it is decided, so these relations are genuinely optional
            // even though the accessor itself is typed as always present.
            $blue = $bout->athlete_a_id === null ? null : $bout->athleteA;
            $green = $bout->athlete_b_id === null ? null : $bout->athleteB;
            $winner = $bout->winner_athlete_id === null ? null : $bout->winner;

            $rows[] = [
                $bout->phase($totalRounds),
                $bout->fight_number,
                $this->slotName($blue, $bout),
                Noc::normalise($blue?->noc_code),
                $this->slotName($green, $bout),
                Noc::normalise($green?->noc_code),
                $winner?->fullname,
            ];
        }

        return $rows;
    }

    /**
     * An empty slot reads BYE where the bracket gave a walkover — the
     * specification requires it be labelled rather than left blank — and stays
     * blank where the bout feeding it simply has not been fought yet.
     */
    private function slotName(?Athlete $athlete, Bout $bout): string
    {
        if ($athlete !== null) {
            return $athlete->fullname;
        }

        return $bout->is_bye ? 'BYE' : '';
    }

    /** Bracket size the draw was built for, for the caller's heading. */
    public function bracketTitle(): string
    {
        return BracketSeeding::phaseName($this->category->numberedAthletes()->count());
    }
}
