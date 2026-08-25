{{-- A round robin, on the screens that run it.

     Not a bracket, and deliberately not drawn as one. There is no tree here to
     walk: every pairing was known the moment the draw was made, nobody
     advances, and the only thing that changes as the session runs is which
     contests have a result and what the table says. So the surface is the two
     things an official actually reads — the fixtures, and the standings.

     Shared by the administrator's screen and the published operator view. The
     `editable` flag is what separates them: an operator presents this table,
     and presenting is not scoring. --}}
@php
    $editable = $editable ?? false;
    $byRound = $bouts->groupBy('round');
@endphp

<x-ui.card flush :title="__('Round robin')">
    <x-slot:head>
        <x-ui.tag variant="brand">{{ __('Round Robin') }}</x-ui.tag>

        @if ($weightCategory->formatWasOverridden())
            <x-ui.tag variant="amber">{{ __('Local rules override') }}</x-ui.tag>
        @endif

        <span class="text-[12.5px] text-muted">
            {{ trans_choice(
                '{1}:count contest|[2,*]:count contests',
                $bouts->count(), ['count' => $bouts->count()]
            ) }}
            ·
            {{ trans_choice('{1}:count round|[2,*]:count rounds', $byRound->count(), ['count' => $byRound->count()]) }}
        </span>
    </x-slot:head>

    <div class="rule-2"></div>

    {{-- The fixtures, round by round. A round is a grouping of the schedule
         and nothing more — losing one does not put anybody out. --}}
    <div class="flex flex-col gap-5 p-[18px]">
        @foreach ($byRound as $round => $contests)
            <div wire:key="rr-round-{{ $round }}">
                <div class="kicker mb-2 text-ink/55">{{ __('Round :n', ['n' => $round]) }}</div>

                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach ($contests as $bout)
                        <div class="rounded-md border border-line bg-surface px-3.5 py-2.5" wire:key="rr-bout-{{ $bout->id }}">
                            <div class="mb-1.5 flex items-center gap-2 text-[12px] text-muted">
                                @if ($bout->fight_number)
                                    <span class="font-bold">{{ __('No. :n', ['n' => $bout->fight_number]) }}</span>
                                @else
                                    <span>{{ __('Not scheduled') }}</span>
                                @endif

                                <span class="ms-auto">
                                    @if ($bout->isDecided())
                                        <x-ui.tag variant="brand">{{ __('Decided') }}</x-ui.tag>
                                    @else
                                        <x-ui.tag>{{ __('Pending') }}</x-ui.tag>
                                    @endif
                                </span>
                            </div>

                            @foreach ([[$bout->athleteA, $bout->athlete_a_id], [$bout->athleteB, $bout->athlete_b_id]] as [$athlete, $athleteId])
                                @php($won = $athleteId !== null && $bout->winner_athlete_id === $athleteId)

                                <div @class([
                                    'flex items-center gap-2 py-1 text-[13.5px]',
                                    'font-bold text-brand-700 dark:text-brand-400' => $won,
                                ])>
                                    <x-athlete :athlete="$athlete" show-code />

                                    @if ($editable && ! $bout->isDecided() && $bout->athlete_a_id && $bout->athlete_b_id)
                                        @can('manage-competition')
                                            <flux:button
                                                size="xs"
                                                variant="ghost"
                                                class="ms-auto"
                                                wire:click="recordResult({{ $bout->id }}, '{{ $athleteId === $bout->athlete_a_id ? 'a' : 'b' }}')"
                                            >{{ __('Won') }}</flux:button>
                                        @endcan
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</x-ui.card>

{{-- The table. Derived from the contests above every time it is rendered, so
     a correction upstairs is already reflected here. --}}
@if ($standings)
    <x-ui.card flush :title="__('Standings')">
        <x-slot:head>
            @if ($standings['complete'])
                <x-ui.tag variant="brand">{{ __('Group complete') }}</x-ui.tag>
            @else
                <x-ui.tag>{{ __(':n of :total contests decided', [
                    'n' => $standings['contests']['decided'],
                    'total' => $standings['contests']['total'],
                ]) }}</x-ui.tag>
            @endif

            @if ($standings['unresolved'] !== [])
                <x-ui.tag variant="amber">{{ __('Referee decision required') }}</x-ui.tag>
            @endif
        </x-slot:head>

        <div class="rule-2"></div>

        <div class="overflow-x-auto">
            <table class="w-full text-[13.5px]">
                <thead>
                    <tr class="border-b border-line text-left text-[12px] uppercase tracking-wider text-ink/55">
                        <th class="px-[18px] py-2.5">{{ __('Rank') }}</th>
                        <th class="px-[18px] py-2.5">{{ __('Athlete') }}</th>
                        <th class="px-[18px] py-2.5">{{ __('NOC') }}</th>
                        <th class="px-[18px] py-2.5 text-right">{{ __('Played') }}</th>
                        <th class="px-[18px] py-2.5 text-right">{{ __('Won') }}</th>
                        <th class="px-[18px] py-2.5 text-right">{{ __('Lost') }}</th>
                        <th class="px-[18px] py-2.5 text-right">{{ __('Points') }}</th>
                        <th class="px-[18px] py-2.5">{{ __('Standing') }}</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($standings['rows'] as $row)
                        <tr class="border-b border-line/60 last:border-0" wire:key="rr-rank-{{ $row['athlete']->id }}">
                            <td class="px-[18px] py-2.5 font-bold tabular-nums">{{ $row['rank'] }}</td>
                            <td class="px-[18px] py-2.5"><x-athlete :athlete="$row['athlete']" /></td>
                            <td class="px-[18px] py-2.5 font-bold text-muted">{{ $row['noc'] }}</td>
                            <td class="px-[18px] py-2.5 text-right tabular-nums">{{ $row['played'] }}</td>
                            <td class="px-[18px] py-2.5 text-right font-bold tabular-nums">{{ $row['wins'] }}</td>
                            <td class="px-[18px] py-2.5 text-right tabular-nums">{{ $row['losses'] }}</td>
                            <td class="px-[18px] py-2.5 text-right font-bold tabular-nums">{{ $row['points'] }}</td>
                            <td class="px-[18px] py-2.5">
                                @if ($row['medal'])
                                    <x-ui.tag variant="brand">{{ __(ucfirst($row['medal'])) }}</x-ui.tag>
                                @elseif ($row['state'] === \App\Services\RoundRobinStandings::STATE_NEEDS_DECISION)
                                    <x-ui.tag variant="amber">{{ __('Level — decision required') }}</x-ui.tag>
                                @elseif ($row['state'] === \App\Services\RoundRobinStandings::STATE_PROVISIONAL)
                                    <span class="text-[12.5px] text-muted">{{ __('Provisional') }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Said plainly rather than left for somebody to work out from the
             ranks: the table has taken these athletes as far as the rules take
             them, and the rest is a decision somebody has to make. --}}
        @if ($standings['unresolved'] !== [])
            <div class="border-t border-line bg-amber-soft px-[18px] py-3.5 text-[13px] text-amber-deep">
                {{ __('The tie-breaks in the rules do not separate every athlete in this group. A technical or referee decision is required before the medals are awarded.') }}
            </div>
        @endif
    </x-ui.card>
@endif
