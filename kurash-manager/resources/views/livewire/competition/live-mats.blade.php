{{-- Polls itself rather than the page it sits in.

     .visible so a dashboard left open on a second monitor behind another window
     stops asking, and 10s because that is the granularity a desk needs to see a
     mat free up — the hall's own screen refreshes faster and is the authority
     there. --}}
<div wire:poll.10s.visible>
    @if ($mats->isEmpty())
        <p class="m-0 text-[13.5px] text-muted">
            {{ __('No mats are active yet, so nothing can be sent to a scoreboard.') }}
        </p>
    @else
        <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(230px,1fr))]">
            @foreach ($mats as $mat)
                <div class="rounded-md border border-line bg-ground p-3.5" wire:key="mat-{{ $mat->court->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="text-[13.5px] font-semibold">{{ $mat->court->label() }}</span>

                        {{-- The word is the state; the dot only repeats it. A
                             hall has colourblind officials and a projector at
                             the back, so nothing here is carried by hue. --}}
                        @if ($mat->isLive())
                            <x-ui.tag variant="brand" dot>{{ __('Live') }}</x-ui.tag>
                        @else
                            <x-ui.tag variant="muted">{{ __('Free') }}</x-ui.tag>
                        @endif
                    </div>

                    @if ($mat->bout)
                        @php $bout = $mat->bout; @endphp

                        <div class="mt-2.5 flex flex-wrap items-center gap-2 text-[12px] text-muted">
                            @if ($bout->fight_number)
                                <span class="font-semibold tabular-nums text-ink">#{{ $bout->fight_number }}</span>
                            @endif

                            @if ($bout->weightCategory)
                                <span>{{ $bout->weightCategory->label }} {{ __('kg') }}</span>
                            @endif
                        </div>

                        <div class="mt-2 flex flex-col gap-1 text-[13.5px]">
                            {{-- Blue is athlete A and green is athlete B, the
                                 same way round as the mat screen and the board.
                                 The name of the corner is written out because
                                 the swatch alone is not readable to everyone. --}}
                            <div class="flex items-center gap-2">
                                <span class="size-2.5 flex-none rounded-full bg-info" aria-hidden="true"></span>
                                <span class="sr-only">{{ __('Blue') }}:</span>
                                <span class="truncate">{{ $bout->athleteA?->fullname ?? __('To be decided') }}</span>
                            </div>

                            <div class="flex items-center gap-2">
                                <span class="size-2.5 flex-none rounded-full bg-brand" aria-hidden="true"></span>
                                <span class="sr-only">{{ __('Green') }}:</span>
                                <span class="truncate">{{ $bout->athleteB?->fullname ?? __('To be decided') }}</span>
                            </div>
                        </div>

                        {{-- Only states the bout rows actually carry. There is
                             no "delayed" here because the schema has no such
                             thing, and a status the system cannot verify is one
                             the desk would learn to distrust. --}}
                        @if ($mat->isInJazzo())
                            <div class="mt-2.5">
                                <x-ui.tag variant="amber">{{ __('Jazzo — stopped') }}</x-ui.tag>
                            </div>
                        @elseif ($mat->clockStopped())
                            <div class="mt-2.5">
                                <x-ui.tag variant="info">{{ __('Clock stopped') }}</x-ui.tag>
                            </div>
                        @endif
                    @else
                        <p class="mt-2.5 text-[13px] text-muted">{{ __('Waiting for a contest.') }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
