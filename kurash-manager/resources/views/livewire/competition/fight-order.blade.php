<x-page
    :kicker="$championship->title"
    :title="__('Fight Order')"
    :subtitle="__('Every weight class runs round by round, so athletes get bouts between their own.')"
    :breadcrumbs="[
        ['label' => __('Championships'), 'href' => route('championships.index')],
        ['label' => $championship->title, 'href' => route('championships.show', $championship)],
        ['label' => __('Fight order')],
    ]"
>
    <x-slot:actions>
        <a href="{{ route('exports.fight-order', ['championship' => $championship, 'format' => 'pdf']) }}"
           class="px-2.5 py-1 text-xs font-bold text-brand-700 no-underline hover:bg-brand-500/10 dark:text-brand-400">{{ __('PDF') }}</a>
        <a href="{{ route('exports.fight-order', ['championship' => $championship, 'format' => 'csv']) }}"
           class="px-2.5 py-1 text-xs font-bold text-brand-700 no-underline hover:bg-brand-500/10 dark:text-brand-400">{{ __('Excel') }}</a>
        <button type="button" onclick="window.print()"
                class="px-2.5 py-1 text-xs font-bold text-brand-700 hover:bg-brand-500/10 dark:text-brand-400">{{ __('Print') }}</button>
        <span class="mx-1.5 h-5 w-0.5 bg-divider"></span>
    </x-slot:actions>

    <div class="hidden print:block">
        <h1 class="text-xl font-bold">{{ $championship->title }} — {{ __('Fight order') }}</h1>
    </div>

    <div class="print:hidden"><x-competition.flash /></div>

    @can('manage-competition')
        <x-ui.card class="print:hidden">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="flex flex-col gap-1.5">
                        <label for="min-rest" class="kicker">{{ __('Minimum bouts of rest') }}</label>
                        <flux:input id="min-rest" wire:model="minimumRest" type="number" min="0" max="20" class="w-44" />
                    </div>

                    <flux:button variant="primary" wire:click="schedule">{{ __('Build running order') }}</flux:button>
                    <flux:button variant="ghost" wire:click="clear" wire:confirm="{{ __('Clear every fight number?') }}">
                        {{ __('Clear') }}
                    </flux:button>
                </div>

                <flux:checkbox wire:model.live="hideCompleted" :label="__('Hide finished')" />
            </div>

            {{-- Without a mat there is nowhere to send a bout, so the whole
                 send-to-mat control disappears from every row. Saying so beats
                 leaving an operator to wonder where the buttons went. --}}
            @if ($courts->isEmpty())
                <div class="mt-4 flex flex-wrap items-center gap-3 border border-danger-200 bg-danger-100/60 p-3 dark:bg-danger-500/10">
                    <x-ui.tag variant="danger">{{ __('No mats') }}</x-ui.tag>
                    <span class="text-sm">{{ __('No mats are set up, so bouts cannot be sent to a scoreboard yet.') }}</span>
                    <flux:button size="xs" :href="route('courts.index', $championship)" wire:navigate>{{ __('Add a mat') }}</flux:button>
                </div>
            @endif

            @if ($unscheduled > 0)
                <div class="mt-4 flex flex-wrap items-center gap-3 border border-danger-200 bg-danger-100/60 p-3 dark:bg-danger-500/10">
                    <x-ui.tag variant="danger">{{ __('Unscheduled') }}</x-ui.tag>
                    <span class="text-sm">
                        {{ trans_choice(
                            '{1}:count bout has no fight number yet.|[2,*]:count bouts have no fight number yet.',
                            $unscheduled, ['count' => $unscheduled]
                        ) }}
                    </span>
                </div>
            @endif

            @if ($violations->isNotEmpty())
                <div class="mt-4 flex flex-col gap-1 border border-divider p-3">
                    <span class="text-sm font-bold">
                        {{ trans_choice(
                            '{1}:count bout gives less rest than requested.|[2,*]:count bouts give less rest than requested.',
                            $violations->count(), ['count' => $violations->count()]
                        ) }}
                    </span>
                    @foreach ($violations->take(5) as $violation)
                        <span class="text-[13px] text-ink/55">
                            {{ __('Fight :n follows fight :from after only :gap bout(s).', [
                                'n' => $violation['bout']->fight_number,
                                'from' => $violation['feeder']->fight_number,
                                'gap' => $violation['gap'],
                            ]) }}
                        </span>
                    @endforeach
                </div>
            @endif
        </x-ui.card>
    @endcan

    <x-ui.card flush>
        <div class="overflow-x-auto">
            <table class="t">
                <thead>
                    <tr>
                        <th class="num">{{ __('#') }}</th>
                        <th>{{ __('Category') }}</th>
                        <th>{{ __('Phase') }}</th>
                        <th>{{ __('Blue') }}</th>
                        <th>{{ __('Green') }}</th>
                        <th>{{ __('Mat') }}</th>
                        <th>{{ __('Winner') }}</th>
                        <th class="print:hidden"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($bouts as $bout)
                        {{-- A decided bout is history: it drops back so the
                             contests still to come carry the eye. --}}
                        <tr @class(['opacity-55' => $bout->isDecided()]) wire:key="fo-{{ $bout->id }}">
                            <td class="num font-bold">{{ $bout->fight_number }}</td>
                            <td>{{ $bout->weightCategory->label }} {{ __('kg') }}</td>
                            <td class="text-ink/55">{{ $bout->phase((int) ($roundsByCategory[$bout->weight_category_id] ?? $bout->round)) }}</td>

                            {{-- The corner colour is carried by a square beside
                                 the name, so the column reads as a corner even
                                 when the heading has scrolled away. --}}
                            <td @class(['font-bold' => $bout->winner_athlete_id && $bout->winner_athlete_id === $bout->athlete_a_id])>
                                <span class="inline-flex items-center gap-2">
                                    <span class="size-2.5 flex-none bg-info-500"></span>
                                    <x-athlete :athlete="$bout->athleteA" />
                                </span>
                            </td>
                            <td @class(['font-bold' => $bout->winner_athlete_id && $bout->winner_athlete_id === $bout->athlete_b_id])>
                                <span class="inline-flex items-center gap-2">
                                    <span class="size-2.5 flex-none bg-brand-500"></span>
                                    <x-athlete :athlete="$bout->athleteB" />
                                </span>
                            </td>

                            <td class="text-ink/55">{{ $bout->court?->label() ?? '—' }}</td>
                            <td><x-athlete :athlete="$bout->winner" /></td>
                            <td class="print:hidden">
                                @can('manage-competition')
                                    <div class="flex justify-end gap-1">
                                        @if (! $bout->isDecided())
                                            <flux:button size="xs" variant="ghost" wire:click="move({{ $bout->id }}, 'up')" icon="chevron-up" />
                                            <flux:button size="xs" variant="ghost" wire:click="move({{ $bout->id }}, 'down')" icon="chevron-down" />

                                            @if ($bout->isReadyToFight())
                                                @foreach ($courts as $court)
                                                    <flux:button
                                                        size="xs"
                                                        wire:click="sendToMat({{ $bout->id }}, {{ $court->id }})"
                                                        wire:key="fo-mat-{{ $bout->id }}-{{ $court->id }}"
                                                    >{{ __('Mat :n', ['n' => $court->number]) }}</flux:button>
                                                @endforeach
                                            @endif
                                        @endif
                                    </div>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-8 text-center text-ink/55">
                                {{ __('No running order yet. Draw the brackets, then build the order.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
</x-page>
