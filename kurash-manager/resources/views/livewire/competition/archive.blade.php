<div class="flex flex-col gap-6">
    <div>
        <flux:heading size="xl">{{ __('Archive') }}</flux:heading>
        <flux:subheading>
            {{ __('Closed competitions and the reports that came out of them. An archived championship stops accepting changes.') }}
        </flux:subheading>
    </div>

    <x-competition.flash />

    @if ($closable->isNotEmpty())
        <flux:card class="flex flex-col gap-4">
            <div>
                <flux:heading size="lg">{{ __('Ready to close') }}</flux:heading>
                <flux:subheading>{{ __('Competitions that have been fought but are still open for editing.') }}</flux:subheading>
            </div>

            <div class="flex flex-col gap-2">
                @foreach ($closable as $championship)
                    <div class="flex flex-wrap items-center gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700"
                         wire:key="closable-{{ $championship->id }}">
                        <div class="min-w-0">
                            <div class="font-medium">{{ $championship->title }}</div>
                            <flux:text class="text-xs">
                                {{ $championship->location ?: __('Location not set') }}
                                @if ($championship->starts_on)
                                    · {{ $championship->starts_on->format('j M Y') }}
                                @endif
                            </flux:text>
                        </div>

                        @if ($championship->undecided_count > 0)
                            <flux:badge size="sm" color="amber">
                                {{ trans_choice(
                                    '{1}:count contest undecided|[2,*]:count contests undecided',
                                    $championship->undecided_count,
                                    ['count' => $championship->undecided_count]
                                ) }}
                            </flux:badge>
                        @else
                            <flux:badge size="sm" color="green" icon="check">{{ __('All decided') }}</flux:badge>
                        @endif

                        @can('manage-competition')
                            <flux:button
                                size="xs"
                                class="ms-auto"
                                variant="primary"
                                :disabled="$championship->undecided_count > 0"
                                wire:click="archive({{ $championship->id }})"
                                wire:confirm="{{ __('Close :title? Nothing in it can be changed afterwards without reopening it.', ['title' => $championship->title]) }}"
                            >
                                {{ __('Archive') }}
                            </flux:button>
                        @endcan
                    </div>
                @endforeach
            </div>
        </flux:card>
    @endif

    @forelse ($archived as $championship)
        <flux:card class="flex flex-col gap-5" wire:key="archived-{{ $championship->id }}">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <flux:heading size="lg">{{ $championship->title }}</flux:heading>
                    <flux:subheading>
                        {{ $championship->location ?: __('Location not set') }}
                        @if ($championship->starts_on)
                            · {{ $championship->starts_on->format('j M Y') }}
                        @endif
                        · {{ trans_choice('{1}:count athlete|[2,*]:count athletes', $championship->athletes_count, ['count' => $championship->athletes_count]) }}
                    </flux:subheading>
                </div>

                <flux:badge color="zinc" size="sm" icon="lock-closed">
                    {{ __('Archived :date', ['date' => $championship->archived_at?->format('j M Y')]) }}
                </flux:badge>
            </div>

            @if (($top = $standings[$championship->id] ?? collect())->isNotEmpty())
                <div class="flex flex-wrap gap-4">
                    @foreach ($top as $i => $row)
                        <div class="flex items-center gap-2 text-sm">
                            <span class="font-mono text-xs text-zinc-500">{{ $i + 1 }}</span>
                            <x-flag :noc="$row['noc_code']" show-code />
                            <span class="tabular-nums text-zinc-500">
                                {{ $row['gold'] }}–{{ $row['silver'] }}–{{ $row['bronze'] }}
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="flex flex-wrap gap-2">
                @foreach ([
                    ['route' => 'exports.results', 'label' => __('Results')],
                    ['route' => 'exports.medals', 'label' => __('Medal standing')],
                    ['route' => 'exports.fight-order', 'label' => __('Fight order')],
                    ['route' => 'exports.entries-noc', 'label' => __('Entries by NOC')],
                ] as $export)
                    <flux:button size="xs" variant="ghost" :href="route($export['route'], ['championship' => $championship, 'format' => 'pdf'])">
                        {{ $export['label'] }} · PDF
                    </flux:button>
                @endforeach

                <flux:button size="xs" variant="ghost" :href="route('medals.index', $championship)" wire:navigate>
                    {{ __('Open medal table') }}
                </flux:button>
            </div>

            @if ($championship->events->isNotEmpty())
                <div class="flex flex-col gap-1 border-t border-zinc-200 pt-3 dark:border-zinc-700">
                    @foreach ($championship->events as $event)
                        <flux:text class="text-xs">
                            <span class="font-mono">{{ $event->created_at?->format('j M Y H:i') }}</span>
                            · <span class="capitalize">{{ $event->action }}</span>
                            {{ __('by') }} {{ $event->user?->name ?? __('system') }}
                            @if ($event->note)
                                — {{ $event->note }}
                            @endif
                        </flux:text>
                    @endforeach
                </div>
            @endif

            @can('manage-competition')
                @if ($confirmingReopen === $championship->id)
                    <flux:callout variant="warning" icon="lock-open">
                        <div class="flex flex-col gap-3">
                            <span>{{ __('Reopening lets results be changed after the medals were given out. The reason goes on the record.') }}</span>
                            <flux:input wire:model="reopenReason" :label="__('Reason')" :placeholder="__('e.g. transcription error in the -73 kg final')" />
                            <div class="flex gap-2">
                                <flux:button size="xs" variant="danger" wire:click="reopen({{ $championship->id }})">{{ __('Reopen') }}</flux:button>
                                <flux:button size="xs" variant="ghost" wire:click="cancelReopen">{{ __('Cancel') }}</flux:button>
                            </div>
                        </div>
                    </flux:callout>
                @else
                    <div>
                        <flux:button size="xs" variant="ghost" wire:click="confirmReopen({{ $championship->id }})">
                            {{ __('Reopen') }}
                        </flux:button>
                    </div>
                @endif
            @endcan
        </flux:card>
    @empty
        <flux:card class="flex flex-col items-start gap-3 py-10 text-center sm:items-center">
            <flux:heading size="lg">{{ __('Nothing archived yet') }}</flux:heading>
            <flux:subheading>{{ __('A championship appears here once every contest is decided and it has been closed.') }}</flux:subheading>
        </flux:card>
    @endforelse
</div>
