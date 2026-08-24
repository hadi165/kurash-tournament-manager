<?php

namespace App\Exports;

use App\Models\Athlete;
use App\Models\Bout;
use App\Models\WeightCategory;
use App\Support\BracketSeeding;
use App\Support\Noc;

/**
 * The draw as a tree, ready to be drawn on paper or on a worksheet.
 *
 * Both writers read this one description, so the PDF and the spreadsheet
 * cannot disagree about who is seeded where or which square carries which
 * fight number. The seeding is BracketSeeding's — this reads the order, it
 * does not reimplement it.
 */
final class BracketSheet
{
    /**
     * @param  bool  $fightNumbers  Whether the squares carry the number the
     *                              running order gave each contest. A bracket
     *                              saved at the end of a draw ceremony does not:
     *                              the draw is settled at that point and the
     *                              running order is not, so printing numbers
     *                              there would put figures on a sheet that the
     *                              schedule has not agreed to yet.
     */
    public function __construct(
        public readonly WeightCategory $category,
        public readonly bool $fightNumbers = true,
    ) {}

    /** The bracket the draw was built for, not what today's list would give. */
    public function size(): int
    {
        return (int) ($this->category->draw_bucket_size
            ?: BracketSeeding::size($this->category->drawnAthletes()->count()));
    }

    public function rounds(): int
    {
        return $this->size() >= 2 ? BracketSeeding::totalRounds($this->size()) : 0;
    }

    /**
     * One seat per bracket position, top to bottom, in seeding order.
     *
     * The upper seat of every pair is the blue corner and the lower the green,
     * which is the same rule the mat screens read.
     *
     * @return list<array{seed:int, name:string, noc:string, corner:string, bye:bool}>
     */
    public function seats(): array
    {
        $drawn = $this->category->drawnAthletes()->get()->keyBy('draw_number');

        $seats = [];

        foreach (BracketSeeding::order($this->size()) as $row => $seed) {
            /** @var Athlete|null $athlete */
            $athlete = $drawn->get($seed);

            $seats[] = [
                'seed' => $seed,
                'name' => $athlete instanceof Athlete ? $athlete->fullname : 'BYE',
                'noc' => $athlete instanceof Athlete ? (string) Noc::normalise($athlete->noc_code) : '',
                'corner' => $row % 2 === 0 ? 'blue' : 'green',
                'bye' => ! $athlete instanceof Athlete,
            ];
        }

        return $seats;
    }

    /**
     * The match squares of one round, each with the rows it spans.
     *
     * @return list<array{row:int, span:int, fight:string}>
     */
    public function matches(int $round): array
    {
        $span = 2 ** $round;
        $bouts = $this->category->bouts()
            ->where('round', $round)
            ->orderBy('position_in_round')
            ->get()
            ->keyBy('position_in_round');

        $matches = [];

        for ($position = 0; $position < intdiv($this->size(), $span); $position++) {
            /** @var Bout|null $bout */
            $bout = $bouts->get($position);

            $matches[] = [
                'row' => $position * $span,
                'span' => $span,
                // The number the running order gave this contest. A bye has no
                // contest to number, an undrawn order has none yet, and a
                // bracket asked for without them shows none at all.
                'fight' => $this->fightNumbers && $bout?->fight_number
                    ? 'No. '.$bout->fight_number
                    : '',
            ];
        }

        return $matches;
    }

    public function phase(int $round): string
    {
        return BracketSeeding::phaseName(intdiv($this->size(), 2 ** ($round - 1)));
    }

    public function champion(): string
    {
        $final = $this->category->bouts()
            ->whereNull('next_bout_id')
            ->with('winner')
            ->first();

        return $final?->winner->fullname ?? '';
    }

    public function filename(): string
    {
        return 'Bracket-'.$this->category->exportName();
    }

    /** @return array<string, string> */
    public function meta(): array
    {
        $championship = $this->category->ageCategory->championship;

        return array_filter([
            'Competition' => $championship->title,
            'Category' => $this->category->ageCategory->name,
            'Gender / Weight Category' => $this->category->exportName(),
            'Bracket' => 'Bracket of '.$this->size(),
            'Venue' => $championship->location,
            'Date' => $championship->starts_on?->format('j M Y'),
        ]);
    }
}
