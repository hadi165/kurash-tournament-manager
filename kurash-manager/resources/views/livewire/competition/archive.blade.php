<x-page
    :title="__('Archive')"
    :subtitle="__('Closed competitions and the reports that came out of them. An archived championship stops accepting changes.')"
>
    <x-competition.flash />

    @if ($closable->isNotEmpty())
        <x-ui.card :title="__('Ready to close')" :subtitle="__('Competitions that have been fought but are still open for editing.')">
            <div class="flex flex-col gap-2.5">
                @foreach ($closable as $championship)
                    <div class="flex flex-wrap items-center gap-3.5 rounded-md border border-line bg-ground px-[18px] py-3.5"
                         wire:key="closable-{{ $championship->id }}">
                        <div class="min-w-0">
                            <div class="text-[14.5px] font-semibold">{{ $championship->title }}</div>
                            <div class="mt-0.5 text-[12.5px] text-muted">
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
        <x-ui.card wire:key="archived-{{ $championship->id }}">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="m-0 text-xl">{{ $championship->title }}</h2>
                    <p class="mt-1.5 text-[13.5px] text-muted">
                        {{ $championship->location ?: __('Location not set') }}
                        @if ($championship->starts_on)
                            · {{ $championship->starts_on->format('j M Y') }}
                        @endif
                        · {{ trans_choice('{1}:count athlete|[2,*]:count athletes', $championship->athletes_count, ['count' => $championship->athletes_count]) }}
                    </p>
                </div>

                <x-ui.tag>{{ __('Archived :date', ['date' => $championship->archived_at?->format('j M Y')]) }}</x-ui.tag>
            </div>

            @if (($top = $standings[$championship->id] ?? collect())->isNotEmpty())
                {{-- The top of the table as chips rather than as a table: three
                     lines of medals is a summary, and a summary should not need
                     column headings. --}}
                <div class="mt-[18px] flex flex-wrap gap-2.5">
                    @foreach ($top as $i => $row)
                        <div class="flex items-center gap-2 rounded-full border border-line bg-ground px-3.5 py-1.5 text-[13px]">
                            <span class="font-semibold text-muted">{{ $i + 1 }}</span>
                            <x-flag :noc="$row['noc_code']" show-code />
                            <span class="tabular-nums text-muted">
                                {{ $row['gold'] }}–{{ $row['silver'] }}–{{ $row['bronze'] }}
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="mt-[18px] flex flex-wrap gap-2">
                @foreach ([
                    ['route' => 'exports.results', 'label' => __('Results')],
                    ['route' => 'exports.medals', 'label' => __('Medal standing')],
                    ['route' => 'exports.fight-order', 'label' => __('Fight order')],
                    ['route' => 'exports.entries-noc', 'label' => __('Entries by NOC')],
                ] as $export)
                    <x-ui.chip :href="route($export['route'], ['championship' => $championship, 'format' => 'pdf'])">
                        {{ $export['label'] }} · PDF
                    </x-ui.chip>
                @endforeach

                <x-ui.chip :href="route('medals.index', $championship)" wire:navigate>
                    {{ __('Open medal table') }}
                </x-ui.chip>
            </div>

            @if ($championship->events->isNotEmpty())
                {{-- The event log is what a protest is settled from, so it stays
                     on the card — quiet, under a hairline, but present. --}}
                <div class="mt-[18px] flex flex-col gap-1.5 border-t border-line-soft pt-4">
                    @foreach ($championship->events as $event)
                        <div class="text-[12.5px] text-muted">
                            <span class="tabular-nums">{{ $event->created_at?->format('j M Y H:i') }}</span>
                            · {{ $event->action }}
                            {{ __('by') }} {{ $event->user?->name ?? __('system') }}
                            @if ($event->note)
                                — {{ $event->note }}
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            @can('manage-competition')
                <div class="mt-4">
                    @if ($confirmingReopen === $championship->id)
                        <div class="flex flex-col gap-3 rounded-md bg-danger-soft px-[18px] py-4">
                            <span class="text-[13.5px] text-danger dark:text-danger-200">
                                {{ __('Reopening lets results be changed after the medals were given out. The reason goes on the record.') }}
                            </span>

                            <div class="flex flex-col gap-[7px]">
                                <label for="reopen-{{ $championship->id }}" class="text-[12.5px] font-semibold text-muted">{{ __('Reason') }}</label>
                                <flux:input id="reopen-{{ $championship->id }}" wire:model="reopenReason"
                                            :placeholder="__('e.g. transcription error in the -73 kg final')" />
                            </div>

                            <div class="flex gap-2">
                                <flux:button size="sm" variant="danger" wire:click="reopen({{ $championship->id }})">
                                    {{ __('Reopen') }}
                                </flux:button>
                                <flux:button size="sm" variant="ghost" wire:click="cancelReopen">{{ __('Cancel') }}</flux:button>
                            </div>
                        </div>
                    @else
                        <flux:button size="sm" variant="ghost" wire:click="confirmReopen({{ $championship->id }})">
                            {{ __('Reopen') }}
                        </flux:button>
                    @endif
                </div>
            @endcan
        </x-ui.card>
    @empty
        <x-ui.card class="py-10 text-center">
            <h2 class="m-0 text-2xl">{{ __('Nothing archived yet') }}</h2>
            <p class="mt-2 text-[13.5px] text-muted">
                {{ __('A championship appears here once every contest is decided and it has been closed.') }}
            </p>
        </x-ui.card>
    @endforelse
</x-page>
