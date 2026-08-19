<div class="flex flex-col gap-6">
    <div>
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('championships.index')" wire:navigate>{{ __('Championships') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ $championship->title }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <flux:heading size="xl" class="mt-2">{{ $championship->title }}</flux:heading>
        <flux:subheading>{{ __('Age categories and the weight classes inside them.') }}</flux:subheading>

        <div class="mt-3 flex flex-wrap gap-2">
            <flux:button size="sm" :href="route('fight-order.index', $championship)" wire:navigate>{{ __('Fight order') }}</flux:button>
            <flux:button size="sm" :href="route('courts.index', $championship)" wire:navigate>{{ __('Mats & scoreboards') }}</flux:button>
            <flux:button size="sm" :href="route('medals.index', $championship)" wire:navigate>{{ __('Medals') }}</flux:button>
        </div>

        {{-- Venue screens. Plain links, not wire:navigate: these are opened on
             a second monitor and left there. --}}
        <div class="mt-3 flex flex-wrap items-center gap-2">
            <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Venue screens') }}</flux:text>
            <flux:button size="xs" variant="ghost" icon="tv" :href="route('display.mats', $championship)" target="_blank">{{ __('Mats') }}</flux:button>
            <flux:button size="xs" variant="ghost" icon="list-bullet" :href="route('display.fight-order', $championship)" target="_blank">{{ __('Fight order') }}</flux:button>
            <flux:button size="xs" variant="ghost" icon="trophy" :href="route('display.medals', $championship)" target="_blank">{{ __('Medals') }}</flux:button>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-6">
            <x-competition.exports
                route="exports.entries-weight"
                :params="['championship' => $championship]"
                :label="__('Entries by weight')"
            />
            <x-competition.exports
                route="exports.entries-noc"
                :params="['championship' => $championship]"
                :label="__('Entries by NOC')"
            />
        </div>
    </div>

    <x-competition.flash />

    @can('manage-competition')
        <flux:card>
            <form wire:submit="save" class="flex flex-col gap-4">
                <flux:heading size="lg">
                    {{ $editingId ? __('Edit age category') : __('New age category') }}
                </flux:heading>

                <div class="grid gap-4 md:grid-cols-[1fr_2fr_auto]">
                    <flux:input wire:model="ageCategoryName" :label="__('Name')" placeholder="{{ __('Men Senior') }}" required />

                    <flux:input
                        wire:model="weightLabels"
                        :label="__('Weight classes')"
                        placeholder="-60, -66, -73, -81, -90, +90"
                        :description="__('Comma separated, in display order. Use + for an open upper class.')"
                        required
                    />

                    <flux:select wire:model="gender" :label="__('Gender')" class="md:w-32">
                        <flux:select.option value="M">{{ __('Male') }}</flux:select.option>
                        <flux:select.option value="F">{{ __('Female') }}</flux:select.option>
                        <flux:select.option value="X">{{ __('Mixed') }}</flux:select.option>
                    </flux:select>
                </div>

                <div class="flex items-center gap-3">
                    <flux:button type="submit" variant="primary">
                        {{ $editingId ? __('Save changes') : __('Add category') }}
                    </flux:button>

                    @if ($editingId)
                        <flux:button type="button" variant="ghost" wire:click="cancelEdit">{{ __('Cancel') }}</flux:button>
                    @endif
                </div>
            </form>
        </flux:card>
    @endcan

    @forelse ($ageCategories as $ageCategory)
        <flux:card wire:key="age-{{ $ageCategory->id }}">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <flux:heading size="lg">{{ $ageCategory->name }}</flux:heading>
                    <flux:text class="mt-1">
                        {{ trans_choice('{0}No athletes|{1}:count athlete|[2,*]:count athletes', $ageCategory->athletes_count, ['count' => $ageCategory->athletes_count]) }}
                    </flux:text>
                </div>

                <div class="flex flex-wrap gap-2">
                    <flux:button size="sm" :href="route('athletes.index', $ageCategory)" wire:navigate>
                        {{ __('Registration') }}
                    </flux:button>
                    <flux:button size="sm" :href="route('weighin.index', $ageCategory)" wire:navigate>
                        {{ __('Weigh-in') }}
                    </flux:button>

                    @can('manage-competition')
                        <flux:button size="sm" variant="ghost" wire:click="edit({{ $ageCategory->id }})">{{ __('Edit') }}</flux:button>
                        <flux:button
                            size="sm"
                            variant="danger"
                            wire:click="delete({{ $ageCategory->id }})"
                            wire:confirm="{{ __('Delete this age category?') }}"
                        >{{ __('Delete') }}</flux:button>
                    @endcan
                </div>
            </div>

            <flux:separator class="my-4" />

            <div class="flex flex-wrap gap-2">
                @forelse ($ageCategory->weightCategories as $weightCategory)
                    <flux:button
                        size="sm"
                        variant="filled"
                        :href="route('bracket.show', $weightCategory)"
                        wire:navigate
                        wire:key="weight-{{ $weightCategory->id }}"
                    >
                        <span class="font-medium">{{ $weightCategory->label }}</span>
                        <span class="ms-2 text-zinc-500 tabular-nums">{{ $weightCategory->athletes_count }}</span>
                    </flux:button>
                @empty
                    <flux:text class="text-zinc-500">{{ __('No weight classes defined.') }}</flux:text>
                @endforelse
            </div>
        </flux:card>
    @empty
        <flux:card>
            <flux:text class="text-zinc-500">
                {{ __('No age categories yet. Add one above — for example "Men Senior" with -60, -66, -73, -81, -90, +90.') }}
            </flux:text>
        </flux:card>
    @endforelse
</div>
