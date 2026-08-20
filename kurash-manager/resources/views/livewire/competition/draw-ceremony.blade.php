{{--
    The draw as a hall watches it happen.

    Polls like the scoreboard does and holds no state of its own: every panel
    divides the same revealed count, so the board, the pool and the footer
    cannot disagree about where the draw has got to.
--}}
@php
    use App\Support\BracketSeeding;

    $championship = $weightCategory->ageCategory->championship;
    $brandLogo = config('branding.logo');
    $hasBrandLogo = $brandLogo && is_file(public_path($brandLogo));

    // Column widths are fixed: this is 1920×1080 furniture read from the back
    // of a hall, not a responsive page.
    $columns = '300px repeat('.max(1, $rounds).', 150px) 210px';
@endphp

<div
    wire:poll.2s
    class="dc dc-board-page {{ $size >= 32 ? 'dc-board-32' : '' }} {{ $complete ? 'dc-complete' : '' }}"
    x-data="{
        seen: Date.now(),
        stale: false,
        handle: null,
        beat: null,
        pace: @js($pace),
        drawing: @js((bool) $drawing),
        {{-- True for the first moment of a position, before the name lands.
             Derived from the same stamp the server derives the reveal from, so
             the page and the board can never disagree about where the draw is. --}}
        anticipating: false,
        watchBeat() {
            clearInterval(this.beat)

            if (! this.pace || ! this.drawing) { this.anticipating = false; return }

            const tick = () => {
                const elapsed = (Date.now() / 1000) - this.pace.at
                this.anticipating = (elapsed % this.pace.per) < 1.1
            }

            tick()
            this.beat = setInterval(tick, 200)
        },
        init() {
            this.watchBeat()

            {{-- The board runs between polls, so a dead feed leaves a
                 plausible but wrong draw on screen. Six seconds without an
                 update and the dot says so. --}}
            this.handle = setInterval(() => { this.stale = Date.now() - this.seen > 6000 }, 1000)
        },
        destroy() { clearInterval(this.handle); clearInterval(this.beat) },
    }"
    x-on:livewire:updated="seen = Date.now(); stale = false; drawing = @js((bool) $drawing); pace = @js($pace); watchBeat()"
>
    <header class="dc-head">
        <div class="dc-head-id">
            @if ($hasBrandLogo)
                <span class="dc-logo">
                    <img src="{{ asset($brandLogo) }}" alt="{{ config('branding.short_name') }}">
                </span>
            @endif

            <div>
                <div class="dc-org">{{ config('branding.organisation') }}</div>
                <div class="dc-champ">{{ $championship->title }}</div>
            </div>
        </div>

        <div class="dc-chips">
            <span class="dc-chip">{{ $weightCategory->ageCategory->name }} · {{ $weightCategory->label }} {{ __('kg') }}</span>

            @if ($size > 0)
                <span class="dc-chip">{{ __('Bracket of :size', ['size' => $size]) }}</span>
                {{-- Counted separately, because they are different numbers: the
                     bracket has sixteen seats, the draw has twelve athletes. --}}
                <span class="dc-chip">{{ trans_choice('{1}:count athlete|[2,*]:count athletes', $total, ['count' => $total]) }}</span>
            @endif

            @if ($complete)
                <span class="dc-chip dc-chip-done"><span class="dc-dot"></span>{{ __('Draw complete') }}</span>
            @elseif ($waiting)
                <span class="dc-chip">{{ __('Official draw') }}</span>
            @else
                <span class="dc-chip dc-chip-live"><span class="dc-dot"></span>{{ __('Live draw') }}</span>
            @endif

            <span class="dc-feed" :class="stale && 'dc-feed-stale'"
                  :title="stale ? '{{ __('Feed stale — no update for over 6 seconds') }}' : '{{ __('Draw feed live') }}'"></span>
        </div>
    </header>

    <div class="dc-body">
        <aside class="dc-pool">
            <div class="dc-kicker">
                {{ $waiting ? __('Official draw') : ($complete ? __('Top seed') : __('Now drawing')) }}
            </div>

            {{-- On completion the panel holds the seed that leads the bracket,
                 so the space does not simply go blank at the moment the hall
                 looks at it hardest. --}}
            @php $spotlight = $drawing ?? ($seats[0]['athlete'] ?? null); @endphp

            @if ($waiting)
                {{-- Nothing is revealed until somebody starts the ceremony. --}}
                <div class="dc-panel dc-now dc-now-waiting">
                    <div class="dc-now-name">{{ __('Ready to begin') }}</div>
                    <div class="dc-now-meta">
                        {{ trans_choice(
                            '{1}:count athlete waiting to be drawn|[2,*]:count athletes waiting to be drawn',
                            $total, ['count' => $total]
                        ) }}
                    </div>
                </div>
            @else
                <div class="dc-panel dc-now" wire:key="now-{{ $revealed }}">
                    <div class="dc-now-noc">
                        <span x-show="! anticipating">{{ $spotlight ? \App\Support\Noc::normalise($spotlight->noc_code) : '—' }}</span>
                        <span x-show="anticipating" x-cloak>{{ __('Drawing') }}</span>
                    </div>

                    {{-- Both are rendered; the beat only decides which shows, so
                         a page with no script still reads the name. --}}
                    <div class="dc-now-name" x-show="! anticipating">{{ $spotlight?->fullname ?? __('Waiting') }}</div>
                    <div class="dc-now-name dc-now-drawing" x-show="anticipating" x-cloak>{{ __('Drawing…') }}</div>
                    <div class="dc-now-meta">
                        @if ($drawing)
                            {{ __('Position :n of :total', ['n' => $revealed + 1, 'total' => $total]) }}
                        @elseif ($spotlight)
                            {{ __('Seed :n', ['n' => $spotlight->draw_number]) }}
                        @endif
                    </div>
                </div>
            @endif

            {{-- The only control on the screen, and it starts the telling
                 rather than the draw: the bracket was decided before this page
                 was opened. --}}
            {{-- One control, and it moves the telling forward: begin, then the
                 next position, then nothing once every position is placed. --}}
            @if ($ceremony && ! $complete)
                <div class="dc-start">
                    @if ($waiting)
                        <button type="button" class="dc-button dc-button-primary" wire:click="startCeremony">
                            {{ __('Begin draw') }}
                        </button>
                    @else
                        <button type="button" class="dc-button dc-button-primary" wire:click="nextDraw">
                            {{ __('Next draw') }}
                        </button>
                    @endif
                </div>
            @endif

            <div class="dc-kicker">{{ $complete ? __('Pool drawn') : __('Still to be drawn') }}</div>

            <div class="dc-pool-list">
                @forelse ($pool as $entry)
                    <div class="dc-pool-row" wire:key="pool-{{ $entry['noc'] }}">
                        <span class="dc-pool-noc">{{ $entry['noc'] }}</span>
                        <span class="dc-pool-name">{{ $entry['name'] }}</span>
                        <span class="dc-pool-count">{{ $entry['count'] }}</span>
                    </div>
                @empty
                    <div class="dc-pool-empty">{{ __('Every position has been drawn.') }}</div>
                @endforelse
            </div>
        </aside>

        <main class="dc-board">
            @if ($size > 0)
                <div class="dc-heads" style="grid-template-columns: {{ $columns }}">
                    <div class="dc-head-col">{{ __('Draw') }}</div>

                    @for ($round = 1; $round <= $rounds; $round++)
                        <div class="dc-head-col">
                            {{ BracketSeeding::phaseName((int) ($size / (2 ** ($round - 1)))) }}
                        </div>
                    @endfor

                    <div class="dc-head-col">{{ __('Champion') }}</div>
                </div>

                <div class="dc-grid" style="grid-template-columns: {{ $columns }}; grid-template-rows: repeat({{ $size }}, var(--dc-row));">
                    @foreach ($seats as $row => $seat)
                        <div @class([
                            'dc-seat-row',
                            'dc-seat-filled' => $seat['athlete'] !== null,
                            'dc-seat-new' => $seat['justFilled'],
                        ]) style="grid-row: {{ $row + 1 }}" wire:key="seat-{{ $seat['seed'] }}">
                            <span class="dc-seat-no">{{ $seat['seed'] }}</span>

                            @if ($seat['athlete'])
                                <span class="dc-seat-name">{{ $seat['athlete']->fullname }}</span>
                                <span class="dc-seat-noc">{{ \App\Support\Noc::normalise($seat['athlete']->noc_code) }}</span>
                            @else
                                <span class="dc-seat-name dc-seat-empty">—</span>
                            @endif
                        </div>
                    @endforeach

                    {{-- The tree itself is drawn with borders on cells that span
                         their match's rows: round r covers 2^r seats, which is
                         what grid row spans were made for. --}}
                    @for ($round = 1; $round <= $rounds; $round++)
                        @php $span = 2 ** $round; @endphp

                        @for ($match = 0; $match < $size / $span; $match++)
                            <div class="dc-match"
                                 style="grid-column: {{ $round + 1 }}; grid-row: {{ $match * $span + 1 }} / span {{ $span }}"
                                 wire:key="match-{{ $round }}-{{ $match }}"></div>
                        @endfor
                    @endfor

                    <div class="dc-champion" style="grid-column: {{ $rounds + 2 }}">
                        <div class="dc-champion-kicker">{{ __('Champion') }}</div>
                        <div class="dc-champion-slot">{{ __('To be decided') }}</div>
                    </div>
                </div>
            @else
                <div class="dc-panel dc-board-empty">
                    <p class="dc-sub m-0">{{ __('No draw numbers have been given out in this weight class yet.') }}</p>
                </div>
            @endif
        </main>
    </div>

    <footer class="dc-foot">
        <div class="dc-foot-group">
            <span class="dc-foot-label">
                {{ __(':n drawn', ['n' => $revealed]) }}
                ·
                {{ __(':n drawing', ['n' => $drawing ? 1 : 0]) }}
                ·
                {{ __(':n remaining', ['n' => $remainingCount]) }}
            </span>

            <div class="dc-track">
                <div class="dc-fill" style="width: {{ $total > 0 ? round($revealed / $total * 100) : 0 }}%"></div>
            </div>
        </div>

        <div class="dc-foot-group">
            <span class="dc-legend"><span class="dc-legend-dot"></span>{{ __('Champion path') }}</span>
            <span class="dc-legend">
                {{ $championship->location }}@if ($championship->starts_on) · {{ $championship->starts_on->format('j M Y') }} @endif
            </span>
        </div>
    </footer>
</div>
