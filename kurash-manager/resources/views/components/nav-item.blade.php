@props([
    'href',
    'active' => false,
    // A count shown at the end of the pill. Null renders the item exactly as
    // it was before badges existed, so an item without one gains no markup.
    'badge' => null,
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
>@if ($badge === null){{ $slot }}@else<span class="flex items-center justify-between gap-2">
        <span class="min-w-0 truncate">{{ $slot }}</span>

        {{-- Reads as a count, not as a state: the number is the whole message,
             so it carries no hue of its own. --}}
        <span class="flex-none rounded-full bg-nav-active-bg px-2 py-0.5 text-[11px] font-bold tabular-nums text-nav-active-ink">
            {{ $badge }}
        </span>
    </span>@endif</a>
