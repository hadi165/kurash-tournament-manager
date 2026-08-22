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
        $meta = $report->meta();

        return Pdf::loadView('exports.table', [
            'title' => $report->title(),
            'meta' => $meta,
            'headings' => $report->headings(),
            'rows' => $report->rows(),
            'documentTag' => DocumentReference::tag($report),
            'documentReference' => DocumentReference::reference($report),
            // Only the reports that end in a meaningful sum carry a total row;
            // a running order has nothing to add up.
            'total' => $report instanceof HasTotal ? $report->total() : null,
            // The foot of every page says which competition the sheet belongs
            // to, so a page separated from its stack can still be filed.
            'footerLine' => collect([$meta['Competition'] ?? null, $meta['Venue'] ?? null])
                ->filter()
                ->implode(' · '),
        ])
            ->setPaper('a4', $orientation)
            ->download($report->filename().'.pdf');
    }
}
