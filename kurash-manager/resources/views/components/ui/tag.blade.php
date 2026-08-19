@props([
    // brand  — a good state: passed, done, active, decided
    // info   — neutral information, and the blue corner
    // danger — a failed or blocking state
    // muted  — a state that is simply not started yet
    // outline— the same, but where a fill would be too loud in a dense table
    'variant' => 'muted',
])

@php
    $styles = match ($variant) {
        'brand' => 'bg-brand-200 text-brand-800',
        'info' => 'bg-info-200 text-info-800',
        'danger' => 'bg-danger-200 text-danger-700',
        'outline' => 'border border-line text-ink/70',
        default => 'bg-n-200 text-ink',
    };
@endphp

<span {{ $attributes->class(['kicker inline-flex items-center whitespace-nowrap px-2.5 py-[3px]', $styles]) }}>
    {{ $slot }}
</span>
