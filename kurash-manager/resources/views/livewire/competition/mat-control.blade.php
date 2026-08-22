{{--
    The clock lives here rather than on the server. It is the mat's clock: the
    operator starts and stops it with the referee, and a press carries the
    reading with it so the log records what the contest actually showed. A
    server-held timer would drift the moment the tab was backgrounded.

    The two corners are blue and green panels rather than two identical boxes
    with a coloured word on them. A referee glancing down mid-contest is looking
    for a side, not reading a label.
--}}
<div
    x-data="{
        total: @js($boutSeconds),
        left: @js($boutSeconds),
        jazzoAt: @js($jazzoAt),
        running: false,
        handle: null,
        tick() {
            if (this.left <= 0) { this.stop(); return }
            this.left--
            this.offerJazzo()
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
        {{-- The browser notices half time because the browser holds the clock,
             but it does not decide: the server checks the reading and the empty
             board again before it stops anything. --}}
        offerJazzo() {
            if (@js($anyScore) || @js($inJazzo)) return
            if (this.left > this.jazzoAt) return
            this.stop()
            $wire.callJazzo(this.left)
        },
        {{-- A new contest starts from the top of its own division's clock, which
             is not the same number from one division to the next. --}}
        reset(seconds = null) {
            this.stop()
            if (seconds) this.total = seconds
            this.left = this.total
        },
        get display() {
            const m = Math.floor(this.left / 60)
            const s = String(this.left % 60).padStart(2, '0')
            return `${m}:${s}`
        },
    }"
    x-on:bout-changed.window="reset($event.detail?.seconds ?? null)"
>    <x-page
        :kicker="$court->championship->title"
        :title="$court->label()"
        :subtitle="__('Khalol ends the contest. Two yonbosh make a khalol. Chala never adds up to one.')"
        :breadcrumbs="auth()->user()?->isReferee()
            ? [['label' => __('Mats'), 'href' => route('referee.mats')], ['label' => $court->label()]]
            : [
                ['label' => __('Championships'), 'href' => route('championships.index')],
                ['label' => $court->championship->title, 'href' => route('championships.show', $court->championship)],
                ['label' => __('Mats'), 'href' => route('courts.index', $court->championship)],
                ['label' => $court->label()],
            ]"
    >
        {{-- target=_blank on purpose: this goes on the projector or a second
             monitor, and the operator must not lose the mat screen to it. --}}
        <x-slot:actions>
            {{-- Chosen by ear, which is why the preview is beside the choice
                 rather than somewhere in a settings screen. The mat next to
                 this one wants a different one. --}}
            @can('score-bout', $court)
                @if (! empty($finishSounds))
                    <div class="flex items-center gap-1.5 px-2" x-data="{
                        preview: null,
                        play(src) {
                            {{-- Stopped before the next one starts, or holding
                                 the button lays them over each other. --}}
                            if (this.preview) { this.preview.pause() }
                            this.preview = new Audio(src)
                            this.preview.play().catch(() => {})
                        },
                    }">
                        <label for="mat-sound" class="text-xs font-semibold text-muted">{{ __('End sound') }}</label>

                        <flux:select id="mat-sound" wire:model.live="finishSound" size="sm" class="w-[110px]">
                            @foreach ($finishSounds as $path => $label)
                                <flux:select.option value="{{ $path }}" :selected="$finishSound === $path">{{ __($label) }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        {{-- The chosen file, straight from the server: the
                             select saves as it changes, so this is always what
                             the mat would actually sound. --}}
                        <button type="button"
                                x-on:click="play(@js(asset($court->finishSound() ?? '')))"
                                class="rounded-full px-2 py-1 text-xs font-bold text-brand-700 hover:bg-brand-500/10 dark:text-brand-400">
                            {{ __('Play') }}
                        </button>
                    </div>

                    <span class="mx-1.5 h-5 w-0.5 bg-line"></span>
                @endif
            @endcan

            <a href="{{ route('display.scoreboard', $court) }}" target="_blank"
               class="px-2.5 py-1 text-xs font-bold text-brand-700 no-underline hover:bg-brand-500/10 dark:text-brand-400">{{ __('Open scoreboard') }}</a>
            <span class="mx-1.5 h-5 w-0.5 bg-line"></span>
        </x-slot:actions>

        {{-- The operator hears the same buzzer the hall does, at the same
             moment, because it is the same component reading the same
             state. --}}
        <x-competition.finish-bell :court="$court" :bout="$bout" :decided="(bool) $bout?->isDecided()" />

        <x-competition.flash />

        @if ($bout === null)
            {{-- A khalol pressed on the wrong side ends the contest instantly.
                 The way back is here, while the contest is still standing on
                 this mat — not through the bracket, which asks for a different
                 winner rather than for the mistake to be undone. --}}
            @if ($justDecided)
                <x-ui.card>
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <h3 class="m-0 text-xl">
                                {{ __(':name wins', ['name' => $justDecided->winner?->fullname]) }}
                                <span class="text-ink/55">·</span>
                                <span class="capitalize">{{ $justDecided->win_type }}</span>
                            </h3>
                            <p class="mt-1 text-[13px] text-ink/55">
                                {{ __('Fight :n', ['n' => $justDecided->fight_number ?? $justDecided->play_code]) }}
                                · {{ $justDecided->athleteA?->fullname }} {{ __('v') }} {{ $justDecided->athleteB?->fullname }}
                            </p>
                        </div>

                        @can('score-bout')
                            <flux:button size="sm" variant="danger" wire:click="reopen"
                                         wire:confirm="{{ __('Put this contest back on the mat and take back the call that ended it?') }}">
                                {{ __('Reopen contest') }}
                            </flux:button>
                        @endcan
                    </div>
                </x-ui.card>
            @endif

            <x-ui.card class="px-6 py-10 text-center">
                <h3 class="m-0 text-2xl">{{ __('Nothing on this mat') }}</h3>
                <p class="mt-2 text-[13px] text-ink/55">
                    {{ __('Bring the next contest on, or send one here from the fight order.') }}
                </p>
            </x-ui.card>
        @else
            @php
                // Blue is side A and green is side B throughout the system —
                // the yakhtak an athlete wears, decided by the bracket, not a
                // decoration chosen per screen.
                $sides = [
                    'a' => ['athlete' => $bout->athleteA, 'tally' => $tally['a'], 'colour' => 'blue',  'name' => __('Yakhtak Blue')],
                    'b' => ['athlete' => $bout->athleteB, 'tally' => $tally['b'], 'colour' => 'green', 'name' => __('Yakhtak Green')],
                ];

                // Scores read left to right in the order they are worth; the
                // penalties follow in the order they escalate. The same six
                // counters the wall board shows, in the same colours — G, Y and
                // C yellow, D and T red — so a referee looking up at the board
                // is reading the screen they just pressed.
                $counters = [
                    ['call' => 'yonbosh',  'key' => 'Y', 'label' => __('Yonbosh'),  'tone' => 'yellow'],
                    ['call' => 'chala',    'key' => 'C', 'label' => __('Chala'),    'tone' => 'yellow'],
                    ['call' => 'girrom',   'key' => 'G', 'label' => __('Girrom'),   'tone' => 'yellow'],
                    ['call' => 'dakki',    'key' => 'D', 'label' => __('Dakki'),    'tone' => 'red'],
                    ['call' => 'tanbeh',   'key' => 'T', 'label' => __('Tanbeh'),   'tone' => 'red'],
                    ['call' => 'madichal', 'key' => 'M', 'label' => __('Madichal'), 'tone' => 'red'],
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
                        @can('score-bout')
                            <flux:button size="xs" variant="ghost" wire:click="voidLast">{{ __('Take back last call') }}</flux:button>
                        @endcan
                    </div>
                </div>

                <div class="rule-2"></div>

                {{-- The two corners and the clock sit on one grid with 1px
                     gutters: the panels meet on rules rather than floating, so
                     the operator's eye lands on the same three places all day. --}}
                <div class="grid gap-px bg-n-300 lg:grid-cols-[1fr_minmax(240px,auto)_1fr]">
                    @foreach (['a', 'b'] as $key)
                        @php $side = $sides[$key]; @endphp

                        @if ($key === 'b')
                            {{-- Clock column, between the two corners on a wide screen. --}}
                            <div class="order-first flex flex-col items-center justify-center gap-3 bg-surface px-5 py-6 lg:order-none">
                                <div class="font-mono text-5xl font-bold tabular-nums leading-none" x-text="display"></div>

                                <div class="kicker"
                                     :class="running ? 'text-brand-600 dark:text-brand-400' : 'text-ink/55'"
                                     x-text="running ? @js(__('Kurash')) : @js(__('Tuxta'))"></div>

                                {{-- Jazzo: half the contest gone with nothing
                                     scored. The same yellow the wall board
                                     uses, so the operator and the hall are
                                     looking at the same state. --}}
                                @if ($inJazzo)
                                    <div class="rounded-md border-2 border-warn-500 bg-warn-500/15 px-4 py-2 text-center">
                                        <div class="text-lg font-black uppercase tracking-widest text-warn-700 dark:text-warn-400">{{ __('Jazzo') }}</div>
                                        <div class="mt-0.5 text-[11px] text-ink/55">{{ __('Half time, nothing scored') }}</div>
                                    </div>
                                @endif

                                @can('score-bout')
                                    <div class="flex flex-wrap justify-center gap-2">
                                        @if ($inJazzo)
                                            <flux:button size="sm" variant="primary"
                                                x-on:click="$wire.resume(left).then(() => start())">{{ __('Resume') }}</flux:button>
                                        @else
                                            <flux:button size="sm" variant="primary" x-on:click="toggle()"
                                                x-text="running ? @js(__('Tuxta')) : @js(__('Kurash'))"></flux:button>
                                        @endif

                                        <flux:button size="sm" variant="ghost"
                                            x-on:click="stop(); $wire.stoppage(left)">{{ __('Log stoppage') }}</flux:button>
                                    </div>

                                    <div class="flex flex-wrap justify-center gap-2">
                                        <flux:button size="sm" variant="ghost" x-on:click="stop(); $wire.finishOnTime()">
                                            {{ __('Time — decide') }}
                                        </flux:button>

                                        <flux:button size="sm" variant="ghost" wire:click="resetClock">
                                            {{ __('Reset time') }}
                                        </flux:button>
                                    </div>
                                @endcan

                                <span class="text-center text-[11px] text-ink/55">
                                    {{ __(':n minute contest', ['n' => round($boutSeconds / 60, 1)]) }}
                                </span>
                            </div>
                        @endif

                        {{-- The corner is a panel in its own colour, not a
                             white box with a coloured word on it. --}}
                        <div @class([
                            'flex flex-col gap-4 p-5',
                            'bg-info-500/10 dark:bg-info-500/[0.12]' => $side['colour'] === 'blue',
                            'bg-brand-500/10 dark:bg-brand-500/[0.12]' => $side['colour'] === 'green',
                        ])>
                            <div class="flex items-center justify-between gap-2">
                                <span class="flex items-center gap-2">
                                    <span @class(['h-5 w-2 flex-none', $side['colour'] === 'blue' ? 'bg-info-500' : 'bg-brand-500'])></span>
                                    <span @class([
                                        'text-base font-black uppercase tracking-widest',
                                        'text-info-700 dark:text-info-300' => $side['colour'] === 'blue',
                                        'text-brand-700 dark:text-brand-300' => $side['colour'] === 'green',
                                    ])>{{ $side['name'] }}</span>
                                </span>

                                @can('score-bout')
                                    {{-- The referee's override. Named for the
                                         colour rather than for the athlete,
                                         because the colour is what is being
                                         pointed at on the mat. --}}
                                    <flux:button size="xs" variant="primary" wire:click="declareWinner('{{ $key }}')">
                                        {{ $side['colour'] === 'blue' ? __('Win Blue') : __('Win Green') }}
                                    </flux:button>
                                @endcan
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

                            {{-- One tile per counter, each with its own minus.
                                 A referee correcting a mistake should not have
                                 to work out how many undos ago it was. --}}
                            <div class="grid grid-cols-3 gap-2 sm:grid-cols-6 lg:grid-cols-3 xl:grid-cols-6">
                                @foreach ($counters as $counter)
                                    @php $value = $side['tally']->{$counter['call']}; @endphp

                                    <div @class([
                                        'rounded-md border px-2 py-2 text-center',
                                        'border-warn-500/45 bg-warn-500/10' => $counter['tone'] === 'yellow' && $value > 0,
                                        'border-danger-500/45 bg-danger-500/10' => $counter['tone'] === 'red' && $value > 0,
                                        'border-ink/12 bg-surface/60' => $value === 0,
                                    ])>
                                        <div @class([
                                            'text-[30px] font-black leading-none tabular-nums',
                                            'text-warn-700 dark:text-warn-400' => $counter['tone'] === 'yellow' && $value > 0,
                                            'text-danger-700 dark:text-danger-400' => $counter['tone'] === 'red' && $value > 0,
                                            'text-ink/30' => $value === 0,
                                        ])>{{ $value }}</div>

                                        <div class="mt-1 text-[11px] font-bold uppercase tracking-wider text-ink/55">{{ $counter['key'] }}</div>

                                        @can('score-bout')
                                            <button type="button"
                                                    wire:click="decrease('{{ $counter['call'] }}', '{{ $key }}')"
                                                    @disabled($value === 0)
                                                    title="{{ __('Take back one :call', ['call' => $counter['label']]) }}"
                                                    class="mt-1 w-full rounded border border-ink/12 py-0.5 text-xs font-bold leading-none text-ink/55 disabled:opacity-30 enabled:hover:bg-ink/5">
                                                &minus;
                                            </button>
                                        @endcan
                                    </div>
                                @endforeach
                            </div>

                            @can('score-bout')
                                <div class="mt-auto flex flex-col gap-2">
                                    <div class="flex flex-wrap gap-2">
                                        <flux:button size="sm" variant="primary"
                                            x-on:click="stop(); $wire.score('khalol', @js($key), left)">{{ __('Khalol') }}</flux:button>
                                        <flux:button size="sm"
                                            x-on:click="$wire.score('yonbosh', @js($key), left)">{{ __('Yonbosh') }}</flux:button>
                                        <flux:button size="sm"
                                            x-on:click="$wire.score('chala', @js($key), left)">{{ __('Chala') }}</flux:button>
                                    </div>

                                    {{-- Penalties are recorded against the side
                                         whose panel they sit in. What they hand
                                         the opponent — a chala for a tanbeh, a
                                         yonbosh for a dakki — is applied by the
                                         rules, not by a second press. --}}
                                    <div class="flex flex-wrap gap-2">
                                        <flux:button size="sm" variant="danger"
                                            x-on:click="$wire.score('tanbeh', @js($key), left)">{{ __('Tanbeh') }}</flux:button>
                                        <flux:button size="sm" variant="danger"
                                            x-on:click="$wire.score('dakki', @js($key), left)">{{ __('Dakki') }}</flux:button>
                                        <flux:button size="sm" variant="danger"
                                            x-on:click="stop(); $wire.score('girrom', @js($key), left)">{{ __('Girrom') }}</flux:button>
                                        <flux:button size="sm" variant="danger"
                                            x-on:click="$wire.score('madichal', @js($key), left)">{{ __('Madichal') }}</flux:button>
                                    </div>
                                </div>
                            @endcan
                        </div>
                    @endforeach
                </div>

                @if ($awaitingDecision)
                    <div class="rule-2"></div>

                    <div class="flex flex-wrap items-center gap-3 bg-brand-500/10 px-6 py-4">
                        <x-ui.tag variant="brand">{{ __('Decision') }}</x-ui.tag>
                        <span class="text-sm">{{ __('Level on scores, on how they were earned and on warnings. Who did the referees give it to?') }}</span>

                        @can('score-bout')
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
                    :subtitle="__('Every call, what caused it, and who entered it. This is the record a protest is settled from.')"
                >
                    <div class="rule-2"></div>

                    @php
                        $voided = $log->where('action', 'score_voided')->pluck('after.voids_event_id')->filter()->map(fn ($id) => (int) $id)->all();

                        // A row whose cause was withdrawn is struck through
                        // with its cause, so the log reads the way the board
                        // does rather than showing a chala that no longer
                        // counts as though it still did.
                        $liveIds = collect($calls)->pluck('id')->all();
                    @endphp

                    <div class="overflow-x-auto">
                        <table class="t">
                            <thead>
                                <tr>
                                    <th>{{ __('#') }}</th>
                                    <th>{{ __('Clock') }}</th>
                                    <th>{{ __('Call') }}</th>
                                    <th>{{ __('Yakhtak') }}</th>
                                    <th>{{ __('Origin') }}</th>
                                    <th>{{ __('Entered by') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($log as $entry)
                                    @php
                                        $clock = $entry->after['clock'] ?? null;
                                        $isVoid = $entry->action === 'score_voided';
                                        $isScore = $entry->action === 'scored';
                                        $struck = in_array($entry->id, $voided, true)
                                            || ($isScore && ! in_array((int) $entry->id, $liveIds, true));
                                        $colour = $entry->competitor_side;
                                    @endphp

                                    <tr @class(['text-ink/40 line-through' => $struck]) wire:key="log-{{ $entry->id }}">
                                        <td class="font-mono text-xs tabular-nums text-ink/40">{{ $entry->sequence_number }}</td>
                                        <td class="font-mono text-xs tabular-nums">
                                            {{ $clock === null ? '—' : sprintf('%d:%02d', intdiv($clock, 60), $clock % 60) }}
                                        </td>
                                        <td>
                                            @if ($entry->action === 'stoppage')
                                                <span class="italic text-ink/55">{{ __('Tuxta') }}</span>
                                            @elseif ($entry->action === 'jazzo')
                                                <span class="font-bold text-warn-700 dark:text-warn-400">{{ __('Jazzo') }}</span>
                                            @elseif ($entry->action === 'resumed')
                                                <span class="italic text-ink/55">{{ __('Resumed') }}</span>
                                            @elseif ($isVoid)
                                                <span class="italic text-ink/55">{{ __('Taken back: :call', ['call' => $entry->event_type ?? ($entry->after['call'] ?? '')]) }}</span>
                                            @else
                                                <span class="font-bold capitalize">{{ $entry->event_type ?? ($entry->after['call'] ?? '') }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($colour === 'blue')
                                                <x-ui.tag variant="info">{{ __('Blue') }}</x-ui.tag>
                                            @elseif ($colour === 'green')
                                                <x-ui.tag variant="brand">{{ __('Green') }}</x-ui.tag>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="text-[11px] uppercase tracking-wider text-ink/55">
                                            {{ $entry->origin ? str_replace('_', ' ', $entry->origin) : '—' }}
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

                            @can('score-bout')
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
