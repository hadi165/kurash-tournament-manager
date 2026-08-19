<x-page
    :kicker="config('branding.organisation')"
    :title="__('Archive')"
    :subtitle="__('Closed competitions and the reports that came out of them. An archived championship stops accepting changes.')"
>
    <x-competition.flash />

    @if ($closable->isNotEmpty())
        <x-ui.card :title="__('Ready to close')" :subtitle="__('Competitions that have been fought but are still open for editing.')" flush>
            <div class="rule-2"></div>

            <div class="flex flex-col">
                @foreach ($closable as $championship)
                    <div class="flex flex-wrap items-center gap-3.5 border-b border-ink/12 px-6 py-4 last:border-b-0"
                         wire:key="closable-{{ $championship->id }}">
                        <div class="min-w-0">
                            <div class="font-bold">{{ $championship->title }}</div>
                            <div class="text-xs text-ink/55">
                                {{ $championship->location ?: __('Location not set') }}
                                @if ($championship->starts_on)
                                    · {{ $championship->starts_on->format('j M Y') }}
                                @endif
                            </div>
                        </div>

                        @if ($championship->undecided_count > 0)
                            <x-ui.tag variant="danger">
                                {{ trans_choice('{1}:count contest undecided|[2,*]:count contests undecided', $championship->undecided_count, ['count' => $championship->undecided_count]) }}
                            </x-ui.tag>
                        @else
                            <x-ui.tag variant="brand">{{ __('All decided') }}</x-ui.tag>
                        @endif

                        @can('manage-competition')
                            <flux:button
                                size="sm"
                                variant="primary"
                                class="ms-auto"
                                :disabled="$championship->undecided_count > 0"
                                wire:click="archive({{ $championship->id }})"
                                wire:confirm="{{ __('Close :title? Nothing in it can be changed afterwards without reopening it.', ['title' => $championship->title]) }}"
                            >{{ __('Archive') }}</flux:button>
                        @endcan
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    @endif

    @forelse ($archived as $championship)
        <x-ui.card flush wire:key="archived-{{ $championship->id }}">
            <div class="flex flex-wrap items-start justify-between gap-3.5 px-6 pb-4 pt-5">
                <div>
                    <h3 class="m-0 text-2xl">{{ $championship->title }}</h3>
                    <p class="mt-1 text-[13px] text-ink/55">
                        {{ $championship->location ?: __('Location not set') }}
                        @if ($championship->starts_on)
                            · {{ $championship->starts_on->format('j M Y') }}
                        @endif
                        · {{ trans_choice('{1}:count athlete|[2,*]:count athletes', $championship->athletes_count, ['count' => $championship->athletes_count]) }}
                    </p>
                </div>

                <x-ui.tag variant="muted">
                    {{ __('Archived :date', ['date' => $championship->archived_at?->format('j M Y')]) }}
                </x-ui.tag>
            </div>

            @if (($top = $standings[$championship->id] ?? collect())->isNotEmpty())
                <div class="rule-2"></div>

                <div class="flex flex-wrap gap-6 px-6 py-4">
                    @foreach ($top as $i => $row)
                        <div class="flex items-center gap-2 text-sm">
                            <span class="font-mono text-xs text-ink/55">{{ $i + 1 }}</span>
                            <x-flag :noc="$row['noc_code']" show-code />
                            <span class="tabular-nums text-ink/55">
                                {{ $row['gold'] }}–{{ $row['silver'] }}–{{ $row['bronze'] }}
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="rule-2"></div>

            <div class="flex flex-wrap items-center gap-2 px-6 py-4">
                @foreach ([
                    ['route' => 'exports.results', 'label' => __('Results')],
                    ['route' => 'exports.medals', 'label' => __('Medal standing')],
                    ['route' => 'exports.fight-order', 'label' => __('Fight order')],
                    ['route' => 'exports.entries-noc', 'label' => __('Entries by NOC')],
                ] as $export)
                    <a href="{{ route($export['route'], ['championship' => $championship, 'format' => 'pdf']) }}"
                       class="px-2.5 py-1 text-xs font-bold text-brand-700 no-underline hover:bg-brand-500/10 dark:text-brand-400">
                        {{ $export['label'] }} · PDF
                    </a>
                @endforeach

                <flux:button size="xs" variant="ghost" :href="route('medals.index', $championship)" wire:navigate>
                    {{ __('Open medal table') }}
                </flux:button>
            </div>

            @if ($championship->events->isNotEmpty())
                <div class="rule-2"></div>

                {{-- The event log is set in mono: it is a record to be read line
                     by line, not prose. --}}
                <div class="flex flex-col gap-1 px-6 py-4 font-mono text-[11px] text-ink/55">
                    @foreach ($championship->events as $event)
                        <div>
                            <span>{{ $event->created_at?->format('j M Y H:i') }}</span>
                            · <span class="uppercase">{{ $event->action }}</span>
                            {{ __('by') }} {{ $event->user?->name ?? __('system') }}
                            @if ($event->note)
                                — {{ $event->note }}
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            @can('manage-competition')
                <div class="rule-2"></div>

                <div class="px-6 py-4">
                    @if ($confirmingReopen === $championship->id)
                        <div class="flex flex-col gap-3 border border-danger-200 bg-danger-100/60 p-4 dark:bg-danger-500/10">
                            <span class="text-sm">
                                {{ __('Reopening lets results be changed after the medals were given out. The reason goes on the record.') }}
                            </span>

                            <div class="flex flex-col gap-1.5">
                                <label for="reopen-{{ $championship->id }}" class="kicker">{{ __('Reason') }}</label>
                                <flux:input id="reopen-{{ $championship->id }}" wire:model="reopenReason"
                                            :placeholder="__('e.g. transcription error in the -73 kg final')" />
                            </div>

                            <div class="flex gap-2">
                                <flux:button size="xs" variant="danger" wire:click="reopen({{ $championship->id }})">
                                    {{ __('Reopen') }}
                                </flux:button>
                                <flux:button size="xs" variant="ghost" wire:click="cancelReopen">{{ __('Cancel') }}</flux:button>
                            </div>
                        </div>
                    @else
                        <flux:button size="xs" variant="ghost" wire:click="confirmReopen({{ $championship->id }})">
                            {{ __('Reopen') }}
                        </flux:button>
                    @endif
                </div>
            @endcan
        </x-ui.card>
    @empty
        <x-ui.card class="px-6 py-10 text-center">
            <h3 class="m-0 text-2xl">{{ __('Nothing archived yet') }}</h3>
            <p class="mt-2 text-[13px] text-ink/55">
                {{ __('A championship appears here once every contest is decided and it has been closed.') }}
            </p>
        </x-ui.card>
    @endforelse
</x-page>
