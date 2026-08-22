@props([
    // Names already on the screen before the draw runs. Real ones only — the
    // sorting phase shows who is actually in the class, never invented people.
    'names' => [],
    // Seed pairings the algorithm will use, from BracketSeeding. Seeds are
    // real; the athletes behind them are not claimed until the server answers.
    'pairs' => [],
])

{{-- The draw ceremony overlay.

     Phases run on timers, but the one that says the draw is done runs on the
     server: `draw-completed` is dispatched by the component after the
     transaction commits, and nothing here says "complete" without it. If the
     request outlasts the choreography the overlay parks on a calm
     indeterminate state rather than claiming progress it has not been told
     about.

     Alpine owns the visual state and Livewire owns the truth; the two meet at
     three events and nowhere else. --}}
<div
    class="dc"
    wire:key="draw-ceremony"
    x-data="{
        phase: 'idle',
        mode: 'bracket',
        message: '',
        busy: false,
        timers: [],
        reduced: window.matchMedia('(prefers-reduced-motion: reduce)').matches,

        start(mode) {
            {{-- The guard that makes a double click harmless: the second one
                 never reaches the server, because the first is still running. --}}
            if (this.busy) return false

            this.mode = mode
            this.message = ''
            this.busy = true
            this.phase = 'preparing'

            const step = this.reduced ? 0 : 1
            this.queue(() => this.phase = 'sorting', 600 * step)
            this.queue(() => this.phase = mode === 'positions' ? 'waiting' : 'pairing', 2100 * step)
            this.queue(() => { if (this.phase === 'pairing') this.phase = 'waiting' }, 3000 * step)

            return true
        },

        succeed() {
            if (! this.busy) return

            {{-- The overlay covers the wait and then gets out of the way. An
                 admin drawing a bracket wants the bracket, not a ceremony:
                 the celebration belongs on the venue screen, in front of a
                 hall that came to watch it. --}}
            this.clear()
            this.finish()
        },

        fail(message) {
            this.clear()
            this.busy = false
            this.phase = 'error'
            this.message = message || '{{ __('The draw did not run. Nothing was changed.') }}'
            this.$nextTick(() => this.$refs.retry?.focus())
        },

        retry() {
            const mode = this.mode
            this.finish()
            this.$nextTick(() => {
                if (this.start(mode)) {
                    this.$wire.call(mode === 'positions' ? 'drawAtRandom' : 'generate')
                }
            })
        },

        finish() {
            this.clear()
            this.phase = 'idle'
            this.busy = false
        },

        queue(fn, ms) { this.timers.push(setTimeout(fn, ms)) },
        clear() { this.timers.forEach(clearTimeout); this.timers = [] },
    }"
    x-on:draw-started.window="start($event.detail.mode)"
    x-on:draw-completed.window="succeed()"
    x-on:draw-failed.window="fail($event.detail.message)"
    {{-- A refresh or a back button lands on a page with no overlay at all: the
         state lives here, not on the server, so there is nothing to restore
         and nothing to get stuck. --}}
    x-on:beforeunload.window="clear()"
>
    <div
        x-show="phase !== 'idle'"
        x-cloak
        class="dc-overlay"
        role="status"
        aria-live="polite"
        :aria-busy="busy ? 'true' : 'false'"
    >
        {{-- Phase 1 — preparing --}}
        <template x-if="phase === 'preparing'">
            <div class="flex flex-col items-center gap-6">
                <svg class="dc-emblem" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <path d="M4 10h12v14h12M4 38h12V24M32 24h12" stroke-linecap="square" />
                    <rect x="30" y="17" width="14" height="14" />
                </svg>

                <div>
                    <h2 class="dc-heading">{{ __('Preparing draw') }}</h2>
                    <p class="dc-sub mt-2">{{ __('Organizing competitors and divisions') }}</p>
                </div>

                <div class="dc-progress"><span></span></div>
            </div>
        </template>

        {{-- Phase 2 — sorting. Real names, in the order the screen already
             holds them; the tokens do not claim to be seeding anybody. --}}
        <template x-if="phase === 'sorting'">
            <div class="flex flex-col items-center gap-6">
                <h2 class="dc-heading">{{ __('Sorting competitors') }}</h2>
                <p class="dc-sub">{{ __('Reading the entry list for this weight class') }}</p>

                <div class="dc-tokens">
                    @foreach (array_slice($names, 0, 16) as $i => $name)
                        <span class="dc-token" style="animation-delay: {{ min($i * 45, 700) }}ms">{{ $name }}</span>
                    @endforeach
                </div>

                <div class="dc-progress"><span></span></div>
            </div>
        </template>

        {{-- Phase 3 — pairing. The seat numbers are the real seeding order; who
             lands in them is the server's answer, still on its way. --}}
        <template x-if="phase === 'pairing'">
            <div class="flex flex-col items-center gap-6">
                <h2 class="dc-heading">{{ __('Forming the bracket') }}</h2>
                <p class="dc-sub">{{ __('Pairing seeds round by round') }}</p>

                <div class="dc-pairs">
                    @foreach (array_slice($pairs, 0, 8) as $i => [$top, $bottom])
                        {{-- No gold here. Gold means the final, the champion,
                             the slot just filled and the complete chip; spent
                             on a first-round pair it would mean nothing. --}}
                        <div class="dc-seat" style="animation-delay: {{ $i * 90 }}ms">
                            <span class="dc-seat-seed">{{ $top }}</span>
                            <span class="text-[var(--dc-muted)]">{{ __('v') }}</span>
                            <span class="dc-seat-seed">{{ $bottom }}</span>
                        </div>
                    @endforeach
                </div>

                <div class="dc-progress"><span></span></div>
            </div>
        </template>

        {{-- The request has outlasted the choreography. Say so plainly rather
             than looping the sequence, which would imply work that is not
             happening. --}}
        <template x-if="phase === 'waiting'">
            <div class="flex flex-col items-center gap-6">
                <h2 class="dc-heading">{{ __('Drawing') }}</h2>
                <p class="dc-sub">{{ __('Waiting for the draw to be recorded') }}</p>
                <div class="dc-progress"><span></span></div>
            </div>
        </template>

        {{-- Failure. Never dressed up as anything else, and explicit that the
             entry list was not touched. --}}
        <template x-if="phase === 'error'">
            <div class="dc-panel dc-error flex flex-col items-start gap-4 text-left">
                <span class="dc-kicker" style="color: #e0464f">{{ __('Draw could not be completed') }}</span>

                <p class="m-0 text-[15px] font-semibold">
                    {{ __('Your competitors were not changed. Review the highlighted issue and try again.') }}
                </p>

                <p class="dc-sub m-0" x-text="message"></p>

                <div class="flex gap-2.5">
                    <button type="button" class="dc-button dc-button-primary" x-ref="retry" x-on:click="retry()">
                        {{ __('Try again') }}
                    </button>
                    <button type="button" class="dc-button" x-on:click="finish()">{{ __('Close') }}</button>
                </div>
            </div>
        </template>
    </div>
</div>
