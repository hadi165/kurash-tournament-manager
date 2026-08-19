{{--
    The federation's scoreboard, as it reads in the hall.

    Layout follows the IKA board: mat and phase top left, contest clock in red
    on black in the middle, division top right; then each athlete as a name
    band and a row of counts, with one shared set of column letters between
    them. Y / C / D / T — yonbosh, chala, dakki, tanbeh. There is no halal
    column because a halal ends the contest, and an ended contest shows WINNER.

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
    }"
    x-init="start()"
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
        // row shows; dakki is derived from tanbeh rather than counted
        // separately, so the D column is the state, not a tally.
        $sides = $bout === null ? [] : [
            [
                'athlete' => $bout->athleteA,
                'tally' => $tally['a'],
                'id' => $bout->athlete_a_id,
                'corner' => 'blue',
            ],
            [
                'athlete' => $bout->athleteB,
                'tally' => $tally['b'],
                'id' => $bout->athlete_b_id,
                'corner' => 'green',
            ],
        ];
    @endphp

    <header class="head">
        <div class="head__left">
            <div>{{ $court->label() }}@if ($bout?->fight_number) / No.{{ $bout->fight_number }} @endif</div>
            <div>{{ $bout?->phase($totalRounds) }}</div>
        </div>

        <div class="clock">
            <span x-text="display">{{ sprintf('%02d:%02d', intdiv($secondsLeft, 60), $secondsLeft % 60) }}</span>
        </div>

        <div class="head__right">
            <div>{{ $bout ? $genderLabel : '' }}</div>
            <div>{{ $bout?->weightCategory?->label }}{{ $bout ? ' kg' : '' }}</div>
        </div>
    </header>

    @if ($bout === null)
        <div class="idle">{{ __('No contest on this mat') }}</div>
    @else
        @foreach ($sides as $index => $side)
            @php $isWinner = $winner !== null && $winner->id === $side['id']; @endphp

            {{-- The top athlete's name band sits above their row, the bottom
                 athlete's below theirs, so each name reads next to its counts. --}}
            @if ($index === 0)
                <div class="band band--{{ $side['corner'] }}">{{ $side['athlete']?->fullname }}</div>
            @endif

            <div class="row">
                {{-- The flag is rendered here rather than through x-flag: that
                     component sizes itself with Tailwind utilities, and this
                     board is standalone HTML with its own stylesheet. --}}
                @php($iso = \App\Support\Noc::iso($side['athlete']?->noc_code))

                <div class="row__who">
                    @if ($iso)
                        <img class="row__flag" src="{{ asset("flags/{$iso}.svg") }}" alt="{{ $side['athlete']?->noc_name }}">
                    @else
                        <span class="row__flag row__flag--blank"></span>
                    @endif

                    <span class="row__noc">{{ \App\Support\Noc::normalise($side['athlete']?->noc_code) }}</span>
                </div>

                @if ($isWinner)
                    <div class="cell">{{ $side['tally']->yonbosh }}</div>
                    <div class="cell cell--winner"><span>{{ __('WINNER') }}</span></div>
                    <div class="cell">{{ $side['tally']->tanbeh }}</div>
                @else
                    <div class="cell">{{ $side['tally']->yonbosh }}</div>
                    <div class="cell">{{ $side['tally']->chala }}</div>
                    <div class="cell">{{ $side['tally']->isDakki() ? 1 : 0 }}</div>
                    <div class="cell">{{ $side['tally']->tanbeh }}</div>
                @endif
            </div>

            {{-- One shared set of column letters, between the two rows. --}}
            @if ($index === 0)
                <div class="legend">
                    <div class="row__who"></div>
                    <div>Y</div>
                    <div>C</div>
                    <div>D</div>
                    <div>T</div>
                </div>
            @else
                <div class="band band--{{ $side['corner'] }}">{{ $side['athlete']?->fullname }}</div>
            @endif
        @endforeach
    @endif
</div>

<style>
    /* Sized in vh throughout: the same board has to read on a laptop at the
       scorers' table and a projector at the end of a hall, and neither should
       need its own stylesheet. */
    .board {
        height: 100vh;
        display: flex;
        flex-direction: column;
        background: #000;
        color: #fff;
        font-family: "Arial Narrow", Arial, system-ui, sans-serif;
        overflow: hidden;
    }

    .head {
        flex: 0 0 19%;
        display: grid;
        grid-template-columns: 1fr auto 1fr;
        align-items: center;
        gap: 2vh;
        padding: 0 2.4vh;
        background: #0b0b0b;
    }

    .head__left, .head__right {
        font-size: 3vh;
        font-weight: 700;
        line-height: 1.25;
        letter-spacing: 0.01em;
    }

    .head__right { text-align: right; }

    /* Red on black, the way the hall clock reads. A system monospace stack
       rather than a web font: a venue machine is often offline, and a clock
       that falls back to a proportional face is a clock nobody can read at
       thirty metres. */
    .clock {
        background: #000;
        border: 0.4vh solid #1c1c1c;
        border-radius: 0.6vh;
        padding: 0.4vh 2.4vh;
        font-family: ui-monospace, "DejaVu Sans Mono", "Courier New", monospace;
        font-size: 9vh;
        font-weight: 700;
        line-height: 1.05;
        color: #ff2a17;
        font-variant-numeric: tabular-nums;
        letter-spacing: 0.02em;
    }

    .band {
        flex: 0 0 11%;
        display: flex;
        align-items: center;
        padding: 0 2.4vh;
        font-size: 4.6vh;
        font-weight: 700;
        letter-spacing: 0.02em;
        text-transform: uppercase;
        color: #fff;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* Kurash wrestlers wear a blue or a green yakhtak, and the bracket decides
       which. Carrying that onto the name bands costs nothing and means the
       board says which corner is which without another row of chrome. */
    .band--blue  { background: #1560b0; }
    .band--green { background: #1f7a3d; }

    .row {
        flex: 1 1 0;
        display: grid;
        grid-template-columns: 30% repeat(4, 1fr);
        align-items: center;
        background: #fff;
        color: #000;
        min-height: 0;
    }

    .row__who {
        display: flex;
        align-items: center;
        gap: 1.6vh;
        padding-left: 2.4vh;
        min-width: 0;
    }

    .row__flag {
        width: 9vh;
        height: 6.4vh;
        object-fit: cover;
        border: 1px solid rgba(0, 0, 0, 0.25);
        flex: none;
        display: block;
    }

    /* A delegation with no flag on file still needs the column to hold its
       width, or the NOC code jumps left and the two rows stop lining up. */
    .row__flag--blank { background: #d8d8d8; }

    .row__noc {
        font-size: 7vh;
        font-weight: 700;
        letter-spacing: 0.01em;
    }

    .cell {
        text-align: center;
        font-size: 13vh;
        font-weight: 700;
        line-height: 1;
        font-variant-numeric: tabular-nums;
    }

    /* Spans the two middle columns, leaving the yonbosh and tanbeh counts
       readable either side of it. */
    .cell--winner {
        grid-column: span 2;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #86d94a;
        align-self: stretch;
        margin: 1.2vh 0.8vh;
    }

    .cell--winner span {
        font-size: 7.4vh;
        font-weight: 700;
        letter-spacing: 0.02em;
    }

    .legend {
        flex: 0 0 7%;
        display: grid;
        grid-template-columns: 30% repeat(4, 1fr);
        align-items: center;
        background: #fff;
        color: #000;
        border-top: 0.3vh solid #000;
    }

    .legend > div {
        text-align: center;
        font-size: 3.4vh;
        font-weight: 700;
        letter-spacing: 0.08em;
    }

    .idle {
        flex: 1;
        display: grid;
        place-items: center;
        font-size: 5vh;
        color: #6b6b6b;
    }
</style>
