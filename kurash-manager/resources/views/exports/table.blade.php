{{-- Every PDF export renders through this one template so all federation
     paperwork shares a layout. Styles are inline because Dompdf resolves no
     external stylesheets. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 18mm 14mm; }

        body {
            font-family: DejaVu Sans, sans-serif;   /* ships with Dompdf and covers Cyrillic */
            font-size: 9.5pt;
            color: #111;
        }

        .association {
            text-align: center;
            font-size: 12pt;
            font-weight: bold;
            letter-spacing: 0.06em;
            border-bottom: 2px solid #111;
            padding-bottom: 5px;
            margin-bottom: 10px;
        }

        /* Vertically centred against the wordmark. Dompdf has no flexbox, so
           the logo is an inline image with an explicit height. */
        .association img { height: 34px; vertical-align: middle; margin-right: 8px; }

        /* Stated explicitly: Dompdf leaks the centred alignment of the header
           above onto the blocks that follow it, which indents the title and
           meta away from the table they belong to. */
        h1 { font-size: 12pt; margin: 0 0 6px; text-align: left; }

        .meta { margin: 0 0 12px; text-align: left; }
        .meta div { margin-bottom: 2px; }
        .meta span { font-weight: bold; }

        table { width: 100%; border-collapse: collapse; }

        thead { display: table-header-group; }   /* repeat headings on every page */

        th, td {
            border: 0.5pt solid #999;
            padding: 4px 6px;
            text-align: left;
            vertical-align: top;
        }

        th { background: #eceff1; font-weight: bold; }

        tbody tr:nth-child(even) td { background: #f7f8f9; }

        .empty { text-align: center; padding: 18px; color: #666; font-style: italic; }

        /* Two separately anchored elements rather than one footer containing
           floats. Dompdf leaks a float out of a fixed block and into the main
           flow, which indented the title and meta away from the table. */
        .foot-left, .foot-right {
            position: fixed;
            bottom: -12mm;
            font-size: 7.5pt;
            color: #666;
        }

        .foot-left { left: 0; }
        .foot-right { right: 0; }

        .page::after { content: counter(page); }
    </style>
</head>
<body>
    {{-- Required on every page after the welcome screen. --}}
    @php
        // Dompdf reads from disk, not over HTTP, and needs GD for raster
        // artwork — PrintLogo returns a path only when it can actually be
        // drawn, so a server without GD prints the header without the mark
        // instead of failing the whole document.
        $printLogo = \App\Support\PrintLogo::path();
    @endphp

    <div class="association">
        @if ($printLogo)
            <img src="{{ $printLogo }}" alt="">
        @endif
        {{ config('branding.organisation') }}
    </div>

    <h1>{{ $title }}</h1>

    @if (! empty($meta))
        <div class="meta">
            @foreach ($meta as $label => $value)
                <div><span>{{ $label }}:</span> {{ $value }}</div>
            @endforeach
        </div>
    @endif

    <table>
        <thead>
            <tr>
                @foreach ($headings as $heading)
                    <th>{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td class="empty" colspan="{{ max(1, count($headings)) }}">Nothing to report yet.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="foot-left">Printed {{ now()->format('Y-m-d H:i') }}</div>
    <div class="foot-right">Page <span class="page"></span></div>
</body>
</html>
