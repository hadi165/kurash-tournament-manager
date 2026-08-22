<?php

namespace App\Exports;

use App\Support\PrintLogo;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The same reports, as a workbook.
 *
 * CSV is plain text: it cannot carry a logo, a heading style, a column width or
 * a frozen row, so a spreadsheet exported beside a branded PDF never looked
 * like it came from the same competition. This reads the same Report the PDF
 * writer does — no report class knows which one is rendering it — and XLSX is
 * Unicode natively, so the byte-order mark CSV needed is gone with it.
 */
class XlsxWriter
{
    public function download(Report $report): StreamedResponse
    {
        $book = new Spreadsheet;
        $page = $book->getActiveSheet();
        $page->setTitle(mb_substr($report->title(), 0, 31));

        $headings = $report->headings();
        $rows = $report->rows();
        $columns = max(1, count($headings));
        $lastColumn = Coordinate::stringFromColumnIndex($columns);

        $page->setCellValue('A1', config('branding.organisation'));
        $page->getStyle('A1')->getFont()->setBold(true)->setSize(13)->getColor()->setRGB('019A44');

        $page->setCellValue('A2', $report->title());
        $page->getStyle('A2')->getFont()->setBold(true)->setSize(16);

        $logo = PrintLogo::path();

        if ($logo !== null) {
            $drawing = new Drawing;
            $drawing->setPath($logo);
            $drawing->setHeight(44);
            $drawing->setCoordinates($lastColumn.'1');
            $drawing->setWorksheet($page);
        }

        // The meta pairs above the table, label and value, as on the PDF.
        $row = 4;

        foreach ($report->meta() as $label => $value) {
            $page->setCellValue('A'.$row, $label);
            $page->setCellValue('B'.$row, $value);
            $page->getStyle('A'.$row)->getFont()->setBold(true)->getColor()->setRGB('5D6D67');
            $row++;
        }

        $headingRow = $row + 1;

        foreach ($headings as $index => $heading) {
            $page->setCellValue(Coordinate::stringFromColumnIndex($index + 1).$headingRow, $heading);
        }

        $headingRange = 'A'.$headingRow.':'.$lastColumn.$headingRow;
        $page->getStyle($headingRange)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $page->getStyle($headingRange)->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('019A44');

        foreach ($rows as $offset => $line) {
            foreach ($line as $index => $value) {
                $page->setCellValueExplicit(
                    Coordinate::stringFromColumnIndex($index + 1).($headingRow + 1 + $offset),
                    (string) $value,
                    // Written as text or as a number depending on what it is,
                    // so an IKA id keeps its leading zeroes and a count still
                    // sums.
                    is_numeric($value) && ! is_string($value)
                        ? DataType::TYPE_NUMERIC
                        : DataType::TYPE_STRING,
                );
            }
        }

        $lastRow = $headingRow + count($rows);

        if ($rows !== []) {
            $page->getStyle('A'.($headingRow + 1).':'.$lastColumn.$lastRow)
                ->getBorders()->getBottom()
                ->setBorderStyle(Border::BORDER_HAIR)->getColor()->setRGB('E4EAE7');

            // Numeric columns read right, as they do on the printed sheet.
            foreach ($headings as $index => $heading) {
                $column = Coordinate::stringFromColumnIndex($index + 1);
                $values = array_filter(array_column($rows, $index), fn ($value) => $value !== null && $value !== '');

                if ($values !== [] && count(array_filter($values, 'is_numeric')) === count($values)) {
                    $page->getStyle($column.($headingRow + 1).':'.$column.$lastRow)
                        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                }
            }
        }

        for ($index = 1; $index <= $columns; $index++) {
            $page->getColumnDimension(Coordinate::stringFromColumnIndex($index))->setAutoSize(true);
        }

        // The headings stay put and can be filtered — which is most of why
        // somebody asked for a spreadsheet rather than a PDF.
        $page->freezePane('A'.($headingRow + 1));
        $page->setAutoFilter($headingRange.':'.$lastColumn.max($lastRow, $headingRow));

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
        }, $report->filename().'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
