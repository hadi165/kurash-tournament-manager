<?php

namespace App\Exports;

use App\Models\Athlete;
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

    /*
     |-------------------------------------------------------------------------
     | The tree, in half-rows
     |-------------------------------------------------------------------------
     |
     | A connector has to start and finish on the *centre* of the node it joins,
     | and a table can only draw a border on a cell edge. So the sheet is ruled
     | in half-seats: every seat is two rows tall, which puts a cell edge
     | exactly on each seat's centre line, and every branch of the tree can then
     | be one cell with borders on three of its sides.
     |
     |     seat 0   ──┐            A branch is drawn top, right and bottom:
     |                │            the two horizontals arrive from the pair it
     |                ├── out      joins, the vertical carries between them, and
     |                │            the round to its right draws the horizontal
     |     seat 1   ──┘            leaving its centre. Nothing is positioned;
     |                             every line is a cell edge.
     |
     | Both writers read this, so the printed sheet and the worksheet cannot
     | disagree about where a line goes — the same reason BracketSeeding is the
     | only opinion about where a seed sits.
     */

    /** Rows in the ruled sheet: two per seat, so a centre line is a cell edge. */
    public function halfRows(): int
    {
        return $this->size() * 2;
    }

    /**
     * Every branch of the tree, round by round.
     *
     * `row` and `span` are half-rows. `centre` is the half-row the branch's
     * outgoing line sits above — the round to the right draws that line as its
     * own border, which is what makes the join continuous rather than two
     * segments that happen to meet.
     *
     * @return list<array{round:int, position:int, row:int, span:int, centre:int, fight:string, winner:string, winnerNoc:string, final:bool}>
     */
    public function branches(): array
    {
        $rounds = $this->rounds();

        if ($rounds < 1) {
            return [];
        }

        $contests = $this->contestsByRound();
        $branches = [];

        for ($round = 1; $round <= $rounds; $round++) {
            // A branch spans half of the block it sits in, centred: the block
            // is every seat the match draws from, and the half it does not
            // cover is the distance from a seat's top to its centre at each
            // end.
            $block = 2 ** ($round + 1);
            $span = 2 ** $round;
            $offset = 2 ** ($round - 1);

            for ($position = 0; $position < intdiv($this->halfRows(), $block); $position++) {
                $row = $position * $block + $offset;

                $branches[] = [
                    'round' => $round,
                    'position' => $position,
                    'row' => $row,
                    'span' => $span,
                    'centre' => $row + intdiv($span, 2),
                    'fight' => $contests[$round][$position]['fight'] ?? '',
                    // Written on the line the branch leaves by, which is where
                    // a hand would write it on a printed sheet.
                    'winner' => $contests[$round][$position]['winner'] ?? '',
                    // Its own field rather than parsed back out of the name:
                    // the nation is a fact about the athlete, and a renderer
                    // asking for a flag should not have to read one.
                    'winnerNoc' => $contests[$round][$position]['winnerNoc'] ?? '',
                    'final' => $round === $rounds,
                ];
            }
        }

        return $branches;
    }

    /**
     * One round's matches, in whole seats.
     *
     * @deprecated Superseded by branches(), which describes the tree in
     *             half-seats so a connector can start on a centre line. Kept
     *             because this was public API: `row` and `span` here are seat
     *             rows, which is what a caller written against the old sheet
     *             expects. Read from the same geometry, so the two cannot
     *             disagree.
     *
     * @return list<array{row:int, span:int, fight:string}>
     */
    public function matches(int $round): array
    {
        $span = 2 ** $round;

        $matches = [];

        foreach ($this->branches() as $branch) {
            if ($branch['round'] !== $round) {
                continue;
            }

            $matches[] = [
                'row' => $branch['position'] * $span,
                'span' => $span,
                'fight' => $branch['fight'],
            ];
        }

        return $matches;
    }

    /**
     * One round's column, top to bottom, with every half-row accounted for.
     *
     * A table cannot leave a hole: the rows a branch does not cover still need
     * a cell, or the column below them shifts. This returns both, in order, so
     * a renderer walks a column rather than working out where the gaps are.
     *
     * @return list<array{row:int, span:int, branch:array{round:int, position:int, row:int, span:int, centre:int, fight:string, winner:string, winnerNoc:string, final:bool}|null}>
     */
    public function column(int $round): array
    {
        $cells = [];
        $at = 0;

        foreach ($this->branches() as $branch) {
            if ($branch['round'] !== $round) {
                continue;
            }

            if ($branch['row'] > $at) {
                $cells[] = ['row' => $at, 'span' => $branch['row'] - $at, 'branch' => null];
            }

            $cells[] = ['row' => $branch['row'], 'span' => $branch['span'], 'branch' => $branch];
            $at = $branch['row'] + $branch['span'];
        }

        if ($at < $this->halfRows()) {
            $cells[] = ['row' => $at, 'span' => $this->halfRows() - $at, 'branch' => null];
        }

        return $cells;
    }

    /**
     * The half-row the champion's line sits above.
     *
     * The final's own centre: the tree does not stop at the final, and the line
     * the winner's name is written on is the same line the final leaves by.
     */
    public function championRow(): int
    {
        $rounds = $this->rounds();

        foreach ($this->branches() as $branch) {
            if ($branch['round'] === $rounds) {
                return $branch['centre'];
            }
        }

        return intdiv($this->halfRows(), 2);
    }

    /**
     * What is known about each contest, by round and position.
     *
     * Read in one query rather than one per round: a bracket of 128 is seven
     * rounds, and the column walk asks for them repeatedly.
     *
     * @return array<int, array<int, array{fight:string, winner:string, winnerNoc:string}>>
     */
    private function contestsByRound(): array
    {
        $contests = [];

        foreach ($this->category->bouts()->with('winner')->orderBy('position_in_round')->get() as $bout) {
            $contests[(int) $bout->round][(int) $bout->position_in_round] = [
                // A bye has no contest to number, an undrawn running order has
                // none yet, and a bracket asked for without them shows none.
                'fight' => $this->fightNumbers && $bout->fight_number
                    ? 'No. '.$bout->fight_number
                    : '',
                'winner' => (string) $bout->winner?->fullname,
                'winnerNoc' => (string) Noc::normalise($bout->winner?->noc_code),
            ];
        }

        return $contests;
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
