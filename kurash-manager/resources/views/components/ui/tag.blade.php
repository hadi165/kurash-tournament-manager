@props([
    // brand  — a good state: passed, done, active, decided
    // info   — neutral information, and the blue corner
    // danger — a failed or blocking state
    // amber  — a caution: something needs attention but nothing is broken
    // muted  — a state that is simply not started yet
    'variant' => 'muted',
    'dot' => false,   // a filled dot before the label, for a live state
])

@php
    [$styles, $dotColour] = match ($variant) {
        'brand' => ['bg-brand-soft text-brand-deep', 'bg-brand'],
        'info' => ['bg-info-soft text-info-deep', 'bg-info'],
        'danger' => ['bg-danger-soft text-danger-deep', 'bg-danger'],
        // Named here at last: nine callers across the draw, operator and
        // standings screens were already asking for amber — a locked draw, a
        // rule override, a tie awaiting a decision — and every one of them was
        // falling through to the muted default and reading as grey.
        'amber' => ['bg-amber-soft text-amber-deep', 'bg-amber'],
        default => ['bg-ground text-muted ring-1 ring-inset ring-line', 'bg-muted'],
    };
@endphp

{{-- A pill. The dot is what separates "live" from a label that merely names a
     state, so it is opt-in rather than automatic. --}}
<span {{ $attributes->class([
    'inline-flex items-center gap-2 whitespace-nowrap rounded-full px-3 py-1 text-xs font-semibold',
    $styles,
]) }}>
    @if ($dot)
        <span class="size-[7px] flex-none rounded-full {{ $dotColour }}"></span>
    @endif

    {{ $slot }}
</span>
