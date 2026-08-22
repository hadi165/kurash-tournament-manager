{{--
    The federation's scoreboard, as it reads in the hall.

    Mat, contest number and phase top left; the contest clock in the middle;
    weight class and division top right. Below that each athlete has a pane in
    the colour of their yakhtak — a blue panel and a green panel, not two grey
    boxes with a coloured word on them, because at thirty metres the colour is
    what carries and the word is the caption.

    Six counters per side. G / Y / C are set in yellow, D / T in red, and M
    counts the madichal that ends the contest on the third. There is no khalol
    column because a khalol ends the contest, and an ended contest shows WINNER.

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
    {{-- A decided contest turns the whole board the winner's yakhtak colour.
         Not a badge on one pane: from the back of a hall the colour of the
         screen is the result, and it is legible before any word on it is. --}}
    class="board {{ $winnerSide ? '-won -won-'.$winnerSide : '' }}"
>
    @php
        $genderLabel = match ($bout?->weightCategory?->gender) {
            'M' => __('Men'),
            'F' => __('Women'),
            default => __('Open'),
        };

        // Top pane first, as the board is read. Blue is side A and green is
        // side B throughout the system — the yakhtak the bracket assigned, not
        // a decoration chosen per screen.
        $sides = $bout === null ? [] : [
            ['athlete' => $bout->athleteA, 'tally' => $tally['a'], 'id' => $bout->athlete_a_id, 'yakhtak' => 'blue'],
            ['athlete' => $bout->athleteB, 'tally' => $tally['b'], 'id' => $bout->athlete_b_id, 'yakhtak' => 'green'],
        ];

        $brandLogo = config('branding.logo');
        $hasBrandLogo = $brandLogo && is_file(public_path($brandLogo));
    @endphp

    <x-competition.finish-bell :bout="$bout" :decided="$winnerSide !== null" />

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
            {{-- One contest, one clock. The period row that used to sit under
                 it is gone: a kurash contest as this system runs it is a single
                 round, and a dot labelled "Period 1" that could never become a
                 Period 2 was furniture claiming to be information. --}}
            <div class="clock" :class="urgent && '-urgent'" x-text="display">{{ sprintf('%02d:%02d', intdiv($secondsLeft, 60), $secondsLeft % 60) }}</div>
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

                <div class="pane -{{ $side['yakhtak'] }} {{ $isWinner ? '-winner' : '' }}">
                    <span class="pane__bar"></span>

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
                                <span class="tag">
                                    {{ $side['yakhtak'] === 'blue' ? __('Yakhtak Blue') : __('Yakhtak Green') }}
                                </span>

                                @if ($isWinner)
                                    {{-- Set as literal capitals rather than
                                         transformed: this word is the result,
                                         and it should read as the result in
                                         the markup a screen reader reaches
                                         too. The reason sits beside it, so the
                                         hall is told how as well as who. --}}
                                    <span class="tag -winner">{{ __('WINNER') }}</span>

                                    @if ($victoryReason)
                                        <span class="tag -method">{{ Str::upper($victoryReason) }}</span>
                                    @endif
                                @endif
                            </div>

                            <div class="pane__name">{{ $side['athlete']?->fullname }}</div>
                            <div class="pane__country">{{ $side['athlete']?->noc_name }}</div>
                        </div>
                    </div>

                    {{-- A decided contest drops the counters that only matter
                         while it is running, so the counts that stand are the
                         only counts on the board. --}}
                    @php
                        $cells = $isWinner
                            ? [
                                ['Y', $side['tally']->yonbosh, 'yellow'],
                                ['C', $side['tally']->chala, 'yellow'],
                            ]
                            : [
                                ['G', $side['tally']->girrom, 'yellow'],
                                ['Y', $side['tally']->yonbosh, 'yellow'],
                                ['C', $side['tally']->chala, 'yellow'],
                                ['D', $side['tally']->dakki, 'red'],
                                ['T', $side['tally']->tanbeh, 'red'],
                                ['M', $side['tally']->madichal, 'red'],
                            ];
                    @endphp

                    <div class="cells">
                        @foreach ($cells as [$key, $value, $tone])
                            {{-- A counter at zero dims to the plate colour, so
                                 the hall's eye goes to what actually happened
                                 rather than to a row of noughts. --}}
                            <div class="cell -{{ $tone }} {{ $value === 0 ? '-dim' : '' }}">
                                <div class="cell__value">{{ $value }}</div>
                                <div class="cell__key">{{ $key }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach

            {{-- Jazzo sits over the middle of the two panes: half the contest
                 gone with nothing scored, and the referee has stopped it. It
                 stays until the mat resumes, which is what tells a hall that
                 sees a stopped clock why it stopped. --}}
            @if ($inJazzo)
                <div class="jazzo" role="status">
                    <div class="jazzo__word">{{ __('JAZZO') }}</div>
                    <div class="jazzo__sub">{{ __('Half time · no score') }}</div>
                </div>
            @endif
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
                @foreach ([
                    ['G', __('Girrom'), 'yellow'],
                    ['Y', __('Yonbosh'), 'yellow'],
                    ['C', __('Chala'), 'yellow'],
                    ['D', __('Dakki'), 'red'],
                    ['T', __('Tanbeh'), 'red'],
                    ['M', __('Madichal'), 'red'],
                ] as [$key, $word, $tone])
                    <span class="legend__item"><span class="legend__key -{{ $tone }}">{{ $key }}</span>{{ $word }}</span>
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

    /* The federation's mark, at the size it is meant to be read at. It shares
       the header with the mat name rather than being tucked into a corner of
       it: this is whose competition the board belongs to. */
    .chip {
        flex: none;
        background: #fff;
        padding: 0.9vh;
        border-radius: 1.3vh;
        border: 0.19vh solid var(--line);
        line-height: 0;
    }

    .chip img {
        width: 9.2vh;
        height: 9.2vh;
        object-fit: contain;
        display: block;
    }

    .chip__text {
        display: grid;
        place-items: center;
        width: 9.2vh;
        height: 9.2vh;
        font-size: 2.6vh;
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
        justify-content: center;
    }

    /* Light-on-dark in both themes, and a system monospace stack rather than a
       web font: a clock that falls back to a proportional face is a clock
       nobody can read at thirty metres. */
    .clock {
        font-family: ui-monospace, 'DejaVu Sans Mono', 'Courier New', monospace;
        font-size: 10.5vh;
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
        position: relative;
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 1.7vh;
        padding: 1.7vh 2.4vh;
        min-height: 0;
    }

    /* Each pane is its athlete's yakhtak. The tint is the panel, not a stripe
       on a grey panel: the two sides have to be told apart from the back of a
       hall, and colour is the only thing that carries that far. */
    .pane {
        flex: 1 1 0;
        display: flex;
        align-items: center;
        min-height: 0;
        overflow: hidden;
        border-radius: 2vh;
        border: 0.28vh solid var(--line);
        background: var(--pane);
    }

    .pane.-blue {
        background: var(--blue-tint);
        border-color: var(--blue);
    }

    .pane.-green {
        background: var(--green-tint);
        border-color: var(--green);
    }

    /* ── The winner ─────────────────────────────────────────────────────── */

    /* A decided contest turns the whole board, not one badge on one pane. The
       tokens are redefined on the root rather than overridden per element, so
       every part of the board — header, panes, strip, footer — moves together
       and nothing has to be remembered when a piece is added later. */
    .board.-won-blue {
        --bg: var(--won-blue-bg);
        --chrome: var(--won-blue-chrome);
        --strip: var(--won-blue-chrome);
        --pane: var(--won-blue-chrome);
        --line: var(--blue);
        --muted: var(--won-ink-muted);
        --text: var(--won-ink);
    }

    .board.-won-green {
        --bg: var(--won-green-bg);
        --chrome: var(--won-green-chrome);
        --strip: var(--won-green-chrome);
        --pane: var(--won-green-chrome);
        --line: var(--green);
        --muted: var(--won-ink-muted);
        --text: var(--won-ink);
    }

    /* The losing pane recedes rather than disappearing: the hall still wants
       to see who was beaten and on what. */
    .board.-won .pane:not(.-winner) {
        opacity: 0.5;
    }

    .board.-won .pane.-winner {
        box-shadow: inset 0 0 0 0.65vh var(--gold);
    }

    /* Kurash wrestlers wear a blue or a green yakhtak and the bracket decides
       which, so the bar is the athlete's yakhtak rather than decoration. */
    .pane__bar {
        width: 2.4vh;
        align-self: stretch;
        flex: none;
    }

    .pane.-blue .pane__bar { background: var(--blue); }
    .pane.-green .pane__bar { background: var(--green); }

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

    /* The yakhtak label, at a size that reads from the floor. It names the
       colour the athlete is wearing, which is how the hall, the coaches and
       the referee all refer to them. */
    .tag {
        display: inline-flex;
        padding: 0.55vh 2vh;
        border-radius: 999px;
        color: #fff;
        font-size: 2.8vh;
        font-weight: 900;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .pane.-blue .tag { background: var(--blue); }
    .pane.-green .tag { background: var(--green); }

    .pane .tag.-winner {
        background: var(--text);
        color: var(--bg);
        font-size: 3vh;
        font-weight: 900;
        letter-spacing: 0.08em;
        padding: 0.55vh 2.2vh;
        text-transform: none;
    }

    /* How the contest was won, beside who won it. */
    .pane .tag.-method {
        background: var(--gold);
        color: #1a1204;
        font-size: 2.6vh;
        letter-spacing: 0.12em;
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
        font-size: 2.7vh;
        font-weight: 700;
        color: var(--muted);
        margin-top: 0.2vh;
    }

    /* ── Score tiles ────────────────────────────────────────────────────── */

    .cells {
        display: flex;
        align-self: stretch;
        gap: 1vh;
        padding: 1.3vh 1.3vh 1.3vh 0;
    }

    .cell {
        width: 11.5vh;
        border-radius: 1.5vh;
        background: var(--cell);
        border: 0.28vh solid var(--cell-line);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
    }

    /* G, Y and C in yellow; D, T and M in red. The federation's own
       convention, and the one thing on this board that must not be read
       wrongly at distance. */
    .cell.-yellow {
        border-color: var(--score-yellow);
        background: var(--score-yellow-fill);
    }

    .cell.-red {
        border-color: var(--score-red);
        background: var(--score-red-fill);
    }

    .cell.-yellow .cell__value, .cell.-yellow .cell__key { color: var(--score-yellow); }
    .cell.-red .cell__value, .cell.-red .cell__key { color: var(--score-red); }

    /* A counter at nought is not news. It keeps its place so the row never
       reflows mid-contest, and drops back to the plate colour. */
    .cell.-dim {
        background: var(--cell-dim);
        border-color: var(--cell-line);
    }

    .cell.-dim .cell__value,
    .cell.-dim .cell__key { color: var(--dim); }

    .cell__value {
        font-size: 9.6vh;
        font-weight: 900;
        line-height: 1;
        font-variant-numeric: tabular-nums;
    }

    /* The letter under the number, large enough to be read rather than
       inferred: a board where only the digits are legible tells a hall a
       number and not what it counts. */
    .cell__key {
        font-size: 3.2vh;
        font-weight: 900;
        letter-spacing: 0.1em;
        margin-top: 0.4vh;
        line-height: 1;
    }

    /* ── Jazzo ──────────────────────────────────────────────────────────── */

    /* Centred over both panes, because it is the state of the contest rather
       than of either athlete. */
    .jazzo {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        z-index: 2;
        background: var(--jazzo-fill);
        border: 0.65vh solid var(--jazzo-line);
        border-radius: 2vh;
        padding: 2.2vh 6vh;
        text-align: center;
        box-shadow: 0 1.5vh 4vh rgb(0 0 0 / 0.45);
    }

    .jazzo__word {
        font-size: 8.5vh;
        font-weight: 900;
        line-height: 1;
        letter-spacing: 0.08em;
        color: var(--jazzo-text);
    }

    .jazzo__sub {
        margin-top: 0.9vh;
        font-size: 2.4vh;
        font-weight: 700;
        letter-spacing: 0.14em;
        text-transform: uppercase;
        color: var(--jazzo-text);
        opacity: 0.75;
    }

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
        gap: 2.8vh;
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
        font-size: 2.4vh;
        font-weight: 900;
        color: var(--text);
    }

    .legend__key.-yellow { color: var(--score-yellow); }
    .legend__key.-red { color: var(--score-red); }

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
