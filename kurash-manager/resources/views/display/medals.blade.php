@php($title = __('Medals'))

<x-display.layout :title="$title" :championship="$championship" :refresh="30">
    <div class="grid" style="grid-template-columns: minmax(280px, 1fr) minmax(380px, 2fr)">
        <div class="panel">
            <h2>{{ __('Standing by NOC') }}</h2>

            @if ($standings->isEmpty())
                <div class="muted">{{ __('No class has been decided yet.') }}</div>
            @else
                <table>
                    <thead>
                        <tr>
                            <th>{{ __('NOC') }}</th>
                            <th>{{ __('G') }}</th>
                            <th>{{ __('S') }}</th>
                            <th>{{ __('B') }}</th>
                            <th>{{ __('Total') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($standings as $row)
                            <tr>
                                <td style="font-weight:600">
                                    @php($iso = \App\Support\Noc::iso($row['noc_code']))
                                    <span class="competitor">
                                        @if ($iso)<img class="flag" src="{{ asset("flags/{$iso}.svg") }}" alt="{{ $row['noc_code'] }}">@endif
                                        <span>{{ \App\Support\Noc::normalise($row['noc_code']) }}</span>
                                    </span>
                                </td>
                                <td class="num win">{{ $row['gold'] }}</td>
                                <td class="num">{{ $row['silver'] }}</td>
                                <td class="num">{{ $row['bronze'] }}</td>
                                <td class="num">{{ $row['total'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div class="panel">
            <h2>{{ __('Podiums') }}</h2>

            @if ($podiums->isEmpty())
                <div class="muted">{{ __('No class has been decided yet.') }}</div>
            @else
                <div class="scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>{{ __('Category') }}</th>
                                <th>{{ __('Gold') }}</th>
                                <th>{{ __('Silver') }}</th>
                                <th>{{ __('Bronze') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($podiums as $row)
                                <tr>
                                    <td>{{ $row['category']->exportName() }}</td>
                                    <td class="win"><x-display.athlete :athlete="$row['podium']['gold']" /></td>
                                    <td><x-display.athlete :athlete="$row['podium']['silver']" /></td>
                                    <td class="muted">
                                        {{ collect($row['podium']['bronze'])->map->label()->implode(', ') ?: '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</x-display.layout>
