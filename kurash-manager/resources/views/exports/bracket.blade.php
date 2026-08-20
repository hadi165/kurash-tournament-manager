{{-- The draw sheet, as the federation files it.

     Dompdf cannot place absolute connectors, so the tree is a layout table:
     one row per bracket seat, one cell per round spanning 2^r rows, and the
     connectors drawn as cell borders. The geometry is exactly what rowspan was
     made for, and the result is identical to the design. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $sheet->filename() }}</title>
    <style>
        @page { margin: 22px 26px; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 9px;
            color: #111a16;
            margin: 0;
        }

        .head { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .head td { vertical-align: top; border: 0; padding: 0; }

        .org { font-size: 11px; font-weight: bold; letter-spacing: 0.16em; text-transform: uppercase; color: #019a44; }
        .title { font-size: 20px; font-weight: bold; margin-top: 3px; }
        .meta { font-size: 9px; color: #5d6d67; margin-top: 4px; }

        .chip {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 8.5px;
            font-weight: bold;
            margin-right: 4px;
        }

        .chip-cat { background: #e8f6fd; color: #086690; }
        .chip-size { background: #eaf7ef; color: #046830; }
        .chip-count { background: #eef1f0; color: #5d6d67; }

        /* The logo sits top right in its own box, as the design files it. */
        .logo { border: 1px solid #e2e8e5; border-radius: 5px; padding: 4px; }

        .rule { border-top: 3px solid #019a44; margin: 8px 0 10px; }

        .tree { width: 100%; border-collapse: collapse; table-layout: fixed; }

        .tree th {
            font-size: 8px;
            font-weight: bold;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #5d6d67;
            text-align: left;
            padding: 0 0 6px 6px;
            border: 0;
        }

        .tree td { border: 0; padding: 0; }

        /* One seat: the number in its corner colour, the name on a ruled
           baseline, the NOC at the right of it. */
        /* Row height follows the bracket: a draw of eight should fill its
           sheet, and a draw of thirty-two should still fit on one. */
        .seat { border-bottom: 1px solid #b9c4bf; height: {{ $sheet->size() >= 32 ? 20 : 40 }}px; }

        .seat-no {
            display: inline-block;
            width: 18px;
            padding: 2px 0;
            border-radius: 2px;
            color: #fff;
            font-size: 8.5px;
            font-weight: bold;
            text-align: center;
        }

        .seat-blue { background: #1a9fd8; }
        .seat-green { background: #019a44; }

        .seat-name { font-size: 9.5px; font-weight: bold; padding-left: 4px; }
        .seat-noc { font-size: 8.5px; font-weight: bold; color: #5d6d67; }
        .seat-bye .seat-name { font-weight: normal; color: #7d8b85; }

        /* The connector: a bar down the left edge of the cell the match spans,
           closed at the bottom, with the fight-number box centred on it. */
        .match {
            border-left: 1px solid #b9c4bf;
            border-bottom: 1px solid #b9c4bf;
            text-align: center;
            vertical-align: middle;
        }

        .fight {
            display: inline-block;
            min-width: 42px;
            padding: 2px 5px;
            border: 1px solid #b9c4bf;
            border-radius: 3px;
            font-size: 8.5px;
            font-weight: bold;
            background: #fff;
        }

        .match-final .fight { background: #eaf7ef; border-color: #019a44; }

        .champion { border-left: 1px solid #b9c4bf; vertical-align: middle; text-align: center; }

        .champion-label {
            font-size: 8px;
            font-weight: bold;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #046830;
        }

        .champion-line { border-bottom: 1px solid #b9c4bf; margin: 4px 8px 0; height: 14px; font-weight: bold; }

        .foot {
            margin-top: 12px;
            padding-top: 6px;
            border-top: 1px solid #e0e5e3;
            font-size: 8px;
            color: #5d6d67;
        }

        .key { display: inline-block; width: 8px; height: 8px; border-radius: 2px; }
    </style>
</head>
<body>
    @php
        $printLogo = \App\Support\PrintLogo::path();
        $seats = $sheet->seats();
        $rounds = $sheet->rounds();
        $meta = $sheet->meta();

        // Every match square, keyed by the row it starts on, so each row can
        // ask which cells begin on it.
        $matches = [];

        for ($round = 1; $round <= $rounds; $round++) {
            foreach ($sheet->matches($round) as $match) {
                $matches[$match['row']][$round] = $match;
            }
        }
    @endphp

    <table class="head">
        <tr>
            <td>
                <div class="org">{{ config('branding.organisation') }}</div>
                <div class="title">{{ $meta['Competition'] ?? '' }}</div>

                <div style="margin-top: 6px;">
                    <span class="chip chip-cat">{{ $meta['Gender / Weight Category'] ?? '' }}</span>
                    <span class="chip chip-size">{{ $meta['Bracket'] ?? '' }}</span>
                    <span class="chip chip-count">
                        {{ $sheet->category->draw_athlete_count ?? count($seats) }} athletes ·
                        {{ $sheet->category->draw_bye_count ?? 0 }} byes
                    </span>
                </div>
            </td>

            <td style="text-align: right; width: 130px;">
                @if ($printLogo)
                    <span class="logo"><img src="{{ $printLogo }}" alt="" style="width: 56px; height: 56px;"></span>
                @endif

                <div class="meta" style="margin-top: 6px;">
                    <b>{{ __('Draw sheet') }}</b><br>
                    {{ $meta['Date'] ?? '' }}@if (! empty($meta['Venue'])) · {{ $meta['Venue'] }} @endif
                </div>
            </td>
        </tr>
    </table>

    <div class="rule"></div>

    <table class="tree">
        <thead>
            <tr>
                <th style="width: 150px;">{{ __('Draw') }}</th>
                @for ($round = 1; $round <= $rounds; $round++)
                    <th>{{ $sheet->phase($round) }}</th>
                @endfor
                <th style="width: 110px;">{{ __('Champion') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($seats as $row => $seat)
                <tr>
                    <td class="seat {{ $seat['bye'] ? 'seat-bye' : '' }}">
                        <span class="seat-no {{ $seat['corner'] === 'blue' ? 'seat-blue' : 'seat-green' }}">{{ $seat['seed'] }}</span>
                        <span class="seat-name">{{ $seat['name'] }}</span>
                        <span class="seat-noc">{{ $seat['noc'] }}</span>
                    </td>

                    @for ($round = 1; $round <= $rounds; $round++)
                        @if (isset($matches[$row][$round]))
                            @php $match = $matches[$row][$round]; @endphp

                            <td class="match {{ $round === $rounds ? 'match-final' : '' }}" rowspan="{{ $match['span'] }}">
                                @if ($match['fight'] !== '')
                                    <span class="fight">{{ $match['fight'] }}</span>
                                @endif
                            </td>
                        @endif
                    @endfor

                    @if ($row === 0)
                        <td class="champion" rowspan="{{ count($seats) }}">
                            <div class="champion-label">{{ __('Champion') }}</div>
                            <div class="champion-line">{{ $sheet->champion() }}</div>
                        </td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="foot">
        <span class="key" style="background: #1a9fd8;"></span> {{ __('Blue corner') }}
        &nbsp;&nbsp;
        <span class="key" style="background: #019a44;"></span> {{ __('Green corner') }}
        &nbsp;&nbsp;·&nbsp;&nbsp;
        {{ __('Fight numbers follow the published running order.') }}
        &nbsp;&nbsp;·&nbsp;&nbsp;
        {{ __('Generated :when', ['when' => now()->format('j M Y H:i')]) }}
    </div>
</body>
</html>
