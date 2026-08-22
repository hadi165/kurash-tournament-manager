@php
    // The three figures describe the list on screen, so they follow the class
    // filter rather than reporting the whole category behind it.
    $weighed = $athletes->whereNotNull('weighin_kg')->count();
    $passed = $athletes->where('weighin_status', 'pass')->count();
    $failed = $weighed - $passed;
@endphp

<x-page
    :kicker="__(App\Support\Gender::label($competition))"
    kicker-variant="info"
    :title="__('Weigh-in')"
    :subtitle="__('A 0.5 kg tolerance applies below an upper limit. Open classes have no upper bound.')"
    :breadcrumbs="[
        ['label' => __('Championships'), 'href' => route('championships.index')],
        ['label' => $championship->title, 'href' => route('championships.show', $championship)],
        ['label' => __('Weigh-in')],
    ]"
>
    <x-competition.flash />

    <x-ui.stats cards :items="[
        ['value' => $weighed, 'label' => __('Weighed')],
        ['value' => $passed, 'label' => __('Passed'), 'accent' => true],
        ['value' => $failed, 'label' => __('Outside class'), 'danger' => true],
    ]" />

    <x-ui.card flush>
        <div class="flex flex-wrap items-center justify-between gap-3 px-7 pb-4 pt-5">
            <div class="flex flex-wrap items-center gap-3">
                <label for="weigh-filter" class="text-[12.5px] font-semibold text-muted">{{ __('Weight class') }}</label>
                <flux:select id="weigh-filter" wire:model.live="weightFilter" size="sm" class="w-[200px]">
                    <flux:select.option value="">{{ __('All classes') }}</flux:select.option>
                    {{-- Prefixed with the age group only where there is more
                         than one, because otherwise two -66s in the same list
                         say nothing about which is which. --}}
                    @foreach ($weightCategories as $weightCategory)
                        <flux:select.option value="{{ $weightCategory->id }}">
                            @if ($divisions->count() > 1){{ $weightCategory->ageCategory?->age_group }} @endif{{ $weightCategory->label }} {{ __('kg') }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            @if ($weightFilter)
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-[12.5px] text-muted">{{ __('Confirmed list') }}</span>
                    <x-ui.chip :href="route('exports.weigh-in', ['weightCategory' => $weightFilter, 'format' => 'pdf'])">{{ __('PDF') }}</x-ui.chip>
                    <x-ui.chip :href="route('exports.weigh-in', ['weightCategory' => $weightFilter, 'format' => 'csv'])">{{ __('Excel') }}</x-ui.chip>
                </div>
            @endif
        </div>

        <div class="overflow-x-auto">
            <table class="t">
                <thead>
                    <tr>
                        <th>{{ __('IKA ID') }}</th>
                        <th>{{ __('Name') }}</th>
                        <th>{{ __('NOC') }}</th>
                        <th>{{ __('Class') }}</th>
                        <th>{{ __('Measured') }}</th>
                        <th>{{ __('Result') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($athletes as $athlete)
                        <tr wire:key="weighin-{{ $athlete->id }}">
                            <td class="font-mono text-xs text-muted">{{ $athlete->ika_id }}</td>
                            <td class="font-semibold">{{ $athlete->fullname }}</td>
                            <td>
                                <span class="inline-flex items-center gap-2">
                                    <x-flag :noc="$athlete->noc_code" :name="$athlete->noc_name" />
                                    <span class="rounded-sm border border-line bg-ground px-2 py-0.5 font-mono text-[11.5px]">
                                        {{ \App\Support\Noc::normalise($athlete->noc_code) }}
                                    </span>
                                </span>
                            </td>
                            <td>{{ $athlete->weightCategory?->label ?? '—' }}</td>
                            <td>
                                @can('manage-competition')
                                    <div class="flex items-center gap-2">
                                        <flux:input
                                            wire:model="weights.{{ $athlete->id }}"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            size="sm"
                                            class="w-24"
                                        />
                                        <x-ui.chip wire:click="record({{ $athlete->id }})">{{ __('Save') }}</x-ui.chip>
                                    </div>
                                @else
                                    <span class="tabular-nums">{{ $athlete->weighin_kg ?? '—' }}</span>
                                @endcan
                            </td>
                            <td>
                                @if ($athlete->weighin_kg === null)
                                    <x-ui.tag>{{ __('Not weighed') }}</x-ui.tag>
                                @elseif ($athlete->weighin_status === 'pass')
                                    <x-ui.tag variant="brand">{{ $athlete->weighin_kg }} {{ __('kg') }}</x-ui.tag>
                                @else
                                    <x-ui.tag variant="danger">{{ __('Outside class') }}</x-ui.tag>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center text-muted">{{ __('No athletes to weigh in.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
</x-page>
