@props([
    'size' => 'md',        // sm | md | lg
    'showName' => true,
    'stacked' => false,    // name under the mark rather than beside it
])

@php
    $logo = config('branding.logo');
    $hasLogo = $logo && is_file(public_path($logo));

    $organisation = config('branding.organisation');
    $short = config('branding.short_name');

    [$markSize, $textSize] = match ($size) {
        'lg' => ['h-16 w-16', 'text-lg'],
        'sm' => ['h-7 w-7', 'text-xs'],
        default => ['h-10 w-10', 'text-sm'],
    };
@endphp

<span {{ $attributes->merge([
    'class' => 'flex items-center gap-2.5 '.($stacked ? 'flex-col text-center gap-2' : ''),
]) }}>
    @if ($hasLogo)
        <img
            src="{{ asset($logo) }}"
            alt="{{ $organisation }}"
            class="{{ $markSize }} shrink-0 object-contain"
        />
    @else
        {{-- No official artwork installed. A typographic monogram, deliberately
             plain: inventing a crest here would put something in front of a
             federation that looks like their emblem and is not. Set
             branding.logo to replace it everywhere at once. --}}
        <span
            class="{{ $markSize }} grid shrink-0 place-items-center rounded-full border-2 border-current font-bold tracking-tight text-zinc-800 dark:text-zinc-100"
            aria-hidden="true"
        >
            <span @class(['text-[0.6rem]' => $size === 'sm', 'text-xs' => $size === 'md', 'text-base' => $size === 'lg'])>
                {{ $short }}
            </span>
        </span>
    @endif

    @if ($showName)
        <span class="{{ $textSize }} font-semibold leading-tight text-zinc-900 dark:text-zinc-100">
            {{ $organisation }}
        </span>
    @endif
</span>
