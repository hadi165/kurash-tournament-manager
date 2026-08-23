{{-- Every PDF export renders through this one template so all federation
     paperwork shares a layout. Styles are inline because Dompdf resolves no
     external stylesheets, and every rule here is one Dompdf actually supports:
     no flexbox, no web fonts, no absolute connectors. Where the design uses a
     flex row, this template uses a layout table. --}}
@php
    /*
     | Two schemes, one template. A report is about preparation — entries,
     | weigh-ins, draws, running orders — and prints green. A result is about
     | what happened, and prints blue, so a medal standing is never mistaken
     | for a start list on a table covered in paper.
     |
     | The writer says which; nothing here decides it.
     */
    $scheme = ($palette ?? 'green') === 'blue'
        ? ['base' => '#0b5fa5', 'edge' => '#3f83bf', 'sub' => '#cfe1f0', 'tag' => '#e6f0f9', 'ref' => '#c6dcf0']
        : ['base' => '#019a44', 'edge' => '#35ac66', 'sub' => '#cfeadb', 'tag' => '#e4f5ea', 'ref' => '#c8e7d6'];
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        /* No side margins: the header band is full-bleed green, so it has to
           reach the paper edge. The top and bottom margins are the space the
           band and the footer are drawn into; every other block sets its own
           40px side padding to line up with them.

           Dompdf anchors a fixed block to the *content* box rather than to the
           page, so the band and the footer are pulled back out into the margin
           with negative offsets — measured from the flow, not from the paper.
           Probed rather than assumed: top: 0 sits exactly where the text
           starts, which is why the two used to overlap. */
        @page { margin: 112px 0 66px; }

        body {
            font-family: DejaVu Sans, sans-serif;   /* ships with Dompdf and covers Cyrillic */
            font-size: 11px;
            color: #111a16;
            margin: 0;
        }

        /* ── Header band, repeated on every page ─────────────────────────── */

        .band {
            position: fixed;
            top: -112px;
            left: 0;
            width: 100%;
            background: {{ $scheme['base'] }};
            color: #fff;
        }

        .band table { width: 100%; border-collapse: collapse; }
        .band td { padding: 15px 40px; vertical-align: middle; border: 0; }

        /* The logo always sits on a white chip and is never recoloured: the
           artwork is the federation's. */
        .chip {
            background: #fff;
            padding: 5px;
            border-radius: 3px;
        }

        /* Block, so the chip is the height of the artwork and not of the line
           box around it: inline leading was adding a third again and pushing
           the mark out through the bottom of the band. */
        .chip img { display: block; }

        .chip-text {
            display: inline-block;
            padding: 9px 11px;
            font-size: 15px;
            font-weight: bold;
            letter-spacing: 0.04em;
            color: {{ $scheme['base'] }};
        }

        .org { font-size: 14px; font-weight: bold; }

        .org-sub {
            font-size: 8.5px;
            font-weight: bold;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: {{ $scheme['sub'] }};
        }

        .doc-tag {
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: {{ $scheme['tag'] }};
        }

        .doc-ref {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 9px;
            color: {{ $scheme['ref'] }};
        }

        /* ── Title and meta ──────────────────────────────────────────────── */

        .sheet { padding: 0 40px; }

        h1 {
            text-align: center;
            font-size: 23px;
            font-weight: bold;
            letter-spacing: -0.015em;
            margin: 0;
            line-height: 1.15;
        }

        /* A horizontal row of label/value pairs rather than a stacked list:
           the same information in a third of the height. */
        .meta {
            width: 100%;
            border-collapse: collapse;
            margin-top: 14px;
            border-bottom: 1px solid #e0e5e3;
        }

        .meta td { padding: 0 17px 16px; vertical-align: top; border: 0; text-align: center; }

        .meta .label {
            font-size: 8.5px;
            font-weight: bold;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #7d8b85;
        }

        .meta .value { font-size: 12.5px; font-weight: bold; padding-top: 3px; }

        /* ── The table ───────────────────────────────────────────────────── */

        .data { width: 100%; border-collapse: collapse; margin-top: 20px; }

        thead { display: table-header-group; }   /* repeat headings on every page */

        .data th {
            background: {{ $scheme['base'] }};
            color: #fff;
            font-size: 9px;
            font-weight: bold;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            text-align: center;
            padding: 9px 10px;
            border-right: 1px solid {{ $scheme['edge'] }};
        }

        .data th:last-child { border-right: 0; }

        /* Only a bottom hairline: a full grid turns a start list into graph
           paper and hides the one line somebody is looking for. */
        .data td {
            font-size: 11px;
            padding: 8px 10px;
            border-bottom: 1px solid #eceeed;
            vertical-align: middle;
            text-align: center;
        }

        .data td.strong { font-weight: bold; }

        .data tbody tr:nth-child(even) td { background: #f7f9f8; }

        /* Four by three, the shape the artwork is drawn on, so nothing is
           stretched. The hairline that keeps a white-edged flag readable is in
           the artwork itself — see App\Support\PrintFlag. */
        .flag {
            width: 16px;
            height: 12px;
            vertical-align: middle;
            margin-right: 5px;
        }

        /* Status reads as a chip in the fixed vocabulary the specification
           sets: a value, not a sentence. */
        .chip-status {
            display: inline-block;
            padding: 2px 9px;
            border-radius: 2px;
            font-size: 9px;
            font-weight: bold;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        .chip-done { background: #e2f4e9; color: #046830; }
        .chip-idle { background: #eef1f0; color: #5d6d67; }
        .chip-short { background: #fbe9ec; color: #8f1626; }

        .empty {
            text-align: center;
            padding: 18px;
            color: #7d8b85;
            font-style: italic;
            font-size: 11px;
        }

        .total {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            background: #f2f5f4;
            border-top: 2px solid {{ $scheme['base'] }};
        }

        .total td {
            padding: 10px;
            font-size: 11px;
            font-weight: bold;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            border: 0;
        }

        .total td { text-align: center; }

        /* ── Footer ──────────────────────────────────────────────────────── */

        /* Three separately anchored fixed elements rather than one footer
           containing floats. Dompdf leaks a float out of a fixed block and
           into the main flow, which indented the title and meta away from the
           table they belong to. */
        .foot-rule, .foot-left, .foot-right { position: fixed; }

        /* Anchored to both edges so the rule spans the sheet in either
           orientation — a landscape fight order and a portrait medal standing
           share this template. */
        .foot-rule {
            bottom: -20px;
            left: 40px;
            right: 40px;
            border-top: 2px solid {{ $scheme['base'] }};
        }

        .foot-left {
            bottom: -46px;
            left: 40px;
            font-size: 8.5px;
            color: #7d8b85;
            line-height: 1.5;
        }

        .foot-left .strong { font-weight: bold; color: #111a16; }

        .foot-right {
            bottom: -46px;
            right: 40px;
            text-align: right;
            font-size: 8.5px;
            font-weight: bold;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #7d8b85;
        }

        .page::after { content: counter(page); }
    </style>
</head>
<body>
    @php
        // Dompdf reads from disk, not over HTTP, and needs GD for raster
        // artwork — PrintLogo returns a path only when it can actually be
        // drawn, so a server without GD prints the header without the mark
        // instead of failing the whole document.
        $printLogo = \App\Support\PrintLogo::path();

        $documentTag ??= null;
        $documentReference ??= null;
        $total ??= null;
        $footerLine ??= null;

        // Alignment is read off the data rather than declared by each report:
        // a column whose every filled value is a number is a column of
        // numbers, and numbers belong on the right.
        $isNumeric = [];

        foreach (array_keys($headings) as $column) {
            $values = array_filter(
                array_map(fn (array $row) => $row[$column] ?? null, $rows),
                fn ($value) => $value !== null && $value !== '',
            );

            $isNumeric[$column] = $values !== []
                && count(array_filter($values, fn ($value) => is_numeric($value))) === count($values);
        }

        $last = count($headings) - 1;

        /*
         | The nation in a cell, if there is one.
         |
         | Two shapes, because the tables carry it two ways: a column headed
         | NOC holds the code on its own, and a corner on a running order
         | holds a name with the code after it — "Rustam Kamolov (UZB)",
         | which is how the screen sets it. Both are checked against the code
         | table, so a three-letter word that is not a nation stays a word.
         */
        $flagFor = function ($cell, string $heading): ?string {
            $value = trim((string) $cell);

            if ($value === '') {
                return null;
            }

            $code = strcasecmp(trim($heading), 'NOC') === 0
                ? $value
                : (preg_match('/\(([A-Za-z]{3})\)\s*$/', $value, $found) ? $found[1] : null);

            // The print copy, not the one the screens fly: Dompdf establishes
            // no viewport for an SVG, so the raster set is what paper gets.
            return $code !== null ? \App\Support\PrintFlag::path($code) : null;
        };

        // The specification fixes this vocabulary, so the template can read it.
        $chipKind = function ($value): ?string {
            $value = trim((string) $value);

            return match (true) {
                strcasecmp($value, 'Done') === 0 => 'done',
                strcasecmp($value, 'Not Started') === 0 => 'idle',
                str_starts_with(strtolower($value), 'needs') => 'short',
                default => null,
            };
        };
    @endphp

    <div class="band">
        <table>
            <tr>
                <td>
                    <table>
                        <tr>
                            <td style="padding: 0;">
                                {{-- Height only: the artwork is the federation's and a
                                     forced square would squash a wordmark. Set to fill
                                     the band — 70px of artwork, 5px of chip either side
                                     and the 15px the row is padded by comes to the 112px
                                     the page reserves for the header.

                                     Where it cannot be drawn — a PNG on a server with no
                                     GD — the chip carries the short name instead, so the
                                     header holds its shape either way rather than
                                     collapsing to a bare line of text. --}}
                                @if ($printLogo)
                                    <span class="chip"><img src="{{ $printLogo }}" alt="" style="height: 70px;"></span>
                                @else
                                    <span class="chip chip-text">{{ config('branding.short_name') }}</span>
                                @endif
                            </td>
                            <td style="padding: 0 0 0 14px;">
                                <div class="org">{{ config('branding.organisation') }}</div>
                                <div class="org-sub">Official competition document</div>
                            </td>
                        </tr>
                    </table>
                </td>
                <td style="text-align: right;">
                    <div class="doc-tag">{{ $documentTag ?? 'Competition document' }}</div>
                    @if ($documentReference)
                        <div class="doc-ref">{{ $documentReference }}</div>
                    @endif
                </td>
            </tr>
        </table>
    </div>

    <div class="sheet">
        <h1>{{ $title }}</h1>

        @if (! empty($meta))
            <table class="meta">
                <tr>
                    @foreach ($meta as $label => $value)
                        <td>
                            <div class="label">{{ $label }}</div>
                            <div class="value">{{ $value }}</div>
                        </td>
                    @endforeach
                </tr>
            </table>
        @endif

        <table class="data">
            <thead>
                <tr>
                    {{-- Added here rather than by each report, so every sheet
                         numbers its lines the same way and no report has to
                         carry a counter of its own. --}}
                    <th style="width: 34px;">Item No.</th>

                    @foreach ($headings as $column => $heading)
                        <th>{{ $heading }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $line => $row)
                    <tr>
                        <td class="strong">{{ $line + 1 }}</td>

                        @foreach ($row as $column => $cell)
                            @php
                                $kind = $chipKind($cell);
                                $flag = $kind ? null : $flagFor($cell, $headings[$column] ?? '');
                            @endphp

                            <td @class(['strong' => $kind === null && $column === $last])>
                                @if ($kind)
                                    <span class="chip-status chip-{{ $kind }}">{{ $cell }}</span>
                                @else
                                    {{-- Read off disk rather than over a URL:
                                         this renders on the server with no
                                         browser and, at an event, often with
                                         no route off the hall's network. --}}
                                    @if ($flag)
                                        <img class="flag" src="{{ $flag }}" alt="">
                                    @endif

                                    {{ $cell }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td class="empty" colspan="{{ count($headings) + 1 }}">Nothing to report yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        {{-- No rows, no total: a sum of nothing is not information. --}}
        @if ($total && $rows !== [])
            <table class="total">
                <tr>
                    <td>{{ $total['label'] }}</td>
                    <td class="value">{{ $total['value'] }}</td>
                </tr>
            </table>
        @endif
    </div>

    <div class="foot-rule"></div>

    <div class="foot-left">
        {{-- On every sheet, in the same place: a page that leaves the venue
             should say who produced it. --}}
        <div class="strong">{{ config('branding.company') }}</div>
        @if ($footerLine)
            <div>{{ $footerLine }}</div>
        @endif
        <div>Generated {{ now()->format('j M Y H:i') }}</div>
    </div>

    {{-- Page N, not "N of M": this Dompdf resolves counter(pages) to zero,
         and a footer that says "page 1 of 0" is worse than one that does not
         count. --}}
    <div class="foot-right">Page <span class="page"></span></div>
</body>
</html>
