{{-- The create form is folded away until it is wanted. The Alpine state sits
     on the root rather than inside the page, because the button that opens the
     form is beside the title and the form itself is in the content column. --}}
<div x-data="{ open: false }">
    <x-page
        :title="__('Championships')"
        :subtitle="__('Define a tournament before adding categories, athletes or draws.')"
    >
        @can('manage-competition')
            <x-slot:aside>
                <div x-show="! open && ! @js((bool) $editingId)">
                    <flux:button variant="primary" x-on:click="open = true">
                        {{ __('+ New championship') }}
                    </flux:button>
                </div>
            </x-slot:aside>
        @endcan

        <x-competition.flash />

        @can('manage-competition')
            <div x-show="open || @js((bool) $editingId)" x-cloak>
                <x-ui.card :title="$editingId ? __('Edit championship') : __('New championship')">
                    <form wire:submit="save">
                        <div class="grid gap-[18px] md:grid-cols-[2fr_1fr_1fr]">
                            <div class="flex flex-col gap-[7px]">
                                <label for="champ-title" class="text-[12.5px] font-semibold text-muted">{{ __('Title') }}</label>
                                <flux:input id="champ-title" wire:model="title" placeholder="{{ __('Asian Kurash Championship 2026') }}" required />
                            </div>

                            <div class="flex flex-col gap-[7px]">
                                <label for="champ-location" class="text-[12.5px] font-semibold text-muted">{{ __('Location') }}</label>
                                <flux:input id="champ-location" wire:model="location" placeholder="{{ __('Tashkent') }}" />
                            </div>

                            <div class="flex flex-col gap-[7px]">
                                <label for="champ-starts" class="text-[12.5px] font-semibold text-muted">{{ __('Start date') }}</label>
                                <flux:input id="champ-starts" wire:model="starts_on" type="date" />
                            </div>
                        </div>

                        {{-- Everything downstream reads these two lists. A
                             championship that runs only seniors will not offer a
                             junior on registration, at the weigh-in or in a
                             draw, because there is nowhere else for one to come
                             from. --}}
                        <div class="mt-[22px] grid gap-[18px] md:grid-cols-[1fr_2fr]">
                            <div class="flex flex-col gap-[7px]">
                                <span class="text-[12.5px] font-semibold text-muted">{{ __('Competitions') }}</span>
                                <div class="flex flex-wrap items-center gap-4 pt-1.5">
                                    @foreach ([\App\Support\Gender::MEN => __('Men'), \App\Support\Gender::WOMEN => __('Women'), \App\Support\Gender::OPEN => __('Open')] as $value => $label)
                                        <flux:checkbox wire:model="genders" value="{{ $value }}" :label="$label" />
                                    @endforeach
                                </div>
                                @error('genders')
                                    <span class="text-[12.5px] text-danger">{{ $message }}</span>
                                @enderror
                            </div>

                            <div class="flex flex-col gap-[7px]">
                                <span class="text-[12.5px] font-semibold text-muted">{{ __('Age groups') }}</span>
                                <div class="flex flex-wrap items-center gap-4 pt-1.5">
                                    @foreach ($this->ageGroupChoices() as $group)
                                        <flux:checkbox wire:model="ageGroups" value="{{ $group }}" :label="__($group)" />
                                    @endforeach
                                </div>
                                @error('ageGroups')
                                    <span class="text-[12.5px] text-danger">{{ $message }}</span>
                                @enderror
                                <span class="text-[12.5px] text-muted">
                                    {{ __('Every division is one competition paired with one of these.') }}
                                </span>
                            </div>
                        </div>

                        <div class="mt-[22px] flex gap-2.5">
                            <flux:button type="submit" variant="primary">
                                {{ $editingId ? __('Save changes') : __('Create championship') }}
                            </flux:button>

                            <flux:button type="button" variant="ghost" wire:click="cancelEdit" x-on:click="open = false">
                                {{ __('Cancel') }}
                            </flux:button>
                        </div>
                    </form>
                </x-ui.card>
            </div>
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
                                       class="font-semibold text-ink no-underline hover:text-brand">
                                        {{ $championship->title }}
                                    </a>
                                </td>
                                <td class="text-muted">{{ $championship->location ?: '—' }}</td>
                                <td class="tabular-nums text-muted">{{ $championship->starts_on?->format('d M Y') ?: '—' }}</td>
                                <td class="num">{{ $championship->age_categories_count }}</td>
                                <td class="num">{{ $championship->athletes_count }}</td>
                                <td>
                                    @can('manage-competition')
                                        <div class="flex justify-end gap-1.5">
                                            <x-ui.chip variant="ghost" wire:click="edit({{ $championship->id }})" x-on:click="open = true">
                                                {{ __('Edit') }}
                                            </x-ui.chip>

                                            {{-- Destructive actions are red text on a red-soft
                                                 hover, never a solid red block: the weight of a
                                                 filled button belongs to the primary action. --}}
                                            <x-ui.chip
                                                variant="danger"
                                                wire:click="delete({{ $championship->id }})"
                                                wire:confirm="{{ __('Delete this championship and everything in it?') }}"
                                            >{{ __('Delete') }}</x-ui.chip>
                                        </div>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-8 text-center text-muted">{{ __('No championships yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    </x-page>
</div>
