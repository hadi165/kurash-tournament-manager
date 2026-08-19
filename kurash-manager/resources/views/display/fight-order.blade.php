@php($title = __('Fight order'))

<x-display.layout :title="$title" :championship="$championship" :refresh="15">
    @if ($bouts->isEmpty())
        <div class="empty">{{ __('The running order has not been built yet.') }}</div>
    @else
        <div class="scroll">
            <table>
                <thead>
                    <tr>
                        <th>{{ __('#') }}</th>
                        <th>{{ __('Category') }}</th>
                        <th>{{ __('Phase') }}</th>
                        <th>{{ __('Blue') }}</th>
                        <th>{{ __('Green') }}</th>
                        <th>{{ __('Mat') }}</th>
                        <th>{{ __('Winner') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($bouts as $bout)
                        <tr @class(['muted' => $bout->isDecided()])>
                            <td class="num">{{ $bout->fight_number }}</td>
                            <td>{{ $bout->weightCategory->exportName() }}</td>
                            <td>{{ $bout->phase((int) ($rounds[$bout->weight_category_id] ?? $bout->round)) }}</td>
                            <td @class(['blue' => ! $bout->isDecided()])><x-display.athlete :athlete="$bout->athleteA" /></td>
                            <td @class(['green' => ! $bout->isDecided()])><x-display.athlete :athlete="$bout->athleteB" /></td>
                            <td>{{ $bout->court?->label() ?? '—' }}</td>
                            <td class="win">{{ $bout->winner?->fullname }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-display.layout>
