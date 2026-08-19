@props([
    'title',
    'kicker' => null,
    'subtitle' => null,
    // list<array{label:string, href?:string|null}> — the last one reads as the
    // current page and is not a link.
    'breadcrumbs' => [],
    'context' => null,   // plain text shown instead of breadcrumbs, e.g. "Platform"
])

{{-- The shared screen shell: utility bar, page header, content column.

     Every screen in the system is this shape, so it lives in one place rather
     than being pasted into each view. The negative margin cancels the padding
     the Flux main region applies, because the utility bar and its 2px rule have
     to reach the full width of the column. --}}
<div class="-m-6 flex flex-col lg:-m-8">

    <div class="flex flex-wrap items-center justify-between gap-4 border-b-2 border-line px-8 py-2.5">
        <div class="flex flex-wrap items-center gap-2 text-[13px]">
            @if ($breadcrumbs)
                @foreach ($breadcrumbs as $i => $crumb)
                    @if ($i > 0)
                        <span class="text-ink/55">/</span>
                    @endif

                    @if (! empty($crumb['href']) && ! $loop->last)
                        <a href="{{ $crumb['href'] }}" wire:navigate
                           class="font-semibold text-brand-700 no-underline hover:underline dark:text-brand-400">{{ $crumb['label'] }}</a>
                    @else
                        <span class="font-semibold text-ink/55">{{ $crumb['label'] }}</span>
                    @endif
                @endforeach
            @else
                <span class="font-semibold text-ink/55">{{ $context ?? __('Platform') }}</span>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-1.5">
            {{ $actions ?? '' }}
            <x-ui.theme-toggle />
        </div>
    </div>

    @if (isset($hero))
        {{ $hero }}
    @else
        <header class="px-8 pt-7">
            @if ($kicker)
                <div class="kicker text-brand-600 dark:text-brand-400">{{ $kicker }}</div>
            @endif

            <h1 class="mb-1 mt-2 text-4xl">{{ $title }}</h1>

            @if ($subtitle)
                <p class="m-0 text-sm text-ink/55">{{ $subtitle }}</p>
            @endif
        </header>
    @endif

    <div class="flex max-w-[1240px] flex-col gap-5 px-8 pb-12 pt-[22px]">
        {{ $slot }}
    </div>
</div>
