@php
    $championship = $weightCategory->ageCategory->championship;

    /*
     | The competition this class is run in, and the entry list scoped to it.
     |
     | Taken from the division rather than from the weight class: the entries
     | screen narrows by age_categories.gender — see ScopesToCompetition — so
     | asking the weight class instead would build a link that silently fell
     | back to showing every competition.
     |
     | `competition` is a query parameter and not a path segment because the
     | entry list belongs to the whole championship and a competition is a way
     | of reading it. Dropping it shows everything, which is why an unconfigured
     | gender degrades to the full list rather than to an error.
     */
    $competition = $weightCategory->ageCategory?->gender;

    $entriesUrl = route('entries.index', array_filter([
        'championship' => $championship,
        'competition' => $competition,
    ]));

    $drawn = trans_choice(
        '{0}Nobody drawn yet|{1}:count athlete drawn|[2,*]:count athletes drawn',
        $drawnCount, ['count' => $drawnCount]
    );

    $subtitle = $projectedSize
        ? $drawn.' · '.__('bracket of :size', ['size' => $projectedSize])
        : $drawn;

    // Handed to the ceremony overlay: the athletes this class actually holds,
    // and the seeding the generator will use. Both are real — the overlay
    // invents nobody and predicts nothing.
    $ceremonyNames = $athletes->pluck('fullname')->all();
    $ceremonyPairs = $projectedSize ? \App\Support\BracketSeeding::firstRoundPairs($projectedSize) : [];
@endphp

<x-page
    :kicker="$weightCategory->ageCategory->name"
    :title="$weightCategory->label.' '.__('kg')"
    :subtitle="$subtitle"
    :breadcrumbs="[
        ['label' => __('Championships'), 'href' => route('championships.index')],
        ['label' => $championship->title, 'href' => route('championships.show', $championship)],
        ['label' => __('Entries and Draw'), 'href' => $entriesUrl],
        ['label' => $weightCategory->label.' '.__('kg')],
    ]"
>

    {{-- The sheet the draw numbers get written onto, and the drawn bracket
         itself. Both are named the way the federation files them. --}}
    <x-slot:aside>
        {{-- The way back to the class list this screen is opened from, still
             narrowed to the competition it belongs to: an official working the
             women's classes should land back among the women's classes, not at
             the top of the championship.

             The breadcrumb above says the same thing, but a crumb is a small
             grey label and this is a screen somebody works in for a whole
             division — they should not have to aim at it. --}}
        <x-ui.chip :href="$entriesUrl" wire:navigate>
            {{ __('All :competition weight classes', [
                'competition' => \App\Support\Gender::label($competition),
            ]) }}
        </x-ui.chip>

        @if ($weightCategory->isDrawPublished())
            <x-ui.tag variant="brand">{{ __('Published to operators') }}</x-ui.tag>
        @elseif ($bouts->isNotEmpty())
            <x-ui.tag>{{ __('Not published') }}</x-ui.tag>
        @endif

        @if ($weightCategory->isDrawLocked())
            <x-ui.tag variant="amber">{{ __('Locked') }}</x-ui.tag>
        @endif
    </x-slot:aside>

    <x-slot:actions>
        <span class="kicker me-1 text-ink/55">{{ __('Weigh-in list') }}</span>
        <a href="{{ route('exports.weigh-in', ['weightCategory' => $weightCategory, 'format' => 'pdf']) }}"
           class="px-2.5 py-1 text-xs font-bold text-brand-700 no-underline hover:bg-brand-500/10 dark:text-brand-400">{{ __('PDF') }}</a>
        <a href="{{ route('exports.weigh-in', ['weightCategory' => $weightCategory, 'format' => 'csv']) }}"
           class="px-2.5 py-1 text-xs font-bold text-brand-700 no-underline hover:bg-brand-500/10 dark:text-brand-400">{{ __('Excel') }}</a>

        @if ($bouts->isNotEmpty())
            <span class="mx-1.5 h-5 w-0.5 bg-line"></span>
            <span class="kicker me-1 text-ink/55">{{ __('Draw result') }}</span>
            <a href="{{ route('exports.draw', ['weightCategory' => $weightCategory, 'format' => 'pdf']) }}"
               class="px-2.5 py-1 text-xs font-bold text-brand-700 no-underline hover:bg-brand-500/10 dark:text-brand-400">{{ __('PDF') }}</a>
            <a href="{{ route('exports.draw', ['weightCategory' => $weightCategory, 'format' => 'csv']) }}"
               class="px-2.5 py-1 text-xs font-bold text-brand-700 no-underline hover:bg-brand-500/10 dark:text-brand-400">{{ __('Excel') }}</a>

            <span class="mx-1.5 h-5 w-0.5 bg-line"></span>
            <span class="kicker me-1 text-ink/55">{{ __('Draw numbers') }}</span>
            <a href="{{ route('exports.draw-numbers', ['weightCategory' => $weightCategory, 'format' => 'pdf']) }}"
               class="px-2.5 py-1 text-xs font-bold text-brand-700 no-underline hover:bg-brand-500/10 dark:text-brand-400">{{ __('PDF') }}</a>
            <a href="{{ route('exports.draw-numbers', ['weightCategory' => $weightCategory, 'format' => 'csv']) }}"
               class="px-2.5 py-1 text-xs font-bold text-brand-700 no-underline hover:bg-brand-500/10 dark:text-brand-400">{{ __('Excel') }}</a>
        @endif

        <span class="mx-1.5 h-5 w-0.5 bg-line"></span>
    </x-slot:actions>

    <x-competition.flash />

    @if ($podium['decided'])
        <x-ui.card flush :title="__('Podium')">
            <div class="rule-2"></div>

            <div class="grid gap-px bg-n-300 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))]">
                <div class="bg-surface px-6 py-4">
                    <div class="kicker text-brand-600 dark:text-brand-400">{{ __('Gold') }}</div>
                    <div class="mt-1.5 font-bold"><x-athlete :athlete="$podium['gold']" /></div>
                </div>

                <div class="bg-surface px-6 py-4">
                    <div class="kicker text-ink/55">{{ __('Silver') }}</div>
                    <div class="mt-1.5 font-bold"><x-athlete :athlete="$podium['silver']" /></div>
                </div>

                @foreach ($podium['bronze'] as $bronze)
                    <div class="bg-surface px-6 py-4" wire:key="bronze-{{ $bronze->id }}">
                        <div class="kicker text-ink/55">{{ __('Bronze') }}</div>
                        <div class="mt-1.5 font-bold"><x-athlete :athlete="$bronze" /></div>
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    @endif

    @can('manage-competition')
        <x-ui.card :title="__('Draw numbers')">
            <x-slot:head>
                <flux:button
                    size="sm"
                    wire:click="drawAtRandom"
                    wire:confirm="{{ __('Replace all draw numbers in this class with a random draw?') }}"
                    wire:loading.attr="disabled"
                    wire:target="drawAtRandom,generate"
                    x-on:click="$dispatch('draw-started', { mode: 'positions' })"
                >{{ __('Draw at random') }}</flux:button>
                <flux:button size="sm" variant="primary" wire:click="saveDraws">{{ __('Save draw numbers') }}</flux:button>
            </x-slot:head>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @forelse ($athletes as $athlete)
                    {{-- The athlete is the field's label. Sitting them beside the
                         input squeezed the names down to "Ak…", "asg…", "DDD…". --}}
                    <div class="flex flex-col gap-1.5" wire:key="draw-{{ $athlete->id }}">
                        <label for="draw-{{ $athlete->id }}" class="flex items-center gap-2 text-[13px] font-bold">
                            <span class="min-w-0 break-words">{{ $athlete->fullname }}</span>

                            @if ($athlete->weighin_status === 'fail')
                                <x-ui.tag variant="danger" class="ms-auto">{{ __('failed weigh-in') }}</x-ui.tag>
                            @else
                                <x-ui.tag variant="outline" class="ms-auto">{{ $athlete->noc_code }}</x-ui.tag>
                            @endif
                        </label>

                        <flux:input id="draw-{{ $athlete->id }}" wire:model="draws.{{ $athlete->id }}" type="number" min="1" />
                    </div>
                @empty
                    <p class="text-sm text-ink/55">{{ __('No athletes registered in this weight class.') }}</p>
                @endforelse
            </div>

            <div class="rule-2 my-5"></div>

            {{-- What drawing now would produce, before anybody commits to it.
                 The figures come from the same query the generator counts, so
                 the summary and the draw cannot disagree. --}}
            <div class="mb-4 flex flex-wrap items-center gap-x-6 gap-y-1.5 rounded-md border border-line bg-ground px-[18px] py-3.5 text-[13px]">
                <span><span class="text-muted">{{ __('Registered athletes') }}</span> <b class="tabular-nums">{{ $drawSummary['athletes'] }}</b></span>

                @if ($resolvedFormat === \App\Support\TournamentFormat::RoundRobin)
                    <span><span class="text-muted">{{ __('Contests') }}</span> <b class="tabular-nums">{{ $drawSummary['contests'] }}</b></span>
                    <span><span class="text-muted">{{ __('Rounds') }}</span> <b class="tabular-nums">{{ $drawSummary['rounds'] }}</b></span>
                    <span><span class="text-muted">{{ __('Rest positions') }}</span> <b class="tabular-nums">{{ $drawSummary['athletes'] % 2 === 1 ? __('one per round') : '—' }}</b></span>
                @else
                    <span><span class="text-muted">{{ __('Bracket size') }}</span> <b class="tabular-nums">{{ $drawSummary['size'] ?: '—' }}</b></span>
                    <span><span class="text-muted">{{ __('Byes') }}</span> <b class="tabular-nums">{{ $drawSummary['byes'] }}</b></span>
                    <span><span class="text-muted">{{ __('First-round bouts') }}</span> <b class="tabular-nums">{{ $drawSummary['firstRound'] }}</b></span>
                @endif
            </div>

            {{-- The format.

                 Offered only where the rule leaves a choice — a field of two
                 to five, which the IKA runs as a round robin and which this
                 system will run as a bracket if a federation has local reasons
                 to. Six or more has one lawful shape and no selector at all,
                 so there is nothing on the screen inviting somebody to pick
                 the wrong one. --}}
            @if (count($formatChoices) > 1)
                <div class="mb-4 rounded-md border border-line bg-ground px-[18px] py-3.5">
                    <div class="kicker mb-2 text-ink/55">{{ __('Tournament format') }}</div>

                    <flux:select wire:model.live="format" size="sm" class="max-w-md">
                        @foreach ($formatChoices as $choice)
                            <flux:select.option value="{{ $choice->value }}">
                                {{ $choice->followsIkaRule($drawSummary['athletes'])
                                    ? __(':format — IKA default', ['format' => $choice->label()])
                                    : __(':format — local rules override', ['format' => $choice->label()]) }}
                            </flux:select.option>
                        @endforeach
                    </flux:select>

                    {{-- The non-compliance warning, the reason, and the
                         confirmation — all three, because a departure from the
                         rule that nobody signed is one nobody can answer for
                         when the federation asks about it afterwards. --}}
                    @if ($confirmingOverride)
                        <div class="mt-3.5 rounded-md bg-amber-soft px-[18px] py-3.5">
                            <div class="flex flex-wrap items-center gap-3">
                                <x-ui.tag variant="amber">{{ __('Not IKA compliant') }}</x-ui.tag>
                                <span class="text-[13.5px] text-amber-deep">
                                    {{ __('The IKA rule runs :count athletes as a round robin. A knockout here is a local decision.', [
                                        'count' => $drawSummary['athletes'],
                                    ]) }}
                                </span>
                            </div>

                            @if ($mayOverride)
                                <div class="mt-3">
                                    <flux:input
                                        wire:model="overrideReason"
                                        size="sm"
                                        :label="__('Reason for the override')"
                                        :placeholder="__('Recorded against your name, with the time.')"
                                    />
                                </div>
                            @else
                                <p class="mt-2 text-[13px] text-amber-deep">
                                    {{ __('Only an administrator may authorise this. Choose the round robin, or ask one to draw it.') }}
                                </p>
                            @endif
                        </div>
                    @endif
                </div>
            @endif

            {{-- A class of one has nobody to fight. It is settled by an
                 administrator placing the athlete, never by the software
                 noticing they are on their own. --}}
            @if ($drawnFormat === \App\Support\TournamentFormat::Placement)
                <div class="mb-4 rounded-md border border-line bg-ground px-[18px] py-3.5">
                    @if ($weightCategory->draw_placement_athlete_id)
                        <div class="flex flex-wrap items-center gap-3">
                            <x-ui.tag variant="brand">{{ __('Placed first') }}</x-ui.tag>
                            <span class="text-[13.5px]">
                                <x-athlete :athlete="$weightCategory->placedAthlete" />
                            </span>
                            <span class="text-[12.5px] text-muted">
                                {{ __('Recorded :when', ['when' => $weightCategory->draw_placement_at?->format('j M Y H:i')]) }}
                            </span>
                        </div>
                    @else
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="text-[13.5px]">
                                {{ __('One athlete is entered. Being unopposed is not a result — place them to settle the class.') }}
                            </span>
                            <flux:button size="sm" variant="primary" wire:click="placeSoleAthlete">
                                {{ __('Place first') }}
                            </flux:button>
                        </div>
                    @endif
                </div>
            @endif

            @if ($weightCategory->drawIsStale())
                {{-- Informational, and only here: the published table an
                     operator is presenting does not change because somebody
                     registered late. --}}
                <div class="mb-4 flex flex-wrap items-center gap-3 rounded-md bg-amber-soft px-[18px] py-3.5">
                    <x-ui.tag variant="amber">{{ __('Entry list changed') }}</x-ui.tag>
                    <span class="text-[13.5px] text-amber-deep">
                        {{ __('This draw was made for :was athletes and the class now holds :now. Redraw it if that is wrong.', [
                            'was' => $weightCategory->draw_athlete_count,
                            'now' => $drawSummary['athletes'],
                        ]) }}
                    </span>
                </div>
            @endif

            {{-- The button is named after the live selection, not the stored
                 resolution: the moment an administrator picks the override in
                 the select, the button that signs for it must say so. --}}
            @php
                $chosenFormat = \App\Support\TournamentFormat::tryFromValue($format) ?? $resolvedFormat;
            @endphp

            <div class="flex flex-wrap items-center gap-3">
                <flux:button
                    variant="primary"
                    wire:click="generate"
                    wire:loading.attr="disabled"
                    wire:target="drawAtRandom,generate"
                    x-on:click="$dispatch('draw-started', { mode: 'bracket' })"
                    :disabled="$drawnCount < 1 || ($confirmingOverride && ! $mayOverride)"
                >{{ $weightCategory->hasDraw()
                    ? __('Redraw :format', ['format' => $chosenFormat?->label() ?? __('the draw')])
                    : __('Draw :format', ['format' => $chosenFormat?->label() ?? __('the draw')]) }}</flux:button>

                @if ($confirmingRegenerate)
                    <flux:button
                        variant="danger"
                        wire:click="generate(true)"
                        wire:loading.attr="disabled"
                        wire:target="drawAtRandom,generate"
                        x-on:click="$dispatch('draw-started', { mode: 'bracket' })"
                    >{{ __('Erase results and redraw') }}</flux:button>
                @endif

                @if ($confirmingReplacePublished)
                    <flux:button
                        variant="danger"
                        wire:click="generate(false, true)"
                        wire:loading.attr="disabled"
                        wire:target="drawAtRandom,generate"
                        x-on:click="$dispatch('draw-started', { mode: 'bracket' })"
                    >{{ __('Replace the published draw') }}</flux:button>
                @endif

                @can('draw.publish')
                    @if ($bouts->isNotEmpty())
                        @if ($weightCategory->isDrawPublished())
                            <flux:button variant="ghost" wire:click="withdrawDraw"
                                         wire:confirm="{{ __('Withdraw this draw? Operators will no longer be able to present it.') }}">
                                {{ __('Withdraw from operators') }}
                            </flux:button>
                        @else
                            <flux:button wire:click="publishDraw">{{ __('Publish to operators') }}</flux:button>
                        @endif
                    @endif

                    {{-- Shown whenever the class is locked, bracket or no
                         bracket. A lock is the one control that must never be
                         out of reach: the screen that refuses to draw has to
                         be the screen that lets you allow it. --}}
                    @if ($bouts->isNotEmpty() || $weightCategory->isDrawLocked())
                        <flux:button variant="ghost" wire:click="toggleDrawLock">
                            {{ $weightCategory->isDrawLocked() ? __('Unlock draw') : __('Lock draw') }}
                        </flux:button>
                    @endif
                @endcan

                {{-- The way out of "cannot remove: a bracket has already been
                     drawn". Delete, correct the entry list, draw again — the
                     draw numbers survive, so nobody loses the draw they
                     made. --}}
                @if ($bouts->isNotEmpty())
                    <flux:button
                        variant="ghost"
                        class="!text-danger hover:!bg-danger-soft"
                        wire:click="deleteBracket"
                        wire:confirm="{{ __('Delete the drawn bracket for this weight class?') }}"
                    >{{ __('Delete bracket') }}</flux:button>
                @endif

                @if ($confirmingDelete)
                    <flux:button variant="danger" wire:click="deleteBracket(true)">
                        {{ __('Erase results and delete') }}
                    </flux:button>
                @endif

                @if ($drawnCount < 2)
                    <span class="text-sm text-ink/55">{{ __('At least two athletes need draw numbers.') }}</span>
                @endif
            </div>
        </x-ui.card>
    @endcan

    @if ($bouts->isNotEmpty())
        @can('manage-competition')
            @if ($courts->isEmpty())
                {{-- Same reason as on the fight order: no mat means no
                     send-to-mat control anywhere in the bracket. --}}
                <div class="flex flex-wrap items-center gap-3 border border-danger-200 bg-danger-100/60 p-3 dark:bg-danger-500/10">
                    <x-ui.tag variant="danger">{{ __('No mats') }}</x-ui.tag>
                    <span class="text-sm">{{ __('No mats are set up, so bouts cannot be sent to a scoreboard yet.') }}</span>
                    <flux:button size="xs" :href="route('courts.index', $championship)" wire:navigate>{{ __('Add a mat') }}</flux:button>
                </div>
            @endif
        @endcan

        @if ($drawnFormat === \App\Support\TournamentFormat::RoundRobin)
            @include('livewire.competition.partials.round-robin', [
                'weightCategory' => $weightCategory,
                'bouts' => $bouts,
                'standings' => $standings,
                'courts' => $courts,
                'editable' => true,
            ])
        @else

        <x-ui.card flush :title="__('Bracket')">
            <div class="rule-2"></div>

            {{-- The tree.

                 Every round's slots share the height equally, so a round-two
                 slot lands exactly between the two round-one slots that feed
                 it without a single position being calculated. The connectors
                 are drawn off that alignment in CSS — a stub right out of each
                 slot, one vertical joining each pair, a stub left into the slot
                 they feed — which is why they stay correct at every bracket
                 size from x/2 to x/32 rather than needing a case each. --}}
            @php
                // The tree does not stop at the final: the last bout feeds a
                // node of its own, which is what the champion column is.
                $finalBout = $rounds->last()?->first();
                $champion = $finalBout?->winner;
            @endphp

            {{-- Scrolls rather than squeezing: a bracket of thirty-two is
                 wider than a laptop, and a column dropped off the right is a
                 round nobody can see. The width counts the champion. --}}
            <div class="overflow-x-auto px-6 py-5">
                <div class="bkt" style="--bkt-line: var(--color-line); min-width: {{ (max(1, $totalRounds) + 1) * 17 }}rem;">
                    @foreach ($rounds as $round => $roundBouts)
                        <div class="bkt__round" wire:key="round-{{ $round }}">
                            <div class="kicker mb-3 text-ink/55">{{ $roundBouts->first()->phase($totalRounds) }}</div>

                            <div class="bkt__slots">
                            @foreach ($roundBouts as $bout)
                                <div class="bkt__slot" wire:key="slot-{{ $bout->id }}">
                                <div
                                    @class(['bkt__match border border-n-300 bg-surface text-sm', 'opacity-60' => $bout->is_bye])
                                    wire:key="bout-{{ $bout->id }}"
                                >
                                    {{-- The number the running order gave this
                                         contest, so the bracket and the sheet a
                                         mat is working from say the same thing.

                                         Outside every permission check: a
                                         number is a fact about the schedule,
                                         not an action anybody takes. A bye has
                                         no contest to number, and an unscheduled
                                         one has no number yet — neither leaves a
                                         bar behind. --}}
                                    @if (! $bout->is_bye)
                                        <div class="flex items-center gap-2 border-b border-ink/12 px-3 py-0.5 text-[11px] font-bold tracking-wide text-ink/55">
                                            @can('manage-competition')
                                                {{-- Typed by hand, one contest at a time.
                                                     The whole-championship scheduler is a
                                                     separate workflow and neither calls the
                                                     other: nothing on this screen numbers
                                                     anything by itself. --}}
                                                <label for="fight-{{ $bout->id }}">{{ __('No.') }}</label>

                                                <input
                                                    id="fight-{{ $bout->id }}"
                                                    type="number"
                                                    min="1"
                                                    max="{{ \App\Livewire\Competition\Bracket::MAX_FIGHT_NUMBER }}"
                                                    step="1"
                                                    inputmode="numeric"
                                                    class="w-14 rounded border border-line bg-ground px-1.5 py-0 text-[11px] font-bold tabular-nums text-ink"
                                                    {{-- Written out as well as bound: the
                                                         server's render has to say what the
                                                         saved number is, not wait for the
                                                         browser to fill it in. --}}
                                                    value="{{ $fightNumbers[$bout->id] ?? '' }}"
                                                    wire:model="fightNumbers.{{ $bout->id }}"
                                                    wire:blur="setFightNumber({{ $bout->id }})"
                                                    wire:keydown.enter="setFightNumber({{ $bout->id }})"
                                                    aria-label="{{ __('Fight number') }}"
                                                >
                                            @elseif ($bout->fight_number)
                                                <span>{{ __('No. :n', ['n' => $bout->fight_number]) }}</span>
                                            @endcan
                                        </div>
                                    @endif

                                    @foreach (['a', 'b'] as $side)
                                        @php
                                            $athlete = $side === 'a' ? $bout->athleteA : $bout->athleteB;
                                            $isWinner = $athlete && $bout->winner_athlete_id === $athlete->id;
                                        @endphp

                                        {{-- The corner square carries the colour, the
                                             brand tint carries the win: a glance down
                                             the bracket reads who went through. --}}
                                        <div @class([
                                            'flex items-center justify-between gap-2 px-3 py-2',
                                            'border-b border-ink/12' => $side === 'a',
                                            'bg-brand-500/10 font-bold' => $isWinner,
                                        ])>
                                            <span class="flex min-w-0 items-center gap-2">
                                                <span @class(['size-2.5 flex-none', $side === 'a' ? 'bg-info-500' : 'bg-brand-500'])></span>
                                                <x-athlete
                                                    :athlete="$athlete"
                                                    :fallback="$bout->is_bye ? __('Bye') : '—'"
                                                    class="min-w-0"
                                                />
                                            </span>

                                            @if ($athlete && ! $bout->isDecided())
                                                @can('manage-competition')
                                                    <flux:button
                                                        size="xs"
                                                        variant="ghost"
                                                        wire:click="recordResult({{ $bout->id }}, '{{ $side }}')"
                                                        :disabled="! $bout->isReadyToFight()"
                                                    >{{ __('Win') }}</flux:button>
                                                @endcan
                                            @endif
                                        </div>
                                    @endforeach

                                    @can('manage-competition')
                                        @if ($bout->isReadyToFight() && $courts->isNotEmpty())
                                            <div class="flex flex-wrap items-center gap-1 border-t border-ink/12 px-3 py-2">
                                                @if ($bout->court)
                                                    <x-ui.tag variant="info">{{ $bout->court->label() }}</x-ui.tag>
                                                @else
                                                    <span class="kicker text-ink/55">{{ __('Send to') }}</span>
                                                @endif

                                                @foreach ($courts as $court)
                                                    <flux:button
                                                        size="xs"
                                                        variant="ghost"
                                                        wire:click="sendToMat({{ $bout->id }}, {{ $court->id }})"
                                                        wire:key="send-{{ $bout->id }}-{{ $court->id }}"
                                                    >{{ $court->number }}</flux:button>
                                                @endforeach
                                            </div>
                                        @endif
                                    @endcan
                                </div>
                                </div>
                            @endforeach
                            </div>
                        </div>
                    @endforeach

                    {{-- The champion: a node the final connects to, drawn the
                         same way every other node is, so the connector into it
                         comes from the same three rules as the rest of the
                         tree rather than from a case of its own. --}}
                    <div class="bkt__round bkt__round--last bkt__round--champion">
                        <div class="kicker mb-3 text-ink/55">{{ __('Champion') }}</div>

                        <div class="bkt__slots">
                            <div class="bkt__slot">
                                <div @class([
                                    'bkt__match border bg-surface px-3 py-2 text-sm',
                                    'border-brand-500 bg-brand-500/10' => $champion !== null,
                                    'border-n-300' => $champion === null,
                                ])>
                                    <div class="kicker text-ink/55">{{ __('Winner') }}</div>

                                    <div class="mt-1 font-bold">
                                        @if ($champion)
                                            <x-athlete :athlete="$champion" />
                                        @else
                                            <span class="text-ink/45">{{ __('To be decided') }}</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </x-ui.card>

        {{-- The geometry is shared with the venue bracket rather than
             written twice: see the partial for why. --}}
        <style>@include('partials.bracket-geometry')</style>
        @endif
    @endif
    <x-draw.ceremony :names="$ceremonyNames" :pairs="$ceremonyPairs" />
</x-page>
