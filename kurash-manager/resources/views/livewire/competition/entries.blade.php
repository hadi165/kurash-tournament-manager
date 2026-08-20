<x-page
    :title="__('Entries and Draw')"
    :subtitle="__('How many are entered, how many made the scale, and which classes can start.')"
    :breadcrumbs="[
        ['label' => __('Championships'), 'href' => route('championships.index')],
        ['label' => $championship->title, 'href' => route('championships.show', $championship)],
        ['label' => __('Entries and Draw')],
    ]"
>
    <x-ui.stats cards :items="[
        ['value' => $totalEntries, 'label' => __('Registered')],
        ['value' => $totalCleared, 'label' => __('Passed the scale')],
        ['value' => $readyToDraw, 'label' => __('Classes ready to draw'), 'accent' => true],
    ]" />

    {{-- Specification §6.2. The Start button opens that class's draw directly;
         the old system stopped at a file-upload page first. --}}
    <x-ui.card
        flush
        :title="__('Entries by weight category')"
        :subtitle="__('Only athletes who passed the scale are counted as entries.')"
    >
        <x-slot:head>
            <x-ui.chip :href="route('exports.entries-weight', ['championship' => $championship, 'format' => 'pdf'])">{{ __('PDF') }}</x-ui.chip>
            <x-ui.chip :href="route('exports.entries-weight', ['championship' => $championship, 'format' => 'csv'])">{{ __('Excel') }}</x-ui.chip>
        </x-slot:head>

        <div class="overflow-x-auto">
            <table class="t">
                <thead>
                    <tr>
                        <th>{{ __('Weight category') }}</th>
                        <th class="num">{{ __('Registered') }}</th>
                        <th class="num">{{ __('Entries') }}</th>
                        <th>{{ __('Bracket') }}</th>
                        <th>{{ __('Exports') }}</th>
                        <th>{{ __('Draw status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($byWeight as $row)
                        @php $category = $row['category']; @endphp

                        <tr wire:key="weight-{{ $category->id }}">
                            <td>
                                <div class="font-semibold">{{ $category->exportName() }}</div>
                                <div class="text-[12.5px] text-muted">{{ $category->ageCategory->name }}</div>
                            </td>
                            <td class="num">{{ $row['registered'] }}</td>
                            <td class="num font-semibold">{{ $row['cleared'] }}</td>
                            <td class="font-mono text-xs text-muted">{{ $row['bracket'] ?? '—' }}</td>
                            <td>
                                <div class="flex gap-1.5">
                                    {{-- The athletes' list, and the drawn bracket
                                         once there is one to print. --}}
                                    <x-ui.chip :href="route('exports.weigh-in', ['weightCategory' => $category, 'format' => 'pdf'])">
                                        {{ __('List') }}
                                    </x-ui.chip>

                                    @if ($row['drawn'])
                                        <x-ui.chip :href="route('exports.draw', ['weightCategory' => $category, 'format' => 'pdf'])">
                                            {{ __('Draw') }}
                                        </x-ui.chip>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <div class="flex items-center gap-2">
                                    @if ($row['drawn'])
                                        <x-ui.tag variant="brand">{{ __('Done') }}</x-ui.tag>
                                        <x-ui.chip :href="route('bracket.show', $category)" wire:navigate>{{ __('Open') }}</x-ui.chip>
                                    @elseif ($row['cleared'] >= 2)
                                        <x-ui.tag>{{ __('Not started') }}</x-ui.tag>
                                        @can('manage-competition')
                                            <flux:button size="xs" variant="primary" :href="route('bracket.show', $category)" wire:navigate>
                                                {{ __('Start draw') }}
                                            </flux:button>
                                        @endcan
                                    @else
                                        <x-ui.tag variant="danger">{{ __('Needs 2 cleared') }}</x-ui.tag>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center text-muted">{{ __('No weight classes yet.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    {{-- Specification §6.1. --}}
    <x-ui.card flush :title="__('Entries by NOC')" :subtitle="__('Largest delegations first.')">
        <x-slot:head>
            <x-ui.chip :href="route('exports.entries-noc', ['championship' => $championship, 'format' => 'pdf'])">{{ __('PDF') }}</x-ui.chip>
            <x-ui.chip :href="route('exports.entries-noc', ['championship' => $championship, 'format' => 'csv'])">{{ __('Excel') }}</x-ui.chip>
        </x-slot:head>

        <div class="overflow-x-auto">
            <table class="t">
                <thead>
                    <tr>
                        <th>{{ __('NOC') }}</th>
                        <th>{{ __('Delegation') }}</th>
                        <th class="num">{{ __('Male') }}</th>
                        <th class="num">{{ __('Female') }}</th>
                        <th class="num">{{ __('Passed the scale') }}</th>
                        <th class="num">{{ __('Entries') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($byNoc as $row)
                        <tr wire:key="noc-{{ $row['noc'] }}">
                            <td>
                                <span class="inline-flex items-center gap-2">
                                    <x-flag :noc="$row['noc']" :name="$row['name']" />
                                    <span class="rounded-sm border border-line bg-ground px-2 py-0.5 font-mono text-[11.5px]">{{ $row['noc'] }}</span>
                                </span>
                            </td>
                            <td class="text-muted">{{ $row['name'] ?? '—' }}</td>
                            <td class="num">{{ $row['male'] }}</td>
                            <td class="num">{{ $row['female'] }}</td>
                            <td class="num">{{ $row['cleared'] }}</td>
                            <td class="num font-semibold">{{ $row['total'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center text-muted">{{ __('Nobody is registered yet.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
</x-page>
