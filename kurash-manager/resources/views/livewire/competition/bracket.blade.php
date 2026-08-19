@php
    $championship = $weightCategory->ageCategory->championship;

    $drawn = trans_choice(
        '{0}Nobody drawn yet|{1}:count athlete drawn|[2,*]:count athletes drawn',
        $drawnCount, ['count' => $drawnCount]
    );

    $subtitle = $projectedSize
        ? $drawn.' · '.__('bracket of :size', ['size' => $projectedSize])
        : $drawn;
@endphp

<x-page
    :kicker="$weightCategory->ageCategory->name"
    :title="$weightCategory->label.' '.__('kg')"
    :subtitle="$subtitle"
    :breadcrumbs="[
        ['label' => __('Championships'), 'href' => route('championships.index')],
        ['label' => $championship->title, 'href' => route('championships.show', $championship)],
        ['label' => $weightCategory->label.' '.__('kg')],
    ]"
>

    {{-- The sheet the draw numbers get written onto, and the drawn bracket
         itself. Both are named the way the federation files them. --}}
    <x-slot:actions>
        <span class="kicker me-1 text-ink/55">{{ __('Weigh-in list') }}</span>
        <a href="{{ route('exports.weigh-in', ['weightCategory' => $weightCategory, 'format' => 'pdf']) }}"
           class="px-2.5 py-1 text-xs font-bold text-brand-700 no-underline hover:bg-brand-500/10 dark:text-brand-400">{{ __('PDF') }}</a>
        <a href="{{ route('exports.weigh-in', ['weightCategory' => $weightCategory, 'format' => 'csv']) }}"
           class="px-2.5 py-1 text-xs font-bold text-brand-700 no-underline hover:bg-brand-500/10 dark:text-brand-400">{{ __('Excel') }}</a>

        @if ($bouts->isNotEmpty())
            <span class="mx-1.5 h-5 w-0.5 bg-divider"></span>
            <span class="kicker me-1 text-ink/55">{{ __('Draw result') }}</span>
            <a href="{{ route('exports.draw', ['weightCategory' => $weightCategory, 'format' => 'pdf']) }}"
               class="px-2.5 py-1 text-xs font-bold text-brand-700 no-underline hover:bg-brand-500/10 dark:text-brand-400">{{ __('PDF') }}</a>
            <a href="{{ route('exports.draw', ['weightCategory' => $weightCategory, 'format' => 'csv']) }}"
               class="px-2.5 py-1 text-xs font-bold text-brand-700 no-underline hover:bg-brand-500/10 dark:text-brand-400">{{ __('Excel') }}</a>
        @endif

        <span class="mx-1.5 h-5 w-0.5 bg-divider"></span>
    </x-slot:actions>

    <x-competition.flash />

    @if ($podium['decided'])
        <x-ui.card flush :title="__('Podium')">
            <div class="rule-2"></div>

            <div class="grid gap-px bg-n-300 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))]">
                <div class="bg-surface px-6 py-4">
                    <div class="kicker text-brand-600 dark:text-brand-400">{{ __('Gold') }}</div>
                    <div class="mt-1.5 font-bold"><x-athlete :athlete="$podium['gold']" /></div>
                </div>

                <div class="bg-surface px-6 py-4">
                    <div class="kicker text-ink/55">{{ __('Silver') }}</div>
                    <div class="mt-1.5 font-bold"><x-athlete :athlete="$podium['silver']" /></div>
                </div>

                @foreach ($podium['bronze'] as $bronze)
                    <div class="bg-surface px-6 py-4" wire:key="bronze-{{ $bronze->id }}">
                        <div class="kicker text-ink/55">{{ __('Bronze') }}</div>
                        <div class="mt-1.5 font-bold"><x-athlete :athlete="$bronze" /></div>
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    @endif

    @can('manage-competition')
        <x-ui.card :title="__('Draw numbers')">
            <x-slot:head>
                <flux:button size="sm" wire:click="drawAtRandom" wire:confirm="{{ __('Replace all draw numbers in this class with a random draw?') }}">
                    {{ __('Draw at random') }}
                </flux:button>
                <flux:button size="sm" variant="primary" wire:click="saveDraws">{{ __('Save draw numbers') }}</flux:button>
            </x-slot:head>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @forelse ($athletes as $athlete)
                    {{-- The athlete is the field's label. Sitting them beside the
                         input squeezed the names down to "Ak…", "asg…", "DDD…". --}}
                    <div class="flex flex-col gap-1.5" wire:key="draw-{{ $athlete->id }}">
                        <label for="draw-{{ $athlete->id }}" class="flex items-center gap-2 text-[13px] font-bold">
                            <span class="truncate">{{ $athlete->fullname }}</span>

                            @if ($athlete->weighin_status === 'fail')
                                <x-ui.tag variant="danger" class="ms-auto">{{ __('failed weigh-in') }}</x-ui.tag>
                            @else
                                <x-ui.tag variant="outline" class="ms-auto">{{ $athlete->noc_code }}</x-ui.tag>
                            @endif
                        </label>

                        <flux:input id="draw-{{ $athlete->id }}" wire:model="draws.{{ $athlete->id }}" type="number" min="1" />
                    </div>
                @empty
                    <p class="text-sm text-ink/55">{{ __('No athletes registered in this weight class.') }}</p>
                @endforelse
            </div>

            <div class="rule-2 my-5"></div>

            <div class="flex flex-wrap items-center gap-3">
                <flux:button
                    variant="primary"
                    wire:click="generate"
                    :disabled="$drawnCount < 2"
                >{{ $bouts->isEmpty() ? __('Draw the bracket') : __('Redraw the bracket') }}</flux:button>

                @if ($confirmingRegenerate)
                    <flux:button variant="danger" wire:click="generate(true)">
                        {{ __('Erase results and redraw') }}
                    </flux:button>
                @endif

                @if ($drawnCount < 2)
                    <span class="text-sm text-ink/55">{{ __('At least two athletes need draw numbers.') }}</span>
                @endif
            </div>
        </x-ui.card>
    @endcan

    @if ($bouts->isNotEmpty())
        @can('manage-competition')
            @if ($courts->isEmpty())
                {{-- Same reason as on the fight order: no mat means no
                     send-to-mat control anywhere in the bracket. --}}
                <div class="flex flex-wrap items-center gap-3 border border-danger-200 bg-danger-100/60 p-3 dark:bg-danger-500/10">
                    <x-ui.tag variant="danger">{{ __('No mats') }}</x-ui.tag>
                    <span class="text-sm">{{ __('No mats are set up, so bouts cannot be sent to a scoreboard yet.') }}</span>
                    <flux:button size="xs" :href="route('courts.index', $championship)" wire:navigate>{{ __('Add a mat') }}</flux:button>
                </div>
            @endif
        @endcan

        <x-ui.card flush :title="__('Bracket')">
            <div class="rule-2"></div>

            <div class="overflow-x-auto px-6 py-5">
                <div class="flex gap-6" style="min-width: {{ max(1, $totalRounds) * 17 }}rem;">
                    @foreach ($rounds as $round => $roundBouts)
                        <div class="flex flex-1 flex-col gap-3" wire:key="round-{{ $round }}">
                            <div class="kicker text-ink/55">{{ $roundBouts->first()->phase($totalRounds) }}</div>

                            @foreach ($roundBouts as $bout)
                                <div
                                    @class(['border border-n-300 bg-surface text-sm', 'opacity-60' => $bout->is_bye])
                                    wire:key="bout-{{ $bout->id }}"
                                >
                                    @foreach (['a', 'b'] as $side)
                                        @php
                                            $athlete = $side === 'a' ? $bout->athleteA : $bout->athleteB;
                                            $isWinner = $athlete && $bout->winner_athlete_id === $athlete->id;
                                        @endphp

                                        {{-- The corner square carries the colour, the
                                             brand tint carries the win: a glance down
                                             the bracket reads who went through. --}}
                                        <div @class([
                                            'flex items-center justify-between gap-2 px-3 py-2',
                                            'border-b border-ink/12' => $side === 'a',
                                            'bg-brand-500/10 font-bold' => $isWinner,
                                        ])>
                                            <span class="flex min-w-0 items-center gap-2">
                                                <span @class(['size-2.5 flex-none', $side === 'a' ? 'bg-info-500' : 'bg-brand-500'])></span>
                                                <x-athlete
                                                    :athlete="$athlete"
                                                    :fallback="$bout->is_bye ? __('Bye') : '—'"
                                                    class="min-w-0"
                                                />
                                            </span>

                                            @if ($athlete && ! $bout->isDecided())
                                                @can('manage-competition')
                                                    <flux:button
                                                        size="xs"
                                                        variant="ghost"
                                                        wire:click="recordResult({{ $bout->id }}, '{{ $side }}')"
                                                        :disabled="! $bout->isReadyToFight()"
                                                    >{{ __('Win') }}</flux:button>
                                                @endcan
                                            @endif
                                        </div>
                                    @endforeach

                                    @can('manage-competition')
                                        @if ($bout->isReadyToFight() && $courts->isNotEmpty())
                                            <div class="flex flex-wrap items-center gap-1 border-t border-ink/12 px-3 py-2">
                                                @if ($bout->court)
                                                    <x-ui.tag variant="info">{{ $bout->court->label() }}</x-ui.tag>
                                                @else
                                                    <span class="kicker text-ink/55">{{ __('Send to') }}</span>
                                                @endif

                                                @foreach ($courts as $court)
                                                    <flux:button
                                                        size="xs"
                                                        variant="ghost"
                                                        wire:click="sendToMat({{ $bout->id }}, {{ $court->id }})"
                                                        wire:key="send-{{ $bout->id }}-{{ $court->id }}"
                                                    >{{ $court->number }}</flux:button>
                                                @endforeach
                                            </div>
                                        @endif
                                    @endcan
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        </x-ui.card>
    @endif
</x-page>
