@props([
    'href' => null,     // a link when given, a button otherwise
    'variant' => 'soft',
])

@php
    $classes = [
        'inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-3.5 py-[5px] text-[12.5px] font-semibold no-underline transition-colors',
        'border border-line bg-surface text-ink hover:bg-line-soft' => $variant === 'soft',
        'text-ink hover:bg-line-soft' => $variant === 'ghost',
        'text-danger hover:bg-danger-soft' => $variant === 'danger',
    ];
@endphp

{{-- The small pressable thing that is not a full button: an export format, a
     mat to send a bout to, a place in a list of places. --}}
@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="button" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
