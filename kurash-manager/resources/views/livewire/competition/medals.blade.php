<div class="flex flex-col gap-6">
    <div>
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('championships.index')" wire:navigate>{{ __('Championships') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item :href="route('championships.show', $championship)" wire:navigate>{{ $championship->title }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ __('Medals') }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <flux:heading size="xl" class="mt-2">{{ __('Medals') }}</flux:heading>
        <flux:subheading>
            {{ trans_choice('{0}Every weight class is decided|{1}:count weight class still undecided|[2,*]:count weight classes still undecided', $pending, ['count' => $pending]) }}
        </flux:subheading>

        <div class="mt-4 flex flex-wrap items-center gap-6">
            <x-competition.exports
                route="exports.medals"
                :params="['championship' => $championship]"
                :label="__('Medal standing')"
            />
            <x-competition.exports
                route="exports.results"
                :params="['championship' => $championship]"
                :label="__('Results')"
            />

            {{-- A certificate is a laid-out document, so it is PDF only and does
                 not go through the two-format exports component. --}}
            <div class="flex items-center gap-2 print:hidden">
                <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Certificates') }}</flux:text>
                <flux:button size="xs" variant="ghost" icon="document-arrow-down" :href="route('exports.certificates', $championship)">
                    {{ __('PDF') }}
                </flux:button>
            </div>
        </div>
    </div>

    <flux:card class="p-0 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-zinc-200 text-left dark:border-zinc-700">
                    <th class="px-4 py-3 font-medium">{{ __('NOC') }}</th>
                    <th class="px-4 py-3 font-medium tabular-nums">{{ __('Gold') }}</th>
                    <th class="px-4 py-3 font-medium tabular-nums">{{ __('Silver') }}</th>
                    <th class="px-4 py-3 font-medium tabular-nums">{{ __('Bronze') }}</th>
                    <th class="px-4 py-3 font-medium tabular-nums">{{ __('Total') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($standings as $row)
                    <tr class="border-b border-zinc-100 last:border-0 dark:border-zinc-800" wire:key="noc-{{ $row['noc_code'] }}">
                        <td class="px-4 py-3 font-medium"><x-flag :noc="$row['noc_code']" size="md" show-code /></td>
                        <td class="px-4 py-3 tabular-nums">{{ $row['gold'] }}</td>
                        <td class="px-4 py-3 tabular-nums">{{ $row['silver'] }}</td>
                        <td class="px-4 py-3 tabular-nums">{{ $row['bronze'] }}</td>
                        <td class="px-4 py-3 font-medium tabular-nums">{{ $row['total'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-zinc-500">{{ __('No medals awarded yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </flux:card>

    @foreach ($events as $event)
        <flux:card wire:key="event-{{ $event['category']->id }}">
            <flux:heading size="lg">
                {{ $event['category']->ageCategory->name }} — {{ $event['category']->label }} kg
            </flux:heading>

            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Gold') }}</flux:text>
                    <div class="font-medium"><x-athlete :athlete="$event['gold']" /></div>
                </div>
                <div>
                    <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Silver') }}</flux:text>
                    <div class="font-medium"><x-athlete :athlete="$event['silver']" /></div>
                </div>
                @foreach ($event['bronze'] as $bronze)
                    <div wire:key="ev-bronze-{{ $bronze->id }}">
                        <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Bronze') }}</flux:text>
                        <div class="font-medium"><x-athlete :athlete="$bronze" /></div>
                    </div>
                @endforeach
            </div>
        </flux:card>
    @endforeach
</div>
