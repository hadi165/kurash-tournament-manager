<?php

namespace App\Support;

/**
 * National Olympic Committee codes.
 *
 * Competitions identify nations by three-letter IOC codes, but flag artwork is
 * filed under two-letter ISO 3166-1 codes, and the two do not agree in a way
 * any rule could derive:
 *
 *   BRN is Bahrain, not Brunei — Brunei is BRU
 *   IRI is Iran, GER is Germany, SUI is Switzerland, NED is the Netherlands
 *   KSA is Saudi Arabia, RSA is South Africa, TPE is Chinese Taipei
 *
 * Getting one of these wrong puts another country's flag beside an athlete's
 * name on a screen in front of their delegation, so it is a table, not a
 * transformation.
 */
final class Noc
{
    /**
     * IOC three-letter code => ISO 3166-1 alpha-2.
     *
     * @var array<string, string>
     */
    private const ISO = [
        'AFG' => 'af', 'ALB' => 'al', 'ALG' => 'dz', 'AND' => 'ad', 'ANG' => 'ao',
        'ANT' => 'ag', 'ARG' => 'ar', 'ARM' => 'am', 'ARU' => 'aw', 'ASA' => 'as',
        'AUS' => 'au', 'AUT' => 'at', 'AZE' => 'az', 'BAH' => 'bs', 'BAN' => 'bd',
        'BAR' => 'bb', 'BDI' => 'bi', 'BEL' => 'be', 'BEN' => 'bj', 'BER' => 'bm',
        'BHU' => 'bt', 'BIH' => 'ba', 'BIZ' => 'bz', 'BLR' => 'by', 'BOL' => 'bo',
        'BOT' => 'bw', 'BRA' => 'br', 'BRN' => 'bh', 'BRU' => 'bn', 'BUL' => 'bg',
        'BUR' => 'bf', 'CAF' => 'cf', 'CAM' => 'kh', 'CAN' => 'ca', 'CAY' => 'ky',
        'CGO' => 'cg', 'CHA' => 'td', 'CHI' => 'cl', 'CHN' => 'cn', 'CIV' => 'ci',
        'CMR' => 'cm', 'COD' => 'cd', 'COK' => 'ck', 'COL' => 'co', 'COM' => 'km',
        'CPV' => 'cv', 'CRC' => 'cr', 'CRO' => 'hr', 'CUB' => 'cu', 'CYP' => 'cy',
        'CZE' => 'cz', 'DEN' => 'dk', 'DJI' => 'dj', 'DMA' => 'dm', 'DOM' => 'do',
        'ECU' => 'ec', 'EGY' => 'eg', 'ERI' => 'er', 'ESA' => 'sv', 'ESP' => 'es',
        'EST' => 'ee', 'ETH' => 'et', 'FIJ' => 'fj', 'FIN' => 'fi', 'FRA' => 'fr',
        'FSM' => 'fm', 'GAB' => 'ga', 'GAM' => 'gm', 'GBR' => 'gb', 'GBS' => 'gw',
        'GEO' => 'ge', 'GEQ' => 'gq', 'GER' => 'de', 'GHA' => 'gh', 'GRE' => 'gr',
        'GRN' => 'gd', 'GUA' => 'gt', 'GUI' => 'gn', 'GUM' => 'gu', 'GUY' => 'gy',
        'HAI' => 'ht', 'HKG' => 'hk', 'HON' => 'hn', 'HUN' => 'hu', 'INA' => 'id',
        'IND' => 'in', 'IRI' => 'ir', 'IRL' => 'ie', 'IRQ' => 'iq', 'ISL' => 'is',
        'ISR' => 'il', 'ISV' => 'vi', 'ITA' => 'it', 'IVB' => 'vg', 'JAM' => 'jm',
        'JOR' => 'jo', 'JPN' => 'jp', 'KAZ' => 'kz', 'KEN' => 'ke', 'KGZ' => 'kg',
        'KIR' => 'ki', 'KOR' => 'kr', 'KOS' => 'xk', 'KSA' => 'sa', 'KUW' => 'kw',
        'LAO' => 'la', 'LAT' => 'lv', 'LBA' => 'ly', 'LBN' => 'lb', 'LBR' => 'lr',
        'LCA' => 'lc', 'LES' => 'ls', 'LIE' => 'li', 'LTU' => 'lt', 'LUX' => 'lu',
        'MAD' => 'mg', 'MAR' => 'ma', 'MAS' => 'my', 'MAW' => 'mw', 'MDA' => 'md',
        'MDV' => 'mv', 'MEX' => 'mx', 'MGL' => 'mn', 'MHL' => 'mh', 'MKD' => 'mk',
        'MLI' => 'ml', 'MLT' => 'mt', 'MNE' => 'me', 'MON' => 'mc', 'MOZ' => 'mz',
        'MRI' => 'mu', 'MTN' => 'mr', 'MYA' => 'mm', 'NAM' => 'na', 'NCA' => 'ni',
        'NED' => 'nl', 'NEP' => 'np', 'NGR' => 'ng', 'NIG' => 'ne', 'NOR' => 'no',
        'NRU' => 'nr', 'NZL' => 'nz', 'OMA' => 'om', 'PAK' => 'pk', 'PAN' => 'pa',
        'PAR' => 'py', 'PER' => 'pe', 'PHI' => 'ph', 'PLE' => 'ps', 'PLW' => 'pw',
        'PNG' => 'pg', 'POL' => 'pl', 'POR' => 'pt', 'PRK' => 'kp', 'PUR' => 'pr',
        'QAT' => 'qa', 'ROU' => 'ro', 'RSA' => 'za', 'RUS' => 'ru', 'RWA' => 'rw',
        'SAM' => 'ws', 'SEN' => 'sn', 'SEY' => 'sc', 'SGP' => 'sg', 'SKN' => 'kn',
        'SLE' => 'sl', 'SLO' => 'si', 'SMR' => 'sm', 'SOL' => 'sb', 'SOM' => 'so',
        'SRB' => 'rs', 'SRI' => 'lk', 'SSD' => 'ss', 'STP' => 'st', 'SUD' => 'sd',
        'SUI' => 'ch', 'SUR' => 'sr', 'SVK' => 'sk', 'SWE' => 'se', 'SWZ' => 'sz',
        'SYR' => 'sy', 'TAN' => 'tz', 'TGA' => 'to', 'THA' => 'th', 'TJK' => 'tj',
        'TKM' => 'tm', 'TLS' => 'tl', 'TOG' => 'tg', 'TPE' => 'tw', 'TTO' => 'tt',
        'TUN' => 'tn', 'TUR' => 'tr', 'TUV' => 'tv', 'UAE' => 'ae', 'UGA' => 'ug',
        'UKR' => 'ua', 'URU' => 'uy', 'USA' => 'us', 'UZB' => 'uz', 'VAN' => 'vu',
        'VEN' => 've', 'VIE' => 'vn', 'VIN' => 'vc', 'YEM' => 'ye', 'ZAM' => 'zm',
        'ZIM' => 'zw',
    ];

    /**
     * Normalise a code as typed or imported.
     *
     * The legacy data holds "uzb" in lower case, so codes are upper-cased and
     * trimmed before anything looks at them.
     */
    public static function normalise(?string $noc): ?string
    {
        $code = strtoupper(trim((string) $noc));

        return $code === '' ? null : $code;
    }

    /**
     * ISO 3166-1 alpha-2 code for a flag, or null where there is no flag to
     * show — an unrecognised code, or a delegation that competes without one
     * such as the refugee and neutral-athlete teams.
     */
    public static function iso(?string $noc): ?string
    {
        $code = self::normalise($noc);

        return $code === null ? null : (self::ISO[$code] ?? null);
    }

    /** Whether a flag exists on disk for this code. */
    public static function hasFlag(?string $noc): bool
    {
        $iso = self::iso($noc);

        return $iso !== null && is_file(public_path("flags/{$iso}.svg"));
    }

    /** Absolute path to the flag, for Dompdf, which reads from disk. */
    public static function flagPath(?string $noc): ?string
    {
        $iso = self::iso($noc);

        if ($iso === null) {
            return null;
        }

        $path = public_path("flags/{$iso}.svg");

        return is_file($path) ? $path : null;
    }

    /**
     * Every IOC code this application recognises.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::ISO);
    }
}
