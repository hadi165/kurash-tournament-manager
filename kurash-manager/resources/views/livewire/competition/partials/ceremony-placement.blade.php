{{-- A class with one entrant, on the board.

     There is no draw to tell and no fixture to reveal, so the board says the
     two true things: who is entered, and whether the competition office has
     settled the class. It never announces a champion on its own — being
     unopposed is not a result, and the placement is an administrator's signed
     decision recorded on the class. --}}
<div class="dc-solo">
    <div class="dc-panel dc-solo-panel">
        <div class="dc-kicker">{{ __('Single entrant') }}</div>

        @php $entrant = $placed ?? $weightCategory->numberedAthletes()->first(); @endphp

        <div class="dc-solo-name">{{ $entrant?->fullname ?? '—' }}</div>

        <div class="dc-solo-noc">
            {{ \App\Support\Noc::normalise($entrant?->noc_code) }}
            @if ($entrant?->noc_name)
                · {{ $entrant->noc_name }}
            @endif
        </div>

        <p class="dc-sub">
            @if ($placed)
                {{ __('Placed first by decision of the competition administration.') }}
            @else
                {{ __('No opponent entered. The class is awaiting an administrative decision.') }}
            @endif
        </p>
    </div>
</div>
