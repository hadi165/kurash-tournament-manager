@props([
    'title' => null,
    'subtitle' => null,
    'flush' => false,   // no body padding — for a card that is only a table or a grid
])

{{-- A card is a flat surface with a hairline border and no radius. Its internal
     sections are separated by 2px rules, not by gaps, so the whole thing reads
     as one block of the grid. --}}
<section {{ $attributes->class('border border-n-300 bg-surface shadow-elev-sm') }}>
    @if ($title || $subtitle || isset($head))
        <div class="flex flex-wrap items-start justify-between gap-3.5 px-6 pb-4 pt-5">
            <div class="min-w-0">
                @if ($title)
                    <h4 class="m-0 text-xl">{{ $title }}</h4>
                @endif

                @if ($subtitle)
                    <p class="mt-1 text-[13px] text-ink/55">{{ $subtitle }}</p>
                @endif
            </div>

            @isset($head)
                <div class="flex flex-wrap items-center gap-2">{{ $head }}</div>
            @endisset
        </div>

        @unless ($flush)
            <div class="rule-2"></div>
        @endunless
    @endif

    @if ($flush)
        {{ $slot }}
    @else
        <div class="px-6 py-5">{{ $slot }}</div>
    @endif
</section>
