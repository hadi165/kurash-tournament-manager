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
     * IOC three-letter code => [ISO 3166-1 alpha-2, country name].
     *
     * One table rather than two, because a code that gained a flag without a
     * name — or a name without a flag — would be a code this application half
     * knows, and nothing would say so.
     *
     * @var array<string, array{string, string}>
     */
    private const NATIONS = [
        'AFG' => ['af', 'Afghanistan'],
        'ALB' => ['al', 'Albania'],
        'ALG' => ['dz', 'Algeria'],
        'AND' => ['ad', 'Andorra'],
        'ANG' => ['ao', 'Angola'],
        'ANT' => ['ag', 'Antigua and Barbuda'],
        'ARG' => ['ar', 'Argentina'],
        'ARM' => ['am', 'Armenia'],
        'ARU' => ['aw', 'Aruba'],
        'ASA' => ['as', 'American Samoa'],
        'AUS' => ['au', 'Australia'],
        'AUT' => ['at', 'Austria'],
        'AZE' => ['az', 'Azerbaijan'],
        'BAH' => ['bs', 'Bahamas'],
        'BAN' => ['bd', 'Bangladesh'],
        'BAR' => ['bb', 'Barbados'],
        'BDI' => ['bi', 'Burundi'],
        'BEL' => ['be', 'Belgium'],
        'BEN' => ['bj', 'Benin'],
        'BER' => ['bm', 'Bermuda'],
        'BHU' => ['bt', 'Bhutan'],
        'BIH' => ['ba', 'Bosnia and Herzegovina'],
        'BIZ' => ['bz', 'Belize'],
        'BLR' => ['by', 'Belarus'],
        'BOL' => ['bo', 'Bolivia'],
        'BOT' => ['bw', 'Botswana'],
        'BRA' => ['br', 'Brazil'],
        'BRN' => ['bh', 'Bahrain'],
        'BRU' => ['bn', 'Brunei'],
        'BUL' => ['bg', 'Bulgaria'],
        'BUR' => ['bf', 'Burkina Faso'],
        'CAF' => ['cf', 'Central African Republic'],
        'CAM' => ['kh', 'Cambodia'],
        'CAN' => ['ca', 'Canada'],
        'CAY' => ['ky', 'Cayman Islands'],
        'CGO' => ['cg', 'Republic of the Congo'],
        'CHA' => ['td', 'Chad'],
        'CHI' => ['cl', 'Chile'],
        'CHN' => ['cn', 'China'],
        'CIV' => ['ci', 'Côte d\'Ivoire'],
        'CMR' => ['cm', 'Cameroon'],
        'COD' => ['cd', 'Democratic Republic of the Congo'],
        'COK' => ['ck', 'Cook Islands'],
        'COL' => ['co', 'Colombia'],
        'COM' => ['km', 'Comoros'],
        'CPV' => ['cv', 'Cabo Verde'],
        'CRC' => ['cr', 'Costa Rica'],
        'CRO' => ['hr', 'Croatia'],
        'CUB' => ['cu', 'Cuba'],
        'CYP' => ['cy', 'Cyprus'],
        'CZE' => ['cz', 'Czechia'],
        'DEN' => ['dk', 'Denmark'],
        'DJI' => ['dj', 'Djibouti'],
        'DMA' => ['dm', 'Dominica'],
        'DOM' => ['do', 'Dominican Republic'],
        'ECU' => ['ec', 'Ecuador'],
        'EGY' => ['eg', 'Egypt'],
        'ERI' => ['er', 'Eritrea'],
        'ESA' => ['sv', 'El Salvador'],
        'ESP' => ['es', 'Spain'],
        'EST' => ['ee', 'Estonia'],
        'ETH' => ['et', 'Ethiopia'],
        'FIJ' => ['fj', 'Fiji'],
        'FIN' => ['fi', 'Finland'],
        'FRA' => ['fr', 'France'],
        'FSM' => ['fm', 'Micronesia'],
        'GAB' => ['ga', 'Gabon'],
        'GAM' => ['gm', 'The Gambia'],
        'GBR' => ['gb', 'Great Britain'],
        'GBS' => ['gw', 'Guinea-Bissau'],
        'GEO' => ['ge', 'Georgia'],
        'GEQ' => ['gq', 'Equatorial Guinea'],
        'GER' => ['de', 'Germany'],
        'GHA' => ['gh', 'Ghana'],
        'GRE' => ['gr', 'Greece'],
        'GRN' => ['gd', 'Grenada'],
        'GUA' => ['gt', 'Guatemala'],
        'GUI' => ['gn', 'Guinea'],
        'GUM' => ['gu', 'Guam'],
        'GUY' => ['gy', 'Guyana'],
        'HAI' => ['ht', 'Haiti'],
        'HKG' => ['hk', 'Hong Kong'],
        'HON' => ['hn', 'Honduras'],
        'HUN' => ['hu', 'Hungary'],
        'INA' => ['id', 'Indonesia'],
        'IND' => ['in', 'India'],
        'IRI' => ['ir', 'Islamic Republic of Iran'],
        'IRL' => ['ie', 'Ireland'],
        'IRQ' => ['iq', 'Iraq'],
        'ISL' => ['is', 'Iceland'],
        'ISR' => ['il', 'Israel'],
        'ISV' => ['vi', 'Virgin Islands (US)'],
        'ITA' => ['it', 'Italy'],
        'IVB' => ['vg', 'Virgin Islands (British)'],
        'JAM' => ['jm', 'Jamaica'],
        'JOR' => ['jo', 'Jordan'],
        'JPN' => ['jp', 'Japan'],
        'KAZ' => ['kz', 'Kazakhstan'],
        'KEN' => ['ke', 'Kenya'],
        'KGZ' => ['kg', 'Kyrgyzstan'],
        'KIR' => ['ki', 'Kiribati'],
        'KOR' => ['kr', 'South Korea'],
        'KOS' => ['xk', 'Kosovo'],
        'KSA' => ['sa', 'Saudi Arabia'],
        'KUW' => ['kw', 'Kuwait'],
        'LAO' => ['la', 'Laos'],
        'LAT' => ['lv', 'Latvia'],
        'LBA' => ['ly', 'Libya'],
        'LBN' => ['lb', 'Lebanon'],
        'LBR' => ['lr', 'Liberia'],
        'LCA' => ['lc', 'Saint Lucia'],
        'LES' => ['ls', 'Lesotho'],
        'LIE' => ['li', 'Liechtenstein'],
        'LTU' => ['lt', 'Lithuania'],
        'LUX' => ['lu', 'Luxembourg'],
        'MAD' => ['mg', 'Madagascar'],
        'MAR' => ['ma', 'Morocco'],
        'MAS' => ['my', 'Malaysia'],
        'MAW' => ['mw', 'Malawi'],
        'MDA' => ['md', 'Moldova'],
        'MDV' => ['mv', 'Maldives'],
        'MEX' => ['mx', 'Mexico'],
        'MGL' => ['mn', 'Mongolia'],
        'MHL' => ['mh', 'Marshall Islands'],
        'MKD' => ['mk', 'North Macedonia'],
        'MLI' => ['ml', 'Mali'],
        'MLT' => ['mt', 'Malta'],
        'MNE' => ['me', 'Montenegro'],
        'MON' => ['mc', 'Monaco'],
        'MOZ' => ['mz', 'Mozambique'],
        'MRI' => ['mu', 'Mauritius'],
        'MTN' => ['mr', 'Mauritania'],
        'MYA' => ['mm', 'Myanmar'],
        'NAM' => ['na', 'Namibia'],
        'NCA' => ['ni', 'Nicaragua'],
        'NED' => ['nl', 'Netherlands'],
        'NEP' => ['np', 'Nepal'],
        'NGR' => ['ng', 'Nigeria'],
        'NIG' => ['ne', 'Niger'],
        'NOR' => ['no', 'Norway'],
        'NRU' => ['nr', 'Nauru'],
        'NZL' => ['nz', 'New Zealand'],
        'OMA' => ['om', 'Oman'],
        'PAK' => ['pk', 'Pakistan'],
        'PAN' => ['pa', 'Panama'],
        'PAR' => ['py', 'Paraguay'],
        'PER' => ['pe', 'Peru'],
        'PHI' => ['ph', 'Philippines'],
        'PLE' => ['ps', 'Palestine'],
        'PLW' => ['pw', 'Palau'],
        'PNG' => ['pg', 'Papua New Guinea'],
        'POL' => ['pl', 'Poland'],
        'POR' => ['pt', 'Portugal'],
        'PRK' => ['kp', 'North Korea'],
        'PUR' => ['pr', 'Puerto Rico'],
        'QAT' => ['qa', 'Qatar'],
        'ROU' => ['ro', 'Romania'],
        'RSA' => ['za', 'South Africa'],
        'RUS' => ['ru', 'Russia'],
        'RWA' => ['rw', 'Rwanda'],
        'SAM' => ['ws', 'Samoa'],
        'SEN' => ['sn', 'Senegal'],
        'SEY' => ['sc', 'Seychelles'],
        'SGP' => ['sg', 'Singapore'],
        'SKN' => ['kn', 'Saint Kitts and Nevis'],
        'SLE' => ['sl', 'Sierra Leone'],
        'SLO' => ['si', 'Slovenia'],
        'SMR' => ['sm', 'San Marino'],
        'SOL' => ['sb', 'Solomon Islands'],
        'SOM' => ['so', 'Somalia'],
        'SRB' => ['rs', 'Serbia'],
        'SRI' => ['lk', 'Sri Lanka'],
        'SSD' => ['ss', 'South Sudan'],
        'STP' => ['st', 'São Tomé and Príncipe'],
        'SUD' => ['sd', 'Sudan'],
        'SUI' => ['ch', 'Switzerland'],
        'SUR' => ['sr', 'Suriname'],
        'SVK' => ['sk', 'Slovakia'],
        'SWE' => ['se', 'Sweden'],
        'SWZ' => ['sz', 'Eswatini'],
        'SYR' => ['sy', 'Syria'],
        'TAN' => ['tz', 'Tanzania'],
        'TGA' => ['to', 'Tonga'],
        'THA' => ['th', 'Thailand'],
        'TJK' => ['tj', 'Tajikistan'],
        'TKM' => ['tm', 'Turkmenistan'],
        'TLS' => ['tl', 'Timor-Leste'],
        'TOG' => ['tg', 'Togo'],
        'TPE' => ['tw', 'Chinese Taipei'],
        'TTO' => ['tt', 'Trinidad and Tobago'],
        'TUN' => ['tn', 'Tunisia'],
        'TUR' => ['tr', 'Turkey'],
        'TUV' => ['tv', 'Tuvalu'],
        'UAE' => ['ae', 'United Arab Emirates'],
        'UGA' => ['ug', 'Uganda'],
        'UKR' => ['ua', 'Ukraine'],
        'URU' => ['uy', 'Uruguay'],
        'USA' => ['us', 'United States'],
        'UZB' => ['uz', 'Uzbekistan'],
        'VAN' => ['vu', 'Vanuatu'],
        'VEN' => ['ve', 'Venezuela'],
        'VIE' => ['vn', 'Vietnam'],
        'VIN' => ['vc', 'Saint Vincent and the Grenadines'],
        'YEM' => ['ye', 'Yemen'],
        'ZAM' => ['zm', 'Zambia'],
        'ZIM' => ['zw', 'Zimbabwe'],
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

        return $code === null ? null : (self::NATIONS[$code][0] ?? null);
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
        return array_keys(self::NATIONS);
    }

    /** Whether this is a code the application knows at all. */
    public static function exists(?string $noc): bool
    {
        $code = self::normalise($noc);

        return $code !== null && isset(self::NATIONS[$code]);
    }

    /**
     * The country a code stands for, as a competition names it — "Iran", not
     * "Islamic Republic of Iran"; "Chinese Taipei", not "Taiwan".
     */
    public static function name(?string $noc): ?string
    {
        $code = self::normalise($noc);

        return $code === null ? null : (self::NATIONS[$code][1] ?? null);
    }

    /**
     * Every code with its country, in code order.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return array_map(fn (array $nation): string => $nation[1], self::NATIONS);
    }

    /**
     * Codes beginning with what has been typed.
     *
     * Matched from the start of the code rather than anywhere inside it: a
     * three-letter code is read as a prefix by the people who use them, and
     * "IR" meaning Iran and Iraq and nothing else is the behaviour a table
     * official expects.
     *
     * @return array<string, string>
     */
    public static function startingWith(?string $prefix, int $limit = 8): array
    {
        $typed = self::normalise($prefix);

        if ($typed === null) {
            return [];
        }

        $matches = [];

        foreach (self::NATIONS as $code => [, $name]) {
            if (str_starts_with($code, $typed)) {
                $matches[$code] = $name;

                if (count($matches) === $limit) {
                    break;
                }
            }
        }

        return $matches;
    }
}
