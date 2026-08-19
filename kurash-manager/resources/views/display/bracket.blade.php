@php($title = $weightCategory->exportName())

<x-display.layout :title="$title" :championship="$championship" :refresh="15">
    @if ($rounds->isEmpty())
        <div class="empty">{{ __('This class has not been drawn yet.') }}</div>
    @else
        <div class="scroll">
            <div style="display:flex; gap:1.25rem; align-items:flex-start; min-width:{{ max(1, $totalRounds) * 17 }}rem">
                @foreach ($rounds as $round => $roundBouts)
                    <div style="flex:1; min-width:15rem">
                        <h2 style="font-size:0.8rem; text-transform:uppercase; letter-spacing:0.09em; color:var(--muted); margin:0 0 0.6rem">
                            {{ $roundBouts->first()->phase($totalRounds) }}
                        </h2>

                        @foreach ($roundBouts as $bout)
                            <div class="panel" @style(['margin-bottom:0.6rem', 'padding:0.6rem 0.8rem', 'opacity:0.55' => $bout->is_bye])>
                                @foreach ([['a', $bout->athleteA, $bout->athlete_a_id], ['b', $bout->athleteB, $bout->athlete_b_id]] as [$side, $athlete, $athleteId])
                                    @php($isWinner = $athleteId !== null && $bout->winner_athlete_id === $athleteId)

                                    <div @style([
                                        'padding:0.2rem 0',
                                        'border-bottom:1px solid var(--line)' => $side === 'a',
                                        'font-weight:700; color:var(--gold)' => $isWinner,
                                    ])>
                                        <x-display.athlete :athlete="$athlete" :fallback="$bout->is_bye ? __('Bye') : '—'" />
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</x-display.layout>
