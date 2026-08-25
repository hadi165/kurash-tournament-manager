<x-page
    :title="__('Dashboard')"
    :subtitle="__('What the competition needs, and what is happening on the mats.')"
>
    @if ($championship === null)
        <x-ui.card class="py-10 text-center">
            <h2 class="m-0 text-2xl">{{ __('No competitions are open') }}</h2>
            <p class="mt-2 text-[13.5px] text-muted">
                {{ __('Create a championship, or reopen one from the archive, to run it from here.') }}
            </p>
            <div class="mt-5 flex flex-wrap justify-center gap-2">
                <flux:button variant="primary" :href="route('championships.index')" wire:navigate>
                    {{ __('Create a championship') }}
                </flux:button>
                <flux:button :href="route('archive.index')" wire:navigate>
                    {{ __('Open the archive') }}
                </flux:button>
            </div>
        </x-ui.card>
    @else
        {{--
            | The championship on screen
            |
            | One at a time. Everything below reads from this one, so it is
            | named once at the top rather than repeated in six card headings.
        --}}
        <x-ui.card>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2.5">
                        <h2 class="m-0 text-xl">
                            <a href="{{ route('championships.show', $championship) }}" wire:navigate
                               class="text-ink no-underline hover:text-brand">
                                {{ $championship->title }}
                            </a>
                        </h2>

                        <x-ui.tag :variant="$status->tagVariant()" :dot="$status->dotted()">
                            {{ $status->label() }}
                        </x-ui.tag>
                    </div>

                    <p class="mt-1.5 text-[13.5px] text-muted">
                        {{ $championship->location ?: __('Location not set') }}
                        @if ($championship->starts_on)
                            · {{ $championship->starts_on->format('j M Y') }}
                        @endif
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    @if ($openChampionships->count() > 1)
                        {{-- Only worth a control when there is a choice to make.
                             Bound to the URL, so the desk can bookmark the
                             competition it is actually running. --}}
                        <flux:select wire:model.live="selected" size="sm" class="min-w-[210px]"
                                     :label="__('Championship')" label-sr-only>
                            @foreach ($openChampionships as $option)
                                <flux:select.option :value="$option->id">{{ $option->title }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif

                    <flux:button size="sm" :href="route('fight-order.index', $championship)" wire:navigate>
                        {{ __('Manage fight order') }}
                    </flux:button>
                    <flux:button size="sm" :href="route('medals.index', $championship)" wire:navigate>
                        {{ __('Results and medals') }}
                    </flux:button>
                </div>
            </div>

            {{-- Each figure is the way to the screen it is counted on. --}}
            <x-ui.stats grid class="mt-5" :items="[
                ['value' => $counts['athletes'], 'label' => __('Athletes'), 'href' => route('entries.index', $championship)],
                ['value' => $counts['classes'], 'label' => __('Weight classes'), 'href' => route('championships.show', $championship)],
                ['value' => $counts['bouts'], 'label' => __('Contests'), 'href' => route('fight-order.index', $championship)],
                ['value' => $counts['mats'], 'label' => __('Active mats'), 'href' => route('courts.index', $championship)],
            ]" />
        </x-ui.card>

        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            {{--
                | Attention required
                |
                | Blockers only. A contest being fought is not a problem, and
                | listing it here taught operators to skim past the panel that
                | also carries the things that genuinely stop an event.
            --}}
            <x-ui.card :title="__('Attention required')">
                @forelse ($attention as $item)
                    <div class="flex flex-wrap items-center gap-3.5 border-b border-line py-2.5 first:pt-0 last:border-0 last:pb-0"
                         wire:key="attention-{{ $item['key'] }}">
                        <span class="text-sm">{{ $item['text'] }}</span>

                        @if ($item['route'])
                            <x-ui.chip :href="route($item['route'], $item['params'])" wire:navigate>
                                {{ $item['label'] }} →
                            </x-ui.chip>
                        @endif
                    </div>
                @empty
                    <p class="m-0 text-[13.5px] text-muted">
                        {{ __('Nothing is blocking the competition.') }}
                    </p>
                @endforelse
            </x-ui.card>

            {{--
                | Live mats
                |
                | Its own Livewire component so it can refresh without dragging
                | the entry counts, the blockers and the medal standings behind
                | it every ten seconds.
            --}}
            <x-ui.card :title="__('Live mats')">
                <x-slot:head>
                    <a href="{{ route('display.mats', $championship) }}" target="_blank" rel="noopener"
                       class="text-[12.5px] font-semibold text-brand no-underline hover:underline">
                        {{ __('Open the venue screen') }} ↗
                    </a>
                </x-slot:head>

                <livewire:competition.live-mats :championship-id="$championship->id" :key="'mats-'.$championship->id" />
            </x-ui.card>

            {{--
                | Athlete workflow
                |
                | Athletes only. The step that follows is measured in contests,
                | and a funnel that changes unit halfway invites the reader to
                | subtract one from the other.
            --}}
            <x-ui.card :title="__('Athlete workflow')">
                <x-ui.stats grid :items="[
                    ['value' => $workflow['registered'], 'label' => __('Registered'), 'href' => route('entries.index', $championship)],
                    ['value' => $workflow['passed'], 'label' => __('Passed the scale'), 'hue' => 'brand'],
                    ['value' => $workflow['drawn'], 'label' => __('In a generated draw'), 'hue' => 'info'],
                ]" />

                <p class="mt-3.5 text-[12.5px] leading-relaxed text-muted">
                    {{ __('A draw counts the athletes it was generated with, not today\'s entry list.') }}
                </p>
            </x-ui.card>

            {{--
                | Bout completion
                |
                | Byes are excluded from both halves: a walkover is a row so the
                | bracket links up, not a contest anybody fought.
            --}}
            <x-ui.card :title="__('Bout completion')">
                @if ($progress['total'] > 0)
                    <div class="mb-[7px] flex justify-between text-[13px]">
                        <span class="text-muted">{{ __('Contests decided') }}</span>
                        <span class="font-semibold tabular-nums">
                            {{ $progress['decided'] }} / {{ $progress['total'] }}
                        </span>
                    </div>

                    <div class="h-2 overflow-hidden rounded-full bg-line"
                         role="progressbar"
                         aria-valuenow="{{ $progress['percent'] }}"
                         aria-valuemin="0"
                         aria-valuemax="100"
                         aria-label="{{ __('Contests decided') }}">
                        <div class="h-2 rounded-full bg-brand" style="width: {{ $progress['percent'] }}%"></div>
                    </div>

                    <p class="mt-2.5 text-[12.5px] text-muted">
                        {{ __(':percent% complete, byes excluded.', ['percent' => $progress['percent']]) }}
                    </p>
                @else
                    <p class="m-0 text-[13.5px] text-muted">
                        {{ __('No contests have been generated yet.') }}
                    </p>
                @endif
            </x-ui.card>

            {{--
                | Coming up
                |
                | The top of the running order, not the running order. The
                | question is "what do I call next", which only ever concerns
                | the next few rows.
            --}}
            <x-ui.card :title="__('Coming up')">
                <x-slot:head>
                    <div class="flex flex-wrap items-center gap-3">
                        <a href="{{ route('fight-order.index', $championship) }}" wire:navigate
                           class="text-[12.5px] font-semibold text-ink no-underline hover:underline">
                            {{ __('Full fight order') }}
                        </a>
                        <a href="{{ route('display.fight-order', $championship) }}" target="_blank" rel="noopener"
                           class="text-[12.5px] font-semibold text-brand no-underline hover:underline">
                            {{ __('Venue screen') }} ↗
                        </a>
                    </div>
                </x-slot:head>

                @if ($comingUp->isNotEmpty())
                    <div class="overflow-x-auto">
                        <table class="w-full border-collapse text-[13.5px]">
                            <thead>
                                <tr class="text-start text-[12px] text-muted">
                                    <th class="py-1.5 pe-3 text-start font-semibold">{{ __('No.') }}</th>
                                    <th class="py-1.5 pe-3 text-start font-semibold">{{ __('Class') }}</th>
                                    <th class="py-1.5 pe-3 text-start font-semibold">{{ __('Blue') }}</th>
                                    <th class="py-1.5 text-start font-semibold">{{ __('Green') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($comingUp as $bout)
                                    <tr class="border-t border-line" wire:key="next-{{ $bout->id }}">
                                        <td class="py-2 pe-3 font-semibold tabular-nums">{{ $bout->fight_number }}</td>
                                        <td class="py-2 pe-3 text-muted">{{ $bout->weightCategory?->label }}</td>
                                        <td class="py-2 pe-3">{{ $bout->athleteA?->fullname }}</td>
                                        <td class="py-2">{{ $bout->athleteB?->fullname }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @elseif (! $hasRunningOrder)
                    {{-- Two different problems, and the fix is different for
                         each: build the order, or wait for a mat to free up. --}}
                    <p class="m-0 text-[13.5px] text-muted">
                        {{ __('The running order has not been built yet.') }}
                    </p>
                @else
                    <p class="m-0 text-[13.5px] text-muted">
                        {{ __('No contests are waiting — every ready contest is on a mat.') }}
                    </p>
                @endif
            </x-ui.card>

            {{--
                | Medal leaders
                |
                | The top of the table. Complete standings and every podium stay
                | on the medals screen, which is what gets printed.
            --}}
            <x-ui.card :title="__('Medal leaders')">
                <x-slot:head>
                    <div class="flex flex-wrap items-center gap-3">
                        <a href="{{ route('medals.index', $championship) }}" wire:navigate
                           class="text-[12.5px] font-semibold text-ink no-underline hover:underline">
                            {{ __('Full standings') }}
                        </a>
                        <a href="{{ route('display.medals', $championship) }}" target="_blank" rel="noopener"
                           class="text-[12.5px] font-semibold text-brand no-underline hover:underline">
                            {{ __('Venue screen') }} ↗
                        </a>
                    </div>
                </x-slot:head>

                @if ($medals['leaders']->isNotEmpty())
                    <div class="overflow-x-auto">
                        <table class="w-full border-collapse text-[13.5px]">
                            <thead>
                                <tr class="text-[12px] text-muted">
                                    <th class="py-1.5 pe-3 text-start font-semibold">{{ __('NOC') }}</th>
                                    <th class="py-1.5 pe-3 text-end font-semibold">{{ __('Gold') }}</th>
                                    <th class="py-1.5 pe-3 text-end font-semibold">{{ __('Silver') }}</th>
                                    <th class="py-1.5 pe-3 text-end font-semibold">{{ __('Bronze') }}</th>
                                    <th class="py-1.5 text-end font-semibold">{{ __('Total') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($medals['leaders'] as $row)
                                    <tr class="border-t border-line" wire:key="noc-{{ $row['noc_code'] }}">
                                        <td class="py-2 pe-3 font-semibold">{{ $row['noc_code'] }}</td>
                                        <td class="py-2 pe-3 text-end tabular-nums">{{ $row['gold'] }}</td>
                                        <td class="py-2 pe-3 text-end tabular-nums">{{ $row['silver'] }}</td>
                                        <td class="py-2 pe-3 text-end tabular-nums">{{ $row['bronze'] }}</td>
                                        <td class="py-2 text-end font-semibold tabular-nums">{{ $row['total'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <p class="mt-3 text-[12.5px] text-muted">
                        {{ __(':decided of :total weight classes decided.', [
                            'decided' => $medals['decided'],
                            'total' => $medals['total'],
                        ]) }}
                    </p>
                @else
                    <p class="m-0 text-[13.5px] text-muted">
                        {{ __('No class has been decided yet.') }}
                    </p>
                @endif
            </x-ui.card>
        </div>

        {{--
            | Venue displays
            |
            | Screens for a projector and the monitors around the hall, not tabs
            | of this application — so they open in their own window, without
            | wire:navigate, and stay the standalone cached documents they are.
        --}}
        <x-ui.card class="mt-4" :title="__('Venue displays')"
                   :subtitle="__('Standalone screens for the projector and the monitors around the hall.')">
            <x-slot:head>
                @if (config('display.public'))
                    <x-ui.tag variant="amber">{{ __('Public — no sign-in needed') }}</x-ui.tag>
                @else
                    <x-ui.tag variant="muted">{{ __('Sign-in required') }}</x-ui.tag>
                @endif
            </x-slot:head>

            <div class="flex flex-wrap gap-2">
                <x-ui.chip :href="route('display.mats', $championship)" target="_blank" rel="noopener">
                    {{ __('Live mats') }} ↗
                </x-ui.chip>
                <x-ui.chip :href="route('display.fight-order', $championship)" target="_blank" rel="noopener">
                    {{ __('Fight order') }} ↗
                </x-ui.chip>
                <x-ui.chip :href="route('display.medals', $championship)" target="_blank" rel="noopener">
                    {{ __('Medals') }} ↗
                </x-ui.chip>
            </div>
        </x-ui.card>
    @endif
</x-page>
