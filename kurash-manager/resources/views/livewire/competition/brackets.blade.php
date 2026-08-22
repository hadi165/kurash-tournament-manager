<x-page
    :title="__('Brackets')"
    :subtitle="__('The drawn tree for every weight class, with the draw sheet the mat works from.')"
    :breadcrumbs="[
        ['label' => __('Championships'), 'href' => route('championships.index')],
        ['label' => $championship->title, 'href' => route('championships.show', $championship)],
        ['label' => __('Brackets')],
    ]"
>
    <x-competition.scope :label="$this->competitionLabel()" route="brackets.index" :championship="$championship" />

    <x-ui.card flush>
        <div class="overflow-x-auto">
            <table class="t">
                <thead>
                    <tr>
                        <th>{{ __('Weight category') }}</th>
                        <th>{{ __('Division') }}</th>
                        <th class="num">{{ __('Athletes') }}</th>
                        <th class="num">{{ __('Bracket') }}</th>
                        <th class="num">{{ __('Byes') }}</th>
                        <th class="num">{{ __('Bouts') }}</th>
                        <th>{{ __('Draw sheet') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($categories as $category)
                        @php $sheet = $sheets[$category->id] ?? null; @endphp

                        <tr wire:key="bracket-{{ $category->id }}">
                            <td class="font-semibold">{{ $category->exportName() }}</td>
                            <td class="text-muted">{{ $category->ageCategory?->name }}</td>
                            <td class="num">{{ $category->draw_athlete_count ?? $category->athletes_count }}</td>
                            <td class="num">{{ $category->draw_bucket_size ? 'x/'.$category->draw_bucket_size : '—' }}</td>
                            <td class="num">{{ $category->draw_bye_count ?? '—' }}</td>
                            <td class="num">{{ $category->bouts_count }}</td>
                            <td>
                                @if ($sheet)
                                    <div class="flex flex-wrap gap-1.5">
                                        <x-ui.chip :href="route('exports.bracket-sheet', ['weightCategory' => $category, 'format' => 'pdf'])">
                                            {{ __('PDF') }}
                                        </x-ui.chip>
                                        <x-ui.chip :href="route('exports.bracket-sheet', ['weightCategory' => $category, 'format' => 'xlsx'])">
                                            {{ __('Excel') }}
                                        </x-ui.chip>

                                        {{-- No Open here. This screen is the
                                             drawn tree and the paperwork that
                                             comes off it; the draw itself is
                                             run from Entries, and two doors
                                             into it was one too many. --}}
                                        @if ($category->isDrawPublished())
                                            <x-ui.chip variant="ghost" :href="route('operator.draws.show', $category)" wire:navigate>
                                                {{ __('Present') }}
                                            </x-ui.chip>
                                        @endif
                                    </div>
                                @else
                                    <span class="text-muted">{{ __('Not drawn yet') }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-8 text-center text-muted">{{ __('No weight classes yet.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
</x-page>
