{{-- The bracket, on a wall.

     The same tree the officials work on, drawn by the same geometry — see
     partials/bracket-geometry. What differs is the node: nothing here is
     pressed, so a match carries the two names, the number the running order
     gave it, and nothing else. --}}
@php
    $title = $weightCategory->exportName();

    // The tree does not stop at the final: the last bout feeds a node of its
    // own, which is what the champion column is.
    $finalBout = $rounds->last()?->first();
    $champion = $finalBout?->winner;
@endphp

<x-display.layout :title="$title" :championship="$championship" :refresh="15">
    <x-slot:styles>
        <style>@include('partials.bracket-geometry')</style>

        <style>
            /* Read across a hall, but a bracket of thirty-two is a lot of
               columns: the tree sets its own size rather than inheriting the
               display's reading size, and the page scrolls sideways. */
            .bkt {
                font-size: 0.8rem;
                --bkt-gutter: 2rem;
            }

            .bkt__round { min-width: 16rem; }

            .bkt__head {
                margin: 0 0 0.6rem;
                font-size: 0.85em;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.09em;
                color: var(--muted);
                line-height: 1.6;
            }

            .bkt__match {
                background: var(--panel);
                border: 1px solid var(--line);
                border-radius: 8px;
                overflow: hidden;
            }

            .bkt__match--bye { opacity: 0.55; }

            .bkt__match--champion { border-color: var(--gold); }

            /* The fight number, on its own line above the names: a strip
               rather than a badge, so a long name never has to share a row
               with it. */
            .bkt__no {
                padding: 0.15rem 0.7rem;
                border-bottom: 1px solid var(--line);
                font-size: 0.72em;
                font-weight: 700;
                letter-spacing: 0.06em;
                color: var(--muted);
            }

            .bkt__side {
                padding: 0.35rem 0.7rem;
                min-width: 0;
            }

            .bkt__side + .bkt__side { border-top: 1px solid var(--line); }

            .bkt__side--won { font-weight: 700; color: var(--gold); }

            /* The name gives way before the flag or the code does: those are
               fixed-width and the name is not. */
            .bkt__side .competitor { display: flex; }

            .bkt__side .competitor > span:not(.noc) {
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .bkt__champ { padding: 0.45rem 0.7rem; }
        </style>
    </x-slot:styles>

    @if ($rounds->isEmpty())
        <div class="empty">{{ __('This class has not been drawn yet.') }}</div>
    @else
        {{-- Scrolls rather than squeezing: a round dropped off the right is a
             round nobody in the hall can see. The width counts the champion. --}}
        <div class="scroll">
            <div class="bkt" style="--bkt-line: var(--line); min-width: {{ (max(1, $totalRounds) + 1) * 18 }}rem">
                @foreach ($rounds as $round => $roundBouts)
                    <div class="bkt__round">
                        <h2 class="bkt__head">{{ $roundBouts->first()->phase($totalRounds) }}</h2>

                        <div class="bkt__slots">
                            @foreach ($roundBouts as $bout)
                                <div class="bkt__slot">
                                    <div @class(['bkt__match', 'bkt__match--bye' => $bout->is_bye])>
                                        {{-- A bye has no contest to number, and
                                             an unscheduled one has no number
                                             yet. Neither leaves a bar behind. --}}
                                        @if (! $bout->is_bye && $bout->fight_number)
                                            <div class="bkt__no">{{ __('No. :n', ['n' => $bout->fight_number]) }}</div>
                                        @endif

                                        @foreach ([[$bout->athleteA, $bout->athlete_a_id], [$bout->athleteB, $bout->athlete_b_id]] as [$athlete, $athleteId])
                                            @php($isWinner = $athleteId !== null && $bout->winner_athlete_id === $athleteId)

                                            <div @class(['bkt__side', 'bkt__side--won' => $isWinner])>
                                                <x-display.athlete
                                                    :athlete="$athlete"
                                                    :fallback="$bout->is_bye ? __('Bye') : '—'"
                                                />
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                {{-- The champion: a node the final connects to, drawn the same
                     way every other node is. --}}
                <div class="bkt__round bkt__round--last bkt__round--champion">
                    <h2 class="bkt__head">{{ __('Champion') }}</h2>

                    <div class="bkt__slots">
                        <div class="bkt__slot">
                            <div @class(['bkt__match', 'bkt__match--champion' => $champion !== null])>
                                <div class="bkt__no">{{ __('Winner') }}</div>

                                <div class="bkt__champ">
                                    @if ($champion)
                                        <span class="win"><x-display.athlete :athlete="$champion" /></span>
                                    @else
                                        <span class="muted">{{ __('To be decided') }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</x-display.layout>
