{{-- Minimal header, mat selector, read-only board. Nothing else is on this
     page, and nothing else is in the response. --}}
<div
    class="dc sbv"
    x-data="{
        seen: Date.now(),
        status: 'live',
        handle: null,
        full: false,
        init() {
            {{-- Three states, not two: a board that has simply not been told
                 anything for six seconds is not the same as one whose page has
                 gone offline, and an official should be able to tell them
                 apart from across a room. --}}
            this.handle = setInterval(() => {
                this.status = ! navigator.onLine
                    ? 'offline'
                    : (Date.now() - this.seen > 6000 ? 'reconnecting' : 'live')
            }, 1000)
        },
        destroy() { clearInterval(this.handle) },
        toggleFullscreen() {
            if (document.fullscreenElement) { document.exitFullscreen(); this.full = false; return }
            document.documentElement.requestFullscreen?.().then(() => { this.full = true })
        },
    }"
    x-on:livewire:updated.window="seen = Date.now()"
>
    <header class="sbv-head">
        <div class="sbv-head-id">
            @php $brandLogo = config('branding.logo'); @endphp

            @if ($brandLogo && is_file(public_path($brandLogo)))
                <span class="dc-logo"><img src="{{ asset($brandLogo) }}" alt="{{ config('branding.short_name') }}"></span>
            @endif

            <div class="sbv-titles">
                <span class="sbv-champ">{{ $court?->championship?->title ?? config('branding.organisation') }}</span>
                <span class="sbv-label">
                    {{ __('Scoreboard') }}@if ($court) · {{ $court->label() }} @endif
                </span>
            </div>
        </div>

        <div class="sbv-tools">
            {{-- The mat selector. It changes which board is on screen and
                 nothing about the competition. --}}
            @if ($courts->count() > 1)
                <label for="sbv-mat" class="sbv-sr">{{ __('Select mat') }}</label>
                <select id="sbv-mat" class="sbv-select" wire:model.live="courtId" wire:change="selectMat($event.target.value)">
                    @foreach ($courts as $option)
                        {{-- The mat first: it is what the selector is for, and
                             a long championship name would push it out of a
                             narrow control. --}}
                        <option value="{{ $option->id }}" @selected($option->id === $courtId)>
                            {{ $option->label() }} — {{ $option->championship?->title }}
                        </option>
                    @endforeach
                </select>
            @endif

            {{-- Status is a word and a shape, never colour alone. --}}
            <span class="sbv-status" role="status" aria-live="polite"
                  :class="status === 'live' ? '-live' : (status === 'reconnecting' ? '-warn' : '-off')">
                <span class="dc-dot"></span>
                <span x-text="status === 'live' ? @js(__('Live')) : (status === 'reconnecting' ? @js(__('Reconnecting')) : @js(__('Offline')))"></span>
            </span>

            <span class="sbv-readonly">{{ __('Read only') }}</span>

            <button type="button" class="dc-button" x-on:click="toggleFullscreen()"
                    :aria-pressed="full ? 'true' : 'false'" aria-label="{{ __('Toggle fullscreen') }}">
                {{ __('Fullscreen') }}
            </button>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="dc-button" aria-label="{{ __('Sign out') }}">{{ __('Sign out') }}</button>
            </form>
        </div>
    </header>

    {{-- The mat that is on screen is announced, so a change is not a silent
         swap for somebody who cannot see the header. --}}
    <p class="sbv-sr" role="status" aria-live="polite">
        @if ($court)
            {{ __('Showing :mat', ['mat' => $court->label()]) }}
        @endif
    </p>

    @if ($courts->isEmpty())
        <div class="sbv-state">
            <h1 class="dc-heading">{{ __('No mats available') }}</h1>
            <p class="dc-sub">{{ __('Your account is not assigned to a scoreboard yet.') }}</p>
        </div>
    @elseif ($court === null)
        <div class="sbv-state">
            <h1 class="dc-heading">{{ __('Choose a mat') }}</h1>
            <p class="dc-sub">{{ __('Pick the mat you want to watch.') }}</p>

            <div class="sbv-picker">
                @foreach ($courts as $option)
                    <button type="button" class="dc-panel sbv-card" wire:click="selectMat({{ $option->id }})" wire:key="pick-{{ $option->id }}">
                        <span class="sbv-card-no">{{ $option->number }}</span>
                        <span class="sbv-card-name">{{ $option->name ?: __('Mat :n', ['n' => $option->number]) }}</span>
                        <span class="sbv-card-meta">{{ $option->championship?->title }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    @else
        {{-- The board is the same component the venue projector runs, keyed by
             mat so switching swaps the whole thing — and with it the poll that
             feeds it — rather than re-pointing a live one. --}}
        <div class="sbv-board">
            <livewire:competition.scoreboard :court="$court" :embedded="true" :key="'board-'.$court->id" />
        </div>
    @endif
</div>
