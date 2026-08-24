{{-- The draw sheet, as the federation files it.

     Dompdf places no absolute connectors and honours no pseudo-element, so
     every line in this tree is a cell border and nothing else.

     The sheet is ruled in half-seats — two rows per athlete — because a
     connector has to start and finish on the *centre* of the node it joins and
     a border can only be drawn on a cell edge. With half-rows, each centre line
     falls on an edge, and a branch of the tree becomes one cell carrying three
     borders:

         seat 0  ──┐          top     the horizontal arriving from the upper node
                   │          right   the vertical carrying between the pair
                   ├── out    bottom  the horizontal arriving from the lower one
                   │
         seat 1  ──┘          and the round to the right draws the line leaving
                              this one's centre, as its own top or bottom border

     So the joins are continuous by construction rather than by two segments
     meeting at a coordinate. BracketSheet works the rows out; this file only
     lays them down. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $sheet->filename() }}</title>
    <style>
        @page { margin: {{ $scale['margin'] }}px 26px; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 9px;
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
        .chip-size { background: #eaf7ef; color: #046830; }
        .chip-count { background: #eef1f0; color: #5d6d67; }

        /* The logo sits top right in its own box, as the design files it. */
        .logo { border: 1px solid #e2e8e5; border-radius: 5px; padding: 4px; }

        /* Height only: a forced square squashes artwork that is not one. */
        .logo img { height: {{ $scale['logo'] }}px; }

        .rule { border-top: 3px solid #019a44; margin: 6px 0 8px; }

        .tree { width: 100%; border-collapse: collapse; table-layout: fixed; }

        .tree th {
            font-size: {{ $scale['head'] }}px;
            font-weight: bold;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #5d6d67;
            text-align: left;
            padding: 0 0 5px 6px;
            border: 0;
        }

        /* Everything is borderless until a cell asks for a line. Written as
           `td.x` rather than `.x`: a bare class loses to `.tree td` on
           specificity, which is how a whole tree of connectors came to be
           drawn and then rubbed out again. */
        .tree td { border: 0; padding: 0; }

        /* A seat is written on its own centre line — the upper of its two rows,
           ruled underneath — so the horizontal the tree leaves by starts at the
           name rather than somewhere below it.

           The height is set here and not on the row: Dompdf takes a height from
           a cell and ignores one on a `tr`, which is why the whole tree used to
           print at a third of the page whatever it was told. */
        .tree td.seat, .tree td.seat-under {
            height: {{ $scale['halfRow'] }}px;
            padding-right: 3px;
        }

        .tree td.seat {
            border-bottom: 1px solid #b9c4bf;
            vertical-align: bottom;
        }

        .seat-no {
            display: inline-block;
            width: {{ $scale['badge'] }}px;
            padding: 1px 0;
            border-radius: 2px;
            color: #fff;
            font-size: {{ $scale['noc'] }}px;
            font-weight: bold;
            text-align: center;
        }

        .seat-blue { background: #1a9fd8; }
        .seat-green { background: #019a44; }

        .seat-name { font-size: {{ $scale['name'] }}px; font-weight: bold; padding-left: 4px; }
        .seat-noc { font-size: {{ $scale['noc'] }}px; font-weight: bold; color: #5d6d67; }

        /* Smaller than the one the tables fly: a seat is a narrow column with
           a badge and a name already in it. */
        .seat-flag { width: {{ $scale['flag'] }}px; height: {{ round($scale['flag'] * 0.75, 1) }}px; vertical-align: middle; margin-right: 3px; }
        .tree td.seat-bye .seat-name { font-weight: normal; color: #7d8b85; }

        /* A branch is three cells stacked, not one box — see BracketSheet's
           parts(). The vertical runs down all of them; the line from the upper
           node lands on the first and the line from the lower one on the last.
           Written as `td.x` for the same reason everything else here is: a bare
           class loses to `.tree td` and the borders vanish. */
        .tree td.branch { border-right: 1px solid #7d8b85; }
        .tree td.branch-top { border-top: 1px solid #7d8b85; }
        .tree td.branch-bottom { border-bottom: 1px solid #7d8b85; }

        /* Last, so it outranks the three above on source order — they are all
           the same specificity. */
        .tree td.branch-final { border-color: #019a44; }

        .tree td.align-top { vertical-align: top; }
        .tree td.align-bottom { vertical-align: bottom; }
        .tree td.fight-cell { text-align: center; vertical-align: bottom; }

        /* The fight number sits on the line the branch leaves by, which is the
           point of putting it here rather than beside the tree. */
        .fight {
            display: inline-block;
            min-width: {{ $scale['fight'] }}px;
            padding: 1px 4px;
            border: 1px solid #b9c4bf;
            border-radius: 3px;
            font-size: {{ $scale['noc'] }}px;
            font-weight: bold;
            background: #fff;
        }

        .fight-cell.branch-final .fight, .branch-final .fight { background: #eaf7ef; border-color: #019a44; }

        /* Whoever arrived on the line, written against the line they arrived
           on — which is the edge of the round they arrived *into*, not the one
           they came from. The background keeps the name legible where it
           crosses the rule. */
        .entrant {
            display: inline-block;
            padding: 0 3px;
            font-size: {{ $scale['name'] }}px;
            font-weight: bold;
            background: #fff;
        }

        /* The nation in brackets after the name. Lighter than the name it
           follows: the name is what is being read, and the code is what
           settles which of two athletes sharing one it is. */
        .entrant-noc { font-size: {{ $scale['noc'] }}px; color: #5d6d67; }

        /* The champion is a node of the tree, not a caption beside it: the
           final's own centre line runs on into it and the name is written on
           that line. */
        .tree td.champion {
            border-bottom: 1px solid #019a44;
            padding-left: 8px;
            vertical-align: bottom;
        }

        .champion-label {
            font-size: {{ $scale['head'] }}px;
            font-weight: bold;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #046830;
        }

        .champion-name { font-size: {{ $scale['name'] }}px; font-weight: bold; padding-bottom: 2px; }

        /* The foot is two columns: the key on the left, the podium on the
           right, sharing one rule. A `float` would be the obvious way to put
           the medals in the corner and the wrong one — Dompdf floats a table
           out of the flow and off the bottom of the page. */
        .foot {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            border-top: 1px solid #e0e5e3;
        }

        .foot td {
            border: 0;
            padding: 5px 0 0;
            vertical-align: bottom;
            font-size: 8px;
            color: #5d6d67;
        }

        /* The medals, bottom right. Fixed width because a shrink-to-fit table
           in Dompdf takes whatever width it likes. */
        .medals {
            border-collapse: collapse;
            width: {{ $scale['medals'] }}px;
        }

        .medals td, .medals th {
            border: 1px solid #d6ded9;
            padding: 1.5px 5px;
            font-size: {{ $scale['noc'] }}px;
            color: #111a16;
        }

        .medals th {
            background: #019a44;
            color: #fff;
            font-size: {{ $scale['head'] }}px;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            text-align: left;
        }

        /* The place is the medal: the column is coloured rather than captioned,
           so 1, 2, 3, 3 reads as gold, silver and two bronzes at a glance. */
        .medal-place {
            width: 14px;
            text-align: center;
            font-weight: bold;
            color: #fff;
        }

        .medal-1 { background: #c9a227; }
        .medal-2 { background: #9aa5ab; }
        .medal-3 { background: #a9713f; }

        .medal-name { font-weight: bold; }
        .medal-noc { width: 34px; font-weight: bold; color: #5d6d67; }
        .medal-blank { color: #9fada7; font-weight: normal; }

        .medal-flag { width: {{ $scale['flag'] }}px; height: {{ round($scale['flag'] * 0.75, 1) }}px; vertical-align: middle; margin-right: 3px; }

        .key { display: inline-block; width: 8px; height: 8px; border-radius: 2px; }
    </style>
</head>
<body>
    @php
        $printLogo = \App\Support\PrintLogo::path();
        $seats = $sheet->seats();
        $rounds = $sheet->rounds();
        $meta = $sheet->meta();
        $halfRows = $sheet->halfRows();
        $championRow = $sheet->championRow();

        // Every cell of the tree, keyed by the half-row it starts on, so a row
        // can ask what begins on it rather than searching for itself.
        $cells = [];

        for ($round = 1; $round <= $rounds; $round++) {
            foreach ($sheet->column($round) as $cell) {
                $cells[$cell['row']][$round] = $cell;
            }
        }
    @endphp

    <table class="head">
        <tr>
            <td>
                <div class="org">{{ config('branding.organisation') }}</div>
                <div class="title">{{ $meta['Competition'] ?? '' }}</div>

                <div style="margin-top: 5px;">
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
                    <span class="logo"><img src="{{ $printLogo }}" alt=""></span>
                @endif

                <div class="meta" style="margin-top: 5px;">
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
                <th style="width: {{ $scale['seatColumn'] }}%;">{{ __('Draw') }}</th>

                @for ($round = 1; $round <= $rounds; $round++)
                    <th style="width: {{ $scale['roundColumn'] }}%;">{{ $sheet->phase($round) }}</th>
                @endfor

                <th style="width: {{ $scale['championColumn'] }}%;">{{ __('Champion') }}</th>
            </tr>
        </thead>
        <tbody>
            @for ($row = 0; $row < $halfRows; $row++)
                <tr>
                    {{-- A seat is two rows tall, so its centre is a cell edge
                         and the tree has something to hang a line on. The name
                         goes in the upper row, on the rule; the lower row is
                         what gives the centre line something to be the edge
                         of. --}}
                    @if ($row % 2 === 0)
                        @php $seat = $seats[intdiv($row, 2)]; @endphp

                        <td class="seat {{ $seat['bye'] ? 'seat-bye' : '' }}">
                            <span class="seat-no {{ $seat['corner'] === 'blue' ? 'seat-blue' : 'seat-green' }}">{{ $seat['seed'] }}</span>
                            <span class="seat-name">{{ $seat['name'] }}</span>
                            @php $seatFlag = \App\Support\PrintFlag::path($seat['noc']); @endphp
                            @if ($seatFlag)
                                <img class="seat-flag" src="{{ $seatFlag }}" alt="">
                            @endif
                            <span class="seat-noc">{{ $seat['noc'] }}</span>
                        </td>
                    @else
                        <td class="seat-under"></td>
                    @endif

                    @for ($round = 1; $round <= $rounds; $round++)
                        {{-- A blank cell is still a cell: a hole would shift
                             the column below it. --}}
                        @if (isset($cells[$row][$round]))
                            @php $cell = $cells[$row][$round]; @endphp

                            <td rowspan="{{ $cell['span'] }}" @class([
                                'branch' => $cell['kind'] !== 'blank',
                                'branch-top' => $cell['top'],
                                'branch-bottom' => $cell['bottom'],
                                'branch-final' => $cell['final'],
                                'fight-cell' => $cell['kind'] === 'fight',
                                'align-top' => $cell['kind'] !== 'fight' && $cell['align'] === 'top',
                                'align-bottom' => $cell['kind'] !== 'fight' && $cell['align'] === 'bottom',
                            ])>
                                @if ($cell['text'] === '')
                                    {{-- Nothing decided yet, and the line is
                                         left clear for somebody to write on. --}}
                                @elseif ($cell['kind'] === 'fight')
                                    <span class="fight">{{ $cell['text'] }}</span>
                                @else
                                    @php $entrantFlag = \App\Support\PrintFlag::path($cell['noc']); @endphp

                                    <span class="entrant">
                                        @if ($entrantFlag)
                                            <img class="seat-flag" src="{{ $entrantFlag }}" alt="{{ $cell['noc'] }}">
                                        @endif
                                        {{ $cell['text'] }}
                                        @if ($cell['noc'] !== '')
                                            <span class="entrant-noc">({{ $cell['noc'] }})</span>
                                        @endif
                                    </span>
                                @endif
                            </td>
                        @endif
                    @endfor

                    {{-- The champion's line is the final's own centre line,
                         carried on to the right edge of the sheet. --}}
                    @if ($row === 0)
                        <td class="champion" rowspan="{{ $championRow }}">
                            <div class="champion-label">{{ __('Champion') }}</div>
                            <div class="champion-name">
                                @if ($sheet->champion() === '')
                                    —
                                @else
                                    @php $championFlag = \App\Support\PrintFlag::path($sheet->championNoc()); @endphp
                                    @if ($championFlag)
                                        <img class="seat-flag" src="{{ $championFlag }}" alt="{{ $sheet->championNoc() }}">
                                    @endif
                                    {{ $sheet->champion() }}
                                    @if ($sheet->championNoc() !== '')
                                        <span class="entrant-noc">({{ $sheet->championNoc() }})</span>
                                    @endif
                                @endif
                            </div>
                        </td>
                    @elseif ($row === $championRow)
                        <td rowspan="{{ $halfRows - $championRow }}"></td>
                    @endif
                </tr>
            @endfor
        </tbody>
    </table>

    @php $podium = $sheet->podium(); @endphp

    <table class="foot">
        <tr>
            <td>
                <span class="key" style="background: #1a9fd8;"></span> {{ __('Yakhtak Blue') }}
                &nbsp;&nbsp;
                <span class="key" style="background: #019a44;"></span> {{ __('Yakhtak Green') }}
                &nbsp;&nbsp;·&nbsp;&nbsp;
                {{ __('Fight numbers follow the published running order.') }}
                &nbsp;&nbsp;·&nbsp;&nbsp;
                {{ __('Generated :when', ['when' => now()->format('j M Y H:i')]) }}
            </td>

            {{-- The podium, in the bottom right corner: two places and the two
                 bronzes, the champion's beaten semi-finalist listed first. --}}
            @if ($podium !== [])
                <td style="width: {{ $scale['medals'] }}px; text-align: right;">
                    <table class="medals">
                        <tr><th colspan="3">{{ __('Medals') }}</th></tr>

                        @foreach ($podium as $place)
                            <tr>
                                <td class="medal-place medal-{{ $place['place'] }}">{{ $place['place'] }}</td>

                                <td class="medal-name">
                                    @if ($place['name'] === '')
                                        <span class="medal-blank">{{ __('not yet decided') }}</span>
                                    @else
                                        @php $medalFlag = \App\Support\PrintFlag::path($place['noc']); @endphp
                                        @if ($medalFlag)
                                            <img class="medal-flag" src="{{ $medalFlag }}" alt="{{ $place['noc'] }}">
                                        @endif
                                        {{ $place['name'] }}
                                    @endif
                                </td>

                                <td class="medal-noc">{{ $place['noc'] }}</td>
                            </tr>
                        @endforeach
                    </table>
                </td>
            @endif
        </tr>
    </table>
</body>
</html>
