<div class="flex flex-col gap-6">
    <div class="print:hidden">
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('championships.index')" wire:navigate>{{ __('Championships') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item :href="route('championships.show', $championship)" wire:navigate>{{ $championship->title }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ __('Entries') }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <flux:heading size="xl" class="mt-2">{{ __('Entries') }}</flux:heading>
        <flux:subheading>
            {{ __('How many are entered, how many made the scale, and which classes can start.') }}
        </flux:subheading>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        @foreach ([
            ['label' => __('Registered'), 'value' => $totalEntries],
            ['label' => __('Passed the scale'), 'value' => $totalCleared],
            ['label' => __('Classes ready to draw'), 'value' => $readyToDraw],
        ] as $stat)
            <flux:card class="flex flex-col gap-1">
                <div class="text-3xl font-semibold tabular-nums">{{ $stat['value'] }}</div>
                <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ $stat['label'] }}</flux:text>
            </flux:card>
        @endforeach
    </div>

    {{-- Specification §6.2. The Start button opens that class's draw directly;
         the old system stopped at a file-upload page first. --}}
    <flux:card class="flex flex-col gap-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <flux:heading size="lg">{{ __('Number of Entries by Weight Categories') }}</flux:heading>
                <flux:subheading>{{ __('Only athletes who passed the scale are counted as entries.') }}</flux:subheading>
            </div>

            <x-competition.exports route="exports.entries-weight" :params="['championship' => $championship]" />
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 text-left dark:border-zinc-700">
                        <th class="px-3 py-2 font-medium">{{ __('Weight category') }}</th>
                        <th class="px-3 py-2 text-right font-medium">{{ __('Registered') }}</th>
                        <th class="px-3 py-2 text-right font-medium">{{ __('Number of entries') }}</th>
                        <th class="px-3 py-2 font-medium">{{ __('Bracket') }}</th>
                        <th class="px-3 py-2 font-medium">{{ __("Athlete's list") }}</th>
                        <th class="px-3 py-2 font-medium">{{ __('Draw result') }}</th>
                        <th class="px-3 py-2 font-medium">{{ __('Draw status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($byWeight as $row)
                        @php $category = $row['category']; @endphp
                        <tr class="border-b border-zinc-100 last:border-0 dark:border-zinc-800" wire:key="weight-{{ $category->id }}">
                            <td class="px-3 py-2">
                                <div class="font-medium">{{ $category->exportName() }}</div>
                                <flux:text class="text-xs">{{ $category->ageCategory->name }}</flux:text>
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $row['registered'] }}</td>
                            <td class="px-3 py-2 text-right font-medium tabular-nums">{{ $row['cleared'] }}</td>
                            <td class="px-3 py-2 font-mono text-xs">{{ $row['bracket'] ?? '—' }}</td>
                            <td class="px-3 py-2">
                                <flux:button size="xs" variant="ghost"
                                    :href="route('exports.weigh-in', ['weightCategory' => $category, 'format' => 'pdf'])">
                                    PDF
                                </flux:button>
                            </td>
                            <td class="px-3 py-2">
                                @if ($row['drawn'])
                                    <flux:button size="xs" variant="ghost"
                                        :href="route('exports.draw', ['weightCategory' => $category, 'format' => 'pdf'])">
                                        PDF
                                    </flux:button>
                                    <flux:button size="xs" variant="ghost"
                                        :href="route('exports.draw', ['weightCategory' => $category, 'format' => 'csv'])">
                                        Excel
                                    </flux:button>
                                @else
                                    <span class="text-zinc-400">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-2">
                                @if ($row['drawn'])
                                    <div class="flex items-center gap-2">
                                        <flux:badge size="sm" color="green">{{ __('Done') }}</flux:badge>
                                        <flux:button size="xs" variant="ghost" :href="route('bracket.show', $category)" wire:navigate>
                                            {{ __('Open') }}
                                        </flux:button>
                                    </div>
                                @elseif ($row['cleared'] >= 2)
                                    <div class="flex items-center gap-2">
                                        <flux:badge size="sm" color="zinc">{{ __('Not Started') }}</flux:badge>
                                        @can('manage-competition')
                                            <flux:button size="xs" variant="primary" :href="route('bracket.show', $category)" wire:navigate>
                                                {{ __('Start') }}
                                            </flux:button>
                                        @endcan
                                    </div>
                                @else
                                    <flux:badge size="sm" color="amber">
                                        {{ __('Needs 2 cleared') }}
                                    </flux:badge>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-8 text-center text-zinc-500">{{ __('No weight classes yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </flux:card>

    {{-- Specification §6.1. --}}
    <flux:card class="flex flex-col gap-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <flux:heading size="lg">{{ __('Number of Entries by NOC') }}</flux:heading>
                <flux:subheading>{{ __('Largest delegations first.') }}</flux:subheading>
            </div>

            <x-competition.exports route="exports.entries-noc" :params="['championship' => $championship]" />
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 text-left dark:border-zinc-700">
                        <th class="px-3 py-2 font-medium">{{ __('NOC') }}</th>
                        <th class="px-3 py-2 font-medium">{{ __('Delegation') }}</th>
                        <th class="px-3 py-2 text-right font-medium">{{ __('Male') }}</th>
                        <th class="px-3 py-2 text-right font-medium">{{ __('Female') }}</th>
                        <th class="px-3 py-2 text-right font-medium">{{ __('Passed the scale') }}</th>
                        <th class="px-3 py-2 text-right font-medium">{{ __('Entries') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($byNoc as $row)
                        <tr class="border-b border-zinc-100 last:border-0 dark:border-zinc-800" wire:key="noc-{{ $row['noc'] }}">
                            <td class="px-3 py-2"><x-flag :noc="$row['noc']" :name="$row['name']" show-code /></td>
                            <td class="px-3 py-2">{{ $row['name'] ?? '—' }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $row['male'] }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $row['female'] }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $row['cleared'] }}</td>
                            <td class="px-3 py-2 text-right font-medium tabular-nums">{{ $row['total'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-8 text-center text-zinc-500">{{ __('Nobody is registered yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </flux:card>
</div>
