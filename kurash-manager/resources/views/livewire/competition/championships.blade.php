<x-page
    :kicker="config('branding.organisation')"
    :title="__('Championships')"
    :subtitle="__('Define a tournament before adding categories, athletes or draws.')"
>
    <x-competition.flash />

    @can('manage-competition')
        <x-ui.card>
            <form wire:submit="save">
                <h4 class="m-0 text-xl">{{ $editingId ? __('Edit championship') : __('New championship') }}</h4>

                <div class="my-[18px] grid gap-4 md:grid-cols-[2fr_1fr_1fr]">
                    <div class="flex flex-col gap-1.5">
                        <label for="champ-title" class="kicker">{{ __('Title') }}</label>
                        <flux:input id="champ-title" wire:model="title" placeholder="{{ __('Asian Kurash Championship 2026') }}" required />
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label for="champ-location" class="kicker">{{ __('Location') }}</label>
                        <flux:input id="champ-location" wire:model="location" placeholder="{{ __('Tashkent') }}" />
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label for="champ-starts" class="kicker">{{ __('Start date') }}</label>
                        <flux:input id="champ-starts" wire:model="starts_on" type="date" />
                    </div>
                </div>

                <div class="flex gap-2.5">
                    <flux:button type="submit" variant="primary">
                        {{ $editingId ? __('Save changes') : __('Create championship') }}
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
                        <th>{{ __('Title') }}</th>
                        <th>{{ __('Location') }}</th>
                        <th>{{ __('Starts') }}</th>
                        <th class="num">{{ __('Categories') }}</th>
                        <th class="num">{{ __('Athletes') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($championships as $championship)
                        <tr wire:key="championship-{{ $championship->id }}">
                            <td>
                                <a href="{{ route('championships.show', $championship) }}" wire:navigate
                                   class="font-bold text-brand-700 no-underline hover:underline dark:text-brand-400">
                                    {{ $championship->title }}
                                </a>
                            </td>
                            <td class="text-ink/55">{{ $championship->location ?: '—' }}</td>
                            <td class="text-ink/55 tabular-nums">{{ $championship->starts_on?->format('d M Y') ?: '—' }}</td>
                            <td class="num">{{ $championship->age_categories_count }}</td>
                            <td class="num">{{ $championship->athletes_count }}</td>
                            <td>
                                @can('manage-competition')
                                    <div class="flex justify-end gap-2">
                                        <flux:button size="xs" variant="ghost" wire:click="edit({{ $championship->id }})">
                                            {{ __('Edit') }}
                                        </flux:button>

                                        {{-- Destructive actions are ghost buttons in red text: the
                                             weight of a filled button belongs to the primary action. --}}
                                        <flux:button
                                            size="xs"
                                            variant="ghost"
                                            class="!text-danger-500 hover:!bg-danger-500/10"
                                            wire:click="delete({{ $championship->id }})"
                                            wire:confirm="{{ __('Delete this championship and everything in it?') }}"
                                        >{{ __('Delete') }}</flux:button>
                                    </div>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center text-ink/55">{{ __('No championships yet.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
</x-page>
