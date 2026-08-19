{{-- Entrance cards, four to an A4 page, laid out as a table because Dompdf
     has no flexbox and floats leak between pages. Cut lines are the cell
     borders. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Accreditation') }} — {{ $championship->title }}</title>
    <style>
        @page { margin: 8mm; }

        body {
            font-family: DejaVu Sans, sans-serif;   /* covers Cyrillic, which the delegation lists need */
            font-size: 8pt;
            color: #14150f;
            margin: 0;
        }

        table.grid { width: 100%; border-collapse: collapse; }
        table.grid > tr > td, table.grid td.cell { width: 50%; padding: 3mm; vertical-align: top; }

        .card {
            border: 0.75pt solid #14150f;
            height: 62mm;
            position: relative;
        }

        .card .top {
            background: #14150f;
            color: #ffffff;
            padding: 2.5mm 3mm;
            font-size: 6.5pt;
            font-weight: bold;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            line-height: 1.5;
        }

        .card .body { padding: 3mm; }

        /* Photo and details side by side; a table because Dompdf drops
           inline-block widths at this scale. */
        .who { width: 100%; border-collapse: collapse; }
        .who td { vertical-align: top; padding: 0; }

        .photo {
            width: 20mm; height: 25mm;
            border: 0.5pt solid #cfcfc2;
            background: #e4e4db;
        }
        .photo img { width: 20mm; height: 25mm; }

        .details { padding-left: 3mm; }
        .name { font-size: 12pt; font-weight: bold; line-height: 1.15; }
        .idline { font-size: 7.5pt; color: #4d4f45; margin-top: 1.5mm; }
        .role {
            font-size: 8pt;
            font-weight: bold;
            color: #1f4e8c;
            margin-top: 1.5mm;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .foot {
            position: absolute;
            bottom: 0; left: 0; right: 0;
            border-top: 0.5pt solid #cfcfc2;
            padding: 2mm 3mm;
        }

        .foot table { width: 100%; border-collapse: collapse; }
        .foot td { vertical-align: middle; padding: 0; }

        .zone {
            display: inline-block;
            width: 5mm; height: 5mm;
            border: 0.5pt solid #cfcfc2;
            background: #e4e4db;
            color: #7c7f71;
            text-align: center;
            font-size: 7pt;
            line-height: 5mm;
            margin-right: 1mm;
        }

        .zone.on { background: #9c6c15; border-color: #9c6c15; color: #ffffff; font-weight: bold; }

        .qr { width: 16mm; height: 16mm; }

        .conditions {
            font-size: 5.5pt;
            color: #7c7f71;
            line-height: 1.35;
            margin-top: 1.5mm;
        }

        .empty { padding: 20mm; text-align: center; font-style: italic; color: #666; }
    </style>
</head>
<body>
@php $chunks = collect($cards)->chunk(4); @endphp

@forelse ($chunks as $chunk)
    <table class="grid">
        @foreach ($chunk->chunk(2) as $pair)
            <tr>
                @foreach ($pair as $card)
                    @php $athlete = $card['athlete']; @endphp
                    <td class="cell">
                        <div class="card">
                            <div class="top">
                                {{ $branding['organisation'] }}<br>
                                {{ $championship->title }}
                                @if ($championship->location) · {{ $championship->location }} @endif
                            </div>

                            <div class="body">
                                <table class="who">
                                    <tr>
                                        <td style="width: 20mm;">
                                            @if ($athlete->photo_url && is_file(public_path($athlete->photo_url)))
                                                <img class="photo" src="{{ public_path($athlete->photo_url) }}" alt="">
                                            @else
                                                <div class="photo"></div>
                                            @endif
                                        </td>
                                        <td class="details">
                                            <div class="name">{{ $athlete->fullname }}</div>
                                            <div class="idline">{{ $athlete->ika_id }}</div>
                                            <div class="idline">
                                                {{ $athlete->noc_code }}
                                                @if ($athlete->weightCategory)
                                                    · {{ $athlete->weightCategory->label }} kg
                                                @endif
                                            </div>
                                            @if ($athlete->club)
                                                <div class="idline">{{ $athlete->club }}</div>
                                            @endif
                                            <div class="role">{{ $athlete->position_title ?: __('Athlete') }}</div>
                                        </td>
                                    </tr>
                                </table>
                            </div>

                            <div class="foot">
                                <table>
                                    <tr>
                                        <td>
                                            @foreach ($zones as $number => $description)
                                                <span class="zone {{ in_array($number, $card['areas'], true) ? 'on' : '' }}">{{ $number }}</span>
                                            @endforeach

                                            <div class="conditions">
                                                {{ __('Valid only with photographic identification. Non-transferable. Must be worn and visible at all times within the venue. The organising committee may withdraw this card at any time.') }}
                                            </div>
                                        </td>
                                        <td style="width: 16mm; text-align: right;">
                                            @if ($card['qr'])
                                                <img class="qr" src="{{ $card['qr'] }}" alt="{{ $athlete->ika_id }}">
                                            @endif
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </td>
                @endforeach

                @if ($pair->count() === 1)
                    <td class="cell"></td>
                @endif
            </tr>
        @endforeach
    </table>

    @if (! $loop->last)
        <div style="page-break-after: always;"></div>
    @endif
@empty
    <div class="empty">{{ __('Nobody is registered yet, so there are no cards to print.') }}</div>
@endforelse
</body>
</html>
