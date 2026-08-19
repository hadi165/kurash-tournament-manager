<div class="flex flex-col gap-6">
    <div>
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('championships.index')" wire:navigate>{{ __('Championships') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item :href="route('championships.show', $ageCategory->championship)" wire:navigate>
                {{ $ageCategory->championship->title }}
            </flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ __('Registration') }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <flux:heading size="xl" class="mt-2">{{ __('Registration') }} — {{ $ageCategory->name }}</flux:heading>
        <flux:subheading>{{ __('Each athlete gets an IKA ID on registration, independent of any passport number.') }}</flux:subheading>

        {{-- Cards are laid out, not tabulated, so they are PDF only. Four to a
             sheet, cut on the cell borders. --}}
        <div class="mt-4 flex items-center gap-2 print:hidden">
            <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Accreditation cards') }}</flux:text>
            <flux:button size="xs" variant="ghost" icon="identification" :href="route('exports.accreditation.category', $ageCategory)">
                {{ __('This category') }}
            </flux:button>
            <flux:button size="xs" variant="ghost" icon="identification" :href="route('exports.accreditation', $ageCategory->championship)">
                {{ __('Whole championship') }}
            </flux:button>
        </div>
    </div>

    <x-competition.flash />

    @can('manage-competition')
        <flux:card>
            <form wire:submit="save" class="flex flex-col gap-4">
                <flux:heading size="lg">{{ $editingId ? __('Edit athlete') : __('Register athlete') }}</flux:heading>

                <div class="grid gap-4 md:grid-cols-3">
                    <flux:input wire:model="fullname" :label="__('Full name')" required />
                    <flux:input wire:model="noc_code" :label="__('NOC code')" placeholder="UZB" maxlength="8" required />
                    <flux:input wire:model="noc_name" :label="__('Country')" placeholder="{{ __('Uzbekistan') }}" />

                    <flux:select wire:model="gender" :label="__('Gender')" required>
                        <flux:select.option value="M">{{ __('Male') }}</flux:select.option>
                        <flux:select.option value="F">{{ __('Female') }}</flux:select.option>
                    </flux:select>

                    <flux:select wire:model="weight_category_id" :label="__('Weight class')" required>
                        <flux:select.option value="">{{ __('Select…') }}</flux:select.option>
                        @foreach ($weightCategories as $weightCategory)
                            <flux:select.option value="{{ $weightCategory->id }}">{{ $weightCategory->label }} kg</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:input wire:model="national_id" :label="__('Passport / national ID')" :description="__('Optional')" />
                </div>

                <div class="flex items-center gap-3">
                    <flux:button type="submit" variant="primary">
                        {{ $editingId ? __('Save changes') : __('Register') }}
                    </flux:button>

                    @if ($editingId)
                        <flux:button type="button" variant="ghost" wire:click="cancelEdit">{{ __('Cancel') }}</flux:button>
                    @endif
                </div>
            </form>
        </flux:card>
    @endcan

    <flux:card class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:heading size="lg">
                {{ trans_choice('{0}No athletes|{1}:count athlete|[2,*]:count athletes', $athletes->count(), ['count' => $athletes->count()]) }}
            </flux:heading>

            <flux:input
                wire:model.live.debounce.300ms="search"
                icon="magnifying-glass"
                :placeholder="__('Search name, IKA ID or NOC')"
                class="max-w-xs"
            />
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 text-left dark:border-zinc-700">
                        <th class="px-3 py-2 font-medium">{{ __('IKA ID') }}</th>
                        <th class="px-3 py-2 font-medium">{{ __('Name') }}</th>
                        <th class="px-3 py-2 font-medium">{{ __('NOC') }}</th>
                        <th class="px-3 py-2 font-medium">{{ __('Weight') }}</th>
                        <th class="px-3 py-2 font-medium">{{ __('Weigh-in') }}</th>
                        <th class="px-3 py-2 font-medium tabular-nums">{{ __('Draw') }}</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($athletes as $athlete)
                        <tr class="border-b border-zinc-100 last:border-0 dark:border-zinc-800" wire:key="athlete-{{ $athlete->id }}">
                            <td class="px-3 py-2 font-mono text-xs">{{ $athlete->ika_id }}</td>
                            <td class="px-3 py-2 font-medium">{{ $athlete->fullname }}</td>
                            <td class="px-3 py-2"><x-flag :noc="$athlete->noc_code" :name="$athlete->noc_name" show-code /></td>
                            <td class="px-3 py-2">{{ $athlete->weightCategory?->label ?? '—' }}</td>
                            <td class="px-3 py-2">
                                @if ($athlete->weighin_kg === null)
                                    <flux:badge size="sm" color="zinc">{{ __('Not weighed') }}</flux:badge>
                                @elseif ($athlete->weighin_status === 'pass')
                                    <flux:badge size="sm" color="green">{{ $athlete->weighin_kg }} kg</flux:badge>
                                @else
                                    <flux:badge size="sm" color="red">{{ $athlete->weighin_kg }} kg</flux:badge>
                                @endif
                            </td>
                            <td class="px-3 py-2 tabular-nums">{{ $athlete->draw_number ?? '—' }}</td>
                            <td class="px-3 py-2 text-right">
                                @can('manage-competition')
                                    <div class="flex justify-end gap-2">
                                        <flux:button size="sm" variant="ghost" wire:click="edit({{ $athlete->id }})">{{ __('Edit') }}</flux:button>
                                        <flux:button
                                            size="sm"
                                            variant="danger"
                                            wire:click="delete({{ $athlete->id }})"
                                            wire:confirm="{{ __('Remove this athlete?') }}"
                                        >{{ __('Remove') }}</flux:button>
                                    </div>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-3 py-8 text-center text-zinc-500">
                                {{ $search !== '' ? __('No athletes match that search.') : __('No athletes registered yet.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </flux:card>
</div>
