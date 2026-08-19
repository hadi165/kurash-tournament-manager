{{--
    The clock lives here rather than on the server. It is the mat's clock: the
    operator starts and stops it with the referee, and a press carries the
    reading with it so the log records what the contest actually showed. A
    server-held timer would drift the moment the tab was backgrounded.
--}}
<div
    class="flex flex-col gap-6"
    x-data="{
        total: @js($boutSeconds),
        left: @js($boutSeconds),
        running: false,
        handle: null,
        tick() {
            if (this.left <= 0) { this.stop(); return }
            this.left--
        },
        start() {
            if (this.running) return
            this.running = true
            this.handle = setInterval(() => this.tick(), 1000)
        },
        stop() {
            this.running = false
            clearInterval(this.handle)
            this.handle = null
        },
        {{-- Publishing on start and stop only. The stored value is an anchor the
             scoreboard derives from, so writing every second would buy nothing
             and cost a query a second per mat. --}}
        toggle() {
            this.running ? this.stop() : this.start()
            $wire.publishClock(this.left, this.running)
        },
        reset() { this.stop(); this.left = this.total },
        get display() {
            const m = Math.floor(this.left / 60)
            const s = String(this.left % 60).padStart(2, '0')
            return `${m}:${s}`
        },
    }"
    x-on:bout-changed.window="reset()"
>
    <div class="print:hidden">
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('championships.index')" wire:navigate>{{ __('Championships') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item :href="route('championships.show', $court->championship)" wire:navigate>
                {{ $court->championship->title }}
            </flux:breadcrumbs.item>
            <flux:breadcrumbs.item :href="route('courts.index', $court->championship)" wire:navigate>{{ __('Mats') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ $court->label() }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <div class="mt-2 flex flex-wrap items-start justify-between gap-3">
            <div>
                <flux:heading size="xl">{{ $court->label() }}</flux:heading>
                <flux:subheading>
                    {{ __('Halal ends the contest. Two yonbosh make a halal. Chala never adds up to one.') }}
                </flux:subheading>
            </div>

            {{-- target=_blank on purpose: this goes on the projector or a second
                 monitor, and the operator must not lose the mat screen to it. --}}
            <flux:button
                size="sm"
                icon="tv"
                :href="route('display.scoreboard', $court)"
                target="_blank"
            >{{ __('Open scoreboard') }}</flux:button>
        </div>
    </div>

    <x-competition.flash />

    @if ($bout === null)
        <flux:card class="flex flex-col items-start gap-3 py-10 sm:items-center">
            <flux:heading size="lg">{{ __('Nothing on this mat') }}</flux:heading>
            <flux:subheading>{{ __('Bring the next contest on, or send one here from the fight order.') }}</flux:subheading>
        </flux:card>
    @else
        @php
            $sides = [
                'a' => ['athlete' => $bout->athleteA, 'tally' => $tally['a'], 'colour' => 'blue',  'name' => __('Blue')],
                'b' => ['athlete' => $bout->athleteB, 'tally' => $tally['b'], 'colour' => 'green', 'name' => __('Green')],
            ];
        @endphp

        <flux:card class="flex flex-col gap-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <flux:heading size="lg">
                        {{ __('Fight :n', ['n' => $bout->fight_number ?? $bout->play_code]) }}
                        <span class="text-zinc-500">·</span>
                        {{ $bout->weightCategory?->exportName() }}
                    </flux:heading>
                    <flux:subheading>{{ $bout->phase($totalRounds) }}</flux:subheading>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <flux:badge color="green" size="sm" icon="play">{{ __('On the mat') }}</flux:badge>
                    @can('manage-competition')
                        <flux:button size="xs" variant="ghost" wire:click="voidLast">{{ __('Take back last call') }}</flux:button>
                    @endcan
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-[1fr_auto_1fr]">
                @foreach (['a', 'b'] as $key)
                    @php $side = $sides[$key]; @endphp

                    @if ($key === 'b')
                        {{-- Clock column, between the two corners on a wide screen. --}}
                        <div class="order-first flex flex-col items-center justify-center gap-3 lg:order-none">
                            <div class="font-mono text-5xl font-bold tabular-nums" x-text="display"></div>

                            <div class="text-xs uppercase tracking-wide"
                                 :class="running ? 'text-green-600 dark:text-green-400' : 'text-zinc-500'"
                                 x-text="running ? @js(__('Kurash')) : @js(__('Tuxta'))"></div>

                            @can('manage-competition')
                                <div class="flex flex-wrap justify-center gap-2">
                                    <flux:button size="sm" variant="primary" x-on:click="toggle()"
                                        x-text="running ? @js(__('Tuxta')) : @js(__('Kurash'))"></flux:button>

                                    <flux:button size="sm" variant="ghost"
                                        x-on:click="stop(); $wire.stoppage(left)">{{ __('Log stoppage') }}</flux:button>
                                </div>

                                <flux:button size="sm" variant="ghost" x-on:click="stop(); $wire.finishOnTime()">
                                    {{ __('Time — decide') }}
                                </flux:button>
                            @endcan

                            <flux:text class="text-center text-xs text-zinc-500">
                                {{ __(':n minute contest', ['n' => round($boutSeconds / 60, 1)]) }}
                            </flux:text>
                        </div>
                    @endif

                    <div @class([
                        'flex flex-col gap-4 rounded-lg border p-4',
                        'border-blue-300 bg-blue-50 dark:border-blue-900 dark:bg-blue-950/40' => $side['colour'] === 'blue',
                        'border-green-300 bg-green-50 dark:border-green-900 dark:bg-green-950/40' => $side['colour'] === 'green',
                    ])>
                        <div class="flex items-center justify-between gap-2">
                            <span @class([
                                'text-xs font-semibold uppercase tracking-widest',
                                'text-blue-700 dark:text-blue-300' => $side['colour'] === 'blue',
                                'text-green-700 dark:text-green-300' => $side['colour'] === 'green',
                            ])>{{ $side['name'] }}</span>

                            @if ($side['tally']->isDakki())
                                <flux:badge size="sm" color="red">{{ __('Dakki') }}</flux:badge>
                            @endif
                        </div>

                        <div>
                            <div class="text-2xl font-semibold">{{ $side['athlete']?->fullname ?? '—' }}</div>
                            <flux:text class="text-sm">
                                <x-flag :noc="$side['athlete']?->noc_code" :name="$side['athlete']?->noc_name" show-code />
                                @if ($side['athlete']?->draw_number)
                                    <span class="text-zinc-500">· {{ __('draw :n', ['n' => $side['athlete']->draw_number]) }}</span>
                                @endif
                            </flux:text>
                        </div>

                        <div class="flex items-end gap-5">
                            @foreach ([
                                ['k' => __('Yonbosh'), 'v' => $side['tally']->yonbosh],
                                ['k' => __('Chala'), 'v' => $side['tally']->chala],
                                ['k' => __('Tanbeh'), 'v' => $side['tally']->tanbeh],
                            ] as $box)
                                <div>
                                    <div class="text-3xl font-bold tabular-nums">{{ $box['v'] }}</div>
                                    <div class="mt-1 text-[0.65rem] uppercase tracking-wide text-zinc-500">{{ $box['k'] }}</div>
                                </div>
                            @endforeach
                        </div>

                        @can('manage-competition')
                            <div class="flex flex-wrap gap-2">
                                <flux:button size="sm" variant="primary"
                                    x-on:click="stop(); $wire.score('halal', @js($key), left)">{{ __('Halal') }}</flux:button>
                                <flux:button size="sm"
                                    x-on:click="$wire.score('yonbosh', @js($key), left)">{{ __('Yonbosh') }}</flux:button>
                                <flux:button size="sm"
                                    x-on:click="$wire.score('chala', @js($key), left)">{{ __('Chala') }}</flux:button>
                                <flux:button size="sm" variant="danger"
                                    x-on:click="$wire.score('tanbeh', @js($key), left)">{{ __('Tanbeh') }}</flux:button>
                            </div>
                        @endcan
                    </div>
                @endforeach
            </div>

            @if ($awaitingDecision)
                <flux:callout variant="warning" icon="scale">
                    <div class="flex flex-wrap items-center gap-3">
                        <span>{{ __('Level on yonbosh and chala. Who did the referees give it to?') }}</span>
                        @can('manage-competition')
                            <flux:button size="xs" wire:click="awardDecision('a')">{{ $bout->athleteA?->fullname }}</flux:button>
                            <flux:button size="xs" wire:click="awardDecision('b')">{{ $bout->athleteB?->fullname }}</flux:button>
                        @endcan
                    </div>
                </flux:callout>
            @endif
        </flux:card>

        @if ($log->isNotEmpty())
            <flux:card>
                <flux:heading size="lg">{{ __('Call log') }}</flux:heading>
                <flux:subheading>{{ __('Every call, with who entered it. This is the record a protest is settled from.') }}</flux:subheading>

                @php $voided = $log->where('action', 'score_voided')->pluck('after.voids_event_id')->filter()->map(fn ($id) => (int) $id)->all(); @endphp

                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-200 text-left dark:border-zinc-700">
                                <th class="px-3 py-2 font-medium">{{ __('Clock') }}</th>
                                <th class="px-3 py-2 font-medium">{{ __('Call') }}</th>
                                <th class="px-3 py-2 font-medium">{{ __('To') }}</th>
                                <th class="px-3 py-2 font-medium">{{ __('Entered by') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($log as $entry)
                                @php
                                    $clock = $entry->after['clock'] ?? null;
                                    $athleteId = $entry->after['athlete_id'] ?? null;
                                    $isVoid = $entry->action === 'score_voided';
                                    $wasVoided = in_array($entry->id, $voided, true);
                                @endphp
                                <tr @class([
                                    'border-b border-zinc-100 last:border-0 dark:border-zinc-800',
                                    'text-zinc-400 line-through dark:text-zinc-600' => $wasVoided,
                                ]) wire:key="log-{{ $entry->id }}">
                                    <td class="px-3 py-2 font-mono text-xs tabular-nums">
                                        {{ $clock === null ? '—' : sprintf('%d:%02d', intdiv($clock, 60), $clock % 60) }}
                                    </td>
                                    <td class="px-3 py-2">
                                        @if ($entry->action === 'stoppage')
                                            <span class="italic">{{ __('Tuxta') }}</span>
                                        @elseif ($isVoid)
                                            <span class="italic">{{ __('Taken back: :call', ['call' => $entry->after['call'] ?? '']) }}</span>
                                        @else
                                            <span class="font-medium capitalize">{{ $entry->after['call'] ?? '' }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2">
                                        @if ($athleteId === $bout->athlete_a_id)
                                            <flux:badge size="sm" color="blue">{{ __('Blue') }}</flux:badge>
                                        @elseif ($athleteId === $bout->athlete_b_id)
                                            <flux:badge size="sm" color="green">{{ __('Green') }}</flux:badge>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-zinc-500">{{ $entry->user?->name ?? __('System') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </flux:card>
        @endif
    @endif

    @if ($upNext->isNotEmpty())
        <flux:card>
            <flux:heading size="lg">{{ __('Up next') }}</flux:heading>
            <flux:subheading>{{ __('Waiting for this mat, in fight order.') }}</flux:subheading>

            <div class="mt-4 flex flex-col gap-2">
                @foreach ($upNext as $next)
                    <div class="flex flex-wrap items-center gap-3 rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-700"
                         wire:key="next-{{ $next->id }}">
                        <span class="font-mono text-xs text-zinc-500">
                            {{ $next->fight_number ? __('Fight :n', ['n' => $next->fight_number]) : __('unscheduled') }}
                        </span>
                        <span class="text-zinc-500">{{ $next->weightCategory?->exportName() }}</span>
                        <x-athlete :athlete="$next->athleteA" />
                        <span class="text-zinc-400">{{ __('v') }}</span>
                        <x-athlete :athlete="$next->athleteB" />

                        @can('manage-competition')
                            <flux:button size="xs" class="ms-auto" wire:click="bringOn({{ $next->id }})">
                                {{ __('Bring on') }}
                            </flux:button>
                        @endcan
                    </div>
                @endforeach
            </div>
        </flux:card>
    @endif
</div>
