{{--
    The federation's scoreboard, as it reads in the hall.

    Mat, contest number and phase top left; the contest clock in the middle;
    weight class and division top right. Below that each athlete is a pane of
    their own, carrying a full-height corner bar in the colour of their
    yakhtak, their flag, their name, and their counts as separated tiles.
    Y / C / D / T — yonbosh, chala, dakki, tanbeh. There is no halal column
    because a halal ends the contest, and an ended contest shows WINNER.

    Polls for the score; runs the clock locally between polls. Two rates on
    purpose: the score only changes when a referee calls something, but a clock
    that moved only when a poll landed would visibly stutter, and a stuttering
    clock in front of a hall reads as a broken system.
--}}
<div
    wire:poll.2s
    x-data="{
        left: @js($secondsLeft),
        running: @js($clockRunning),
        handle: null,
        start() {
            clearInterval(this.handle)
            this.handle = setInterval(() => { if (this.running && this.left > 0) this.left-- }, 1000)
        },
        sync(seconds, running) { this.left = seconds; this.running = running },
        get display() {
            const m = String(Math.floor(this.left / 60)).padStart(2, '0')
            return `${m}:${String(this.left % 60).padStart(2, '0')}`
        },
        {{-- The last twenty seconds are the only moment the clock changes
             colour, so it has to follow the local tick rather than the poll. --}}
        get urgent() { return this.left <= 20 },

        {{-- The board runs its own clock between polls, so a dead feed leaves a
             plausible but wrong board that nobody notices. Six seconds without
             an update and the dot says so. --}}
        seen: Date.now(),
        stale: false,
        watch: null,
        watchFeed() {
            clearInterval(this.watch)
            this.watch = setInterval(() => { this.stale = Date.now() - this.seen > 6000 }, 1000)
        },
    }"
    x-init="start(); watchFeed()"
    x-on:livewire:updated="seen = Date.now(); stale = false"
    {{-- Re-anchored on every poll, so a board left running all weekend cannot
         drift away from the mat. --}}
    x-effect="sync(@js($secondsLeft), @js($clockRunning))"
    class="board"
>
    @php
        $genderLabel = match ($bout?->weightCategory?->gender) {
            'M' => __('Men'),
            'F' => __('Women'),
            default => __('Open'),
        };

        // Top athlete first, as the board is read. Each carries the counts its
        // pane shows; dakki is derived from tanbeh rather than counted
        // separately, so the D tile is the state, not a tally.
        $sides = $bout === null ? [] : [
            ['athlete' => $bout->athleteA, 'tally' => $tally['a'], 'id' => $bout->athlete_a_id, 'corner' => 'blue'],
            ['athlete' => $bout->athleteB, 'tally' => $tally['b'], 'id' => $bout->athlete_b_id, 'corner' => 'green'],
        ];

        $brandLogo = config('branding.logo');
        $hasBrandLogo = $brandLogo && is_file(public_path($brandLogo));
    @endphp

    <header class="head">
        <div class="head__id">
            {{-- The logo always sits on a white chip and is never recoloured:
                 the artwork is the federation's. It is dropped when the board
                 is embedded, because the chrome around it already shows one. --}}
            <div class="chip" @if ($embedded) hidden @endif>
                @if ($hasBrandLogo)
                    <img src="{{ asset($brandLogo) }}" alt="{{ config('branding.short_name') }}">
                @else
                    <span class="chip__text">{{ config('branding.short_name') }}</span>
                @endif
            </div>

            <div class="head__titles">
                <div class="head__strong">
                    {{ $court->label() }}@if ($bout?->fight_number) · {{ __('No.:n', ['n' => $bout->fight_number]) }}@endif
                </div>
                <div class="head__meta">{{ $bout?->phase($totalRounds) }}</div>
            </div>
        </div>

        <div class="head__clock">
            <div class="clock" :class="urgent && '-urgent'" x-text="display">{{ sprintf('%02d:%02d', intdiv($secondsLeft, 60), $secondsLeft % 60) }}</div>

            {{-- One dot per period. A kurash contest as this system runs it is a
                 single period, so there is one — the row is here so a rules
                 edition that splits the contest has somewhere to say so. --}}
            <div class="periods">
                <span class="periods__dot -on"></span>
                <span class="periods__label">{{ __('Period :n', ['n' => 1]) }}</span>
            </div>
        </div>

        <div class="head__division">
            <div class="head__titles head__titles--end">
                <div class="head__strong">{{ $bout?->weightCategory?->label }}{{ $bout ? ' '.__('kg') : '' }}</div>
                {{-- The division, not just the gender: two classes can share a
                     weight label and be different competitions. --}}
                <div class="head__meta">
                    {{ $bout ? ($bout->weightCategory?->ageCategory?->name ?: $genderLabel) : '' }}
                </div>
            </div>

            @if ($readOnly && ! $embedded)
                <span class="readonly">{{ __('Read only') }}</span>
            @endif

            <span class="link" :class="stale ? '-stale' : '-ok'"
                  :title="stale ? @js(__('Feed stale — no update for over 6 seconds')) : @js(__('Scoreboard feed live'))"></span>
        </div>
    </header>

    @if ($bout === null)
        <div class="idle">
            <div class="idle__line">{{ __('No contest on this mat') }}</div>
            <div class="idle__sub">{{ __('The next bout will appear automatically') }}</div>
        </div>
    @else
        <div class="panes">
            @foreach ($sides as $side)
                @php
                    $isWinner = $winner !== null && $winner->id === $side['id'];
                    $iso = \App\Support\Noc::iso($side['athlete']?->noc_code);
                @endphp

                <div class="pane {{ $isWinner ? '-winner -winner-' . $side['corner'] : '' }}">
                    <span class="pane__bar -{{ $side['corner'] }}"></span>

                    <div class="pane__who">
                        {{-- The flag is rendered here rather than through
                             x-flag: that component sizes itself with Tailwind
                             utilities, and this board is standalone HTML with
                             its own stylesheet. At thirty metres the flag is a
                             primary identifier, so it is the normal case and
                             the code is the fallback. --}}
                        <span class="flag">
                            @if ($iso)
                                <img src="{{ asset("flags/{$iso}.svg") }}" alt="{{ $side['athlete']?->noc_name }}">
                            @else
                                {{ \App\Support\Noc::normalise($side['athlete']?->noc_code) }}
                            @endif
                        </span>

                        <div class="pane__titles">
                            <div class="tags">
                                <span class="tag -{{ $side['corner'] }}">
                                    {{ $side['corner'] === 'blue' ? __('Blue corner') : __('Green corner') }}
                                </span>

                                @if ($isWinner)
                                    {{-- Set as literal capitals rather than
                                         transformed: this word is the result,
                                         and it should read as the result in
                                         the markup a screen reader reaches
                                         too. --}}
                                    <span class="tag -winner">{{ __('WINNER') }}</span>
                                @endif
                            </div>

                            <div class="pane__name">{{ $side['athlete']?->fullname }}</div>
                            <div class="pane__country">{{ $side['athlete']?->noc_name }}</div>
                        </div>
                    </div>

                    {{-- A decided contest drops the two tiles that only matter
                         while it is running, so the counts that stand are the
                         only counts on the board. --}}
                    @php
                        $cells = $isWinner
                            ? [['Y', $side['tally']->yonbosh], ['T', $side['tally']->tanbeh]]
                            : [
                                ['Y', $side['tally']->yonbosh],
                                ['C', $side['tally']->chala],
                                ['D', $side['tally']->isDakki() ? 1 : 0],
                                ['T', $side['tally']->tanbeh],
                            ];
                    @endphp

                    <div class="cells">
                        @foreach ($cells as [$key, $value])
                            {{-- A zero dakki or tanbeh count dims, so the
                                 referee's eye goes to what actually scored. --}}
                            <div class="cell {{ in_array($key, ['D', 'T'], true) && $value === 0 ? '-dim' : '' }}">
                                <div class="cell__value">{{ $value }}</div>
                                <div class="cell__key">{{ $key }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Athletes and coaches at this mat should not have to find another
             screen to know they are up. Nothing is shown when the mat has
             nothing left to run: an empty strip would read as a board that had
             lost its feed. --}}
        @if ($nextBout)
            <div class="next">
                <span class="next__kicker">{{ __('Next') }}</span>
                <span class="next__text">
                    {{ __('No.:n', ['n' => $nextBout->fight_number]) }}
                    ·
                    {{ $nextBout->weightCategory?->label }} {{ __('kg') }}
                    {{ match ($nextBout->weightCategory?->gender) {
                        'M' => __('Men'),
                        'F' => __('Women'),
                        default => __('Open'),
                    } }}
                    ·
                    {{ $nextBout->athleteA?->fullname }} ({{ \App\Support\Noc::normalise($nextBout->athleteA?->noc_code) }})
                    {{ __('v') }}
                    {{ $nextBout->athleteB?->fullname }} ({{ \App\Support\Noc::normalise($nextBout->athleteB?->noc_code) }})
                </span>
            </div>
        @endif

        <footer class="foot">
            <div class="legend">
                @foreach ([['Y', __('Yonbosh')], ['C', __('Chala')], ['D', __('Dakki')], ['T', __('Tanbeh')]] as [$key, $word])
                    <span class="legend__item"><span class="legend__key">{{ $key }}</span>{{ $word }}</span>
                @endforeach
            </div>

            <div class="foot__meta">
                {{ $court->championship->title }}@if ($court->championship->location) · {{ $court->championship->location }}@endif
            </div>
        </footer>
    @endif
</div>

<style>
    /* Sized in vh throughout: the same board has to read on a laptop at the
       scorers' table and a projector at the end of a hall, and neither should
       need its own stylesheet. The design is drawn at 1920x1080, so every
       value here is its pixel value divided by 10.8. */
    .board {
        height: 100vh;
        display: flex;
        flex-direction: column;
        background: var(--bg);
        color: var(--text);
        overflow: hidden;
    }

    /* ── Header ─────────────────────────────────────────────────────────── */

    .head {
        flex: 0 0 15.9vh;
        display: grid;
        grid-template-columns: 1fr auto 1fr;
        align-items: center;
        gap: 3vh;
        padding: 0 4.1vh;
        background: var(--chrome);
        border-bottom: 0.19vh solid var(--line);
    }

    .head__id {
        display: flex;
        align-items: center;
        gap: 2.2vh;
        min-width: 0;
    }

    .chip {
        flex: none;
        background: #fff;
        padding: 0.65vh;
        border-radius: 1.1vh;
        border: 0.19vh solid var(--line);
        line-height: 0;
    }

    .chip img {
        width: 5.6vh;
        height: 5.6vh;
        object-fit: contain;
        display: block;
    }

    .chip__text {
        display: grid;
        place-items: center;
        width: 5.6vh;
        height: 5.6vh;
        font-size: 1.8vh;
        font-weight: 900;
        color: #046830;
        line-height: 1;
    }

    .head__titles { min-width: 0; }
    .head__titles--end { text-align: right; }

    .head__strong {
        font-size: 4.3vh;
        font-weight: 900;
        line-height: 1;
        letter-spacing: -0.015em;
        white-space: nowrap;
    }

    .head__meta {
        font-size: 2.1vh;
        font-weight: 600;
        color: var(--muted);
        margin-top: 0.65vh;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .head__clock {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.9vh;
    }

    /* Light-on-dark in both themes, and a system monospace stack rather than a
       web font: a clock that falls back to a proportional face is a clock
       nobody can read at thirty metres. */
    .clock {
        font-family: ui-monospace, 'DejaVu Sans Mono', 'Courier New', monospace;
        font-size: 9.3vh;
        font-weight: 700;
        line-height: 1;
        color: var(--clock-text);
        font-variant-numeric: tabular-nums;
        letter-spacing: 0.01em;
        padding: 0.55vh 3.1vh;
        border-radius: 1.5vh;
        background: var(--clock-plate);
        border: 0.28vh solid var(--clock-plate);
    }

    .clock.-urgent {
        color: var(--clock-urgent);
        border-color: var(--clock-urgent);
    }

    .periods {
        display: flex;
        align-items: center;
        gap: 0.9vh;
    }

    .periods__dot {
        width: 1.7vh;
        height: 1.7vh;
        border-radius: 999px;
        border: 0.19vh solid var(--dim);
    }

    .periods__dot.-on {
        background: var(--green);
        border-color: var(--green);
    }

    .periods__label {
        font-size: 1.95vh;
        font-weight: 700;
        color: var(--muted);
        letter-spacing: 0.1em;
        text-transform: uppercase;
        margin-left: 0.55vh;
    }

    .head__division {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 2.6vh;
    }

    /* The board runs its clock locally between polls, so a dead feed leaves a
       plausible but wrong board. The dot is what says the feed is alive. */
    .link {
        width: 1.85vh;
        height: 1.85vh;
        border-radius: 999px;
        flex: none;
    }

    .link.-ok {
        background: var(--green);
        box-shadow: 0 0 0 0.37vh rgb(1 154 68 / 0.18);
    }

    .link.-stale {
        background: #d7263d;
        box-shadow: 0 0 0 0.37vh rgb(215 38 61 / 0.22);
        animation: sb-pulse 1s ease-in-out infinite;
    }

    @keyframes sb-pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.35; }
    }

    /* A viewer account is told what it is, rather than left to work it out
       from the absence of controls it never had. */
    .readonly {
        font-size: 1.85vh;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: var(--muted);
        border: 0.19vh solid var(--line);
        border-radius: 0.5vh;
        padding: 0.4vh 1.2vh;
    }

    @media (prefers-reduced-motion: reduce) {
        .link.-stale { animation: none; }
    }

    /* ── Athlete panes ──────────────────────────────────────────────────── */

    .panes {
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 1.7vh;
        padding: 1.7vh 2.4vh;
        min-height: 0;
    }

    .pane {
        flex: 1 1 0;
        display: flex;
        align-items: center;
        min-height: 0;
        overflow: hidden;
        border-radius: 2vh;
        border: 0.19vh solid var(--line);
        background: var(--pane);
    }

    .pane.-winner-blue { background: var(--blue-tint); border-color: var(--blue); }
    .pane.-winner-green { background: var(--green-tint); border-color: var(--green); }

    /* Kurash wrestlers wear a blue or a green yakhtak and the bracket decides
       which, so the bar is the athlete's corner rather than decoration. */
    .pane__bar {
        width: 1.85vh;
        align-self: stretch;
        flex: none;
    }

    .pane__bar.-blue { background: var(--blue); }
    .pane__bar.-green { background: var(--green); }

    .pane__who {
        flex: 1;
        min-width: 0;
        display: flex;
        align-items: center;
        gap: 3.1vh;
        padding: 0 3.7vh;
    }

    .flag {
        width: 14.6vh;
        height: 9.8vh;
        flex: none;
        border-radius: 1.1vh;
        border: 0.19vh solid var(--cell-line);
        background: var(--flag-fill);
        display: grid;
        place-items: center;
        overflow: hidden;
        font-size: 3.9vh;
        font-weight: 900;
        color: var(--text);
    }

    .flag img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .pane__titles { min-width: 0; }

    .tags {
        display: flex;
        align-items: center;
        gap: 1.3vh;
    }

    .tag {
        display: inline-flex;
        padding: 0.46vh 1.7vh;
        border-radius: 999px;
        color: #fff;
        font-size: 1.95vh;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .tag.-blue { background: var(--blue); }
    .tag.-green { background: var(--green); }

    .tag.-winner {
        background: var(--text);
        color: var(--bg);
        font-size: 2.3vh;
        font-weight: 900;
        letter-spacing: 0.08em;
        padding: 0.46vh 2vh;
        text-transform: none;
    }

    .pane__name {
        font-size: 6.85vh;
        font-weight: 900;
        line-height: 1.02;
        letter-spacing: -0.02em;
        text-transform: uppercase;
        margin-top: 0.75vh;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .pane__country {
        font-size: 2.4vh;
        font-weight: 600;
        color: var(--muted);
        margin-top: 0.2vh;
    }

    /* ── Score tiles ────────────────────────────────────────────────────── */

    .cells {
        display: flex;
        align-self: stretch;
        gap: 1.3vh;
        padding: 1.3vh 1.3vh 1.3vh 0;
    }

    .cell {
        width: 15.2vh;
        border-radius: 1.5vh;
        background: var(--cell);
        border: 0.19vh solid var(--cell-line);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
    }

    .cell.-dim { background: var(--cell-dim); }

    .cell__value {
        font-size: 9.6vh;
        font-weight: 900;
        line-height: 1;
        font-variant-numeric: tabular-nums;
    }

    .cell__key {
        font-size: 2vh;
        font-weight: 700;
        letter-spacing: 0.14em;
        color: var(--muted);
        margin-top: 0.37vh;
    }

    .cell.-dim .cell__value,
    .cell.-dim .cell__key { color: var(--dim); }

    /* ── Footer and idle ────────────────────────────────────────────────── */

    .next {
        flex: 0 0 5.7vh;
        display: flex;
        align-items: center;
        gap: 1.85vh;
        padding: 0 4.1vh;
        background: var(--strip);
        border-top: 0.19vh solid var(--line);
        min-width: 0;
    }

    .next__kicker {
        flex: none;
        font-size: 1.85vh;
        font-weight: 900;
        letter-spacing: 0.14em;
        text-transform: uppercase;
        color: var(--muted);
    }

    .next__text {
        font-size: 2.6vh;
        font-weight: 700;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .foot {
        flex: 0 0 7vh;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 3vh;
        padding: 0 4.1vh;
        background: var(--chrome);
        border-top: 0.19vh solid var(--line);
    }

    .legend {
        display: flex;
        align-items: center;
        gap: 3.5vh;
    }

    .legend__item {
        display: inline-flex;
        align-items: center;
        gap: 0.9vh;
        font-size: 1.95vh;
        font-weight: 600;
        color: var(--muted);
        letter-spacing: 0.03em;
    }

    .legend__key {
        font-size: 2.2vh;
        font-weight: 900;
        color: var(--text);
    }

    .foot__meta {
        font-size: 1.95vh;
        font-weight: 700;
        color: var(--muted);
        letter-spacing: 0.1em;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .idle {
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 1.3vh;
        color: var(--dim);
    }

    .idle__line {
        font-size: 7vh;
        font-weight: 900;
        letter-spacing: -0.01em;
    }

    .idle__sub {
        font-size: 2.8vh;
        font-weight: 600;
    }
</style>
