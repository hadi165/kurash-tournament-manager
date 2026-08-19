<div class="flex flex-col gap-6">
    <div>
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('championships.index')" wire:navigate>{{ __('Championships') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item :href="route('championships.show', $championship)" wire:navigate>{{ $championship->title }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ __('Mats') }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <flux:heading size="xl" class="mt-2">{{ __('Mats and scoreboards') }}</flux:heading>
        <flux:subheading>
            {{ __('Driver in use: :driver', ['driver' => $driver]) }}
            @if ($driver !== 'http')
                — {{ __('no real hardware will be contacted.') }}
            @endif
        </flux:subheading>
    </div>

    <x-competition.flash />

    @can('manage-competition')
        <flux:card>
            <form wire:submit="save" class="flex flex-col gap-4">
                <flux:heading size="lg">{{ $editingId ? __('Edit mat') : __('Add mat') }}</flux:heading>

                <div class="grid gap-4 md:grid-cols-4">
                    <flux:input wire:model="number" type="number" min="1" :label="__('Mat number')" required />
                    <flux:input wire:model="name" :label="__('Name')" placeholder="{{ __('Mat A') }}" />
                    <flux:input wire:model="scoreboard_base_url" :label="__('Scoreboard URL')" placeholder="http://192.168.1.40" />
                    <flux:input
                        wire:model="scoreboard_api_key"
                        type="password"
                        :label="__('API key')"
                        :description="$editingId ? __('Leave blank to keep the current key') : __('Optional')"
                    />
                </div>

                <div class="flex items-center gap-3">
                    <flux:button type="submit" variant="primary">{{ $editingId ? __('Save changes') : __('Add mat') }}</flux:button>
                    @if ($editingId)
                        <flux:button type="button" variant="ghost" wire:click="cancelEdit">{{ __('Cancel') }}</flux:button>
                    @endif
                </div>
            </form>
        </flux:card>
    @endcan

    <flux:card class="p-0 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-zinc-200 text-left dark:border-zinc-700">
                    <th class="px-4 py-3 font-medium tabular-nums">{{ __('Mat') }}</th>
                    <th class="px-4 py-3 font-medium">{{ __('Name') }}</th>
                    <th class="px-4 py-3 font-medium">{{ __('Scoreboard') }}</th>
                    <th class="px-4 py-3 font-medium tabular-nums">{{ __('Bouts') }}</th>
                    <th class="px-4 py-3 font-medium">{{ __('Status') }}</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($courts as $court)
                    <tr class="border-b border-zinc-100 last:border-0 dark:border-zinc-800" wire:key="court-{{ $court->id }}">
                        <td class="px-4 py-3 tabular-nums font-medium">{{ $court->number }}</td>
                        <td class="px-4 py-3">{{ $court->name ?: '—' }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $court->scoreboard_base_url ?: '—' }}</td>
                        <td class="px-4 py-3 tabular-nums">{{ $court->bouts_count }}</td>
                        <td class="px-4 py-3">
                            <flux:badge size="sm" :color="$court->is_active ? 'green' : 'zinc'">
                                {{ $court->is_active ? __('Active') : __('Inactive') }}
                            </flux:badge>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex justify-end gap-2">
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
                                <div class="mt-2 flex justify-end gap-2">
                                    <flux:button size="sm" variant="ghost" wire:click="testConnection({{ $court->id }})">{{ __('Test') }}</flux:button>
                                    <flux:button size="sm" variant="ghost" wire:click="toggleActive({{ $court->id }})">
                                        {{ $court->is_active ? __('Deactivate') : __('Activate') }}
                                    </flux:button>
                                    <flux:button size="sm" variant="ghost" wire:click="edit({{ $court->id }})">{{ __('Edit') }}</flux:button>
                                    <flux:button size="sm" variant="danger" wire:click="delete({{ $court->id }})" wire:confirm="{{ __('Delete this mat?') }}">
                                        {{ __('Delete') }}
                                    </flux:button>
                                </div>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-zinc-500">{{ __('No mats configured yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </flux:card>
</div>
