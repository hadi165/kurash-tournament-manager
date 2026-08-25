{{-- A round robin, on a wall.

     The hall gets the two things it can act on: which contests are still to
     come, and where the table stands. There is no tree here because there is
     nothing to advance through — every pairing was settled when the draw was
     made, and what changes as the session runs is the results and the order
     they put people in.

     Deliberately not the bracket view with the connectors switched off. A tree
     drawn over a round robin would show the same athlete in several
     unconnected boxes, which reads from thirty metres as a draw that has gone
     wrong. --}}
@php
    $title = $weightCategory->exportName();
@endphp

<x-display.layout :title="$title" :championship="$championship" :refresh="15">
    <x-slot:styles>
        <style>
            .rr {
                display: grid;
                gap: 1.5rem;
                grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr);
                align-items: start;
            }

            @media (max-width: 1100px) {
                .rr { grid-template-columns: minmax(0, 1fr); }
            }

            .rr__head {
                margin: 0 0 0.6rem;
                font-size: 0.85em;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.09em;
                color: var(--muted);
            }

            .rr__badge {
                display: inline-block;
                padding: 0.15rem 0.7rem;
                border-radius: 999px;
                background: var(--panel);
                border: 1px solid var(--line);
                font-size: 0.8em;
                font-weight: 700;
                letter-spacing: 0.06em;
                text-transform: uppercase;
                margin-bottom: 0.9rem;
            }

            .rr__round { margin-bottom: 1rem; }

            .rr__match {
                background: var(--panel);
                border: 1px solid var(--line);
                border-radius: 8px;
                margin-bottom: 0.4rem;
                overflow: hidden;
            }

            .rr__no {
                padding: 0.12rem 0.7rem;
                border-bottom: 1px solid var(--line);
                font-size: 0.7em;
                font-weight: 700;
                letter-spacing: 0.06em;
                color: var(--muted);
            }

            .rr__side { padding: 0.3rem 0.7rem; min-width: 0; }
            .rr__side + .rr__side { border-top: 1px solid var(--line); }
            .rr__side--won { font-weight: 700; color: var(--gold); }

            .rr__side .competitor { display: flex; align-items: center; }
            .rr__side .competitor > span:not(.noc) { min-width: 0; overflow-wrap: anywhere; }

            table.rr__table { width: 100%; border-collapse: collapse; }

            .rr__table th {
                text-align: left;
                font-size: 0.75em;
                text-transform: uppercase;
                letter-spacing: 0.08em;
                color: var(--muted);
                padding: 0.3rem 0.6rem;
                border-bottom: 1px solid var(--line);
            }

            .rr__table td {
                padding: 0.35rem 0.6rem;
                border-bottom: 1px solid var(--line);
                font-size: 0.95em;
            }

            .rr__table td.num { text-align: right; font-variant-numeric: tabular-nums; }
            .rr__table tr.gold td { color: var(--gold); font-weight: 700; }
            .rr__note { margin-top: 0.7rem; font-size: 0.85em; color: var(--muted); }
        </style>
    </x-slot:styles>

    @if ($rounds->isEmpty())
        <div class="empty">{{ __('This class has not been drawn yet.') }}</div>
    @else
        <div class="rr__badge">{{ __('Round Robin') }}</div>

        <div class="rr">
            <div>
                <h2 class="rr__head">{{ __('Contests') }}</h2>

                @foreach ($rounds as $round => $contests)
                    <div class="rr__round">
                        <h3 class="rr__head">{{ __('Round :n', ['n' => $round]) }}</h3>

                        @foreach ($contests as $bout)
                            <div class="rr__match">
                                <div class="rr__no">
                                    @if ($bout->fight_number)
                                        {{ __('No. :n', ['n' => $bout->fight_number]) }}
                                    @else
                                        {{ __('To be scheduled') }}
                                    @endif
                                </div>

                                @foreach ([[$bout->athleteA, $bout->athlete_a_id], [$bout->athleteB, $bout->athlete_b_id]] as [$athlete, $athleteId])
                                    @php($won = $athleteId !== null && $bout->winner_athlete_id === $athleteId)

                                    <div @class(['rr__side', 'rr__side--won' => $won])>
                                        <x-display.athlete :athlete="$athlete" fallback="—" />
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>

            <div>
                <h2 class="rr__head">
                    {{ __('Standings') }}
                    @if (! $standings['complete'])
                        — {{ __(':n of :total decided', [
                            'n' => $standings['contests']['decided'],
                            'total' => $standings['contests']['total'],
                        ]) }}
                    @endif
                </h2>

                <table class="rr__table">
                    <thead>
                        <tr>
                            <th>{{ __('#') }}</th>
                            <th>{{ __('Athlete') }}</th>
                            <th>{{ __('NOC') }}</th>
                            <th class="num">{{ __('P') }}</th>
                            <th class="num">{{ __('W') }}</th>
                            <th class="num">{{ __('L') }}</th>
                            <th class="num">{{ __('Pts') }}</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($standings['rows'] as $row)
                            <tr @class(['gold' => $row['medal'] === 'gold'])>
                                <td>{{ $row['rank'] }}</td>
                                <td><x-display.athlete :athlete="$row['athlete']" /></td>
                                <td>{{ $row['noc'] }}</td>
                                <td class="num">{{ $row['played'] }}</td>
                                <td class="num">{{ $row['wins'] }}</td>
                                <td class="num">{{ $row['losses'] }}</td>
                                <td class="num">{{ $row['points'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                @if ($standings['unresolved'] !== [])
                    <div class="rr__note">
                        {{ __('Athletes level on every tie-break in the rules. A technical decision is required.') }}
                    </div>
                @endif
            </div>
        </div>
    @endif
</x-display.layout>
