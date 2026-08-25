{{-- A class with one entrant, on a wall.

     There is nothing to show a schedule of and nothing to rank, so the screen
     says the only two true things: who is entered, and whether the class has
     been settled. It never says "champion" on its own — that is an
     administrator's decision, recorded on the class, and until it is made this
     board says the class is awaiting one. --}}
@php
    $title = $weightCategory->exportName();
    $placed = $weightCategory->placedAthlete;
@endphp

<x-display.layout :title="$title" :championship="$championship" :refresh="20">
    <x-slot:styles>
        <style>
            .solo {
                max-width: 46rem;
                background: var(--panel);
                border: 1px solid var(--line);
                border-radius: 10px;
                padding: 1.6rem 1.8rem;
            }

            .solo__badge {
                display: inline-block;
                padding: 0.15rem 0.7rem;
                border-radius: 999px;
                border: 1px solid var(--line);
                font-size: 0.8em;
                font-weight: 700;
                letter-spacing: 0.06em;
                text-transform: uppercase;
                color: var(--muted);
            }

            .solo__name { margin-top: 0.9rem; font-size: 1.6em; font-weight: 700; }
            .solo__name.won { color: var(--gold); }
            .solo__note { margin-top: 0.7rem; color: var(--muted); }
        </style>
    </x-slot:styles>

    <div class="solo">
        <span class="solo__badge">{{ __('Single entrant') }}</span>

        <div class="solo__name {{ $placed ? 'won' : '' }}">
            @if ($placed)
                <x-display.athlete :athlete="$placed" />
            @else
                <x-display.athlete :athlete="$weightCategory->drawnAthletes()->first()" fallback="—" />
            @endif
        </div>

        <div class="solo__note">
            @if ($placed)
                {{ __('Placed first by decision of the competition administration.') }}
            @else
                {{ __('No opponent entered. The class is awaiting an administrative decision.') }}
            @endif
        </div>
    </div>
</x-display.layout>
