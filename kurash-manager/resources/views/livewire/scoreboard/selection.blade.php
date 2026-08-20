{{-- Where a scoreboard account lands.

     Deliberately not the admin shell: an account that may only read a board
     should never be shown the furniture of the application it cannot use. It
     borrows the broadcast vocabulary instead, which is what it is here for. --}}
@vite('resources/css/ceremony.css')

<div class="dc dc-board-page">
    <header class="dc-head">
        <div class="dc-head-id">
            @php
                $brandLogo = config('branding.logo');
            @endphp

            @if ($brandLogo && is_file(public_path($brandLogo)))
                <span class="dc-logo">
                    <img src="{{ asset($brandLogo) }}" alt="{{ config('branding.short_name') }}">
                </span>
            @endif

            <div>
                <div class="dc-org">{{ config('branding.organisation') }}</div>
                <div class="dc-champ">{{ __('Scoreboards') }}</div>
            </div>
        </div>

        <div class="dc-chips">
            @if ($readOnly)
                <span class="dc-chip">{{ __('Read only') }}</span>
            @endif

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="dc-button">{{ __('Sign out') }}</button>
            </form>
        </div>
    </header>

    <div class="dc-body dc-select">
        @forelse ($championships as $championship)
            <section class="dc-select-group" wire:key="champ-{{ $championship->id }}">
                <div class="dc-kicker">{{ $championship->title }}</div>

                <div class="dc-select-grid">
                    @forelse ($championship->courts as $court)
                        <a href="{{ route('scoreboard.show', $court) }}" class="dc-panel dc-select-card" wire:navigate>
                            <span class="dc-select-no">{{ $court->number }}</span>
                            <span class="dc-select-name">{{ $court->name ?: __('Mat :n', ['n' => $court->number]) }}</span>
                            <span class="dc-select-meta">
                                {{ trans_choice('{0}No bouts assigned|{1}:count bout assigned|[2,*]:count bouts assigned', $court->bouts_count, ['count' => $court->bouts_count]) }}
                            </span>
                        </a>
                    @empty
                        <p class="dc-sub">{{ __('No mats are active in this championship.') }}</p>
                    @endforelse
                </div>
            </section>
        @empty
            <div class="dc-panel dc-board-empty">
                <p class="dc-sub m-0">{{ __('There is no competition to watch yet.') }}</p>
            </div>
        @endforelse
    </div>
</div>
