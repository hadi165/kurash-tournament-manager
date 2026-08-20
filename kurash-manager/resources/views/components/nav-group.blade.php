@props([
    'label',
    // list<array{label:string, href:string, active:bool}>
    'items' => [],
    'active' => false,   // is one of the children the page being viewed?
])

{{-- A sidebar item whose destination needs a choice.

     Registration and the weigh-in form both work on one age category, so with
     more than one in the championship there is no single place for the item to
     go. Rather than sending the click to the category list — which reads as a
     dead link, and does nothing at all when you are already there — the item
     opens and shows the categories themselves. --}}
<div x-data="{ open: @js($active) }">
    <button
        type="button"
        x-on:click="open = ! open"
        :aria-expanded="open"
        @class([
            'flex w-full items-center gap-2 rounded-full px-4 py-2.5 text-start text-[13.5px] leading-snug transition-colors',
            'bg-nav-active-bg font-semibold text-nav-active-ink' => $active,
            'font-medium text-nav-ink hover:bg-nav-hover' => ! $active,
        ])
    >
        <span class="min-w-0 flex-1 truncate">{{ $label }}</span>

        <span class="flex-none text-[10px] transition-transform" :class="open && 'rotate-180'" aria-hidden="true">▾</span>
    </button>

    <div x-show="open" x-cloak class="mt-0.5 flex flex-col gap-0.5 ps-3">
        @foreach ($items as $item)
            <a
                href="{{ $item['href'] }}"
                wire:navigate
                @if ($item['active']) aria-current="page" @endif
                @class([
                    'block truncate rounded-full px-4 py-2 text-[12.5px] no-underline transition-colors',
                    'bg-nav-active-bg font-semibold text-nav-active-ink' => $item['active'],
                    'font-medium text-nav-muted hover:bg-nav-hover hover:text-nav-ink' => ! $item['active'],
                ])
            >{{ $item['label'] }}</a>
        @endforeach
    </div>
</div>
