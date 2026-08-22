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
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The draw sheet, on paper and on a worksheet.
 *
 * Both render the same BracketSheet, so the two files agree on every seat and
 * every fight number. The tree maps onto a spreadsheet the same way it maps
 * onto a layout table: one row per seat, one column per round, and a match
 * written into a merged cell spanning its rows — which is what puts a fight
 * number in every square.
 */
class BracketSheetWriter
{
    /** A4 for a bracket of eight, A3 once the tree needs the width. */
    public function pdf(BracketSheet $sheet): Response
    {
        return Pdf::loadView('exports.bracket', ['sheet' => $sheet])
            ->setPaper($sheet->size() > 8 ? 'a3' : 'a4', 'landscape')
            ->download($sheet->filename().'.pdf');
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

        foreach ($seats as $index => $seat) {
            $row = $first + $index;

            $page->setCellValue('A'.$row, $seat['seed']);
            $page->setCellValue('B'.$row, trim($seat['name'].' '.$seat['noc']));

            // The seed cell carries the corner, white on blue or green, which
            // is the same rule the mat screens read.
            $page->getStyle('A'.$row)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB($seat['corner'] === 'blue' ? '1A9FD8' : '019A44');

            $page->getStyle('A'.$row)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $page->getStyle('A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            $page->getStyle('B'.$row)->getBorders()->getBottom()
                ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('B9C4BF');
        }

        // The tree: a merged cell per match, spanning the rows it feeds from,
        // carrying its fight number.
        for ($round = 1; $round <= $rounds; $round++) {
            $column = $this->column($round + 1);

            foreach ($sheet->matches($round) as $match) {
                $top = $first + $match['row'];
                $bottom = $top + $match['span'] - 1;

                $page->mergeCells($column.$top.':'.$column.$bottom);
                $page->setCellValue($column.$top, $match['fight']);

                $style = $page->getStyle($column.$top.':'.$column.$bottom);
                $style->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                    ->setVertical(Alignment::VERTICAL_CENTER);
                $style->getFont()->setBold(true);
                $style->getBorders()->getLeft()
                    ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('B9C4BF');
                $style->getBorders()->getBottom()
                    ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('B9C4BF');

                if ($round === $rounds) {
                    $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EAF7EF');
                }
            }
        }

        $championColumn = $this->column($rounds + 2);
        $last = $first + count($seats) - 1;

        if (count($seats) > 0) {
            $page->mergeCells($championColumn.$first.':'.$championColumn.$last);
            $page->setCellValue($championColumn.$first, $sheet->champion() ?: 'Champion');
            $page->getStyle($championColumn.$first)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER);
            $page->getStyle($championColumn.$first)->getFont()->setBold(true);
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
