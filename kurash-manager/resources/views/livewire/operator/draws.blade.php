<x-page
    :title="__('Draws to present')"
    :subtitle="__('Weight categories whose draw the competition office has published.')"
>
    <x-ui.card>
        <div class="grid gap-[18px] md:grid-cols-4">
            {{-- Chosen, not typed. An operator at a draw knows which
                 competition they are running, and a misspelt one looks exactly
                 like a competition with nothing published. --}}
            <div class="flex flex-col gap-[7px]">
                <label for="d-championship" class="text-[12.5px] font-semibold text-muted">{{ __('Competition') }}</label>
                <flux:select id="d-championship" wire:model.live="championship">
                    <flux:select.option value="" :selected="$championship === ''">{{ __('All competitions') }}</flux:select.option>
                    @foreach ($championships as $event)
                        <flux:select.option value="{{ $event->id }}" :selected="(string) $event->id === $championship">
                            {{ $event->title }}@if ($event->starts_on) · {{ $event->starts_on->format('j M Y') }} @endif
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="flex flex-col gap-[7px]">
                <label for="d-gender" class="text-[12.5px] font-semibold text-muted">{{ __('Gender') }}</label>
                <flux:select id="d-gender" wire:model.live="gender">
                    <flux:select.option value="" :selected="$gender === ''">{{ __('All') }}</flux:select.option>
                    <flux:select.option value="M" :selected="$gender === 'M'">{{ __('Men') }}</flux:select.option>
                    <flux:select.option value="F" :selected="$gender === 'F'">{{ __('Women') }}</flux:select.option>
                </flux:select>
            </div>

            <div class="flex flex-col gap-[7px]">
                <label for="d-age" class="text-[12.5px] font-semibold text-muted">{{ __('Division') }}</label>
                <flux:select id="d-age" wire:model.live="ageCategory">
                    <flux:select.option value="" :selected="$ageCategory === ''">{{ __('All divisions') }}</flux:select.option>
                    @foreach ($ageCategories as $division)
                        <flux:select.option value="{{ $division->id }}" :selected="(string) $division->id === $ageCategory">
                            {{ $division->name }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="flex flex-col gap-[7px]">
                <label for="d-status" class="text-[12.5px] font-semibold text-muted">{{ __('Draw status') }}</label>
                <flux:select id="d-status" wire:model.live="status">
                    <flux:select.option value="" :selected="$status === ''">{{ __('All') }}</flux:select.option>
                    <flux:select.option value="published" :selected="$status === 'published'">{{ __('Published') }}</flux:select.option>
                    <flux:select.option value="waiting" :selected="$status === 'waiting'">{{ __('Waiting') }}</flux:select.option>
                </flux:select>
            </div>
        </div>
    </x-ui.card>

    @forelse ($categories as $category)
        @php
            $published = $category->isDrawPublished() && $category->bouts_count > 0;
            $gender = match ($category->gender) {
                'M' => __('Men'),
                'F' => __('Women'),
                default => __('Open'),
            };
        @endphp

        <x-ui.card wire:key="cat-{{ $category->id }}">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="flex flex-wrap items-center gap-2.5">
                        <h2 class="m-0 text-[21px]">{{ $category->label }} {{ __('kg') }} {{ $gender }}</h2>

                        @if ($published)
                            <x-ui.tag variant="brand">{{ __('Published') }}</x-ui.tag>
                        @else
                            <x-ui.tag>{{ __('Waiting for publication') }}</x-ui.tag>
                        @endif

                        @if ($category->isDrawLocked())
                            <x-ui.tag variant="amber">{{ __('Locked') }}</x-ui.tag>
                        @endif
                    </div>

                    <p class="mt-1.5 text-[13.5px] text-muted">
                        {{ $category->ageCategory?->name }} · {{ $category->ageCategory?->championship?->title }}
                    </p>

                    {{-- Only ever the stored figures. A category still waiting
                         shows nothing about who is in it. --}}
                    @if ($published)
                        <p class="mt-1 text-[12.5px] text-muted">
                            {{ trans_choice('{1}:count athlete|[2,*]:count athletes', $category->draw_athlete_count ?? 0, ['count' => $category->draw_athlete_count ?? 0]) }}
                            ·
                            {{ __('bracket of :size', ['size' => $category->draw_bucket_size]) }}
                            ·
                            {{ trans_choice('{0}no byes|{1}:count bye|[2,*]:count byes', $category->draw_bye_count ?? 0, ['count' => $category->draw_bye_count ?? 0]) }}
                            ·
                            {{ trans_choice('{1}:count bout|[2,*]:count bouts', $category->bouts_count, ['count' => $category->bouts_count]) }}
                            @if ($category->draw_generated_at)
                                · {{ __('drawn :when', ['when' => $category->draw_generated_at->diffForHumans()]) }}
                            @endif
                        </p>
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    @if ($published)
                        <flux:button variant="primary" :href="route('operator.draws.present', $category)">
                            {{ __('Present draw') }}
                        </flux:button>

                        {{-- The table on its own, for somebody who wants to read
                             it rather than present it. --}}
                        <x-ui.chip :href="route('operator.draws.show', $category)" wire:navigate>
                            {{ __('Draw table') }}
                        </x-ui.chip>
                    @else
                        {{-- Disabled rather than hidden: an operator who cannot
                             find a category has no way to tell whether it is
                             missing or merely unpublished, and the label leaks
                             nothing that the schedule does not already say.
                             Keep the action's name stable as well: this is
                             always where a draw is presented, and publication
                             controls whether that action is available. --}}
                        <flux:button variant="ghost" disabled>{{ __('Present draw') }}</flux:button>
                        <span class="text-[12.5px] text-muted">{{ __('Not yet published') }}</span>
                    @endif
                </div>
            </div>
        </x-ui.card>
    @empty
        <x-ui.card class="py-10 text-center">
            <h2 class="m-0 text-2xl">{{ __('No published draws') }}</h2>
            <p class="mt-2 text-[13.5px] text-muted">
                {{ __('The competition office has not published a weight-category draw yet.') }}
            </p>
        </x-ui.card>
    @endforelse
</x-page>
