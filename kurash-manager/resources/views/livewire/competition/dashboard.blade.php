<x-page
    :title="__('Dashboard')"
    :subtitle="__('Where each competition stands, and what it is waiting on.')"
>
    @forelse ($championships as $c)
        <x-ui.card wire:key="champ-{{ $c['model']->id }}">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="flex flex-wrap items-center gap-2.5">
                        <h2 class="m-0 text-xl">
                            <a href="{{ route('championships.show', $c['model']) }}" wire:navigate
                               class="text-ink no-underline hover:text-brand">
                                {{ $c['model']->title }}
                            </a>
                        </h2>

                        @if ($c['on_mat'] > 0)
                            {{-- Bouts in progress are the only genuinely live
                                 thing on this page, so they get the dot. --}}
                            <x-ui.tag variant="brand" dot>
                                {{ trans_choice('{1}:count bout on a mat|[2,*]:count bouts on mats', $c['on_mat'], ['count' => $c['on_mat']]) }}
                            </x-ui.tag>
                        @endif
                    </div>

                    <p class="mt-1.5 text-[13.5px] text-muted">
                        {{ $c['model']->location ?: __('Location not set') }}
                        @if ($c['model']->starts_on)
                            · {{ $c['model']->starts_on->format('j M Y') }}
                        @endif
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <flux:button size="sm" :href="route('fight-order.index', $c['model'])" wire:navigate>
                        {{ __('Fight order') }}
                    </flux:button>
                    <flux:button size="sm" :href="route('medals.index', $c['model'])" wire:navigate>
                        {{ __('Medals') }}
                    </flux:button>
                </div>
            </div>

            <x-ui.stats grid class="mt-5" :items="[
                ['value' => $c['athletes'], 'label' => __('Athletes')],
                ['value' => $c['classes'], 'label' => __('Weight classes')],
                ['value' => $c['passed'], 'label' => __('Passed the scale')],
                ['value' => $c['bouts'], 'label' => __('Bouts')],
                ['value' => $c['mats'], 'label' => __('Mats')],
            ]" />

            @if ($c['bouts'] > 0)
                <div class="mt-5">
                    <div class="mb-[7px] flex justify-between text-[13px]">
                        <span class="text-muted">{{ __('Bouts decided') }}</span>
                        <span class="font-semibold tabular-nums">{{ $c['decided'] }} / {{ $c['bouts'] }}</span>
                    </div>

                    <div class="h-2 overflow-hidden rounded-full bg-line">
                        <div class="h-2 rounded-full bg-brand" style="width: {{ $c['progress'] }}%"></div>
                    </div>
                </div>
            @endif

            {{-- What the competition is waiting on. Blue rather than green: it
                 is information, not a result. --}}
            @if (! empty($c['next_steps']))
                <div class="mt-5 rounded-md bg-info-soft px-[18px] py-4">
                    <div class="kicker mb-2.5 text-info-deep dark:text-info-300">{{ __('Next up') }}</div>

                    <div class="flex flex-col gap-2.5">
                        @foreach ($c['next_steps'] as $i => $step)
                            <div class="flex flex-wrap items-center gap-3.5" wire:key="step-{{ $c['model']->id }}-{{ $i }}">
                                <span class="text-sm">{{ $step['text'] }}</span>

                                @if ($step['route'])
                                    <x-ui.chip :href="route($step['route'], $step['params'])" wire:navigate>
                                        {{ $step['label'] }} →
                                    </x-ui.chip>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @elseif ($c['bouts'] > 0 && $c['decided'] === $c['bouts'])
                <div class="mt-5 flex flex-wrap items-center gap-3.5 rounded-md bg-brand-soft px-[18px] py-4">
                    <span class="text-sm text-brand-deep dark:text-brand-300">{{ __('Every bout is decided.') }}</span>
                    <x-ui.chip :href="route('medals.index', $c['model'])" wire:navigate>
                        {{ __('See the medals') }} →
                    </x-ui.chip>
                </div>
            @endif
        </x-ui.card>
    @empty
        <x-ui.card class="py-10 text-center">
            <h2 class="m-0 text-2xl">{{ __('No competitions yet') }}</h2>
            <p class="mt-2 text-[13.5px] text-muted">
                {{ __('Create a championship, then add its categories and weight classes.') }}
            </p>
            <div class="mt-5">
                <flux:button variant="primary" :href="route('championships.index')" wire:navigate>
                    {{ __('Create a championship') }}
                </flux:button>
            </div>
        </x-ui.card>
    @endforelse
</x-page>
