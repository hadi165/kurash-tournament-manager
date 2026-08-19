<div class="flex flex-col gap-6">
    <div>
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('championships.index')" wire:navigate>{{ __('Championships') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item :href="route('championships.show', $ageCategory->championship)" wire:navigate>
                {{ $ageCategory->championship->title }}
            </flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ __('Weigh-in') }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <flux:heading size="xl" class="mt-2">{{ __('Weigh-in') }} — {{ $ageCategory->name }}</flux:heading>
        <flux:subheading>{{ __('A 0.5 kg tolerance applies below an upper limit. Open classes have no upper bound.') }}</flux:subheading>
    </div>

    <x-competition.flash />

    <flux:card class="flex flex-col gap-4">
        <flux:select wire:model.live="weightFilter" :label="__('Weight class')" class="max-w-xs">
            <flux:select.option value="">{{ __('All classes') }}</flux:select.option>
            @foreach ($weightCategories as $weightCategory)
                <flux:select.option value="{{ $weightCategory->id }}">{{ $weightCategory->label }} kg</flux:select.option>
            @endforeach
        </flux:select>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 text-left dark:border-zinc-700">
                        <th class="px-3 py-2 font-medium">{{ __('IKA ID') }}</th>
                        <th class="px-3 py-2 font-medium">{{ __('Name') }}</th>
                        <th class="px-3 py-2 font-medium">{{ __('NOC') }}</th>
                        <th class="px-3 py-2 font-medium">{{ __('Class') }}</th>
                        <th class="px-3 py-2 font-medium">{{ __('Measured') }}</th>
                        <th class="px-3 py-2 font-medium">{{ __('Result') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($athletes as $athlete)
                        <tr class="border-b border-zinc-100 last:border-0 dark:border-zinc-800" wire:key="weighin-{{ $athlete->id }}">
                            <td class="px-3 py-2 font-mono text-xs">{{ $athlete->ika_id }}</td>
                            <td class="px-3 py-2 font-medium">{{ $athlete->fullname }}</td>
                            <td class="px-3 py-2"><x-flag :noc="$athlete->noc_code" :name="$athlete->noc_name" show-code /></td>
                            <td class="px-3 py-2">{{ $athlete->weightCategory?->label ?? '—' }}</td>
                            <td class="px-3 py-2">
                                @can('manage-competition')
                                    <div class="flex items-center gap-2">
                                        <flux:input
                                            wire:model="weights.{{ $athlete->id }}"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            class="w-28"
                                            size="sm"
                                        />
                                        <flux:button size="sm" wire:click="record({{ $athlete->id }})">{{ __('Save') }}</flux:button>
                                    </div>
                                @else
                                    <span class="tabular-nums">{{ $athlete->weighin_kg ?? '—' }}</span>
                                @endcan
                            </td>
                            <td class="px-3 py-2">
                                @if ($athlete->weighin_kg === null)
                                    <flux:badge size="sm" color="zinc">{{ __('Not weighed') }}</flux:badge>
                                @elseif ($athlete->weighin_status === 'pass')
                                    <flux:badge size="sm" color="green">{{ __('Passed') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="red">{{ __('Outside class') }}</flux:badge>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-8 text-center text-zinc-500">{{ __('No athletes to weigh in.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </flux:card>
</div>
