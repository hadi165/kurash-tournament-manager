<x-page
    :kicker="$championship->title"
    :title="__('Mats and Scoreboards')"
    :subtitle="__('Every mat in the hall, the scoreboard it drives, and the bouts sent to it.')"
    :breadcrumbs="[
        ['label' => __('Championships'), 'href' => route('championships.index')],
        ['label' => $championship->title, 'href' => route('championships.show', $championship)],
        ['label' => __('Mats')],
    ]"
>
    {{-- The driver belongs in the utility bar rather than the subtitle: on a
         venue machine it is the first thing to check when a scoreboard stays
         dark, and it should read the same on every mat screen. --}}
    <x-slot:actions>
        <span class="kicker me-1 text-ink/55">{{ __('Driver') }}</span>
        <x-ui.tag :variant="$driver === 'http' ? 'brand' : 'outline'">{{ $driver }}</x-ui.tag>
        @if ($driver !== 'http')
            <span class="text-[13px] text-ink/55">{{ __('no real hardware is contacted') }}</span>
        @endif
        <span class="mx-1.5 h-5 w-0.5 bg-divider"></span>
    </x-slot:actions>

    <x-competition.flash />

    @can('manage-competition')
        <x-ui.card>
            <form wire:submit="save">
                <h4 class="m-0 text-xl">{{ $editingId ? __('Edit mat') : __('Add mat') }}</h4>

                <div class="my-[18px] grid gap-4 md:grid-cols-4">
                    <div class="flex flex-col gap-1.5">
                        <label for="court-number" class="kicker">{{ __('Mat number') }}</label>
                        <flux:input id="court-number" wire:model="number" type="number" min="1" required />
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label for="court-name" class="kicker">{{ __('Name') }}</label>
                        <flux:input id="court-name" wire:model="name" :placeholder="__('Mat A')" />
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label for="court-url" class="kicker">{{ __('Scoreboard URL') }}</label>
                        <flux:input id="court-url" wire:model="scoreboard_base_url" placeholder="http://192.168.1.40" />
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label for="court-key" class="kicker">{{ __('API key') }}</label>
                        <flux:input id="court-key" wire:model="scoreboard_api_key" type="password" />
                        <p class="text-[11px] text-ink/55">
                            {{ $editingId ? __('Leave blank to keep the current key') : __('Optional') }}
                        </p>
                    </div>
                </div>

                <div class="flex gap-2.5">
                    <flux:button type="submit" variant="primary">
                        {{ $editingId ? __('Save changes') : __('Add mat') }}
                    </flux:button>

                    @if ($editingId)
                        <flux:button type="button" variant="ghost" wire:click="cancelEdit">{{ __('Cancel') }}</flux:button>
                    @endif
                </div>
            </form>
        </x-ui.card>
    @endcan

    <x-ui.card flush>
        <div class="overflow-x-auto">
            <table class="t">
                <thead>
                    <tr>
                        <th class="num">{{ __('Mat') }}</th>
                        <th>{{ __('Name') }}</th>
                        <th>{{ __('Scoreboard') }}</th>
                        <th class="num">{{ __('Bouts') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($courts as $court)
                        <tr wire:key="court-{{ $court->id }}">
                            <td class="num font-bold">{{ $court->number }}</td>
                            <td>{{ $court->name ?: '—' }}</td>
                            <td class="font-mono text-xs text-ink/55">{{ $court->scoreboard_base_url ?: '—' }}</td>
                            <td class="num">{{ $court->bouts_count }}</td>
                            <td>
                                <x-ui.tag :variant="$court->is_active ? 'brand' : 'muted'">
                                    {{ $court->is_active ? __('Active') : __('Inactive') }}
                                </x-ui.tag>
                            </td>
                            <td>
                                <div class="flex flex-wrap justify-end gap-2">
                                    {{-- Available to viewers too: the mat screen is
                                         read-only for anyone without the gate, and it
                                         is the clearest live view of a contest. --}}
                                    <flux:button size="sm" variant="primary" :href="route('mats.live', $court)" wire:navigate>
                                        {{ __('Open mat') }}
                                    </flux:button>

                                    <flux:button size="sm" icon="tv" :href="route('display.scoreboard', $court)" target="_blank">
                                        {{ __('Scoreboard') }}
                                    </flux:button>
                                </div>

                                @can('manage-competition')
                                    <div class="mt-2 flex flex-wrap justify-end gap-2">
                                        <flux:button size="xs" variant="ghost" wire:click="testConnection({{ $court->id }})">{{ __('Test') }}</flux:button>
                                        <flux:button size="xs" variant="ghost" wire:click="toggleActive({{ $court->id }})">
                                            {{ $court->is_active ? __('Deactivate') : __('Activate') }}
                                        </flux:button>
                                        <flux:button size="xs" variant="ghost" wire:click="edit({{ $court->id }})">{{ __('Edit') }}</flux:button>
                                        <flux:button size="xs" variant="danger" wire:click="delete({{ $court->id }})" wire:confirm="{{ __('Delete this mat?') }}">
                                            {{ __('Delete') }}
                                        </flux:button>
                                    </div>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center text-ink/55">{{ __('No mats configured yet.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
</x-page>
