<div class="flex flex-col gap-6">
    <div>
        <flux:heading size="xl">{{ __('Championships') }}</flux:heading>
        <flux:subheading>{{ __('Define a tournament before adding categories, athletes or draws.') }}</flux:subheading>
    </div>

    <x-competition.flash />

    @can('manage-competition')
        <flux:card>
            <form wire:submit="save" class="flex flex-col gap-4">
                <flux:heading size="lg">
                    {{ $editingId ? __('Edit championship') : __('New championship') }}
                </flux:heading>

                <div class="grid gap-4 md:grid-cols-3">
                    <flux:input
                        wire:model="title"
                        :label="__('Title')"
                        placeholder="{{ __('Asian Kurash Championship 2026') }}"
                        required
                    />
                    <flux:input wire:model="location" :label="__('Location')" placeholder="{{ __('Tashkent') }}" />
                    <flux:input wire:model="starts_on" type="date" :label="__('Start date')" />
                </div>

                <div class="flex items-center gap-3">
                    <flux:button type="submit" variant="primary">
                        {{ $editingId ? __('Save changes') : __('Create championship') }}
                    </flux:button>

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
                    <th class="px-4 py-3 font-medium">{{ __('Title') }}</th>
                    <th class="px-4 py-3 font-medium">{{ __('Location') }}</th>
                    <th class="px-4 py-3 font-medium">{{ __('Starts') }}</th>
                    <th class="px-4 py-3 font-medium tabular-nums">{{ __('Categories') }}</th>
                    <th class="px-4 py-3 font-medium tabular-nums">{{ __('Athletes') }}</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($championships as $championship)
                    <tr class="border-b border-zinc-100 last:border-0 dark:border-zinc-800" wire:key="championship-{{ $championship->id }}">
                        <td class="px-4 py-3">
                            <flux:link :href="route('championships.show', $championship)" wire:navigate class="font-medium">
                                {{ $championship->title }}
                            </flux:link>
                        </td>
                        <td class="px-4 py-3 text-zinc-500">{{ $championship->location ?: '—' }}</td>
                        <td class="px-4 py-3 text-zinc-500 tabular-nums">
                            {{ $championship->starts_on?->format('d M Y') ?: '—' }}
                        </td>
                        <td class="px-4 py-3 tabular-nums">{{ $championship->age_categories_count }}</td>
                        <td class="px-4 py-3 tabular-nums">{{ $championship->athletes_count }}</td>
                        <td class="px-4 py-3 text-right">
                            @can('manage-competition')
                                <div class="flex justify-end gap-2">
                                    <flux:button size="sm" variant="ghost" wire:click="edit({{ $championship->id }})">
                                        {{ __('Edit') }}
                                    </flux:button>
                                    <flux:button
                                        size="sm"
                                        variant="danger"
                                        wire:click="delete({{ $championship->id }})"
                                        wire:confirm="{{ __('Delete this championship and everything in it?') }}"
                                    >
                                        {{ __('Delete') }}
                                    </flux:button>
                                </div>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-zinc-500">
                            {{ __('No championships yet.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </flux:card>
</div>
