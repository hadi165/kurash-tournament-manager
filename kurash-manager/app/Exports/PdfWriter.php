<?php

namespace App\Exports;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class PdfWriter
{
    /**
     * Rendered from one template for every report, so a start list and a medal
     * standing come off the printer looking like the same competition produced
     * them.
     */
    public function download(Report $report, string $orientation = 'portrait'): Response
    {
        return Pdf::loadView('exports.table', [
            'title' => $report->title(),
            'meta' => $report->meta(),
            'headings' => $report->headings(),
            'rows' => $report->rows(),
        ])
            ->setPaper('a4', $orientation)
            ->download($report->filename().'.pdf');
    }
}
