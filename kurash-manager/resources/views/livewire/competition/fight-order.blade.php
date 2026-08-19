<div class="flex flex-col gap-6">
    <div class="print:hidden">
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('championships.index')" wire:navigate>{{ __('Championships') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item :href="route('championships.show', $championship)" wire:navigate>{{ $championship->title }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ __('Fight order') }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <flux:heading size="xl" class="mt-2">{{ __('Fight order') }}</flux:heading>
        <flux:subheading>
            {{ __('Every weight class runs round by round, so athletes get bouts between their own.') }}
        </flux:subheading>

        <div class="mt-4">
            <x-competition.exports route="exports.fight-order" :params="['championship' => $championship]" />
        </div>
    </div>

    <div class="hidden print:block">
        <h1 class="text-xl font-bold">{{ $championship->title }} — {{ __('Fight order') }}</h1>
    </div>

    <div class="print:hidden"><x-competition.flash /></div>

    @can('manage-competition')
        <flux:card class="print:hidden">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div class="flex flex-wrap items-end gap-3">
                    <flux:input
                        wire:model="minimumRest"
                        type="number"
                        min="0"
                        max="20"
                        :label="__('Minimum bouts of rest')"
                        class="w-44"
                    />
                    <flux:button variant="primary" wire:click="schedule">{{ __('Build running order') }}</flux:button>
                    <flux:button variant="ghost" wire:click="clear" wire:confirm="{{ __('Clear every fight number?') }}">
                        {{ __('Clear') }}
                    </flux:button>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <flux:checkbox wire:model.live="hideCompleted" :label="__('Hide finished')" />
                    <flux:button variant="ghost" onclick="window.print()">{{ __('Print') }}</flux:button>
                </div>
            </div>

            {{-- Without a mat there is nowhere to send a bout, so the whole
                 send-to-mat control disappears from every row. Saying so beats
                 leaving an operator to wonder where the buttons went. --}}
            @if ($courts->isEmpty())
                <flux:callout variant="warning" icon="tv" class="mt-4">
                    <div class="flex flex-wrap items-center gap-3">
                        <span>{{ __('No mats are set up, so bouts cannot be sent to a scoreboard yet.') }}</span>
                        <flux:button size="xs" :href="route('courts.index', $championship)" wire:navigate>
                            {{ __('Add a mat') }}
                        </flux:button>
                    </div>
                </flux:callout>
            @endif

            @if ($unscheduled > 0)
                <flux:callout variant="warning" icon="exclamation-triangle" class="mt-4">
                    {{ trans_choice(
                        '{1}:count bout has no fight number yet.|[2,*]:count bouts have no fight number yet.',
                        $unscheduled, ['count' => $unscheduled]
                    ) }}
                </flux:callout>
            @endif

            @if ($violations->isNotEmpty())
                <flux:callout variant="warning" icon="clock" class="mt-4">
                    <div class="flex flex-col gap-1">
                        <span class="font-medium">
                            {{ trans_choice(
                                '{1}:count bout gives less rest than requested.|[2,*]:count bouts give less rest than requested.',
                                $violations->count(), ['count' => $violations->count()]
                            ) }}
                        </span>
                        @foreach ($violations->take(5) as $violation)
                            <span class="text-sm">
                                {{ __('Fight :n follows fight :from after only :gap bout(s).', [
                                    'n' => $violation['bout']->fight_number,
                                    'from' => $violation['feeder']->fight_number,
                                    'gap' => $violation['gap'],
                                ]) }}
                            </span>
                        @endforeach
                    </div>
                </flux:callout>
            @endif
        </flux:card>
    @endcan

    <flux:card class="p-0 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-zinc-200 text-left dark:border-zinc-700">
                    <th class="px-3 py-2 font-medium tabular-nums">{{ __('#') }}</th>
                    <th class="px-3 py-2 font-medium">{{ __('Category') }}</th>
                    <th class="px-3 py-2 font-medium">{{ __('Phase') }}</th>
                    <th class="px-3 py-2 font-medium">{{ __('Blue') }}</th>
                    <th class="px-3 py-2 font-medium">{{ __('Green') }}</th>
                    <th class="px-3 py-2 font-medium">{{ __('Mat') }}</th>
                    <th class="px-3 py-2 font-medium">{{ __('Winner') }}</th>
                    <th class="px-3 py-2 print:hidden"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($bouts as $bout)
                    <tr
                        class="border-b border-zinc-100 last:border-0 dark:border-zinc-800 {{ $bout->isDecided() ? 'text-zinc-400 dark:text-zinc-500' : '' }}"
                        wire:key="fo-{{ $bout->id }}"
                    >
                        <td class="px-3 py-2 font-mono tabular-nums font-medium">{{ $bout->fight_number }}</td>
                        <td class="px-3 py-2">{{ $bout->weightCategory->label }} kg</td>
                        <td class="px-3 py-2">{{ $bout->phase((int) ($roundsByCategory[$bout->weight_category_id] ?? $bout->round)) }}</td>
                        <td class="px-3 py-2 {{ $bout->winner_athlete_id && $bout->winner_athlete_id === $bout->athlete_a_id ? 'font-semibold' : '' }}">
                            <x-athlete :athlete="$bout->athleteA" />
                        </td>
                        <td class="px-3 py-2 {{ $bout->winner_athlete_id && $bout->winner_athlete_id === $bout->athlete_b_id ? 'font-semibold' : '' }}">
                            <x-athlete :athlete="$bout->athleteB" />
                        </td>
                        <td class="px-3 py-2">{{ $bout->court?->label() ?? '—' }}</td>
                        <td class="px-3 py-2"><x-athlete :athlete="$bout->winner" /></td>
                        <td class="px-3 py-2 text-right print:hidden">
                            @can('manage-competition')
                                <div class="flex justify-end gap-1">
                                    @if (! $bout->isDecided())
                                        <flux:button size="xs" variant="ghost" wire:click="move({{ $bout->id }}, 'up')" icon="chevron-up" />
                                        <flux:button size="xs" variant="ghost" wire:click="move({{ $bout->id }}, 'down')" icon="chevron-down" />

                                        @if ($bout->isReadyToFight())
                                            @foreach ($courts as $court)
                                                <flux:button
                                                    size="xs"
                                                    variant="ghost"
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
                        <td colspan="8" class="px-3 py-8 text-center text-zinc-500">
                            {{ __('No running order yet. Draw the brackets, then build the order.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </flux:card>
</div>
