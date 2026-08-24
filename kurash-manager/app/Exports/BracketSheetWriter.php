<?php

namespace App\Exports;

use App\Support\PrintLogo;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The draw sheet, on paper and on a worksheet.
 *
 * Both render the same BracketSheet, so the two files agree on every seat and
 * every fight number.
 *
 * ── Why the worksheet flies no flags ──────────────────────────────────────
 *
 * The printed sheet does, and so do both screens. A spreadsheet cannot: OOXML
 * has no way to put an image *in* a cell. A picture floats over the grid,
 * anchored in absolute units, and every reader decides for itself how tall a
 * row of nine points actually is.
 *
 * Four anchorings were tried against LibreOffice. Anchored to one corner the
 * error accumulates — four pixels out after eight seats, half a seat by the
 * foot of a bracket of thirty-two. Anchored to two corners with offsets
 * measured back from the far cell it writes a negative EMU and the image is
 * dropped; measured forward it renders half a row high, sitting above the name
 * it belongs to and over the heading at the top of the sheet.
 *
 * So the worksheet carries the normalised NOC code beside the name, which is
 * what a spreadsheet is for — it can be sorted and filtered on, and a flag
 * cannot. Nothing is drawn over a name or over a connector, and the tree keeps
 * its borders, its merges and its print scaling. The tree maps onto a spreadsheet the same way it maps
 * onto a layout table: one row per seat, one column per round, and a match
 * written into a merged cell spanning its rows — which is what puts a fight
 * number in every square.
 */
class BracketSheetWriter
{
    /*
     |-------------------------------------------------------------------------
     | Paper
     |-------------------------------------------------------------------------
     |
     | The sheet grows with the tree rather than the tree shrinking to fit A4.
     | A draw sheet is read at a table by officials with a pen, and a bracket of
     | thirty-two squeezed onto A4 is a sheet nobody can write on.
     |
     | The tree is one page at every size. It has to be: a bracket split across
     | a fold is two half-trees, and the connectors between them are the ones
     | that matter. So the rows are sized to what the chosen page leaves, and
     | the page is chosen to keep the rows legible.
     |
     | Beyond thirty-two the paper is large-format — A2 for sixty-four, A1 for
     | the hundred-and-twenty-eight BracketSeeding allows. Those are plotter
     | sizes, and that is the deliberate answer: a field that large is drawn on
     | a wall, not on a desk. Nothing in the federation's schedule reaches them.
     |
     | Landscape short side, in points.
     */
    private const PAPER = [
        8 => ['a4', 595.28],
        32 => ['a3', 841.89],
        64 => ['a2', 1190.55],
        128 => ['a1', 1683.78],
    ];

    /**
     * The furniture the tree has to fit underneath: head, rule, headings, and
     * the foot — which is the key and the podium side by side, and taller than
     * the key alone was. The tree gives the room up; the page does not grow.
     */
    private const CHROME = 275;

    /** Points to CSS pixels, which is what Dompdf lays out in. */
    private const PX_PER_PT = 96 / 72;

    public function pdf(BracketSheet $sheet): Response
    {
        [$paper] = $this->paper($sheet->size());

        return Pdf::loadView('exports.bracket', [
            'sheet' => $sheet,
            'scale' => $this->scale($sheet),
        ])
            ->setPaper($paper, 'landscape')
            ->download($sheet->filename().'.pdf');
    }

    /**
     * The page this bracket is drawn on.
     *
     * @return array{0:string, 1:float}
     */
    public function paper(int $size): array
    {
        foreach (self::PAPER as $seats => $paper) {
            if ($size <= $seats) {
                return $paper;
            }
        }

        return self::PAPER[128];
    }

    /**
     * Row height, type sizes and column widths, all off one number.
     *
     * The row is what the page leaves divided by the rows the tree needs, and
     * everything printed inside a row is a fraction of it — so a bracket of
     * four and a bracket of thirty-two are the same drawing at two sizes rather
     * than two drawings.
     *
     * @return array<string, float|int>
     */
    public function scale(BracketSheet $sheet): array
    {
        [, $heightPt] = $this->paper($sheet->size());

        $margin = 22;
        $available = $heightPt * self::PX_PER_PT - self::CHROME - 2 * $margin;
        $halfRows = max(1, $sheet->halfRows());

        // Floored so the names stay readable and capped so a two-athlete draw
        // does not print two rows a hand tall.
        $halfRow = min(34.0, max(6.0, floor($available / $halfRows)));

        $rounds = max(1, $sheet->rounds());

        return [
            'margin' => $margin,
            'halfRow' => $halfRow,
            'logo' => 62,
            'name' => $this->between($halfRow * 0.42, 6, 10),
            'noc' => $this->between($halfRow * 0.34, 5.5, 8.5),
            'head' => $this->between($halfRow * 0.3, 6, 8),
            'badge' => $this->between($halfRow * 0.6, 12, 18),
            'flag' => $this->between($halfRow * 0.45, 8, 14),
            'fight' => $this->between($halfRow * 1.4, 26, 44),
            // Off the paper rather than off the row: the podium holds four
            // names whatever size the tree above it is.
            'medals' => $this->between($heightPt * self::PX_PER_PT * 0.3, 200, 420),
            // Percentages, so the tree keeps its proportions on any paper.
            'seatColumn' => 26,
            'championColumn' => 14,
            'roundColumn' => round(60 / $rounds, 3),
        ];
    }

    private function between(float $value, float $low, float $high): float
    {
        return round(min($high, max($low, $value)), 2);
    }

    public function xlsx(BracketSheet $sheet): StreamedResponse
    {
        $book = new Spreadsheet;
        $page = $book->getActiveSheet();
        $page->setTitle('Draw sheet');

        $meta = $sheet->meta();
        $seats = $sheet->seats();
        $rounds = $sheet->rounds();

        // Header block: the identity on the left, the logo over the top-right
        // cells, exactly as the printed sheet files it.
        $page->setCellValue('A1', config('branding.organisation'));
        $page->getStyle('A1')->getFont()->setBold(true)->setSize(12)->getColor()->setRGB('019A44');

        $page->setCellValue('A2', $meta['Competition'] ?? '');
        $page->getStyle('A2')->getFont()->setBold(true)->setSize(16);

        $page->setCellValue('A3', implode('  ·  ', array_filter([
            $meta['Gender / Weight Category'] ?? null,
            $meta['Bracket'] ?? null,
            ($sheet->category->draw_athlete_count ?? count($seats)).' athletes',
            ($sheet->category->draw_bye_count ?? 0).' byes',
            $meta['Venue'] ?? null,
            $meta['Date'] ?? null,
        ])));
        $page->getStyle('A3')->getFont()->getColor()->setRGB('5D6D67');

        $logo = PrintLogo::path();

        if ($logo !== null) {
            $drawing = new Drawing;
            $drawing->setPath($logo);
            $drawing->setHeight(56);
            $drawing->setCoordinates($this->column($rounds + 2).'1');
            $drawing->setWorksheet($page);
        }

        // Column headings, one per round, from the same phase names the PDF
        // prints.
        $headingRow = 5;
        $page->setCellValue('A'.$headingRow, 'Draw');

        for ($round = 1; $round <= $rounds; $round++) {
            $page->setCellValue($this->column($round + 1).$headingRow, $sheet->phase($round));
        }

        $page->setCellValue($this->column($rounds + 2).$headingRow, 'Champion');

        $page->getStyle('A'.$headingRow.':'.$this->column($rounds + 2).$headingRow)
            ->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $page->getStyle('A'.$headingRow.':'.$this->column($rounds + 2).$headingRow)
            ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('019A44');

        $first = $headingRow + 1;

        /*
         | Ruled in half-seats, exactly as the printed sheet is.
         |
         | A worksheet draws lines on cell edges and nothing else, so the same
         | trick applies: two rows per athlete puts an edge on every centre
         | line, and a branch of the tree becomes one merged cell bordered top,
         | right and bottom. The two files are then the same drawing, and the
         | geometry is BracketSheet's in both.
         */
        foreach ($seats as $index => $seat) {
            $row = $first + $index * 2;
            $foot = $row + 1;

            $page->mergeCells('A'.$row.':A'.$foot);
            $page->mergeCells('B'.$row.':B'.$foot);

            $page->setCellValue('A'.$row, $seat['seed']);

            // The code beside the name rather than a flag beside it — see the
            // note on flags at the head of this class.
            $page->setCellValue('B'.$row, trim($seat['name'].' '.$seat['noc']));

            $page->getStyle('B'.$row.':B'.$foot)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

            if ($seat['bye']) {
                $page->getStyle('B'.$row.':B'.$foot)->getFont()->getColor()->setRGB('7D8B85');
            }

            // The seat's own rule, which is the line the tree leaves by.
            $page->getStyle('B'.$foot)->getBorders()->getBottom()
                ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('B9C4BF');
        }

        /*
         | The tree itself.
         |
         | A branch is three cells stacked rather than one box — see
         | BracketSheet's parts() — so the walk is by column, and each cell
         | says which of its own edges carry a line. The three together are
         | the same three-sided figure the printed sheet draws, and the two
         | files still cannot disagree about it.
         */
        for ($round = 1; $round <= $rounds; $round++) {
            foreach ($sheet->column($round) as $cell) {
                if ($cell['kind'] === 'blank') {
                    continue;
                }

                $column = $this->column($round + 1);
                $top = $first + $cell['row'];
                $range = $column.$top.':'.$column.($top + $cell['span'] - 1);

                // One row is one cell already; merging it would be a merge of
                // one, which some readers write out and others reject.
                if ($cell['span'] > 1) {
                    $page->mergeCells($range);
                }

                // The code beside the name rather than a flag beside it — see
                // the note on flags at the head of this class.
                $page->setCellValue($column.$top, $cell['kind'] === 'fight'
                    ? $cell['text']
                    : trim($cell['text'].' '.$cell['noc']));

                $style = $page->getStyle($range);
                $style->getFont()->setBold(true);

                $style->getAlignment()
                    ->setHorizontal($cell['kind'] === 'fight'
                        ? Alignment::HORIZONTAL_CENTER
                        : Alignment::HORIZONTAL_LEFT)
                    ->setVertical($cell['align'] === 'top'
                        ? Alignment::VERTICAL_TOP
                        : Alignment::VERTICAL_BOTTOM);

                // The number sits in a single half-row, and a half-row is nine
                // points: at the book's default size the reader clips it.
                if ($cell['kind'] === 'fight') {
                    $style->getFont()->setSize(7);
                }

                $colour = $cell['final'] ? '019A44' : '7D8B85';
                $borders = $style->getBorders();

                $edges = [$borders->getRight()];

                if ($cell['top']) {
                    $edges[] = $borders->getTop();
                }

                if ($cell['bottom']) {
                    $edges[] = $borders->getBottom();
                }

                foreach ($edges as $edge) {
                    $edge->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB($colour);
                }

                if ($cell['final']) {
                    $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EAF7EF');
                }
            }
        }

        // The champion, on the line the final leaves by rather than in a box
        // of its own beside the tree.
        $championColumn = $this->column($rounds + 2);
        $championRow = $sheet->championRow();

        if ($championRow > 0) {
            $range = $championColumn.$first.':'.$championColumn.($first + $championRow - 1);

            $page->mergeCells($range);
            $page->setCellValue($championColumn.$first, $sheet->champion() ?: 'Champion');

            $style = $page->getStyle($range);
            $style->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_BOTTOM);
            $style->getFont()->setBold(true);
            $style->getBorders()->getBottom()
                ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('019A44');
        }

        /*
         | The podium, under the last columns of the tree — the bottom right
         | corner of the sheet, the same corner the printed one files it in.
         |
         | Below the tree rather than beside it, because a block in the margin
         | of a spreadsheet is a block that a sort or a filter walks over.
         */
        $podium = $sheet->podium();

        if ($podium !== []) {
            $placeColumn = $this->column($rounds + 1);
            $head = $first + $sheet->halfRows() + 1;

            $page->setCellValue($placeColumn.$head, 'Medals');
            $page->mergeCells($placeColumn.$head.':'.$championColumn.$head);

            $heading = $page->getStyle($placeColumn.$head.':'.$championColumn.$head);
            $heading->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $heading->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('019A44');

            // Gold, silver, bronze — the place is the medal, so the cell is
            // coloured rather than captioned.
            $medals = [1 => 'C9A227', 2 => '9AA5AB', 3 => 'A9713F'];

            foreach ($podium as $index => $place) {
                $line = $head + 1 + $index;

                $page->setCellValue($placeColumn.$line, $place['place']);
                $page->getStyle($placeColumn.$line)
                    ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $page->getStyle($placeColumn.$line)
                    ->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $page->getStyle($placeColumn.$line)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB($medals[$place['place']] ?? '9AA5AB');

                // Name and code in one cell, as the seats are written — see
                // the note on flags at the head of this class.
                $page->setCellValue(
                    $championColumn.$line,
                    $place['name'] === '' ? 'not yet decided' : trim($place['name'].' '.$place['noc'])
                );

                $name = $page->getStyle($championColumn.$line);
                $name->getFont()->setBold($place['name'] !== '');

                if ($place['name'] === '') {
                    $name->getFont()->getColor()->setRGB('9FADA7');
                }
            }

            $page->getStyle($placeColumn.$head.':'.$championColumn.($head + count($podium)))
                ->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D6DED9');
        }

        // Half the height of a line of text, so a seat still reads as one row
        // even though the sheet is ruled in halves.
        foreach (range($first, $first + $sheet->halfRows() - 1) as $row) {
            $page->getRowDimension($row)->setRowHeight(9);
        }

        // Explicit widths: the tree keeps its proportions rather than
        // collapsing to whatever the longest name happens to be.
        $page->getColumnDimension('A')->setWidth(6);
        $page->getColumnDimension('B')->setWidth(34);

        for ($round = 1; $round <= $rounds; $round++) {
            $page->getColumnDimension($this->column($round + 1))->setWidth(14);
        }

        $page->getColumnDimension($championColumn)->setWidth(22);
        $page->freezePane('A'.$first);

        // Printed, a bracket is one sheet or it is two half-trees: the
        // connectors between the halves are exactly the ones that carry the
        // meaning. Fit to the width and let the length run.
        $page->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setFitToWidth(1)
            ->setFitToHeight(0);

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
        }, $sheet->filename().'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** Column 1 is the seed, 2 the name, then a column per round. */
    private function column(int $index): string
    {
        // The name column sits between the seed and the first round, so every
        // round is one further right than its index suggests.
        return Coordinate::stringFromColumnIndex($index + 1);
    }
}
