<?php

use App\Support\Noc;

describe('resolving a flag from an NOC code', function () {
    /**
     * The pairs that a naive "first two letters" rule gets wrong. Each of these
     * would put another country's flag beside an athlete's name.
     */
    it('maps the codes that do not follow their spelling', function (string $noc, string $iso) {
        expect(Noc::iso($noc))->toBe($iso);
    })->with([
        'Bahrain is BRN, not Brunei' => ['BRN', 'bh'],
        'Brunei is BRU' => ['BRU', 'bn'],
        'Iran is IRI' => ['IRI', 'ir'],
        'Germany is GER' => ['GER', 'de'],
        'Switzerland is SUI' => ['SUI', 'ch'],
        'Netherlands is NED' => ['NED', 'nl'],
        'Saudi Arabia is KSA' => ['KSA', 'sa'],
        'South Africa is RSA' => ['RSA', 'za'],
        'Chinese Taipei is TPE' => ['TPE', 'tw'],
        'North Korea is PRK' => ['PRK', 'kp'],
        'Sri Lanka is SRI' => ['SRI', 'lk'],
        'Slovenia is SLO' => ['SLO', 'si'],
        'Latvia is LAT' => ['LAT', 'lv'],
        'Algeria is ALG' => ['ALG', 'dz'],
    ]);

    it('maps the Central Asian nations kurash is competed in', function (string $noc, string $iso) {
        expect(Noc::iso($noc))->toBe($iso);
    })->with([
        ['UZB', 'uz'],
        ['KAZ', 'kz'],
        ['TJK', 'tj'],
        ['TKM', 'tm'],
        ['KGZ', 'kg'],
        ['AFG', 'af'],
    ]);

    /** The imported legacy data holds "uzb" in lower case. */
    it('accepts a code in any case, with stray spacing', function () {
        expect(Noc::iso('uzb'))->toBe('uz')
            ->and(Noc::iso(' Iri '))->toBe('ir')
            ->and(Noc::normalise('  uzb '))->toBe('UZB');
    });

    it('returns nothing rather than guessing at an unknown code', function () {
        expect(Noc::iso('ZZZ'))->toBeNull()
            ->and(Noc::iso(''))->toBeNull()
            ->and(Noc::iso(null))->toBeNull()
            ->and(Noc::normalise('   '))->toBeNull();
    });
});
