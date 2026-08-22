@props([
    'title' => null,
    'subtitle' => null,
    'flush' => false,   // no body padding — for a card that is only a table or a grid
])

@php $hasHead = $title || $subtitle || isset($head); @endphp

{{-- A card is a white surface held by a shadow, not by a border: the ground is
     tinted just enough that an unbordered card reads as raised. Overflow is
     hidden so a flush table's rows stop at the rounded corner. --}}
<section {{ $attributes->class('overflow-hidden rounded-lg bg-surface shadow-card') }}>
    @if ($hasHead)
        <div @class(['flex flex-wrap items-start justify-between gap-4 px-7 pt-6', 'pb-5' => $flush])>
            <div class="min-w-0">
                @if ($title)
                    <h2 class="m-0 text-[17px]">{{ $title }}</h2>
                @endif

                @if ($subtitle)
                    <p class="mt-1 text-[13px] text-muted">{{ $subtitle }}</p>
                @endif
            </div>

            @isset($head)
                <div class="flex flex-wrap items-center gap-2">{{ $head }}</div>
            @endisset
        </div>
    @endif

    @if ($flush)
        {{ $slot }}
    @else
        {{-- The padding is on an inner div rather than on the card, so a flush
             card and a padded one are the same box from the outside. --}}
        <div @class(['px-7 pb-6', 'pt-5' => $hasHead, 'pt-6' => ! $hasHead])>{{ $slot }}</div>
    @endif
</section>
