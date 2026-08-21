{{--
    Where a referee starts.

    One card per mat, saying what is on it right now. No competition data beyond
    what a referee standing at the tatami can already see, because that is the
    whole point of the role.
--}}
<x-page
    :title="__('Mats')"
    :subtitle="__('Open the mat you are working. Scoring, the clock and the winner are all on that screen.')"
    :context="__('Judging')"
>
    <x-competition.flash />

    @if ($courts->isEmpty())
        <x-ui.card class="px-6 py-10 text-center">
            <h3 class="m-0 text-2xl">{{ __('No mat assigned') }}</h3>
            <p class="mt-2 text-[13px] text-ink/55">
                {{ __('An administrator assigns the mat you work. Until one is assigned, there is nothing here to open.') }}
            </p>
        </x-ui.card>
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($courts as $court)
                @php $bout = $live->get($court->id); @endphp

                <x-ui.card wire:key="mat-{{ $court->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="m-0 text-xl">{{ $court->label() }}</h3>
                            <p class="mt-1 truncate text-[13px] text-ink/55">{{ $court->championship->title }}</p>
                        </div>

                        <x-ui.tag :variant="$bout ? 'brand' : 'muted'">
                            {{ $bout ? __('Running') : __('Idle') }}
                        </x-ui.tag>
                    </div>

                    <div class="mt-4 min-h-[64px] rounded-md border border-ink/12 px-3.5 py-3 text-sm">
                        @if ($bout)
                            <div class="text-[11px] font-bold uppercase tracking-wider text-ink/55">
                                {{ __('Fight :n', ['n' => $bout->fight_number ?? $bout->play_code]) }}
                                · {{ $bout->weightCategory?->exportName() }}
                            </div>

                            {{-- Named by yakhtak rather than by slot, because
                                 that is what the referee is looking at. --}}
                            <div class="mt-1.5 flex flex-col gap-1">
                                <span class="flex items-center gap-2">
                                    <span class="h-3.5 w-1.5 flex-none bg-info-500"></span>
                                    <span class="truncate font-semibold">{{ $bout->athleteA?->fullname }}</span>
                                </span>
                                <span class="flex items-center gap-2">
                                    <span class="h-3.5 w-1.5 flex-none bg-brand-500"></span>
                                    <span class="truncate font-semibold">{{ $bout->athleteB?->fullname }}</span>
                                </span>
                            </div>
                        @else
                            <span class="text-ink/40">{{ __('Nothing on this mat') }}</span>
                        @endif
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <flux:button size="sm" variant="primary" :href="route('mats.live', $court)" wire:navigate>
                            {{ __('Open mat') }}
                        </flux:button>

                        <flux:button size="sm" variant="ghost" :href="route('display.scoreboard', $court)" target="_blank">
                            {{ __('Score Board') }}
                        </flux:button>
                    </div>
                </x-ui.card>
            @endforeach
        </div>
    @endif
</x-page>
