<?php

namespace App\Exports;

use App\Models\Athlete;
use App\Models\WeightCategory;
use App\Services\MedalTable;
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
     * `entrants` are the two who meet here: the winners of the pair of
     * contests that feed it. They belong to this branch and not to the one
     * they came from, because the line they arrive on is this branch's own
     * edge — which is where a hand writes them on a printed sheet. Round one
     * is fed by the draw rather than by a contest, and its entrants are the
     * seats, already named in the column on the left.
     *
     * @return list<array{round:int, position:int, row:int, span:int, centre:int, fight:string, winner:string, winnerNoc:string, entrants:array{0:array{name:string, noc:string}, 1:array{name:string, noc:string}}, final:bool}>
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
                    'entrants' => [
                        $this->entrant($contests, $round - 1, $position * 2),
                        $this->entrant($contests, $round - 1, $position * 2 + 1),
                    ],
                    'final' => $round === $rounds,
                ];
            }
        }

        return $branches;
    }

    /**
     * Whoever won a feeding contest, as the branch above it sees them.
     *
     * @param  array<int, array<int, array{fight:string, winner:string, winnerNoc:string}>>  $contests
     * @return array{name:string, noc:string}
     */
    private function entrant(array $contests, int $round, int $position): array
    {
        return [
            'name' => $contests[$round][$position]['winner'] ?? '',
            'noc' => $contests[$round][$position]['winnerNoc'] ?? '',
        ];
    }

    /**
     * The cells one branch is drawn in, top to bottom.
     *
     * A branch is three things stacked, not one box with a name in the middle:
     *
     *     ── entrant ──┐   the top line, and whoever arrived on it
     *                  │
     *          No. 12  ┤   the number, on the line the branch leaves by
     *                  │
     *     ── entrant ──┘   the bottom line, and whoever arrived on that
     *
     * Splitting it is what puts each name on its own arriving line instead of
     * both of them somewhere in between. The borders survive the split by
     * construction: the first cell carries the line from above, the last the
     * line from below, and every one of them carries the vertical, so the
     * three together are the same three-sided figure as before.
     *
     * Round one has no entrants to write — it is fed by the draw — so its
     * upper cell falls away and the number takes the top line. That is
     * arithmetic, not a case: `$half` is one there and nothing above fits.
     *
     * @param  array{round:int, position:int, row:int, span:int, centre:int, fight:string, winner:string, winnerNoc:string, entrants:array{0:array{name:string, noc:string}, 1:array{name:string, noc:string}}, final:bool}  $branch
     * @return list<array{row:int, span:int, kind:string, text:string, noc:string, align:string, top:bool, bottom:bool, final:bool}>
     */
    private function parts(array $branch): array
    {
        $half = intdiv($branch['span'], 2);
        $parts = [];

        if ($half > 1) {
            $parts[] = [
                'row' => $branch['row'],
                'span' => $half - 1,
                'kind' => 'entrant',
                'text' => $branch['entrants'][0]['name'],
                'noc' => $branch['entrants'][0]['noc'],
                'align' => 'top',
            ];
        }

        $parts[] = [
            'row' => $branch['centre'] - 1,
            'span' => 1,
            'kind' => 'fight',
            'text' => $branch['fight'],
            'noc' => '',
            'align' => 'bottom',
        ];

        $parts[] = [
            'row' => $branch['centre'],
            'span' => $half,
            'kind' => 'entrant',
            'text' => $branch['entrants'][1]['name'],
            'noc' => $branch['entrants'][1]['noc'],
            'align' => 'bottom',
        ];

        $last = count($parts) - 1;

        foreach ($parts as $index => $part) {
            $parts[$index] += [
                'top' => $index === 0,
                'bottom' => $index === $last,
                'final' => $branch['final'],
            ];
        }

        return $parts;
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
     * a cell, or the column below them shifts. This returns the branch's own
     * cells and the blank ones between, in order, so a renderer walks a column
     * rather than working out where the gaps are.
     *
     * A branch is more than one cell — see parts() — so a `kind` says what
     * each is for and three flags say which of its edges carry a line. Both
     * writers read this, and neither works the borders out for itself.
     *
     * @return list<array{row:int, span:int, kind:string, text:string, noc:string, align:string, top:bool, bottom:bool, final:bool}>
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
                $cells[] = $this->blank($at, $branch['row'] - $at);
            }

            foreach ($this->parts($branch) as $part) {
                $cells[] = $part;
            }

            $at = $branch['row'] + $branch['span'];
        }

        if ($at < $this->halfRows()) {
            $cells[] = $this->blank($at, $this->halfRows() - $at);
        }

        return $cells;
    }

    /**
     * A cell holding nothing, so the column below it does not shift up.
     *
     * @return array{row:int, span:int, kind:string, text:string, noc:string, align:string, top:bool, bottom:bool, final:bool}
     */
    private function blank(int $row, int $span): array
    {
        return [
            'row' => $row,
            'span' => $span,
            'kind' => 'blank',
            'text' => '',
            'noc' => '',
            'align' => 'top',
            'top' => false,
            'bottom' => false,
            'final' => false,
        ];
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

    /**
     * The podium, as it is read out and as it is filed.
     *
     * Gold, silver and the two bronzes — derived by MedalTable, not worked out
     * again here, because a sheet that disagrees with the medal standings
     * about who came third is worse than a sheet with no podium on it.
     *
     * The rows exist before the results do. A class that has not been fought
     * prints the places with the names left blank, which is what an official
     * with a pen wants at the table; the sheet is the same drawing either way.
     *
     * @return list<array{place:int, name:string, noc:string}>
     */
    public function podium(): array
    {
        $rounds = $this->rounds();

        if ($rounds < 1) {
            return [];
        }

        $podium = app(MedalTable::class)->forCategory($this->category);

        $rows = [
            $this->place(1, $podium['gold']),
            $this->place(2, $podium['silver']),
        ];

        // Two bronzes wherever there is a semi-final round to lose in. A
        // bracket of two has none, and no third place to award.
        if ($rounds >= 2) {
            $rows[] = $this->place(3, $podium['bronze'][0] ?? null);
            $rows[] = $this->place(3, $podium['bronze'][1] ?? null);
        }

        return $rows;
    }

    /** @return array{place:int, name:string, noc:string} */
    private function place(int $place, ?Athlete $athlete): array
    {
        return [
            'place' => $place,
            'name' => (string) $athlete?->fullname,
            'noc' => (string) Noc::normalise($athlete?->noc_code),
        ];
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
