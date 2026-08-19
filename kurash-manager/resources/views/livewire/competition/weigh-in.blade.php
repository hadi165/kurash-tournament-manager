<x-page
    :kicker="$ageCategory->championship->title"
    :title="__('Weigh-in Form')"
    :subtitle="__('A 0.5 kg tolerance applies below an upper limit. Open classes have no upper bound.')"
    :breadcrumbs="[
        ['label' => __('Championships'), 'href' => route('championships.index')],
        ['label' => $ageCategory->championship->title, 'href' => route('championships.show', $ageCategory->championship)],
        ['label' => __('Weigh-in')],
    ]"
>
    <x-competition.flash />

    <x-ui.card flush>
        <div class="flex flex-wrap items-end justify-between gap-3 px-6 pb-4 pt-5">
            <div class="flex flex-col gap-1.5">
                <label for="weigh-filter" class="kicker">{{ __('Weight class') }}</label>
                <flux:select id="weigh-filter" wire:model.live="weightFilter" class="max-w-xs">
                    <flux:select.option value="">{{ __('All classes') }}</flux:select.option>
                    @foreach ($weightCategories as $weightCategory)
                        <flux:select.option value="{{ $weightCategory->id }}">{{ $weightCategory->label }} kg</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            @if ($weightFilter)
                <div class="flex items-center gap-2">
                    <span class="kicker text-ink/55">{{ __('Confirmed list') }}</span>
                    <a href="{{ route('exports.weigh-in', ['weightCategory' => $weightFilter, 'format' => 'pdf']) }}"
                       class="px-2.5 py-1 text-xs font-bold text-brand-700 no-underline hover:bg-brand-500/10 dark:text-brand-400">{{ __('PDF') }}</a>
                    <a href="{{ route('exports.weigh-in', ['weightCategory' => $weightFilter, 'format' => 'csv']) }}"
                       class="px-2.5 py-1 text-xs font-bold text-brand-700 no-underline hover:bg-brand-500/10 dark:text-brand-400">{{ __('Excel') }}</a>
                </div>
            @endif
        </div>

        <div class="rule-2"></div>

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
                            <td class="font-mono text-xs">{{ $athlete->ika_id }}</td>
                            <td class="font-bold">{{ $athlete->fullname }}</td>
                            <td><x-flag :noc="$athlete->noc_code" :name="$athlete->noc_name" show-code /></td>
                            <td>{{ $athlete->weightCategory?->label ?? '—' }}</td>
                            <td>
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
                            <td>
                                @if ($athlete->weighin_kg === null)
                                    <x-ui.tag variant="outline">{{ __('Not weighed') }}</x-ui.tag>
                                @elseif ($athlete->weighin_status === 'pass')
                                    <x-ui.tag variant="brand">{{ __('Passed') }}</x-ui.tag>
                                @else
                                    <x-ui.tag variant="danger">{{ __('Outside class') }}</x-ui.tag>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center text-ink/55">{{ __('No athletes to weigh in.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
</x-page>
