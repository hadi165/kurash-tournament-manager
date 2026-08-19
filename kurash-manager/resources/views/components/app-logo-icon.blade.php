@php
    $logo = config('branding.logo');
    $hasLogo = $logo && is_file(public_path($logo));
@endphp

@if ($hasLogo)
    {{-- The federation's own artwork. Merged attributes carry the sizing
         classes the layouts pass in; fill-* is inert on an img and harmless. --}}
    <img
        src="{{ asset($logo) }}"
        alt="{{ config('branding.organisation') }}"
        {{ $attributes->class('object-contain') }}
    />
@else
    {{-- No official artwork installed yet. A plain monogram rather than a
         crest: anything more would look like a federation emblem this project
         has no right to invent. Drop the real file at the path in
         config/branding.php and it replaces this everywhere. --}}
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40" {{ $attributes }}>
        <circle cx="20" cy="20" r="18" fill="none" stroke="currentColor" stroke-width="2.5" />
        <text
            x="20"
            y="20"
            fill="currentColor"
            stroke="none"
            text-anchor="middle"
            dominant-baseline="central"
            font-family="system-ui, sans-serif"
            font-size="12"
            font-weight="700"
            letter-spacing="0.5"
        >{{ config('branding.short_name') }}</text>
    </svg>
@endif
