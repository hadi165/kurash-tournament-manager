<?php

use App\Exports\AthleteListReport;
use App\Exports\EntriesByNocReport;
use App\Exports\FightOrderReport;
use App\Exports\MedalStandingReport;
use App\Exports\ResultDocument;
use App\Exports\ResultsReport;
use App\Exports\WeighInFormReport;
use App\Support\PrintLogo;

/**
 * How federation paperwork is set.
 *
 * Every PDF renders through one template, so these are assertions about the
 * template rather than about any report — which is the point: a rule applied
 * report by report is a rule that drifts.
 */
function sheet(array $overrides = []): string
{
    return view('exports.table', $overrides + [
        'title' => 'Fight Order',
        'meta' => ['Competition' => 'Asian Kurash 2026'],
        'headings' => ['No.', 'Blue', 'NOC', 'Winner'],
        'rows' => [
            [1, 'Rustam Kamolov (UZB)', 'KAZ', 'Rustam Kamolov'],
            [2, 'Aziz Turaev (TJK)', 'IRI', 'Aziz Turaev'],
        ],
        'documentTag' => 'Running order',
        'documentReference' => 'FO-1',
        'total' => null,
        'footerLine' => 'Asian Kurash 2026',
        'palette' => 'green',
    ])->render();
}

describe('the colour scheme', function () {
    /** Preparation is green: entries, weigh-ins, draws, running orders. */
    it('prints a report in green', function () {
        expect(sheet())->toContain('#019a44')->not->toContain('#0b5fa5');
    });

    /** What happened is blue, so it is not mistaken for a start list. */
    it('prints a result in blue', function () {
        expect(sheet(['palette' => 'blue']))->toContain('#0b5fa5')->not->toContain('#019a44');
    });

    it('marks the medal standing and the results as results', function () {
        expect(MedalStandingReport::class)->toImplement(ResultDocument::class)
            ->and(ResultsReport::class)->toImplement(ResultDocument::class);
    });

    /** Everything else is preparation, and says so by not saying anything. */
    it('leaves every other report green', function () {
        foreach ([FightOrderReport::class, WeighInFormReport::class, EntriesByNocReport::class, AthleteListReport::class] as $report) {
            expect(is_subclass_of($report, ResultDocument::class))->toBeFalse();
        }
    });
});

describe('the furniture on every sheet', function () {
    it('numbers the lines from one', function () {
        $html = sheet();

        expect($html)->toContain('Item No.')
            ->and($html)->toContain('>1</td>')
            ->and($html)->toContain('>2</td>');
    });

    /** The count has to include the column the template adds itself. */
    it('spans the empty notice across every column', function () {
        expect(sheet(['rows' => []]))->toContain('colspan="5"');
    });

    it('says who produced it, at the foot', function () {
        expect(sheet())->toContain(config('branding.company'));
    });

    it('centres the headings and the cells', function () {
        expect(sheet())->toContain('text-align: center');
    });

    /**
     * Height alone, so the artwork keeps its proportions. A forced square
     * squashes a wordmark, and the mark is the federation's.
     */
    it('sizes the logo without distorting it', function () {
        expect(sheet())->not->toContain('width: 38px; height: 38px');
    });

    /** Where the artwork cannot be drawn, the header keeps its shape. */
    it('falls back to a typographic mark rather than a hole', function () {
        // This machine has no GD, so a PNG logo is refused by PrintLogo and
        // the chip carries the short name instead.
        if (PrintLogo::path() === null) {
            expect(sheet())->toContain(config('branding.short_name'));
        }

        expect(true)->toBeTrue();
    });
});

describe('the nations on a sheet', function () {
    /** A column headed NOC holds the code on its own. */
    it('flies a flag beside a code in an NOC column', function () {
        expect(sheet())->toContain('flags/kz.svg')->toContain('flags/ir.svg');
    });

    /**
     * A corner on a running order holds the name with the code after it, the
     * way the screen sets it — so the flag goes there too.
     */
    it('flies a flag beside a name that names its nation', function () {
        expect(sheet())->toContain('flags/uz.svg')->toContain('flags/tj.svg');
    });

    /** Checked against the code table, so a word that is not a nation is a word. */
    it('leaves a three-letter word alone', function () {
        $html = sheet([
            'headings' => ['Phase', 'Winner'],
            'rows' => [['Final', 'BYE'], ['Semi Final', 'Won']],
        ]);

        expect($html)->not->toContain('class="flag"');
    });

    it('leaves a cell alone when the nation is unknown', function () {
        $html = sheet([
            'headings' => ['NOC'],
            'rows' => [['ZZZ']],
        ]);

        expect($html)->not->toContain('class="flag"');
    });
});
