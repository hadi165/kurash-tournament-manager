@props([
    'href',
    'active' => false,
])

{{-- A sidebar link: a full pill that fills with the drawer's own active tint
     when it is the page you are on. The colours are the drawer's, not the
     page's — it is a different surface and its greys were measured on it. Written as a plain anchor rather than
     flux:sidebar.item so the active treatment is exactly the specified one;
     wire:navigate keeps the SPA navigation the Flux component would have
     given us. --}}
<a
    href="{{ $href }}"
    wire:navigate
    @if ($active) aria-current="page" @endif
    {{ $attributes->class([
        'block rounded-full px-4 py-2.5 text-[13.5px] leading-snug no-underline transition-colors',
        'bg-nav-active-bg font-semibold text-nav-active-ink' => $active,
        'font-medium text-nav-ink hover:bg-nav-hover' => ! $active,
    ]) }}
>{{ $slot }}</a>
