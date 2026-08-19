<div class="flex flex-col gap-6">
    <div>
        <flux:heading size="xl">{{ __('Dashboard') }}</flux:heading>
        <flux:subheading>{{ __('Where each competition stands, and what it is waiting on.') }}</flux:subheading>
    </div>

    @forelse ($championships as $c)
        <flux:card class="flex flex-col gap-5" wire:key="champ-{{ $c['model']->id }}">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <flux:heading size="lg">
                        <a href="{{ route('championships.show', $c['model']) }}" wire:navigate class="hover:underline">
                            {{ $c['model']->title }}
                        </a>
                    </flux:heading>
                    <flux:subheading>
                        {{ $c['model']->location ?: __('Location not set') }}
                        @if ($c['model']->starts_on)
                            · {{ $c['model']->starts_on->format('j M Y') }}
                        @endif
                    </flux:subheading>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    @if ($c['on_mat'] > 0)
                        {{-- Bouts in progress are the only genuinely live thing
                             on this page, so they get the strongest signal. --}}
                        <flux:badge color="green" size="sm" icon="play">
                            {{ trans_choice('{1}:count bout on a mat|[2,*]:count bouts on mats', $c['on_mat'], ['count' => $c['on_mat']]) }}
                        </flux:badge>
                    @endif

                    <flux:button size="xs" variant="ghost" :href="route('fight-order.index', $c['model'])" wire:navigate>
                        {{ __('Fight order') }}
                    </flux:button>
                    <flux:button size="xs" variant="ghost" :href="route('medals.index', $c['model'])" wire:navigate>
                        {{ __('Medals') }}
                    </flux:button>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                @foreach ([
                    ['label' => __('Athletes'), 'value' => $c['athletes']],
                    ['label' => __('Weight classes'), 'value' => $c['classes']],
                    ['label' => __('Passed the scale'), 'value' => $c['passed']],
                    ['label' => __('Bouts'), 'value' => $c['bouts']],
                    ['label' => __('Mats'), 'value' => $c['mats']],
                ] as $stat)
                    <div wire:key="stat-{{ $c['model']->id }}-{{ $stat['label'] }}">
                        <div class="text-2xl font-semibold tabular-nums">{{ $stat['value'] }}</div>
                        <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ $stat['label'] }}</flux:text>
                    </div>
                @endforeach
            </div>

            @if ($c['bouts'] > 0)
                <div>
                    <div class="mb-1 flex items-center justify-between text-xs text-zinc-500">
                        <span>{{ __('Bouts decided') }}</span>
                        <span class="tabular-nums">{{ $c['decided'] }} / {{ $c['bouts'] }}</span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                        <div
                            class="h-full rounded-full bg-green-500 transition-[width]"
                            style="width: {{ $c['progress'] }}%"
                        ></div>
                    </div>
                </div>
            @endif

            @if (! empty($c['next_steps']))
                <div class="flex flex-col gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-900/50 dark:bg-amber-950/30">
                    <flux:text class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-400">
                        {{ __('Next') }}
                    </flux:text>

                    @foreach ($c['next_steps'] as $i => $step)
                        <div class="flex flex-wrap items-center gap-3 text-sm" wire:key="step-{{ $c['model']->id }}-{{ $i }}">
                            <span>{{ $step['text'] }}</span>

                            @if ($step['route'])
                                <flux:button size="xs" :href="route($step['route'], $step['params'])" wire:navigate>
                                    {{ $step['label'] }}
                                </flux:button>
                            @endif
                        </div>
                    @endforeach
                </div>
            @elseif ($c['bouts'] > 0 && $c['decided'] === $c['bouts'])
                <flux:callout variant="success" icon="trophy">
                    {{ __('Every bout is decided.') }}
                    <flux:button size="xs" :href="route('medals.index', $c['model'])" wire:navigate class="ms-2">
                        {{ __('See the medals') }}
                    </flux:button>
                </flux:callout>
            @endif
        </flux:card>
    @empty
        <flux:card class="flex flex-col items-start gap-3 py-10 text-center sm:items-center">
            <flux:heading size="lg">{{ __('No competitions yet') }}</flux:heading>
            <flux:subheading>{{ __('Create a championship, then add its categories and weight classes.') }}</flux:subheading>
            <flux:button variant="primary" :href="route('championships.index')" wire:navigate>
                {{ __('Create a championship') }}
            </flux:button>
        </flux:card>
    @endforelse
</div>
