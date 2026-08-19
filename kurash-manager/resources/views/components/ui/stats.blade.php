@props([
    // list<array{value:string|int, label:string, accent?:bool}>
    'items' => [],
    'cards' => false,   // each figure in its own card, rather than tiles in one
])

{{-- Stat tiles: the page ground inset into a card, so the figures read as part
     of the card that holds them rather than as boxes of their own. --}}
<div {{ $attributes->class([
    'gap-3',
    'grid [grid-template-columns:repeat(auto-fit,minmax(150px,1fr))]' => $cards,
    'flex flex-wrap' => ! $cards,
]) }}>
    @foreach ($items as $item)
        <div @class([
            'min-w-[112px] rounded-md px-4 py-3.5',
            'bg-surface shadow-card' => $cards,
            'bg-ground' => ! $cards,
        ])>
            <div @class([
                'text-[28px] font-bold leading-none tabular-nums',
                'text-brand' => $item['accent'] ?? false,
            ])>{{ $item['value'] }}</div>

            <div class="mt-1 text-xs text-muted">{{ $item['label'] }}</div>
        </div>
    @endforeach
</div>
