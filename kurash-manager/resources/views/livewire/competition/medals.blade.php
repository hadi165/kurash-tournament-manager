<x-page
    :kicker="$championship->title"
    :title="__('Medals')"
    :subtitle="trans_choice('{0}Every weight class is decided.|{1}:count weight class still undecided.|[2,*]:count weight classes still undecided.', $pending, ['count' => $pending])"
    :breadcrumbs="[
        ['label' => __('Championships'), 'href' => route('championships.index')],
        ['label' => $championship->title, 'href' => route('championships.show', $championship)],
        ['label' => __('Medals')],
    ]"
>
    <x-slot:actions>
        <span class="kicker me-1 text-ink/55">{{ __('Results') }}</span>
        <a href="{{ route('exports.results', ['championship' => $championship, 'format' => 'pdf']) }}"
           class="px-2.5 py-1 text-xs font-bold text-brand-700 no-underline hover:bg-brand-500/10 dark:text-brand-400">{{ __('PDF') }}</a>
        <a href="{{ route('exports.results', ['championship' => $championship, 'format' => 'csv']) }}"
           class="px-2.5 py-1 text-xs font-bold text-brand-700 no-underline hover:bg-brand-500/10 dark:text-brand-400">{{ __('Excel') }}</a>

        {{-- A certificate is a laid-out document, so it is PDF only and does not
             get the two-format pair the tabular exports get. --}}
        <a href="{{ route('exports.certificates', $championship) }}"
           class="px-2.5 py-1 text-xs font-bold text-brand-700 no-underline hover:bg-brand-500/10 dark:text-brand-400">{{ __('Certificates') }}</a>

        <span class="mx-1.5 h-5 w-0.5 bg-divider"></span>
    </x-slot:actions>

    <x-ui.card
        flush
        :title="__('Medal Standing')"
        :subtitle="__('Ordered by gold, then silver, then bronze.')"
    >
        <x-slot:head>
            <a href="{{ route('exports.medals', ['championship' => $championship, 'format' => 'pdf']) }}"
               class="px-2.5 py-1 text-xs font-bold text-brand-700 no-underline hover:bg-brand-500/10 dark:text-brand-400">{{ __('PDF') }}</a>
            <a href="{{ route('exports.medals', ['championship' => $championship, 'format' => 'csv']) }}"
               class="px-2.5 py-1 text-xs font-bold text-brand-700 no-underline hover:bg-brand-500/10 dark:text-brand-400">{{ __('Excel') }}</a>
        </x-slot:head>

        <div class="rule-2"></div>

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
                            <td class="num text-ink/55">{{ $i + 1 }}</td>
                            <td class="font-bold"><x-flag :noc="$row['noc_code']" size="md" show-code /></td>
                            <td class="num">{{ $row['gold'] }}</td>
                            <td class="num">{{ $row['silver'] }}</td>
                            <td class="num">{{ $row['bronze'] }}</td>
                            <td class="num font-bold">{{ $row['total'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center text-ink/55">{{ __('No medals awarded yet.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    {{-- One card per decided weight class. The places are a fixed set, so they
         are laid on the same four-cell grid every time rather than flowing:
         gold sits in the same place on every card, however many bronzes there
         are. --}}
    @foreach ($events as $event)
        <x-ui.card flush wire:key="event-{{ $event['category']->id }}">
            <div class="flex flex-wrap items-baseline gap-3 px-6 pb-4 pt-5">
                <h4 class="m-0 text-xl">{{ $event['category']->label }} {{ __('kg') }}</h4>
                <span class="kicker text-ink/55">{{ $event['category']->ageCategory->name }}</span>
            </div>

            <div class="rule-2"></div>

            <div class="grid gap-px bg-n-300 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))]">
                @foreach ([
                    ['label' => __('Gold'), 'athlete' => $event['gold'], 'accent' => true],
                    ['label' => __('Silver'), 'athlete' => $event['silver'], 'accent' => false],
                ] as $place)
                    <div class="bg-surface px-6 py-4">
                        <div @class(['kicker', 'text-brand-600 dark:text-brand-400' => $place['accent'], 'text-ink/55' => ! $place['accent']])>
                            {{ $place['label'] }}
                        </div>
                        <div class="mt-1.5 font-bold"><x-athlete :athlete="$place['athlete']" /></div>
                    </div>
                @endforeach

                @foreach ($event['bronze'] as $bronze)
                    <div class="bg-surface px-6 py-4" wire:key="ev-bronze-{{ $bronze->id }}">
                        <div class="kicker text-ink/55">{{ __('Bronze') }}</div>
                        <div class="mt-1.5 font-bold"><x-athlete :athlete="$bronze" /></div>
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    @endforeach
</x-page>
