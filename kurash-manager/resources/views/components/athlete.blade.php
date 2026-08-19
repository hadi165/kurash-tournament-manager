@props([
    'athlete' => null,
    'fallback' => '—',
    'showCode' => true,
    'size' => 'sm',
])

@if ($athlete)
    <span {{ $attributes->merge(['class' => 'inline-flex min-w-0 items-center gap-1.5']) }}>
        <x-flag :noc="$athlete->noc_code" :name="$athlete->noc_name" :size="$size" />

        <span class="truncate">{{ $athlete->fullname }}</span>

        @if ($showCode)
            <span class="shrink-0 text-xs font-medium text-zinc-500 dark:text-zinc-400">
                {{ \App\Support\Noc::normalise($athlete->noc_code) }}
            </span>
        @endif
    </span>
@else
    <span {{ $attributes->merge(['class' => 'text-zinc-400 dark:text-zinc-500']) }}>{{ $fallback }}</span>
@endif
