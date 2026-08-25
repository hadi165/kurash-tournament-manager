{{-- The round robin, as a hall watches it drawn.

     Every fixture in this class was settled before the board was opened; what
     is being told is which athlete holds which draw number, and the fixtures
     appear as the names that make them land. A pairing is held back until both
     of its athletes have been drawn — the same rule the bracket board follows
     for a seat, and what stops the fixture list giving away positions the hall
     has not been told yet.

     Everything here is read off the contests the generator committed. Nothing
     is recomputed from the entry list, and there is no tree: a round robin has
     no seats to fill in order, no byes, no phase names and nobody to advance,
     so the board shows none of them. --}}
<div class="dc-rr">
    @forelse ($pairings as $round => $fixtures)
        <section class="dc-rr-round" wire:key="rr-round-{{ $round }}">
            <h2 class="dc-rr-head">{{ __('Round :n', ['n' => $round]) }}</h2>

            @foreach ($fixtures as $fixture)
                <article @class([
                    'dc-rr-fixture',
                    'dc-rr-fixture--waiting' => ! $fixture['revealed'],
                    'dc-rr-fixture--done' => $fixture['decided'],
                ]) wire:key="rr-fixture-{{ $fixture['id'] }}">
                    <header class="dc-rr-no">
                        @if ($fixture['fight'])
                            {{ __('No. :n', ['n' => $fixture['fight']]) }}
                        @else
                            {{ __('Not scheduled') }}
                        @endif
                    </header>

                    @foreach ([$fixture['a'], $fixture['b']] as $side)
                        {{-- The winning side is found by id, never by name:
                             two athletes sharing a full name would both be
                             gilded by a name comparison. --}}
                        <div @class([
                            'dc-rr-side',
                            'dc-rr-side--won' => $fixture['decided']
                                && $side !== null
                                && $fixture['winner_id'] === $side['id'],
                        ])>
                            @if ($fixture['revealed'] && $side !== null)
                                {{-- "Draw No." and never "seed": a round robin
                                     seeds nobody, and a number captioned as a
                                     seed tells the hall the competition works a
                                     way it does not. --}}
                                <span class="dc-rr-draw" title="{{ __('Draw No.') }}">{{ $side['draw'] }}</span>

                                @if ($side['iso'])
                                    <img class="dc-rr-flag"
                                         src="{{ asset('flags/'.$side['iso'].'.svg') }}"
                                         alt="{{ $side['country'] ?? $side['noc'] }}"
                                         title="{{ $side['country'] ?? $side['noc'] }}">
                                @else
                                    <span class="dc-rr-flag dc-pool-flag--none" aria-hidden="true"></span>
                                @endif

                                <span class="dc-rr-name">{{ $side['name'] }}</span>
                                <span class="dc-rr-noc">{{ $side['noc'] }}</span>
                            @else
                                <span class="dc-rr-draw dc-rr-draw--empty">—</span>
                                <span class="dc-rr-name dc-seat-empty">{{ __('To be drawn') }}</span>
                            @endif
                        </div>
                    @endforeach
                </article>
            @endforeach
        </section>
    @empty
        {{-- No contests yet: this telling is a positions pull — draw numbers
             handed out before the draw itself is generated. The bracket board
             reveals these onto its seat grid; here they land on the list of
             draw positions, which is the round robin's version of the same
             moment. --}}
        @if (! empty($positions))
            <section class="dc-rr-round dc-rr-positions">
                <h2 class="dc-rr-head">{{ __('Draw positions') }}</h2>

                @foreach ($positions as $position)
                    <article @class([
                        'dc-rr-fixture',
                        'dc-rr-fixture--waiting' => $position['athlete'] === null,
                    ]) wire:key="rr-pos-{{ $position['number'] }}">
                        <div class="dc-rr-side">
                            <span class="dc-rr-draw" title="{{ __('Draw No.') }}">{{ $position['number'] }}</span>

                            @if ($position['athlete'])
                                @if ($position['iso'])
                                    <img class="dc-rr-flag"
                                         src="{{ asset('flags/'.$position['iso'].'.svg') }}"
                                         alt="{{ \App\Support\Noc::normalise($position['athlete']->noc_code) }}">
                                @else
                                    <span class="dc-rr-flag dc-pool-flag--none" aria-hidden="true"></span>
                                @endif

                                <span class="dc-rr-name">{{ $position['athlete']->fullname }}</span>
                                <span class="dc-rr-noc">{{ \App\Support\Noc::normalise($position['athlete']->noc_code) }}</span>
                            @else
                                <span class="dc-rr-name dc-seat-empty">{{ __('To be drawn') }}</span>
                            @endif
                        </div>
                    </article>
                @endforeach
            </section>
        @else
            <div class="dc-panel dc-board-empty">
                <p class="dc-sub m-0">{{ __('No contests have been generated for this weight class yet.') }}</p>
            </div>
        @endif
    @endforelse

    {{-- The table, once the telling is over. Shown beside a draw still being
         revealed it would answer the hall before the reveal reached it. --}}
    @if ($standings)
        <section class="dc-rr-round dc-rr-standings">
            <h2 class="dc-rr-head">{{ __('Standings') }}</h2>

            <table class="dc-rr-table">
                <thead>
                    <tr>
                        <th>{{ __('#') }}</th>
                        <th>{{ __('Athlete') }}</th>
                        <th>{{ __('NOC') }}</th>
                        <th class="num">{{ __('P') }}</th>
                        <th class="num">{{ __('W') }}</th>
                        <th class="num">{{ __('L') }}</th>
                        <th class="num">{{ __('Pts') }}</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($standings['rows'] as $row)
                        <tr wire:key="rr-stand-{{ $row['athlete']->id }}">
                            <td>{{ $row['rank'] }}</td>
                            <td>{{ $row['athlete']->fullname }}</td>
                            <td>{{ $row['noc'] }}</td>
                            <td class="num">{{ $row['played'] }}</td>
                            <td class="num">{{ $row['wins'] }}</td>
                            <td class="num">{{ $row['losses'] }}</td>
                            <td class="num">{{ $row['points'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif
</div>
