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
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The round robin, on paper and on a worksheet.
 *
 * Both render the same RoundRobinSheet, so the two files agree about every
 * fixture and every place in the table.
 *
 * A round robin fits on A4 at every size this system will draw one: five
 * athletes is ten contests and a five-by-five matrix. There is none of the
 * paper arithmetic BracketSheetWriter needs, because there is no tree whose
 * height grows with the field — so the page is fixed and the tables simply sit
 * on it, in the order an official reads them: who was drawn, what they play,
 * how they got on, where that leaves them.
 *
 * The worksheet carries NOC codes beside names rather than flags, for the
 * reason set out at the head of BracketSheetWriter: OOXML cannot put an image
 * *in* a cell, and a picture floating over the grid drifts against the rows it
 * is supposed to belong to. The printed sheet flies the flags.
 */
class RoundRobinSheetWriter
{
    public function pdf(RoundRobinSheet $sheet): Response
    {
        return Pdf::loadView('exports.round-robin', ['sheet' => $sheet])
            ->setPaper('a4', 'portrait')
            ->download($sheet->filename().'.pdf');
    }

    public function xlsx(RoundRobinSheet $sheet): StreamedResponse
    {
        $book = new Spreadsheet;
        $page = $book->getActiveSheet();
        $page->setTitle('Round robin');

        $meta = $sheet->meta();
        $row = 1;

        // ── The heading block ────────────────────────────────────────────
        $page->setCellValue('A1', config('branding.organisation'));
        $page->getStyle('A1')->getFont()->setBold(true)->setSize(12)->getColor()->setRGB('019A44');

        $page->setCellValue('A2', $meta['Competition'] ?? '');
        $page->getStyle('A2')->getFont()->setBold(true)->setSize(16);

        $page->setCellValue('A3', implode('  ·  ', array_filter([
            $meta['Gender / Weight Category'] ?? null,
            $sheet->formatLabel(),
            ($meta['Athletes'] ?? '0').' athletes',
            $meta['Venue'] ?? null,
            $meta['Date'] ?? null,
        ])));
        $page->getStyle('A3')->getFont()->getColor()->setRGB('5D6D67');

        // Stated on the sheet, not only on the screen that produced it.
        $page->setCellValue('A4', $sheet->formatLabel());
        $page->getStyle('A4')->getFont()->setBold(true)->getColor()->setRGB('046830');

        $row = 6;

        // ── Who was drawn ────────────────────────────────────────────────
        $row = $this->heading($page, $row, 'Draw', ['Draw no.', "Athlete's ID (IKA)", 'Athlete', 'NOC']);

        foreach ($sheet->athletes() as $athlete) {
            $page->setCellValue('A'.$row, $athlete['draw'] ?? '—');
            $page->setCellValue('B'.$row, $athlete['ika']);
            $page->setCellValue('C'.$row, $athlete['name']);
            $page->setCellValue('D'.$row, $athlete['noc']);
            $row++;
        }

        $row += 1;

        // ── What everybody plays ─────────────────────────────────────────
        $row = $this->heading($page, $row, 'Contests', ['Round', 'Fight', 'Blue', 'Green', 'Winner', 'Result']);

        foreach ($sheet->rounds() as $number => $contests) {
            foreach ($contests as $contest) {
                $page->setCellValue('A'.$row, $number);
                $page->setCellValue('B'.$row, $contest['fight'] !== '' ? $contest['fight'] : '—');
                $page->setCellValue('C'.$row, trim($contest['a'].' '.$contest['aNoc']));
                $page->setCellValue('D'.$row, trim($contest['b'].' '.$contest['bNoc']));
                $page->setCellValue('E'.$row, $contest['winner'] !== '' ? $contest['winner'] : '—');
                $page->setCellValue('F'.$row, $contest['result'] !== '' ? $contest['result'] : 'Pending');
                $row++;
            }
        }

        $row += 1;

        // ── How they got on ──────────────────────────────────────────────
        $standings = $sheet->standings();

        $row = $this->heading($page, $row, 'Standings', [
            'Rank', 'Athlete', 'NOC', 'Played', 'Won', 'Lost', 'Points', 'Standing',
        ]);

        foreach ($standings['rows'] as $place) {
            $page->setCellValue('A'.$row, $place['rank']);
            $page->setCellValue('B'.$row, $place['athlete']->fullname);
            $page->setCellValue('C'.$row, $place['noc']);
            $page->setCellValue('D'.$row, $place['played']);
            $page->setCellValue('E'.$row, $place['wins']);
            $page->setCellValue('F'.$row, $place['losses']);
            $page->setCellValue('G'.$row, $place['points']);
            $page->setCellValue('H'.$row, $this->standingLabel($place, $standings['complete']));
            $row++;
        }

        $row += 1;

        // ── And the arithmetic behind it ─────────────────────────────────
        $page->setCellValue('A'.$row, 'Points');
        $page->getStyle('A'.$row)->getFont()->setBold(true);
        $page->setCellValue('B'.$row, $sheet->pointsNote());
        $row++;

        $page->setCellValue('A'.$row, 'Tie-breaks');
        $page->getStyle('A'.$row)->getFont()->setBold(true);
        $page->setCellValue('B'.$row, implode(', ', $sheet->tieBreakNotes()));
        $row++;

        if ($standings['unresolved'] !== []) {
            $page->setCellValue('A'.$row, 'Unresolved');
            $page->getStyle('A'.$row)->getFont()->setBold(true)->getColor()->setRGB('A9713F');
            $page->setCellValue('B'.$row, 'Athletes remain level on every tie-break. A technical decision is required.');
        }

        // Wide enough for a name and a nation, so nothing prints clipped.
        foreach (['A' => 10, 'B' => 30, 'C' => 26, 'D' => 26, 'E' => 24, 'F' => 16, 'G' => 10, 'H' => 22] as $column => $width) {
            $page->getColumnDimension($column)->setWidth($width);
        }

        $page->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_PORTRAIT)
            ->setFitToWidth(1)
            ->setFitToHeight(0);

        // The whole of what was written, so a reader does not print the empty
        // remainder of the grid with it.
        $page->getPageSetup()->setPrintArea('A1:H'.max(1, $row));

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
        }, $sheet->filename().'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * A titled block with a header row, returning the row its body starts on.
     *
     * @param  list<string>  $columns
     */
    private function heading(mixed $page, int $row, string $title, array $columns): int
    {
        $page->setCellValue('A'.$row, $title);
        $page->getStyle('A'.$row)->getFont()->setBold(true)->setSize(12);
        $row++;

        foreach ($columns as $index => $column) {
            $page->setCellValue(Coordinate::stringFromColumnIndex($index + 1).$row, $column);
        }

        $last = Coordinate::stringFromColumnIndex(count($columns));
        $range = 'A'.$row.':'.$last.$row;

        $page->getStyle($range)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $page->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('019A44');
        $page->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $page->getStyle($range)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D6DED9');

        return $row + 1;
    }

    /** @param array<string, mixed> $place */
    private function standingLabel(array $place, bool $complete): string
    {
        if ($place['medal'] !== null) {
            return ucfirst((string) $place['medal']);
        }

        return match ($place['state']) {
            'needs_decision' => 'Level — decision required',
            'provisional' => 'Provisional',
            default => $complete ? 'Ranked' : 'Provisional',
        };
    }

    /** The logo, where GD is present to draw it. */
    public function logo(): ?string
    {
        return PrintLogo::path();
    }
}
