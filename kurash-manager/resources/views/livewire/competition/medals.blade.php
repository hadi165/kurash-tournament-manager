<x-page
    :title="__('Results & medals')"
    :subtitle="trans_choice('{0}Every weight class is decided.|{1}:count weight class still undecided.|[2,*]:count weight classes still undecided.', $pending, ['count' => $pending])"
    :breadcrumbs="[
        ['label' => __('Championships'), 'href' => route('championships.index')],
        ['label' => $championship->title, 'href' => route('championships.show', $championship)],
        ['label' => __('Medals')],
    ]"
>
    <x-slot:aside>
        <x-ui.chip :href="route('exports.medals', ['championship' => $championship, 'format' => 'pdf'])">{{ __('Standing') }} · PDF</x-ui.chip>
        <x-ui.chip :href="route('exports.medals', ['championship' => $championship, 'format' => 'csv'])">{{ __('Standing') }} · {{ __('Excel') }}</x-ui.chip>
        <x-ui.chip :href="route('exports.results', ['championship' => $championship, 'format' => 'pdf'])">{{ __('Results') }} · PDF</x-ui.chip>

        {{-- A certificate is a laid-out document, so it is PDF only and does not
             get the two-format pair the tabular exports get. --}}
        <x-ui.chip :href="route('exports.certificates', $championship)">{{ __('Certificates') }} · PDF</x-ui.chip>
    </x-slot:aside>

    <x-ui.card flush>
        <div class="overflow-x-auto">
            <table class="t">
                <thead>
                    <tr>
                        <th class="num">{{ __('#') }}</th>
                        <th>{{ __('NOC') }}</th>
                        <th class="num">{{ __('Gold') }}</th>
                        <th class="num">{{ __('Silver') }}</th>
                        <th class="num">{{ __('Bronze') }}</th>
                        <th class="num">{{ __('Total') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($standings as $i => $row)
                        <tr wire:key="noc-{{ $row['noc_code'] }}">
                            <td class="num text-muted">{{ $i + 1 }}</td>
                            <td>
                                <span class="inline-flex items-center gap-2">
                                    <x-flag :noc="$row['noc_code']" size="md" />
                                    <span class="rounded-sm border border-line bg-ground px-2 py-0.5 font-mono text-[11.5px]">
                                        {{ $row['noc_code'] }}
                                    </span>
                                </span>
                            </td>

                            {{-- Gold carries the colour: a medal table is read
                                 down that column first. --}}
                            <td class="num font-semibold text-brand-deep">{{ $row['gold'] }}</td>
                            <td class="num">{{ $row['silver'] }}</td>
                            <td class="num">{{ $row['bronze'] }}</td>
                            <td class="num font-bold">{{ $row['total'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center text-muted">{{ __('No medals awarded yet.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    {{-- One card per decided weight class. The places are a fixed set, so they
         are laid on the same grid every time: gold sits in the same cell on
         every card, however many bronzes the class awarded. --}}
    @foreach ($events as $event)
        @php
            $podium = collect([
                ['medal' => __('Gold'), 'variant' => 'brand', 'athlete' => $event['gold']],
                ['medal' => __('Silver'), 'variant' => 'info', 'athlete' => $event['silver']],
            ])->concat(
                collect($event['bronze'])->map(fn ($athlete) => ['medal' => __('Bronze'), 'variant' => 'muted', 'athlete' => $athlete])
            );
        @endphp

        <x-ui.card
            :title="$event['category']->ageCategory->name . ' — ' . $event['category']->label . ' ' . __('kg')"
            wire:key="event-{{ $event['category']->id }}"
        >
            <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))]">
                @foreach ($podium as $place)
                    <div class="rounded-md border border-line bg-ground px-4 py-3.5" wire:key="place-{{ $event['category']->id }}-{{ $loop->index }}">
                        <x-ui.tag :variant="$place['variant']">{{ $place['medal'] }}</x-ui.tag>

                        <div class="mt-2.5 text-[14.5px] font-semibold">
                            {{ $place['athlete']?->fullname ?? '—' }}
                        </div>

                        <div class="mt-0.5 text-[12.5px] text-muted">
                            <x-flag :noc="$place['athlete']?->noc_code" :name="$place['athlete']?->noc_name" show-code />
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    @endforeach
</x-page>
