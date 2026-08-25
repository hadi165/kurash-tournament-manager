@php
    $category = $weightCategory;
    $championship = $category->ageCategory?->championship;
@endphp

<x-page
    :title="$category->label.' '.__('kg').' '.match ($category->gender) { 'M' => __('Men'), 'F' => __('Women'), default => __('Open') }"
    :subtitle="$category->ageCategory?->name.' · '.$championship?->title"
    :breadcrumbs="[
        ['label' => __('Draws to present'), 'href' => route('operator.draws.index')],
        ['label' => $category->label.' '.__('kg')],
    ]"
>
    <x-slot:aside>
        <x-ui.chip :href="route('operator.draws.index')" wire:navigate>{{ __('Back to draws') }}</x-ui.chip>
    </x-slot:aside>

    {{-- The admin republished while this page was open. The table on screen is
         still the one it was opened with, and it stays that way until somebody
         reloads: a presentation must never mix two versions of a draw. --}}
    @if ($stale)
        <div class="flex flex-wrap items-center gap-3 rounded-md bg-amber-soft px-[18px] py-3.5">
            <x-ui.tag variant="amber">{{ __('Draw updated') }}</x-ui.tag>
            <span class="text-[13.5px] text-amber-deep">
                {{ __('A newer version of this draw has been published. Reload before presenting it.') }}
            </span>
            <x-ui.chip x-on:click="window.location.reload()">{{ __('Reload') }}</x-ui.chip>
        </div>
    @endif

    {{-- The figures each format actually has.

         A round robin has no bracket to be sized and nobody sits out of one,
         so quoting a bracket size, a bye count and a first-round total over it
         would be four numbers describing a competition that is not being
         held — three of them zero and the fourth misleading. --}}
    @if ($drawnFormat === \App\Support\TournamentFormat::RoundRobin)
        <x-ui.stats grid :items="[
            ['value' => __('Round Robin'), 'label' => __('Format'), 'hue' => 'brand'],
            ['value' => $category->draw_athlete_count ?? 0, 'label' => __('Athletes drawn'), 'hue' => 'info'],
            ['value' => $rounds->count(), 'label' => __('Rounds')],
            ['value' => $bouts->count(), 'label' => __('Contests')],
            ['value' => $bouts->whereNotNull('fight_number')->count(), 'label' => __('Scheduled fights')],
        ]" />
    @elseif ($drawnFormat === \App\Support\TournamentFormat::Placement)
        <x-ui.stats grid :items="[
            ['value' => __('Placement'), 'label' => __('Format'), 'hue' => 'brand'],
            ['value' => $category->draw_athlete_count ?? 0, 'label' => __('Athletes drawn'), 'hue' => 'info'],
        ]" />
    @else
        <x-ui.stats grid :items="[
            ['value' => $category->draw_athlete_count ?? 0, 'label' => __('Athletes drawn'), 'hue' => 'info'],
            ['value' => $category->draw_bucket_size ?? 0, 'label' => __('Bracket size'), 'hue' => 'brand'],
            ['value' => $category->draw_bye_count ?? 0, 'label' => __('Byes'), 'hue' => 'amber'],
            ['value' => $bouts->count(), 'label' => __('Bouts')],
            ['value' => $firstRoundBouts, 'label' => __('First-round bouts')],
        ]" />
    @endif

    {{-- The reveal is presentation state and lives only in the browser: no
         method on this component writes, so nothing here can touch the draw.
         The table is rendered in full and revealed by class, which is what
         keeps it readable when the animation is switched off or skipped. --}}
    <div
        x-data="{
            revealed: {{ $reveal->count() }},
            total: {{ $reveal->count() }},
            running: false,
            handle: null,
            reduced: window.matchMedia('(prefers-reduced-motion: reduce)').matches,

            start() {
                if (this.running) return
                this.running = true
                this.handle = setInterval(() => {
                    if (this.revealed >= this.total) { this.stop(); return }
                    this.revealed++
                }, this.reduced ? 120 : 420)
            },
            stop() { this.running = false; clearInterval(this.handle); this.handle = null },
            replay() { this.stop(); this.revealed = 0; this.start() },
            finish() { this.stop(); this.revealed = this.total },
        }"
        x-init="$nextTick(() => { revealed = total })"
        class="flex flex-col gap-4"
    >
        <x-ui.card>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex flex-wrap items-center gap-2.5">
                    <flux:button variant="primary" x-on:click="replay()" x-show="! running">
                        {{ __('Replay presentation') }}
                    </flux:button>

                    <flux:button variant="primary" x-on:click="stop()" x-show="running" x-cloak>
                        {{ __('Pause') }}
                    </flux:button>

                    <flux:button variant="ghost" x-on:click="start()" x-show="! running && revealed < total" x-cloak>
                        {{ __('Resume') }}
                    </flux:button>

                    <flux:button variant="ghost" x-on:click="finish()">{{ __('Show whole table') }}</flux:button>
                </div>

                <div class="text-[13.5px] font-semibold text-muted" role="status" aria-live="polite">
                    <span x-text="`{{ __('Bout') }} ${Math.min(revealed, total)} {{ __('of') }} ${total}`"></span>
                </div>
            </div>
        </x-ui.card>

        @foreach ($rounds as $round => $roundBouts)
            {{-- A round robin's rounds are groupings of the schedule, not
                 stages of a knockout: calling round two "Semi Final" would
                 tell the hall that losing it puts somebody out, which it does
                 not. --}}
            <x-ui.card
                :title="$drawnFormat === \App\Support\TournamentFormat::RoundRobin
                    ? __('Round :n', ['n' => $round])
                    : $phaseName($round)"
                wire:key="round-{{ $round }}"
            >
                <div class="grid gap-2.5 [grid-template-columns:repeat(auto-fit,minmax(280px,1fr))]">
                    @foreach ($roundBouts as $bout)
                        @php $index = $reveal->search(fn ($b) => $b->id === $bout->id); @endphp

                        <div
                            class="rounded-md border border-line bg-ground px-4 py-3.5 transition-opacity motion-safe:duration-300"
                            :class="revealed > {{ $index }} ? 'opacity-100' : 'opacity-25'"
                            wire:key="bout-{{ $bout->id }}"
                        >
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-[12.5px] font-semibold text-muted">
                                    {{ $bout->fight_number ? __('No.:n', ['n' => $bout->fight_number]) : __('Unscheduled') }}
                                </span>

                                @if ($bout->is_bye)
                                    <x-ui.tag>{{ __('Bye') }}</x-ui.tag>
                                @endif
                            </div>

                            @foreach ([['a', $bout->athleteA, $bout->seed_a], ['b', $bout->athleteB, $bout->seed_b]] as [$side, $athlete, $seed])
                                <div @class([
                                    'mt-2 flex items-center gap-2.5 text-[14.5px]',
                                    'font-bold' => $bout->winner_athlete_id && $athlete && $bout->winner_athlete_id === $athlete->id,
                                ])>
                                    <span @class(['size-2 flex-none rounded-full', $side === 'a' ? 'bg-info' : 'bg-brand'])></span>
                                    <span class="w-6 text-[12.5px] font-semibold tabular-nums text-muted">{{ $seed ?? '—' }}</span>
                                    <span class="min-w-0 flex-1 truncate">
                                        {{ $athlete?->fullname ?? ($bout->is_bye ? __('BYE') : '—') }}
                                    </span>
                                    @if ($athlete)
                                        <span class="text-[12.5px] font-semibold text-muted">
                                            {{ \App\Support\Noc::normalise($athlete->noc_code) }}
                                        </span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </x-ui.card>
        @endforeach

        {{-- The table the fixtures add up to. Shown once every pairing has
             been revealed, because a standings table beside a draw still being
             presented gives the room the answer before the reveal reaches
             it. --}}
        @if ($standings)
            <x-ui.card :title="__('Standings')" x-show="revealed >= total" x-cloak>
                <div class="overflow-x-auto">
                    <table class="w-full text-[13.5px]">
                        <thead>
                            <tr class="border-b border-line text-left text-[12px] uppercase tracking-wider text-ink/55">
                                <th class="py-2.5 pe-4">{{ __('Rank') }}</th>
                                <th class="py-2.5 pe-4">{{ __('Athlete') }}</th>
                                <th class="py-2.5 pe-4">{{ __('NOC') }}</th>
                                <th class="py-2.5 pe-4 text-right">{{ __('Played') }}</th>
                                <th class="py-2.5 pe-4 text-right">{{ __('Won') }}</th>
                                <th class="py-2.5 pe-4 text-right">{{ __('Lost') }}</th>
                                <th class="py-2.5 text-right">{{ __('Points') }}</th>
                            </tr>
                        </thead>

                        <tbody>
                            @foreach ($standings['rows'] as $row)
                                <tr class="border-b border-line/60 last:border-0" wire:key="stand-{{ $row['athlete']->id }}">
                                    <td class="py-2.5 pe-4 font-bold tabular-nums">{{ $row['rank'] }}</td>
                                    <td class="py-2.5 pe-4"><x-athlete :athlete="$row['athlete']" /></td>
                                    <td class="py-2.5 pe-4 font-bold text-muted">{{ $row['noc'] }}</td>
                                    <td class="py-2.5 pe-4 text-right tabular-nums">{{ $row['played'] }}</td>
                                    <td class="py-2.5 pe-4 text-right font-bold tabular-nums">{{ $row['wins'] }}</td>
                                    <td class="py-2.5 pe-4 text-right tabular-nums">{{ $row['losses'] }}</td>
                                    <td class="py-2.5 text-right font-bold tabular-nums">{{ $row['points'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        @endif
    </div>
</x-page>
