@props([
    'href',
    'active' => false,
])

{{-- A sidebar link: a soft rounded row that fills with green-soft when it is
     the page you are on. Written as a plain anchor rather than
     flux:sidebar.item so the active treatment is exactly the specified one;
     wire:navigate keeps the SPA navigation the Flux component would have
     given us. --}}
<a
    href="{{ $href }}"
    wire:navigate
    @if ($active) aria-current="page" @endif
    {{ $attributes->class([
        'block rounded-sm px-3 py-[9px] text-[13.5px] leading-snug no-underline transition-colors',
        'bg-brand-soft font-semibold text-brand-deep dark:text-brand-300' => $active,
        'font-medium text-ink hover:bg-line-soft' => ! $active,
    ]) }}
>{{ $slot }}</a>
