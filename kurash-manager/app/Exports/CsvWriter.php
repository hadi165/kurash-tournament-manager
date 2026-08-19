<?php

namespace App\Exports;

use Symfony\Component\HttpFoundation\StreamedResponse;

class CsvWriter
{
    /**
     * A UTF-8 byte order mark.
     *
     * Without it Excel reads a CSV as the system's legacy codepage, which turns
     * every Persian, Cyrillic and Turkish athlete name into mojibake. The names
     * in this system are mostly not ASCII, so this is not an edge case.
     */
    private const BOM = "\u{FEFF}";

    public function download(Report $report): StreamedResponse
    {
        $filename = $report->filename().'.csv';

        return response()->streamDownload(function () use ($report) {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            echo self::BOM;

            // Context first, matching the PDF, so a spreadsheet detached from
            // its filename still says which competition and class it belongs to.
            foreach ($report->meta() as $label => $value) {
                fputcsv($handle, [$label, $value], escape: '');
            }

            if ($report->meta() !== []) {
                fputcsv($handle, [], escape: '');
            }

            fputcsv($handle, $report->headings(), escape: '');

            foreach ($report->rows() as $row) {
                fputcsv($handle, $row, escape: '');
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
