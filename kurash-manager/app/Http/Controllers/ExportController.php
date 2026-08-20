<?php

namespace App\Http\Controllers;

use App\Exports\AccreditationCards;
use App\Exports\BracketSheet;
use App\Exports\BracketSheetWriter;
use App\Exports\CertificateSheet;
use App\Exports\ConfirmedWeighInReport;
use App\Exports\CsvWriter;
use App\Exports\DrawNumbersReport;
use App\Exports\DrawSheetReport;
use App\Exports\EntriesByNocReport;
use App\Exports\EntriesByWeightCategoryReport;
use App\Exports\FightOrderReport;
use App\Exports\MedalStandingReport;
use App\Exports\PdfDocument;
use App\Exports\PdfWriter;
use App\Exports\Report;
use App\Exports\ResultsReport;
use App\Models\AgeCategory;
use App\Models\Athlete;
use App\Models\Championship;
use App\Models\WeightCategory;
use App\Services\MedalTable;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every table the planning specification asks to be printable or downloadable.
 *
 * All of them are rendered from the database on request rather than written to
 * disk when something changes. The old system kept "Confirmed weight-in List/"
 * and "Draw Results/" folders that could disagree with the competition — here
 * a re-download is always current, and there is no stale file to hand someone.
 */
class ExportController extends Controller
{
    public function __construct(
        private readonly CsvWriter $csv,
        private readonly PdfWriter $pdf,
        private readonly PdfDocument $document,
        private readonly MedalTable $medals,
    ) {}

    /**
     * Certificates for every decided weight class, or just one.
     *
     * PDF only — a certificate is a laid-out document, and offering it as a
     * spreadsheet would be offering something nobody asked for.
     */
    public function certificates(Championship $championship): Response
    {
        $sheet = new CertificateSheet($championship, $this->medals);

        return $this->document->download('exports.certificates', $sheet->data(), $sheet->filename(), 'landscape');
    }

    public function categoryCertificates(WeightCategory $weightCategory): Response
    {
        $weightCategory->load('ageCategory.championship');

        $sheet = new CertificateSheet(
            $weightCategory->ageCategory->championship,
            $this->medals,
            $weightCategory,
        );

        return $this->document->download('exports.certificates', $sheet->data(), $sheet->filename(), 'landscape');
    }

    public function accreditation(Championship $championship): Response
    {
        $cards = AccreditationCards::forChampionship($championship);

        return $this->document->download('exports.accreditation', $cards->data(), $cards->filename());
    }

    public function categoryAccreditation(AgeCategory $ageCategory): Response
    {
        $cards = AccreditationCards::forCategory($ageCategory->load('championship'));

        return $this->document->download('exports.accreditation', $cards->data(), $cards->filename());
    }

    public function athleteAccreditation(Athlete $athlete): Response
    {
        $cards = AccreditationCards::forAthlete($athlete->load('championship'));

        return $this->document->download('exports.accreditation', $cards->data(), $cards->filename());
    }

    public function confirmedWeighIn(WeightCategory $weightCategory, string $format): Response
    {
        return $this->render(new ConfirmedWeighInReport($weightCategory->load('ageCategory.championship')), $format);
    }

    /** What the draw produced: one line per athlete, in draw order. */
    public function drawNumbers(WeightCategory $weightCategory, string $format): Response
    {
        return $this->render(new DrawNumbersReport($weightCategory->load('ageCategory.championship')), $format);
    }

    /**
     * The bracket as a tree, in both formats.
     *
     * Outside render(): a tree is not a table of rows, so it has its own
     * writer rather than being forced through the tabular one.
     */
    public function bracketSheet(WeightCategory $weightCategory, string $format): Response|StreamedResponse
    {
        $sheet = new BracketSheet($weightCategory->load('ageCategory.championship'));

        // Drawable is not drawn: a class whose athletes hold numbers but whose
        // bracket has never been generated has no tree to print.
        abort_unless(
            $weightCategory->hasDraw() && $sheet->size() >= 2,
            404,
            __('This weight class has not been drawn yet.'),
        );

        $writer = app(BracketSheetWriter::class);

        return $format === 'xlsx' ? $writer->xlsx($sheet) : $writer->pdf($sheet);
    }

    public function drawSheet(WeightCategory $weightCategory, string $format): Response
    {
        return $this->render(
            new DrawSheetReport($weightCategory->load('ageCategory.championship')),
            $format,
            orientation: 'landscape',
        );
    }

    public function fightOrder(Championship $championship, string $format): Response
    {
        return $this->render(new FightOrderReport($championship), $format, orientation: 'landscape');
    }

    public function entriesByNoc(Championship $championship, string $format): Response
    {
        return $this->render(new EntriesByNocReport($championship), $format);
    }

    public function entriesByWeight(Championship $championship, string $format): Response
    {
        return $this->render(new EntriesByWeightCategoryReport($championship), $format);
    }

    public function results(Championship $championship, string $format): Response
    {
        return $this->render(new ResultsReport($championship, $this->medals), $format, orientation: 'landscape');
    }

    public function medalStanding(Championship $championship, string $format): Response
    {
        return $this->render(new MedalStandingReport($championship, $this->medals), $format);
    }

    /**
     * The route constrains the format, so an unknown one should be impossible.
     * Named explicitly anyway rather than treating "anything that is not pdf"
     * as CSV, which would hand someone a .xlsx request a CSV without saying so.
     */
    private function render(Report $report, string $format, string $orientation = 'portrait'): Response
    {
        return match ($format) {
            'pdf' => $this->pdf->download($report, $orientation),
            'csv' => $this->csv->download($report),
            default => abort(404),
        };
    }
}
