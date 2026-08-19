<?php

namespace App\Exports;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

/**
 * PDFs that are not tables.
 *
 * A certificate and an accreditation card are laid out, not tabulated, so they
 * cannot go through Report and the shared table template. This is deliberately
 * the only difference: they still render server-side, still come off the same
 * branding config, and are still generated on request rather than written to
 * disk where they could go stale.
 */
class PdfDocument
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function download(string $view, array $data, string $filename, string $orientation = 'portrait', string $paper = 'a4'): Response
    {
        return Pdf::loadView($view, $data + ['branding' => $this->branding()])
            ->setPaper($paper, $orientation)
            ->download($filename.'.pdf');
    }

    /**
     * Logo resolved to a filesystem path, because Dompdf reads from disk rather
     * than over HTTP, and PNG far more reliably than SVG.
     *
     * @return array{organisation:string, short_name:string, logo:string|null}
     */
    private function branding(): array
    {
        $logo = collect([config('branding.logo_print'), config('branding.logo')])
            ->filter()
            ->map(fn (string $path) => public_path($path))
            ->first(fn (string $path) => is_file($path));

        return [
            'organisation' => (string) config('branding.organisation'),
            'short_name' => (string) config('branding.short_name'),
            'logo' => $logo,
        ];
    }
}
