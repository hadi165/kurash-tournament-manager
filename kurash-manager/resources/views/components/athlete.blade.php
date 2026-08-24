@props([
    'athlete' => null,
    'fallback' => '—',
    'showCode' => true,
    'size' => 'sm',
])

@if ($athlete)
    <span {{ $attributes->merge(['class' => 'inline-flex min-w-0 items-center gap-1.5']) }}>
        <x-flag :noc="$athlete->noc_code" :name="$athlete->noc_name" :size="$size" />

        {{-- Wrapped, never cut. A name shortened to "Cholpon Toktog…" is not
             the athlete's name, and the column it did not fit in is the thing
             that should give way. --}}
        <span class="min-w-0 break-words">{{ $athlete->fullname }}</span>

        @if ($showCode)
            <span class="shrink-0 text-xs font-bold text-ink/55">
                {{ \App\Support\Noc::normalise($athlete->noc_code) }}
            </span>
        @endif
    </span>
@else
    <span {{ $attributes->merge(['class' => 'text-ink/40']) }}>{{ $fallback }}</span>
@endif
