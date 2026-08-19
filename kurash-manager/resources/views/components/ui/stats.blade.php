@props([
    // list<array{value:string|int, label:string, accent?:bool}>
    'items' => [],
    // How the figures sit on the page:
    //   default — a row of tiles inset into the card that holds them
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
        default => 'min-w-[112px] rounded-md bg-ground px-4 py-3.5',
    };

    $figure = $grid ? 'text-[26px]' : 'text-[28px]';
@endphp

{{-- Stat tiles: the page ground inset into a card, so the figures read as part
     of the card that holds them rather than as boxes of their own. --}}
<div {{ $attributes->class($layout) }}>
    @foreach ($items as $item)
        <div class="{{ $tile }}">
            <div @class([
                $figure,
                'font-bold leading-none tabular-nums',
                'text-brand' => $item['accent'] ?? false,
            ])>{{ $item['value'] }}</div>

            <div class="mt-1 text-[12.5px] text-muted">{{ $item['label'] }}</div>
        </div>
    @endforeach
</div>
