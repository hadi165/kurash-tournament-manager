@props([
    // list<array{value:string|int, label:string, hue?:string, accent?:bool, danger?:bool}>
    // hue names the data colour the figure carries: brand | info | amber | danger.
    'items' => [],
    // How the figures sit on the page:
    //   default — tiles inset into the card that holds them
    //   grid    — the same tiles on an even grid, for a full-width row
    //   cards   — each figure in a card of its own, for the top of a screen
    'grid' => false,
    'cards' => false,
])

@php
    $layout = match (true) {
        $cards => 'grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(150px,1fr))]',
        $grid => 'grid gap-2.5 [grid-template-columns:repeat(auto-fit,minmax(150px,1fr))]',
        default => 'flex flex-wrap gap-3',
    };

    $tile = match (true) {
        $cards => 'rounded-lg bg-surface px-6 py-5 shadow-card',
        $grid => 'rounded-md border border-line bg-ground px-4 py-3.5',
        default => 'min-w-[116px] rounded-md bg-surface px-4 py-3.5 shadow-chip',
    };

    $figure = $grid ? 'text-[26px]' : 'text-[28px]';

    // The rail carries the bright hue and the figure carries the -deep one.
    // Rails are decorative, so they have no contrast floor; the number is text,
    // so it must never be the bright hue — #c98a00 on white is 2.95:1.
    $rails = [
        'brand' => 'border-l-4 border-brand',
        'info' => 'border-l-4 border-info',
        'amber' => 'border-l-4 border-amber',
        'danger' => 'border-l-4 border-danger',
    ];

    $inks = [
        'brand' => 'text-brand-deep',
        'info' => 'text-info-deep',
        'amber' => 'text-amber-deep',
        'danger' => 'text-danger-deep',
    ];
@endphp

<div {{ $attributes->class($layout) }}>
    @foreach ($items as $item)
        @php
            // accent and danger are the older spellings of the same idea.
            $hue = $item['hue'] ?? match (true) {
                (bool) ($item['accent'] ?? false) => 'brand',
                (bool) ($item['danger'] ?? false) => 'danger',
                default => null,
            };
        @endphp

        <div class="{{ $tile }} {{ $hue ? $rails[$hue] : '' }}">
            <div class="{{ $figure }} font-bold leading-none tabular-nums {{ $hue ? $inks[$hue] : '' }}">
                {{ $item['value'] }}
            </div>

            <div class="mt-1 text-[12.5px] text-muted">{{ $item['label'] }}</div>
        </div>
    @endforeach
</div>
