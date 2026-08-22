@props([
    'title',
    'kicker' => null,          // a pill above the title, e.g. the age category
    'kickerVariant' => 'muted',
    'subtitle' => null,
    // list<array{label:string, href?:string|null}> — the last one reads as the
    // current page and is not a link.
    'breadcrumbs' => [],
    'context' => null,   // plain text shown instead of breadcrumbs, e.g. "Platform"
])

{{-- The shared screen shell: crumb row, page header, content column.

     Every screen in the system is this shape, so it lives in one place rather
     than being pasted into each view. The negative margin cancels the padding
     the Flux main region applies, because the column sets its own — tight to
     the sidebar on the left, which has none of its own. --}}
<div class="-m-6 flex flex-col pb-10 pe-5 ps-1 pt-4 lg:-m-8">

    <div class="flex flex-wrap items-center justify-between gap-4 px-2 pb-4">
        <div class="flex flex-wrap items-center gap-2 text-[13px] text-muted">
            @if ($breadcrumbs)
                @foreach ($breadcrumbs as $crumb)
                    @if (! $loop->first)
                        <span aria-hidden="true">›</span>
                    @endif

                    @if (! empty($crumb['href']) && ! $loop->last)
                        <a href="{{ $crumb['href'] }}" wire:navigate
                           class="font-medium text-muted no-underline hover:text-ink">{{ $crumb['label'] }}</a>
                    @else
                        <span class="font-semibold text-ink">{{ $crumb['label'] }}</span>
                    @endif
                @endforeach
            @else
                <span class="font-semibold text-ink">{{ $context ?? __('Platform') }}</span>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2">
            {{ $actions ?? '' }}
            <x-ui.theme-toggle />
        </div>
    </div>

    @if (isset($hero))
        {{ $hero }}
    @else
        <header class="flex max-w-[1180px] flex-wrap items-end justify-between gap-4 px-2">
            <div>
                @if ($kicker)
                    <x-ui.tag :variant="$kickerVariant" class="mb-3">{{ $kicker }}</x-ui.tag>
                @endif

                <h1 class="m-0 text-[30px]">{{ $title }}</h1>

                @if ($subtitle)
                    <p class="mt-1.5 text-[14.5px] text-muted">{{ $subtitle }}</p>
                @endif
            </div>

            {{-- The one action that belongs beside the title rather than in the
                 top row: the thing this screen exists to create. --}}
            @isset($aside)
                <div class="flex flex-wrap items-center gap-2">{{ $aside }}</div>
            @endisset
        </header>
    @endif

    <div class="flex max-w-[1180px] flex-col gap-4 px-2 pt-[18px]">
        {{ $slot }}
    </div>
</div>
