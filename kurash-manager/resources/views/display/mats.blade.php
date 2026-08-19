@php($title = __('On the mats'))

<x-display.layout :title="$title" :championship="$championship" :refresh="10">
    <div class="grid">
        @forelse ($championship->courts as $court)
            @php($bout = $live->get($court->id))

            <div class="panel">
                <h2>{{ $court->label() }}</h2>

                @if ($bout)
                    <div class="muted" style="font-size:0.85rem; margin-bottom:0.5rem">
                        {{ $bout->weightCategory->exportName() }}
                        @if ($bout->fight_number) · {{ __('Fight :n', ['n' => $bout->fight_number]) }} @endif
                    </div>

                    <div class="blue" style="font-size:1.15rem; font-weight:600">
                        <x-display.athlete :athlete="$bout->athleteA" />
                    </div>
                    <div class="muted" style="font-size:0.8rem; margin:0.15rem 0">{{ __('versus') }}</div>
                    <div class="green" style="font-size:1.15rem; font-weight:600">
                        <x-display.athlete :athlete="$bout->athleteB" />
                    </div>
                @else
                    <div class="muted">{{ __('Free') }}</div>
                @endif
            </div>
        @empty
            <div class="panel"><div class="muted">{{ __('No mats configured.') }}</div></div>
        @endforelse
    </div>

    <div class="panel" style="margin-top:1.25rem">
        <h2>{{ __('Coming up') }}</h2>

        @if ($next->isEmpty())
            <div class="muted">{{ __('Nothing waiting.') }}</div>
        @else
            <div class="scroll">
                <table>
                    <thead>
                        <tr>
                            <th>{{ __('Fight') }}</th>
                            <th>{{ __('Category') }}</th>
                            <th>{{ __('Blue') }}</th>
                            <th>{{ __('Green') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($next as $bout)
                            <tr>
                                <td class="num">{{ $bout->fight_number }}</td>
                                <td class="muted">{{ $bout->weightCategory->exportName() }}</td>
                                <td class="blue"><x-display.athlete :athlete="$bout->athleteA" /></td>
                                <td class="green"><x-display.athlete :athlete="$bout->athleteB" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-display.layout>
