<div class="flex flex-col gap-6">
    <div>
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('championships.index')" wire:navigate>{{ __('Championships') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item :href="route('championships.show', $weightCategory->ageCategory->championship)" wire:navigate>
                {{ $weightCategory->ageCategory->championship->title }}
            </flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ $weightCategory->label }} kg</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <flux:heading size="xl" class="mt-2">
            {{ $weightCategory->ageCategory->name }} — {{ $weightCategory->label }} kg
        </flux:heading>
        <flux:subheading>
            {{ trans_choice('{0}Nobody drawn yet|{1}:count athlete drawn|[2,*]:count athletes drawn', $drawnCount, ['count' => $drawnCount]) }}
            @if ($projectedSize)
                · {{ __('bracket of :size', ['size' => $projectedSize]) }}
            @endif
        </flux:subheading>

        <div class="mt-4 flex flex-wrap items-center gap-6">
            {{-- The sheet the draw numbers get written onto, and the drawn
                 bracket itself. Both are named the way the federation files them. --}}
            <x-competition.exports
                route="exports.weigh-in"
                :params="['weightCategory' => $weightCategory]"
                :label="__('Weigh-in list')"
            />

            @if ($bouts->isNotEmpty())
                <x-competition.exports
                    route="exports.draw"
                    :params="['weightCategory' => $weightCategory]"
                    :label="__('Draw result')"
                />
            @endif
        </div>
    </div>

    <x-competition.flash />

    @if ($podium['decided'])
        <flux:card>
            <flux:heading size="lg">{{ __('Podium') }}</flux:heading>
            <div class="mt-3 flex flex-wrap gap-6">
                <div>
                    <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Gold') }}</flux:text>
                    <div class="font-medium"><x-athlete :athlete="$podium['gold']" /></div>
                </div>
                <div>
                    <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Silver') }}</flux:text>
                    <div class="font-medium"><x-athlete :athlete="$podium['silver']" /></div>
                </div>
                @foreach ($podium['bronze'] as $bronze)
                    <div wire:key="bronze-{{ $bronze->id }}">
                        <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Bronze') }}</flux:text>
                        <div class="font-medium"><x-athlete :athlete="$bronze" /></div>
                    </div>
                @endforeach
            </div>
        </flux:card>
    @endif

    @can('manage-competition')
        <flux:card class="flex flex-col gap-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:heading size="lg">{{ __('Draw numbers') }}</flux:heading>

                <div class="flex flex-wrap gap-2">
                    <flux:button size="sm" wire:click="drawAtRandom" wire:confirm="{{ __('Replace all draw numbers in this class with a random draw?') }}">
                        {{ __('Draw at random') }}
                    </flux:button>
                    <flux:button size="sm" variant="primary" wire:click="saveDraws">{{ __('Save draw numbers') }}</flux:button>
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @forelse ($athletes as $athlete)
                    {{-- The athlete is the field's label. Sitting them beside the
                         input squeezed the names down to "Ak…", "asg…", "DDD…". --}}
                    <flux:input
                        wire:model="draws.{{ $athlete->id }}"
                        wire:key="draw-{{ $athlete->id }}"
                        type="number"
                        min="1"
                        :label="$athlete->fullname"
                        :badge="$athlete->weighin_status === 'fail' ? __('failed weigh-in') : $athlete->noc_code"
                    />
                @empty
                    <flux:text class="text-zinc-500">{{ __('No athletes registered in this weight class.') }}</flux:text>
                @endforelse
            </div>

            <flux:separator />

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
                    <flux:text class="text-zinc-500">{{ __('At least two athletes need draw numbers.') }}</flux:text>
                @endif
            </div>
        </flux:card>
    @endcan

    @if ($bouts->isNotEmpty())
        @can('manage-competition')
            @if ($courts->isEmpty())
                {{-- Same reason as on the fight order: no mat means no
                     send-to-mat control anywhere in the bracket. --}}
                <flux:callout variant="warning" icon="tv">
                    <div class="flex flex-wrap items-center gap-3">
                        <span>{{ __('No mats are set up, so bouts cannot be sent to a scoreboard yet.') }}</span>
                        <flux:button size="xs" :href="route('courts.index', $weightCategory->ageCategory->championship)" wire:navigate>
                            {{ __('Add a mat') }}
                        </flux:button>
                    </div>
                </flux:callout>
            @endif
        @endcan

        <flux:card class="overflow-x-auto">
            <flux:heading size="lg" class="mb-4">{{ __('Bracket') }}</flux:heading>

            <div class="flex gap-6" style="min-width: {{ max(1, $totalRounds) * 17 }}rem;">
                @foreach ($rounds as $round => $roundBouts)
                    <div class="flex flex-1 flex-col gap-3" wire:key="round-{{ $round }}">
                        <flux:text class="text-xs font-semibold uppercase tracking-wide text-zinc-500">
                            {{ $roundBouts->first()->phase($totalRounds) }}
                        </flux:text>

                        @foreach ($roundBouts as $bout)
                            <div
                                class="rounded-lg border border-zinc-200 text-sm dark:border-zinc-700 {{ $bout->is_bye ? 'opacity-60' : '' }}"
                                wire:key="bout-{{ $bout->id }}"
                            >
                                @foreach (['a', 'b'] as $side)
                                    @php
                                        $athlete = $side === 'a' ? $bout->athleteA : $bout->athleteB;
                                        $isWinner = $athlete && $bout->winner_athlete_id === $athlete->id;
                                    @endphp

                                    <div @class([
                                        'flex items-center justify-between gap-2 px-3 py-2',
                                        'border-b border-zinc-100 dark:border-zinc-800' => $side === 'a',
                                        'bg-green-50 font-medium dark:bg-green-950/40' => $isWinner,
                                    ])>
                                        <x-athlete
                                            :athlete="$athlete"
                                            :fallback="$bout->is_bye ? __('Bye') : '—'"
                                            class="min-w-0"
                                        />

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
                                        <div class="flex flex-wrap items-center gap-1 border-t border-zinc-100 px-3 py-2 dark:border-zinc-800">
                                            @if ($bout->court)
                                                <flux:badge size="sm" color="blue">{{ $bout->court->label() }}</flux:badge>
                                            @else
                                                <span class="text-xs text-zinc-500">{{ __('Send to') }}</span>
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
        </flux:card>
    @endif
</div>
