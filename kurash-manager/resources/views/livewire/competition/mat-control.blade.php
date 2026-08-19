{{--
    The clock lives here rather than on the server. It is the mat's clock: the
    operator starts and stops it with the referee, and a press carries the
    reading with it so the log records what the contest actually showed. A
    server-held timer would drift the moment the tab was backgrounded.
--}}
<div
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
>    <x-page
        :kicker="$court->championship->title"
        :title="$court->label()"
        :subtitle="__('Halal ends the contest. Two yonbosh make a halal. Chala never adds up to one.')"
        :breadcrumbs="[
            ['label' => __('Championships'), 'href' => route('championships.index')],
            ['label' => $court->championship->title, 'href' => route('championships.show', $court->championship)],
            ['label' => __('Mats'), 'href' => route('courts.index', $court->championship)],
            ['label' => $court->label()],
        ]"
    >
        {{-- target=_blank on purpose: this goes on the projector or a second
             monitor, and the operator must not lose the mat screen to it. --}}
        <x-slot:actions>
            <a href="{{ route('display.scoreboard', $court) }}" target="_blank"
               class="px-2.5 py-1 text-xs font-bold text-brand-700 no-underline hover:bg-brand-500/10 dark:text-brand-400">{{ __('Open scoreboard') }}</a>
            <span class="mx-1.5 h-5 w-0.5 bg-divider"></span>
        </x-slot:actions>

        <x-competition.flash />

        @if ($bout === null)
            <x-ui.card class="px-6 py-10 text-center">
                <h3 class="m-0 text-2xl">{{ __('Nothing on this mat') }}</h3>
                <p class="mt-2 text-[13px] text-ink/55">
                    {{ __('Bring the next contest on, or send one here from the fight order.') }}
                </p>
            </x-ui.card>
        @else
            @php
                $sides = [
                    'a' => ['athlete' => $bout->athleteA, 'tally' => $tally['a'], 'colour' => 'blue',  'name' => __('Blue')],
                    'b' => ['athlete' => $bout->athleteB, 'tally' => $tally['b'], 'colour' => 'green', 'name' => __('Green')],
                ];
            @endphp

            <x-ui.card flush>
                <div class="flex flex-wrap items-start justify-between gap-3.5 px-6 pb-4 pt-5">
                    <div>
                        <h4 class="m-0 text-xl">
                            {{ __('Fight :n', ['n' => $bout->fight_number ?? $bout->play_code]) }}
                            <span class="text-ink/55">·</span>
                            {{ $bout->weightCategory?->exportName() }}
                        </h4>
                        <p class="mt-1 text-[13px] text-ink/55">{{ $bout->phase($totalRounds) }}</p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <x-ui.tag variant="brand">{{ __('On the mat') }}</x-ui.tag>
                        @can('manage-competition')
                            <flux:button size="xs" variant="ghost" wire:click="voidLast">{{ __('Take back last call') }}</flux:button>
                        @endcan
                    </div>
                </div>

                <div class="rule-2"></div>

                {{-- The two corners and the clock sit on one grid with 1px
                     gutters: the panels meet on rules rather than floating, so
                     the operator's eye lands on the same three places all day. --}}
                <div class="grid gap-px bg-n-300 lg:grid-cols-[1fr_minmax(220px,auto)_1fr]">
                    @foreach (['a', 'b'] as $key)
                        @php $side = $sides[$key]; @endphp

                        @if ($key === 'b')
                            {{-- Clock column, between the two corners on a wide screen. --}}
                            <div class="order-first flex flex-col items-center justify-center gap-3 bg-surface px-5 py-6 lg:order-none">
                                <div class="font-mono text-5xl font-bold tabular-nums leading-none" x-text="display"></div>

                                <div class="kicker"
                                     :class="running ? 'text-brand-600 dark:text-brand-400' : 'text-ink/55'"
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

                                <span class="text-center text-[11px] text-ink/55">
                                    {{ __(':n minute contest', ['n' => round($boutSeconds / 60, 1)]) }}
                                </span>
                            </div>
                        @endif

                        <div class="flex flex-col gap-4 bg-surface p-5">
                            {{-- The corner's colour is a bar, not a wash: a tinted
                                 panel would fight the scoreboard behind it. --}}
                            <div class="flex items-center justify-between gap-2">
                                <span class="flex items-center gap-2">
                                    <span @class(['h-4 w-1.5 flex-none', $side['colour'] === 'blue' ? 'bg-info-500' : 'bg-brand-500'])></span>
                                    <span @class([
                                        'kicker',
                                        'text-info-700 dark:text-info-400' => $side['colour'] === 'blue',
                                        'text-brand-700 dark:text-brand-400' => $side['colour'] === 'green',
                                    ])>{{ $side['name'] }}</span>
                                </span>

                                @if ($side['tally']->isDakki())
                                    <x-ui.tag variant="danger">{{ __('Dakki') }}</x-ui.tag>
                                @endif
                            </div>

                            <div>
                                <div class="text-2xl font-bold">{{ $side['athlete']?->fullname ?? '—' }}</div>
                                <div class="mt-1 text-[13px] text-ink/55">
                                    <x-flag :noc="$side['athlete']?->noc_code" :name="$side['athlete']?->noc_name" show-code />
                                    @if ($side['athlete']?->draw_number)
                                        <span>· {{ __('draw :n', ['n' => $side['athlete']->draw_number]) }}</span>
                                    @endif
                                </div>
                            </div>

                            <div class="flex items-end gap-6">
                                @foreach ([
                                    ['k' => __('Yonbosh'), 'v' => $side['tally']->yonbosh],
                                    ['k' => __('Chala'), 'v' => $side['tally']->chala],
                                    ['k' => __('Tanbeh'), 'v' => $side['tally']->tanbeh],
                                ] as $box)
                                    <div>
                                        <div class="text-[34px] font-bold leading-none tabular-nums">{{ $box['v'] }}</div>
                                        <div class="kicker mt-1.5 text-ink/55">{{ $box['k'] }}</div>
                                    </div>
                                @endforeach
                            </div>

                            @can('manage-competition')
                                <div class="mt-auto flex flex-wrap gap-2">
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
                    <div class="rule-2"></div>

                    <div class="flex flex-wrap items-center gap-3 bg-brand-500/10 px-6 py-4">
                        <x-ui.tag variant="brand">{{ __('Decision') }}</x-ui.tag>
                        <span class="text-sm">{{ __('Level on yonbosh and chala. Who did the referees give it to?') }}</span>

                        @can('manage-competition')
                            <flux:button size="xs" wire:click="awardDecision('a')">{{ $bout->athleteA?->fullname }}</flux:button>
                            <flux:button size="xs" wire:click="awardDecision('b')">{{ $bout->athleteB?->fullname }}</flux:button>
                        @endcan
                    </div>
                @endif
            </x-ui.card>

            @if ($log->isNotEmpty())
                <x-ui.card
                    flush
                    :title="__('Call log')"
                    :subtitle="__('Every call, with who entered it. This is the record a protest is settled from.')"
                >
                    <div class="rule-2"></div>

                    @php $voided = $log->where('action', 'score_voided')->pluck('after.voids_event_id')->filter()->map(fn ($id) => (int) $id)->all(); @endphp

                    <div class="overflow-x-auto">
                        <table class="t">
                            <thead>
                                <tr>
                                    <th>{{ __('Clock') }}</th>
                                    <th>{{ __('Call') }}</th>
                                    <th>{{ __('To') }}</th>
                                    <th>{{ __('Entered by') }}</th>
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

                                    <tr @class(['text-ink/40 line-through' => $wasVoided]) wire:key="log-{{ $entry->id }}">
                                        <td class="font-mono text-xs tabular-nums">
                                            {{ $clock === null ? '—' : sprintf('%d:%02d', intdiv($clock, 60), $clock % 60) }}
                                        </td>
                                        <td>
                                            @if ($entry->action === 'stoppage')
                                                <span class="italic text-ink/55">{{ __('Tuxta') }}</span>
                                            @elseif ($isVoid)
                                                <span class="italic text-ink/55">{{ __('Taken back: :call', ['call' => $entry->after['call'] ?? '']) }}</span>
                                            @else
                                                <span class="font-bold capitalize">{{ $entry->after['call'] ?? '' }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($athleteId === $bout->athlete_a_id)
                                                <x-ui.tag variant="info">{{ __('Blue') }}</x-ui.tag>
                                            @elseif ($athleteId === $bout->athlete_b_id)
                                                <x-ui.tag variant="brand">{{ __('Green') }}</x-ui.tag>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="text-ink/55">{{ $entry->user?->name ?? __('System') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-ui.card>
            @endif
        @endif

        @if ($upNext->isNotEmpty())
            <x-ui.card
                flush
                :title="__('Up next')"
                :subtitle="__('Waiting for this mat, in fight order.')"
            >
                <div class="rule-2"></div>

                <div class="flex flex-col">
                    @foreach ($upNext as $next)
                        <div class="flex flex-wrap items-center gap-3 border-b border-ink/12 px-6 py-3.5 text-sm last:border-b-0"
                             wire:key="next-{{ $next->id }}">
                            <span class="font-mono text-xs text-ink/55">
                                {{ $next->fight_number ? __('Fight :n', ['n' => $next->fight_number]) : __('unscheduled') }}
                            </span>
                            <span class="text-ink/55">{{ $next->weightCategory?->exportName() }}</span>
                            <x-athlete :athlete="$next->athleteA" />
                            <span class="text-ink/40">{{ __('v') }}</span>
                            <x-athlete :athlete="$next->athleteB" />

                            @can('manage-competition')
                                <flux:button size="xs" class="ms-auto" wire:click="bringOn({{ $next->id }})">
                                    {{ __('Bring on') }}
                                </flux:button>
                            @endcan
                        </div>
                    @endforeach
                </div>
            </x-ui.card>
        @endif
    </x-page>
</div>
