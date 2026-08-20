{{-- Every PDF export renders through this one template so all federation
     paperwork shares a layout. Styles are inline because Dompdf resolves no
     external stylesheets, and every rule here is one Dompdf actually supports:
     no flexbox, no web fonts, no absolute connectors. Where the design uses a
     flex row, this template uses a layout table. --}}
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
            background: #019a44;
            color: #fff;
        }

        .band table { width: 100%; border-collapse: collapse; }
        .band td { padding: 20px 40px; vertical-align: middle; border: 0; }

        /* The logo always sits on a white chip and is never recoloured: the
           artwork is the federation's. */
        .chip {
            background: #fff;
            padding: 5px;
            border-radius: 3px;
        }

        .org { font-size: 14px; font-weight: bold; }

        .org-sub {
            font-size: 8.5px;
            font-weight: bold;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: #cfeadb;
        }

        .doc-tag {
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: #e4f5ea;
        }

        .doc-ref {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 9px;
            color: #c8e7d6;
        }

        /* ── Title and meta ──────────────────────────────────────────────── */

        .sheet { padding: 0 40px; }

        h1 {
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

        .meta td { padding: 0 34px 16px 0; vertical-align: top; border: 0; }

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
            background: #019a44;
            color: #fff;
            font-size: 9px;
            font-weight: bold;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            text-align: left;
            padding: 9px 10px;
            border-right: 1px solid #35ac66;
        }

        .data th.num { text-align: right; }
        .data th:last-child { border-right: 0; }

        /* Only a bottom hairline: a full grid turns a start list into graph
           paper and hides the one line somebody is looking for. */
        .data td {
            font-size: 11px;
            padding: 8px 10px;
            border-bottom: 1px solid #eceeed;
            vertical-align: top;
        }

        .data td.num { text-align: right; }
        .data td.strong { font-weight: bold; }

        .data tbody tr:nth-child(even) td { background: #f7f9f8; }

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
            border-top: 2px solid #019a44;
        }

        .total td {
            padding: 10px;
            font-size: 11px;
            font-weight: bold;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            border: 0;
        }

        .total .value { text-align: right; }

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
            border-top: 2px solid #019a44;
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
                            @if ($printLogo)
                                <td style="padding: 0; width: 48px;">
                                    <span class="chip"><img src="{{ $printLogo }}" alt="" style="width: 38px; height: 38px;"></span>
                                </td>
                            @endif
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
                    @foreach ($headings as $column => $heading)
                        <th @class(['num' => $isNumeric[$column]])>{{ $heading }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        @foreach ($row as $column => $cell)
                            @php $kind = $chipKind($cell); @endphp

                            <td @class([
                                'num' => $isNumeric[$column] ?? false,
                                'strong' => $kind === null && ($column === 0 || $column === $last),
                            ])>
                                @if ($kind)
                                    <span class="chip-status chip-{{ $kind }}">{{ $cell }}</span>
                                @else
                                    {{ $cell }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td class="empty" colspan="{{ max(1, count($headings)) }}">Nothing to report yet.</td>
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
        @if ($footerLine)
            <div class="strong">{{ $footerLine }}</div>
        @endif
        <div>Generated {{ now()->format('j M Y H:i') }}</div>
    </div>

    {{-- Page N, not "N of M": this Dompdf resolves counter(pages) to zero,
         and a footer that says "page 1 of 0" is worse than one that does not
         count. --}}
    <div class="foot-right">Page <span class="page"></span></div>
</body>
</html>
