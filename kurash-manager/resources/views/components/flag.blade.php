@props([
    'noc' => null,
    'name' => null,
    'size' => 'sm',      // sm | md | lg
    'showCode' => false,
])

@php
    $code = \App\Support\Noc::normalise($noc);
    $iso = \App\Support\Noc::iso($code);

    $box = match ($size) {
        'lg' => 'h-6 w-8',
        'md' => 'h-4 w-6',
        default => 'h-3 w-[1.125rem]',
    };
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 align-middle']) }}>
    @if ($iso)
        {{-- A plain img, not a CSS sprite: the same file is then usable by the
             venue screens and by the PDF renderer, which reads from disk. --}}
        <img
            src="{{ asset("flags/{$iso}.svg") }}"
            alt="{{ $name ?? $code }}"
            title="{{ $name ?? $code }}"
            loading="lazy"
            class="{{ $box }} shrink-0 object-cover ring-1 ring-ink/20"
        />
    @elseif ($code)
        {{-- A code with no flag — a delegation competing without one, or a code
             this system does not recognise. A neutral placeholder keeps the
             column aligned instead of collapsing the row. --}}
        <span
            class="{{ $box }} shrink-0 bg-n-300 ring-1 ring-ink/20"
            title="{{ __('No flag for :code', ['code' => $code]) }}"
            aria-hidden="true"
        ></span>
    @endif

    @if ($showCode && $code)
        <span class="font-bold tabular-nums">{{ $code }}</span>
    @endif
</span>
