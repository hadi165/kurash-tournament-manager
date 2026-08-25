{{-- The round-robin sheet, as the federation files it.

     Four tables, in the order an official reads them: who was drawn, what
     everybody plays, how everybody got on against everybody, and where that
     leaves them. No tree, and nothing borrowed from the bracket sheet's
     geometry — there is nothing here for a connector to join.

     A4 portrait at every size, because five athletes is ten contests and a
     five-by-five matrix. The bracket's paper arithmetic exists because a tree
     grows with its field; this does not. --}}
@php
    $printLogo = \App\Support\PrintLogo::path();
    $meta = $sheet->meta();
    $standings = $sheet->standings();
    $matrix = $sheet->matrix();
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $sheet->filename() }}</title>
    <style>
        @page { margin: 26px 30px; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 9.5px;
            color: #111a16;
            margin: 0;
        }

        .head { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        .head td { vertical-align: top; border: 0; padding: 0; }

        .org { font-size: 11px; font-weight: bold; letter-spacing: 0.16em; text-transform: uppercase; color: #019a44; }
        .title { font-size: 18px; font-weight: bold; margin-top: 3px; }
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
        .chip-format { background: #eaf7ef; color: #046830; }
        .chip-count { background: #eef1f0; color: #5d6d67; }

        .logo { border: 1px solid #e2e8e5; border-radius: 5px; padding: 4px; }
        .logo img { height: 54px; }

        .rule { border-top: 3px solid #019a44; margin: 6px 0 10px; }

        h2 {
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #5d6d67;
            margin: 12px 0 4px;
        }

        table.grid { width: 100%; border-collapse: collapse; }

        .grid th {
            background: #019a44;
            color: #fff;
            font-size: 8.5px;
            font-weight: bold;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            text-align: left;
            padding: 3px 5px;
            border: 1px solid #b9c4bf;
        }

        .grid td {
            border: 1px solid #d6ded9;
            padding: 3px 5px;
        }

        .grid td.num { text-align: right; }
        .grid td.mid { text-align: center; }
        .grid tr.won td { font-weight: bold; }

        .flag { width: 12px; height: 9px; vertical-align: middle; margin-right: 3px; }
        .noc { font-weight: bold; color: #5d6d67; }
        .muted { color: #7d8b85; }

        .medal { font-weight: bold; }
        .medal-gold { color: #a5851d; }
        .medal-silver { color: #6f7a80; }
        .medal-bronze { color: #8d5c30; }

        .note {
            margin-top: 8px;
            padding-top: 5px;
            border-top: 1px solid #e0e5e3;
            font-size: 8px;
            color: #5d6d67;
        }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            <td>
                <div class="org">{{ config('branding.organisation') }}</div>
                <div class="title">{{ $meta['Competition'] ?? '' }}</div>

                <div style="margin-top: 5px;">
                    <span class="chip chip-cat">{{ $meta['Gender / Weight Category'] ?? '' }}</span>
                    {{-- The format, said plainly on the sheet: nobody reading
                         this later should have to infer it from the shape of
                         the tables. --}}
                    <span class="chip chip-format">{{ $sheet->formatLabel() }}</span>
                    <span class="chip chip-count">{{ $meta['Athletes'] ?? '0' }} athletes</span>
                </div>
            </td>

            <td style="text-align: right; width: 130px;">
                @if ($printLogo)
                    <span class="logo"><img src="{{ $printLogo }}" alt=""></span>
                @endif

                <div class="meta" style="margin-top: 5px;">
                    <b>{{ __('Round robin sheet') }}</b><br>
                    {{ $meta['Date'] ?? '' }}@if (! empty($meta['Venue'])) · {{ $meta['Venue'] }} @endif
                </div>
            </td>
        </tr>
    </table>

    <div class="rule"></div>

    <h2>{{ __('Draw') }}</h2>

    <table class="grid">
        <tr>
            <th style="width: 12%;">{{ __('Draw no.') }}</th>
            <th style="width: 20%;">{{ __("Athlete's ID (IKA)") }}</th>
            <th>{{ __('Athlete') }}</th>
            <th style="width: 14%;">{{ __('NOC') }}</th>
        </tr>

        @foreach ($sheet->athletes() as $athlete)
            <tr>
                <td class="mid">{{ $athlete['draw'] ?? '—' }}</td>
                <td>{{ $athlete['ika'] }}</td>
                <td>
                    @php($flag = \App\Support\PrintFlag::path($athlete['noc']))
                    @if ($flag)<img class="flag" src="{{ $flag }}" alt="{{ $athlete['noc'] }}">@endif
                    {{ $athlete['name'] }}
                </td>
                <td class="noc">{{ $athlete['noc'] }}</td>
            </tr>
        @endforeach
    </table>

    <h2>{{ __('Contests and fight order') }}</h2>

    <table class="grid">
        <tr>
            <th style="width: 9%;">{{ __('Round') }}</th>
            <th style="width: 12%;">{{ __('Fight') }}</th>
            <th>{{ __('Blue') }}</th>
            <th>{{ __('Green') }}</th>
            <th style="width: 18%;">{{ __('Result') }}</th>
        </tr>

        @foreach ($sheet->rounds() as $round => $contests)
            @foreach ($contests as $contest)
                <tr @class(['won' => $contest['decided']])>
                    <td class="mid">{{ $round }}</td>
                    <td class="mid">{{ $contest['fight'] !== '' ? $contest['fight'] : '—' }}</td>

                    <td>
                        @php($flagA = \App\Support\PrintFlag::path($contest['aNoc']))
                        @if ($flagA)<img class="flag" src="{{ $flagA }}" alt="{{ $contest['aNoc'] }}">@endif
                        {{ $contest['a'] }} <span class="noc">({{ $contest['aNoc'] }})</span>
                    </td>

                    <td>
                        @php($flagB = \App\Support\PrintFlag::path($contest['bNoc']))
                        @if ($flagB)<img class="flag" src="{{ $flagB }}" alt="{{ $contest['bNoc'] }}">@endif
                        {{ $contest['b'] }} <span class="noc">({{ $contest['bNoc'] }})</span>
                    </td>

                    <td>
                        @if ($contest['decided'])
                            {{ $contest['winner'] }}@if ($contest['result'] !== '') <span class="muted">· {{ $contest['result'] }}</span>@endif
                        @else
                            <span class="muted">{{ __('Pending') }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        @endforeach
    </table>

    <h2>{{ __('Results matrix') }}</h2>

    <table class="grid">
        <tr>
            <th>{{ __('Athlete') }}</th>
            @foreach ($matrix['athletes'] as $index => $name)
                <th class="mid" style="width: 8%;">{{ $index + 1 }}</th>
            @endforeach
        </tr>

        @foreach ($matrix['athletes'] as $row => $name)
            <tr>
                <td>{{ $row + 1 }}. {{ $name }} <span class="noc">({{ $matrix['nocs'][$row] }})</span></td>

                @foreach ($matrix['athletes'] as $column => $ignored)
                    <td class="mid">{{ $matrix['cells'][$row][$column] ?? '' }}</td>
                @endforeach
            </tr>
        @endforeach
    </table>

    <h2>{{ __('Standings') }}</h2>

    <table class="grid">
        <tr>
            <th style="width: 8%;">{{ __('Rank') }}</th>
            <th>{{ __('Athlete') }}</th>
            <th style="width: 10%;">{{ __('NOC') }}</th>
            <th style="width: 9%;">{{ __('Played') }}</th>
            <th style="width: 8%;">{{ __('Won') }}</th>
            <th style="width: 8%;">{{ __('Lost') }}</th>
            <th style="width: 9%;">{{ __('Points') }}</th>
            <th style="width: 20%;">{{ __('Standing') }}</th>
        </tr>

        @foreach ($standings['rows'] as $place)
            <tr>
                <td class="mid">{{ $place['rank'] }}</td>
                <td>
                    @php($flag = \App\Support\PrintFlag::path($place['noc']))
                    @if ($flag)<img class="flag" src="{{ $flag }}" alt="{{ $place['noc'] }}">@endif
                    {{ $place['athlete']->fullname }}
                </td>
                <td class="noc">{{ $place['noc'] }}</td>
                <td class="num">{{ $place['played'] }}</td>
                <td class="num">{{ $place['wins'] }}</td>
                <td class="num">{{ $place['losses'] }}</td>
                <td class="num">{{ $place['points'] }}</td>
                <td>
                    @if ($place['medal'])
                        <span class="medal medal-{{ $place['medal'] }}">{{ ucfirst($place['medal']) }}</span>
                    @elseif ($place['state'] === \App\Services\RoundRobinStandings::STATE_NEEDS_DECISION)
                        <span class="muted">{{ __('Level — decision required') }}</span>
                    @elseif (! $standings['complete'])
                        <span class="muted">{{ __('Provisional') }}</span>
                    @else
                        {{ __('Ranked') }}
                    @endif
                </td>
            </tr>
        @endforeach
    </table>

    {{-- The arithmetic, on the sheet. A table that ranks two athletes level on
         wins without saying what separated them is one nobody can check. --}}
    <div class="note">
        <b>{{ __('Points') }}:</b> {{ $sheet->pointsNote() }}
        &nbsp;·&nbsp;
        <b>{{ __('Tie-breaks, in order') }}:</b> {{ implode(' → ', $sheet->tieBreakNotes()) }}
        @if (! $standings['complete'])
            &nbsp;·&nbsp;
            {{ __(':n of :total contests decided. Placings are provisional and no medals are awarded.', [
                'n' => $standings['contests']['decided'],
                'total' => $standings['contests']['total'],
            ]) }}
        @endif
        @if ($standings['unresolved'] !== [])
            &nbsp;·&nbsp;
            {{ __('Athletes remain level on every tie-break in the rules. A technical or referee decision is required.') }}
        @endif
        <br>
        {{ __('Generated :when', ['when' => now()->format('j M Y H:i')]) }}
    </div>
</body>
</html>
