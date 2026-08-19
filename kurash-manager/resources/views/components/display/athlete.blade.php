@props([
    'athlete' => null,
    'fallback' => '—',
])

{{-- The venue screens are standalone HTML with their own stylesheet, so this
     leans on the .flag and .noc rules in the display layout rather than on
     Tailwind, which is not loaded there. --}}
@if ($athlete)
    @php($iso = \App\Support\Noc::iso($athlete->noc_code))

    <span class="competitor">
        @if ($iso)
            <img class="flag" src="{{ asset("flags/{$iso}.svg") }}" alt="{{ $athlete->noc_name ?? $athlete->noc_code }}">
        @else
            <span class="flag flag-blank"></span>
        @endif

        <span>{{ $athlete->fullname }}</span>
        <span class="noc">{{ \App\Support\Noc::normalise($athlete->noc_code) }}</span>
    </span>
@else
    <span class="muted">{{ $fallback }}</span>
@endif
