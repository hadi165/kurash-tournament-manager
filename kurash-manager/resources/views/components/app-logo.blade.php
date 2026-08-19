@props([
    'sidebar' => false,
])

@php
    // The federation, not the Laravel application name — this sits at the top
    // of every screen an official uses.
    $name = config('branding.organisation');
    $hasLogo = ($logo = config('branding.logo')) && is_file(public_path($logo));
@endphp

@if ($sidebar)
    <flux:sidebar.brand :name="$name" {{ $attributes }}>
        <x-slot name="logo" @class([
            'flex aspect-square size-8 items-center justify-center rounded-md',
            // Official artwork keeps its own colours; the fallback monogram
            // needs the accent block behind it to read as a mark.
            'bg-accent-content text-accent-foreground' => ! $hasLogo,
        ])>
            <x-app-logo-icon @class([
                'size-5',
                'fill-current text-white dark:text-black' => ! $hasLogo,
                'size-8' => $hasLogo,
            ]) />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="$name" {{ $attributes }}>
        <x-slot name="logo" @class([
            'flex aspect-square size-8 items-center justify-center rounded-md',
            'bg-accent-content text-accent-foreground' => ! $hasLogo,
        ])>
            <x-app-logo-icon @class([
                'size-5',
                'fill-current text-white dark:text-black' => ! $hasLogo,
                'size-8' => $hasLogo,
            ]) />
        </x-slot>
    </flux:brand>
@endif
