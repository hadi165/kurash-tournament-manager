@props([
    'href',
    'active' => false,
])

{{-- A sidebar link in the Modernist style: flush-left, no radius, and an
     active state carried by a 3px green rule down the left edge rather than a
     pill. Written as a plain anchor rather than flux:sidebar.item so the
     active treatment is exactly the specified one; wire:navigate keeps the
     SPA navigation the Flux component would have given us. --}}
<a
    href="{{ $href }}"
    wire:navigate
    @if ($active) aria-current="page" @endif
    {{ $attributes->class([
        'block border-l-[3px] px-[17px] py-[7px] text-[13.5px] leading-snug no-underline transition-colors',
        'border-brand-500 bg-brand-500/15 font-bold text-ink' => $active,
        'border-transparent font-semibold text-ink/85 hover:bg-n-200 hover:text-ink' => ! $active,
    ]) }}
>{{ $slot }}</a>
