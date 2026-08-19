{{-- One certificate per page, landscape. Styles are inline because Dompdf
     resolves no external stylesheets, and the layout avoids flexbox for the
     same reason. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Certificates') }} — {{ $championship->title }}</title>
    <style>
        @page { margin: 0; }

        body {
            font-family: DejaVu Sans, sans-serif;   /* ships with Dompdf and covers Cyrillic */
            color: #14150f;
            margin: 0;
        }

        .sheet {
            position: relative;
            width: 297mm;
            height: 209mm;          /* a hair under A4 landscape: a full 210 spills to a blank page */
            page-break-after: always;
            text-align: center;
        }

        .sheet:last-child { page-break-after: auto; }

        /* Two nested rules rather than a border on the page box, which Dompdf
           positions inconsistently against @page margins. */
        .frame {
            position: absolute;
            top: 12mm; right: 12mm; bottom: 12mm; left: 12mm;
            border: 2pt solid #14150f;
        }

        .frame-inner {
            position: absolute;
            top: 3mm; right: 3mm; bottom: 3mm; left: 3mm;
            border: 0.75pt solid #9c6c15;
        }

        .body { position: absolute; top: 26mm; right: 26mm; left: 26mm; }

        .logo { height: 46px; margin-bottom: 6mm; }

        .org {
            font-size: 11pt;
            font-weight: bold;
            letter-spacing: 0.22em;
            text-transform: uppercase;
            margin-bottom: 10mm;
        }

        .kind {
            font-size: 26pt;
            font-weight: bold;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: #9c6c15;
            margin-bottom: 8mm;
        }

        .lead { font-size: 11pt; color: #4d4f45; margin-bottom: 4mm; }

        .name {
            font-size: 30pt;
            font-weight: bold;
            margin-bottom: 5mm;
        }

        .placing { font-size: 13pt; margin-bottom: 3mm; }
        .placing strong { text-transform: uppercase; letter-spacing: 0.05em; }

        .event { font-size: 12pt; color: #4d4f45; margin-bottom: 10mm; }
        .where { font-size: 10pt; color: #4d4f45; }

        .signatures {
            position: absolute;
            bottom: 24mm; right: 30mm; left: 30mm;
            font-size: 8.5pt;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #7c7f71;
        }

        /* A table, not floats: Dompdf leaks a float out of an absolutely
           positioned block and into the flow of the next page. */
        .signatures table { width: 100%; border-collapse: collapse; }
        .signatures td { width: 33%; text-align: center; padding-top: 3mm; }
        .signatures .rule { border-top: 0.75pt solid #cfcfc2; }

        .empty { padding: 60mm 20mm; font-size: 12pt; color: #666; font-style: italic; }
    </style>
</head>
<body>
@forelse ($certificates as $certificate)
    @php
        $athlete = $certificate['athlete'];
        $place = $certificate['place'];
        $ordinal = [1 => __('first'), 2 => __('second'), 3 => __('third')][$place] ?? $place;
    @endphp

    <div class="sheet">
        <div class="frame"><div class="frame-inner"></div></div>

        <div class="body">
            @if ($branding['logo'])
                <img class="logo" src="{{ $branding['logo'] }}" alt="">
            @endif

            <div class="org">{{ $branding['organisation'] }}</div>

            <div class="kind">{{ __('Certificate of Achievement') }}</div>

            <div class="lead">{{ __('This is to certify that') }}</div>

            <div class="name">{{ $athlete->fullname }}</div>

            <div class="placing">
                {{ __('representing') }} {{ $athlete->noc_name ?: $athlete->noc_code }},
                {{ __('placed') }} <strong>{{ $ordinal }}</strong>
            </div>

            <div class="event">{{ $certificate['category']->exportName() }}</div>

            <div class="where">
                {{ $championship->title }}
                @if ($championship->location) · {{ $championship->location }} @endif
                @if ($championship->starts_on) · {{ $championship->starts_on->format('j F Y') }} @endif
            </div>
        </div>

        <div class="signatures">
            <table>
                <tr>
                    <td class="rule">{{ __('President') }}</td>
                    <td class="rule">{{ __('Technical Delegate') }}</td>
                    <td class="rule">{{ $athlete->ika_id }}</td>
                </tr>
            </table>
        </div>
    </div>
@empty
    <div class="sheet">
        <div class="frame"><div class="frame-inner"></div></div>
        <div class="empty">{{ __('No weight class has been decided yet, so there is nothing to certify.') }}</div>
    </div>
@endforelse
</body>
</html>
