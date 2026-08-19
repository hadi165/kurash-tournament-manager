@props([
    // list<array{value:string|int, label:string, accent?:bool}>
    'items' => [],
    'bordered' => false,   // draw the outer border too, for a standalone strip
])

{{-- The stat strip: equal cells on a neutral ground with 1px gaps, so the
     gutters read as rules and the row shows the modular grid rather than
     floating boxes. --}}
<div {{ $attributes->class([
    'grid gap-px bg-n-300',
    'border border-n-300' => $bordered,
    '[grid-template-columns:repeat(auto-fit,minmax(140px,1fr))]',
]) }}>
    @foreach ($items as $item)
        <div class="bg-surface px-5 py-4">
            <div @class([
                'text-[30px] font-bold leading-none tabular-nums',
                'text-brand-600 dark:text-brand-400' => $item['accent'] ?? false,
            ])>{{ $item['value'] }}</div>

            <div class="kicker mt-1.5 text-ink/55">{{ $item['label'] }}</div>
        </div>
    @endforeach
</div>
