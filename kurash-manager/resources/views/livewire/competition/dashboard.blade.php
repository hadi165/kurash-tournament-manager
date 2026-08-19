<x-page
    :kicker="config('branding.organisation')"
    :title="__('Dashboard')"
    :subtitle="__('Where each competition stands, and what it is waiting on.')"
>
    @forelse ($championships as $c)
        <x-ui.card flush wire:key="champ-{{ $c['model']->id }}">
            <div class="flex flex-wrap items-start justify-between gap-3.5 px-6 pb-4 pt-5">
                <div>
                    <h3 class="m-0 text-2xl">
                        <a href="{{ route('championships.show', $c['model']) }}" wire:navigate
                           class="text-ink no-underline hover:text-brand-600 dark:hover:text-brand-400">
                            {{ $c['model']->title }}
                        </a>
                    </h3>
                    <p class="mt-1 text-[13px] text-ink/55">
                        {{ $c['model']->location ?: __('Location not set') }}
                        @if ($c['model']->starts_on)
                            · {{ $c['model']->starts_on->format('j M Y') }}
                        @endif
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    @if ($c['on_mat'] > 0)
                        {{-- Bouts in progress are the only genuinely live thing
                             on this page, so they get the strongest signal. --}}
                        <x-ui.tag variant="brand">
                            {{ trans_choice('{1}:count bout on a mat|[2,*]:count bouts on mats', $c['on_mat'], ['count' => $c['on_mat']]) }}
                        </x-ui.tag>
                    @endif

                    <flux:button size="sm" variant="ghost" :href="route('fight-order.index', $c['model'])" wire:navigate>
                        {{ __('Fight order') }}
                    </flux:button>
                    <flux:button size="sm" variant="ghost" :href="route('medals.index', $c['model'])" wire:navigate>
                        {{ __('Medals') }}
                    </flux:button>
                </div>
            </div>

            <div class="rule-2"></div>

            <x-ui.stats :items="[
                ['value' => $c['athletes'], 'label' => __('Athletes')],
                ['value' => $c['classes'], 'label' => __('Weight classes')],
                ['value' => $c['passed'], 'label' => __('Passed the scale')],
                ['value' => $c['bouts'], 'label' => __('Bouts')],
                ['value' => $c['mats'], 'label' => __('Mats')],
            ]" />

            @if ($c['bouts'] > 0)
                <div class="rule-2"></div>

                <div class="px-6 py-4">
                    <div class="mb-1.5 flex justify-between text-xs">
                        <span class="text-ink/55">{{ __('Bouts decided') }}</span>
                        <span class="font-bold tabular-nums">{{ $c['decided'] }} / {{ $c['bouts'] }}</span>
                    </div>
                    <div class="h-1.5 bg-ink/15">
                        <div class="h-1.5 bg-brand-500" style="width: {{ $c['progress'] }}%"></div>
                    </div>
                </div>
            @endif

            @if (! empty($c['next_steps']))
                <div class="rule-2"></div>

                <div class="px-6 pb-5 pt-4">
                    <div class="kicker mb-2.5 text-info-600 dark:text-info-400">{{ __('Next') }}</div>

                    <div class="flex flex-col gap-2.5">
                        @foreach ($c['next_steps'] as $i => $step)
                            <div class="flex flex-wrap items-center gap-3.5" wire:key="step-{{ $c['model']->id }}-{{ $i }}">
                                <span class="text-sm">{{ $step['text'] }}</span>

                                @if ($step['route'])
                                    <flux:button size="xs" :href="route($step['route'], $step['params'])" wire:navigate>
                                        {{ $step['label'] }}
                                    </flux:button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @elseif ($c['bouts'] > 0 && $c['decided'] === $c['bouts'])
                <div class="rule-2"></div>

                <div class="flex flex-wrap items-center gap-3.5 px-6 pb-5 pt-4">
                    <x-ui.tag variant="brand">{{ __('All decided') }}</x-ui.tag>
                    <span class="text-sm">{{ __('Every bout is decided.') }}</span>
                    <flux:button size="xs" :href="route('medals.index', $c['model'])" wire:navigate>
                        {{ __('See the medals') }}
                    </flux:button>
                </div>
            @endif
        </x-ui.card>
    @empty
        <x-ui.card class="px-6 py-10 text-center">
            <h3 class="m-0 text-2xl">{{ __('No competitions yet') }}</h3>
            <p class="mt-2 text-[13px] text-ink/55">
                {{ __('Create a championship, then add its categories and weight classes.') }}
            </p>
            <div class="mt-4">
                <flux:button variant="primary" :href="route('championships.index')" wire:navigate>
                    {{ __('Create a championship') }}
                </flux:button>
            </div>
        </x-ui.card>
    @endforelse
</x-page>
