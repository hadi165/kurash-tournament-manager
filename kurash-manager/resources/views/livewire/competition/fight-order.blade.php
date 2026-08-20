<x-page
    :title="__('Fight order')"
    :subtitle="__('Every weight class runs round by round, so athletes get bouts between their own.')"
    :breadcrumbs="[
        ['label' => __('Championships'), 'href' => route('championships.index')],
        ['label' => $championship->title, 'href' => route('championships.show', $championship)],
        ['label' => __('Fight order')],
    ]"
>
    <x-slot:aside>
        <x-ui.chip :href="route('exports.fight-order', ['championship' => $championship, 'format' => 'pdf'])">{{ __('PDF') }}</x-ui.chip>
        <x-ui.chip :href="route('exports.fight-order', ['championship' => $championship, 'format' => 'csv'])">{{ __('Excel') }}</x-ui.chip>
        <x-ui.chip onclick="window.print()">{{ __('Print') }}</x-ui.chip>
    </x-slot:aside>

    <div class="hidden print:block">
        <h1 class="text-xl font-bold">{{ $championship->title }} — {{ __('Fight order') }}</h1>
    </div>

    <div class="print:hidden"><x-competition.flash /></div>

    @can('manage-competition')
        <x-ui.card class="print:hidden">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="flex flex-col gap-[7px]">
                        <label for="min-rest" class="text-[12.5px] font-semibold text-muted">{{ __('Minimum bouts of rest') }}</label>
                        <flux:input id="min-rest" wire:model="minimumRest" type="number" min="0" max="20" class="w-40" />
                    </div>

                    <flux:button variant="primary" wire:click="schedule">{{ __('Build running order') }}</flux:button>
                    <flux:button variant="ghost" wire:click="clear" wire:confirm="{{ __('Clear every fight number?') }}">
                        {{ __('Clear') }}
                    </flux:button>
                </div>

                <div class="flex flex-wrap items-end gap-3">
                    <div class="flex flex-col gap-[7px]">
                        <label for="fo-division" class="text-[12.5px] font-semibold text-muted">{{ __('Division') }}</label>
                        <flux:select id="fo-division" wire:model.live="ageCategory" size="sm" class="w-[190px]">
                            <flux:select.option value="" :selected="$ageCategory === ''">{{ __('All divisions') }}</flux:select.option>
                            @foreach ($ageCategories as $division)
                                <flux:select.option value="{{ $division->id }}" :selected="(string) $division->id === $ageCategory">
                                    {{ $division->name }}
                                </flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    <div class="flex flex-col gap-[7px]">
                        <label for="fo-gender" class="text-[12.5px] font-semibold text-muted">{{ __('Competition') }}</label>
                        <flux:select id="fo-gender" wire:model.live="gender" size="sm" class="w-[150px]">
                            <flux:select.option value="" :selected="$gender === ''">{{ __('Men and women') }}</flux:select.option>
                            <flux:select.option value="M" :selected="$gender === 'M'">{{ __('Men') }}</flux:select.option>
                            <flux:select.option value="F" :selected="$gender === 'F'">{{ __('Women') }}</flux:select.option>
                        </flux:select>
                    </div>

                    <flux:checkbox wire:model.live="hideCompleted" :label="__('Hide finished')" />
                </div>
            </div>

            {{-- Without a mat there is nowhere to send a bout, so the whole
                 send-to-mat control disappears from every row. Saying so beats
                 leaving an operator to wonder where the buttons went. --}}
            @if ($courts->isEmpty())
                <div class="mt-[18px] flex flex-wrap items-center gap-3 rounded-md bg-danger-soft px-[18px] py-3.5">
                    <x-ui.tag variant="danger">{{ __('No mats') }}</x-ui.tag>
                    <span class="text-[13.5px]">{{ __('No mats are set up, so bouts cannot be sent to a scoreboard yet.') }}</span>
                    <x-ui.chip :href="route('courts.index', $championship)" wire:navigate>{{ __('Add a mat') }}</x-ui.chip>
                </div>
            @endif

            @if ($unscheduled > 0)
                <div class="mt-[18px] flex flex-wrap items-center gap-3 rounded-md bg-danger-soft px-[18px] py-3.5">
                    <x-ui.tag variant="danger">
                        {{ __(':count unscheduled', ['count' => $unscheduled]) }}
                    </x-ui.tag>
                    <span class="text-[13.5px]">
                        {{ trans_choice(
                            '{1}:count bout has no fight number yet. Build the running order to place it.|[2,*]:count bouts have no fight number yet. Build the running order to place them.',
                            $unscheduled, ['count' => $unscheduled]
                        ) }}
                    </span>
                </div>
            @endif

            {{-- Not an error: the order is buildable, it simply could not give
                 everyone the rest that was asked for. --}}
            @if ($violations->isNotEmpty())
                <div class="mt-[18px] rounded-md border border-line bg-ground px-[18px] py-3.5">
                    <div class="text-[13.5px] font-semibold">
                        {{ trans_choice(
                            '{1}:count bout gives less rest than requested.|[2,*]:count bouts give less rest than requested.',
                            $violations->count(), ['count' => $violations->count()]
                        ) }}
                    </div>

                    <div class="mt-1 flex flex-col gap-0.5">
                        @foreach ($violations->take(5) as $violation)
                            <span class="text-[12.5px] text-muted">
                                {{ __('Fight :n follows fight :from after only :gap bout(s).', [
                                    'n' => $violation['bout']->fight_number,
                                    'from' => $violation['feeder']->fight_number,
                                    'gap' => $violation['gap'],
                                ]) }}
                            </span>
                        @endforeach
                    </div>
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
                        <tr @class(['opacity-50' => $bout->isDecided()]) wire:key="fo-{{ $bout->id }}">
                            <td class="num font-semibold">{{ $bout->fight_number }}</td>
                            <td>
                                <div class="font-semibold">{{ $bout->weightCategory->exportName() }} {{ __('kg') }}</div>
                                <div class="text-[12.5px] text-muted">{{ $bout->weightCategory->ageCategory?->name }}</div>
                            </td>
                            <td class="text-muted">{{ $bout->phase((int) ($roundsByCategory[$bout->weight_category_id] ?? $bout->round)) }}</td>

                            {{-- The corner colour is carried by a dot beside the
                                 name, so the column reads as a corner even when
                                 the heading has scrolled away. --}}
                            <td @class(['font-semibold' => $bout->winner_athlete_id && $bout->winner_athlete_id === $bout->athlete_a_id])>
                                <span class="inline-flex items-center gap-2">
                                    <span class="size-2 flex-none rounded-full bg-info"></span>
                                    <x-athlete :athlete="$bout->athleteA" />
                                </span>
                            </td>
                            <td @class(['font-semibold' => $bout->winner_athlete_id && $bout->winner_athlete_id === $bout->athlete_b_id])>
                                <span class="inline-flex items-center gap-2">
                                    <span class="size-2 flex-none rounded-full bg-brand"></span>
                                    <x-athlete :athlete="$bout->athleteB" />
                                </span>
                            </td>

                            <td class="text-muted">{{ $bout->court?->label() ?? '—' }}</td>
                            <td class="font-semibold"><x-athlete :athlete="$bout->winner" /></td>
                            <td class="print:hidden">
                                @can('manage-competition')
                                    <div class="flex justify-end gap-1.5">
                                        @if (! $bout->isDecided())
                                            <flux:button size="xs" variant="ghost" wire:click="move({{ $bout->id }}, 'up')" icon="chevron-up" />
                                            <flux:button size="xs" variant="ghost" wire:click="move({{ $bout->id }}, 'down')" icon="chevron-down" />

                                            @if ($bout->isReadyToFight())
                                                @foreach ($courts as $court)
                                                    <x-ui.chip
                                                        wire:click="sendToMat({{ $bout->id }}, {{ $court->id }})"
                                                        wire:key="fo-mat-{{ $bout->id }}-{{ $court->id }}"
                                                    >{{ __('Mat :n', ['n' => $court->number]) }}</x-ui.chip>
                                                @endforeach
                                            @endif
                                        @endif
                                    </div>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-8 text-center text-muted">
                                {{ __('No running order yet. Draw the brackets, then build the order.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
</x-page>
