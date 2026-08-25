<?php

namespace App\Http\Controllers;

use App\Exports\AccreditationCards;
use App\Exports\AthleteListReport;
use App\Exports\BracketSheet;
use App\Exports\BracketSheetWriter;
use App\Exports\CertificateSheet;
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
use App\Exports\RoundRobinSheet;
use App\Exports\RoundRobinSheetWriter;
use App\Exports\WeighInFormReport;
use App\Exports\XlsxWriter;
use App\Models\AgeCategory;
use App\Models\Athlete;
use App\Models\Championship;
use App\Models\WeightCategory;
use App\Services\MedalTable;
use App\Support\Noc;
use Illuminate\Http\Request;
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

        // A credential admits somebody to the venue, and the age rules decide
        // who is admitted to the competition. Saying which athlete and why is
        // more use to an accreditation desk than an empty sheet of card.
        abort_if(
            $cards->isEmpty(),
            404,
            (string) ($athlete->ageVerdict()->reason ?? __('This athlete\'s age has not been verified.')),
        );

        return $this->document->download('exports.accreditation', $cards->data(), $cards->filename());
    }

    public function confirmedWeighIn(WeightCategory $weightCategory, string $format): Response|StreamedResponse
    {
        return $this->render(new WeighInFormReport($weightCategory->load('ageCategory.championship')), $format);
    }

    /** What the draw produced: one line per athlete, in draw order. */
    public function drawNumbers(WeightCategory $weightCategory, string $format): Response|StreamedResponse
    {
        return $this->render(new DrawNumbersReport($weightCategory->load('ageCategory.championship')), $format);
    }

    /**
     * The bracket as a tree, in both formats.
     *
     * Outside render(): a tree is not a table of rows, so it has its own
     * writer rather than being forced through the tabular one.
     */
    public function bracketSheet(WeightCategory $weightCategory, string $format, Request $request): Response|StreamedResponse
    {
        $weightCategory->load('ageCategory.championship');

        // ?fights=0 for the sheet saved off a draw ceremony: positions, no
        // running order. Numbers are the default, because everywhere else asks
        // for the draw after the schedule is made.
        $withNumbers = ! in_array($request->query('fights'), ['0', 'false', 'no'], true);

        /*
         | Routed on what the class was drawn as.
         |
         | A round robin sent through BracketSheet to reuse its layout would
         | print a tree of a competition that is not being held — so it has a
         | sheet and a writer of its own, and this is the fork. The knockout
         | path below is untouched.
         */
        if ($weightCategory->isRoundRobin()) {
            $roundRobin = new RoundRobinSheet($weightCategory, fightNumbers: $withNumbers);
            $writer = app(RoundRobinSheetWriter::class);

            return $format === 'xlsx' ? $writer->xlsx($roundRobin) : $writer->pdf($roundRobin);
        }

        // A class of one has no contests to print at all. Saying so is more
        // use than an empty tree.
        abort_if(
            $weightCategory->isPlacement(),
            404,
            __('This weight class has a single entrant and no contests to print.'),
        );

        $sheet = new BracketSheet($weightCategory, fightNumbers: $withNumbers);

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

    public function drawSheet(WeightCategory $weightCategory, string $format): Response|StreamedResponse
    {
        return $this->render(
            new DrawSheetReport($weightCategory->load('ageCategory.championship')),
            $format,
            orientation: 'landscape',
        );
    }

    public function fightOrder(Championship $championship, string $format, Request $request): Response|StreamedResponse
    {
        // Checked against the competitions this championship actually runs, so
        // anything else prints the whole order rather than an empty sheet.
        $requested = $request->query('competition');
        $competition = is_string($requested) && in_array($requested, $championship->configuredGenders(), true)
            ? $requested
            : null;

        return $this->render(new FightOrderReport($championship, $competition), $format, orientation: 'landscape');
    }

    /**
     * Everyone entered, by nation — the list the hotel and the organising team
     * work from, either whole or for one delegation.
     */
    public function athletes(Championship $championship, string $format, Request $request): Response|StreamedResponse
    {
        // Checked against the codes the system knows, so anything else prints
        // the whole list rather than an empty sheet.
        $requested = $request->query('noc');
        $noc = is_string($requested) && Noc::exists($requested) ? $requested : null;

        return $this->render(new AthleteListReport($championship, $noc), $format, orientation: 'landscape');
    }

    public function entriesByNoc(Championship $championship, string $format): Response|StreamedResponse
    {
        return $this->render(new EntriesByNocReport($championship), $format);
    }

    public function entriesByWeight(Championship $championship, string $format): Response|StreamedResponse
    {
        return $this->render(new EntriesByWeightCategoryReport($championship), $format);
    }

    public function results(Championship $championship, string $format): Response|StreamedResponse
    {
        return $this->render(new ResultsReport($championship, $this->medals), $format, orientation: 'landscape');
    }

    public function medalStanding(Championship $championship, string $format): Response|StreamedResponse
    {
        return $this->render(new MedalStandingReport($championship, $this->medals), $format);
    }

    /**
     * The route constrains the format, so an unknown one should be impossible.
     * Named explicitly anyway rather than treating "anything that is not pdf"
     * as CSV, which would hand someone a .xlsx request a CSV without saying so.
     */
    private function render(Report $report, string $format, string $orientation = 'portrait'): Response|StreamedResponse
    {
        return match ($format) {
            'pdf' => $this->pdf->download($report, $orientation),
            // Both spellings stay: xlsx is what the buttons ask for, csv is
            // still there for anything that has to read the data rather than
            // look at it.
            'xlsx' => app(XlsxWriter::class)->download($report),
            'csv' => $this->csv->download($report),
            default => abort(404),
        };
    }
}
