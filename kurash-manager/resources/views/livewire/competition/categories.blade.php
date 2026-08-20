@php
    use Illuminate\Support\Facades\Gate;

    // Hero figures, derived from the collection the component already loads —
    // no new queries and no change to Categories.php.
    $totalAthletes = $ageCategories->sum('athletes_count');
    $totalWeights = $ageCategories->sum(fn ($c) => $c->weightCategories->count());

    // The capacity bar reads a class's entry count against a full bracket. 16 is
    // the draw the federation's classes are built around; a class beyond it
    // simply reads full rather than overflowing the cell.
    $capacity = 16;

    $tabs = [
        ['label' => __('Categories'), 'href' => route('championships.show', $championship), 'active' => true],
        ['label' => __('Fight order'), 'href' => route('fight-order.index', $championship), 'active' => false],
        ['label' => __('Mats & scoreboards'), 'href' => route('courts.index', $championship), 'active' => false],
        ['label' => __('Medals'), 'href' => route('medals.index', $championship), 'active' => false],
    ];
@endphp

<x-page
    :title="$championship->title"
    :breadcrumbs="[
        ['label' => __('Championships'), 'href' => route('championships.index')],
        ['label' => $championship->title],
    ]"
>
    <x-slot:actions>
        {{-- Two popovers rather than a row of links: the top row carries the
             page's identity, and neither of these is used often enough to
             spend that width on. --}}
        <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false">
            <button type="button" x-on:click="open = ! open" :aria-expanded="open"
                    class="rounded-full px-4 py-2 text-[13.5px] font-semibold text-ink transition-colors hover:bg-line-soft">
                {{ __('Screens') }} ▾
            </button>

            <div x-show="open" x-cloak x-transition.opacity.duration.100ms
                 class="absolute end-0 top-[calc(100%+6px)] z-50 min-w-[250px] rounded-md border border-line bg-surface p-2.5 shadow-pop">
                <div class="kicker px-2 py-1">{{ __('Venue screens') }}</div>

                {{-- Plain links, not wire:navigate: these are opened on a second
                     monitor and left there for the session. --}}
                <div class="flex flex-wrap gap-1.5 px-1 pt-0.5">
                    <x-ui.chip :href="route('display.mats', $championship)" target="_blank">{{ __('Mats') }}</x-ui.chip>
                    <x-ui.chip :href="route('display.fight-order', $championship)" target="_blank">{{ __('Fight order') }}</x-ui.chip>
                    <x-ui.chip :href="route('display.medals', $championship)" target="_blank">{{ __('Medals') }}</x-ui.chip>
                </div>
            </div>
        </div>

        <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false">
            <button type="button" x-on:click="open = ! open" :aria-expanded="open"
                    class="rounded-full px-4 py-2 text-[13.5px] font-semibold text-ink transition-colors hover:bg-line-soft">
                {{ __('Export') }} ▾
            </button>

            <div x-show="open" x-cloak x-transition.opacity.duration.100ms
                 class="absolute end-0 top-[calc(100%+6px)] z-50 min-w-[250px] rounded-md border border-line bg-surface p-2.5 shadow-pop">
                @foreach ([
                    ['label' => __('Entries by weight'), 'route' => 'exports.entries-weight'],
                    ['label' => __('Entries by NOC'), 'route' => 'exports.entries-noc'],
                ] as $export)
                    <div @class(['kicker px-2 py-1', 'mt-1.5 border-t border-line-soft' => ! $loop->first])>
                        {{ $export['label'] }}
                    </div>

                    <div @class(['flex gap-1.5 px-1 pt-0.5', 'pb-2.5' => $loop->first])>
                        <x-ui.chip :href="route($export['route'], ['championship' => $championship, 'format' => 'pdf'])">{{ __('PDF') }}</x-ui.chip>
                        <x-ui.chip :href="route($export['route'], ['championship' => $championship, 'format' => 'csv'])">{{ __('Excel') }}</x-ui.chip>
                    </div>
                @endforeach
            </div>
        </div>
    </x-slot:actions>

    {{-- The header is a card, not a coloured band: the championship is the
         subject of the screen, not a masthead over it. --}}
    <x-slot:hero>
        <div class="flex max-w-[1180px] flex-col items-start gap-[18px] px-2">
            {{-- The hero carries the lead hue as a wash. Everything on it sits
                 on surface, so the tint reads as the page's subject rather
                 than as a state. --}}
            <section class="flex w-full flex-wrap items-end justify-between gap-8 rounded-lg bg-brand-soft px-8 py-7 shadow-card">
                <div>
                    @if ($championship->isArchived())
                        <x-ui.tag>
                            {{ __('Archived :date', ['date' => $championship->archived_at?->format('j M Y')]) }}
                        </x-ui.tag>
                    @else
                        <x-ui.tag variant="brand" dot>
                            {{ collect([
                                __('Live'),
                                $championship->starts_on?->format('j M Y'),
                                $championship->location,
                            ])->filter()->implode(' · ') }}
                        </x-ui.tag>
                    @endif

                    <h1 class="mb-1.5 mt-3.5 text-[34px]">{{ $championship->title }}</h1>
                    <p class="m-0 text-[14.5px] text-muted">
                        {{ __('Age categories and the weight classes inside them.') }}
                    </p>
                </div>

                <x-ui.stats :items="[
                    ['value' => $ageCategories->count(), 'label' => __('Categories'), 'hue' => 'brand'],
                    ['value' => $totalAthletes, 'label' => __('Athletes'), 'hue' => 'info'],
                    ['value' => $totalWeights, 'label' => __('Weight classes'), 'hue' => 'amber'],
                ]" />
            </section>

            {{-- The championship's sections, as one control rather than four
                 links: the pill that is filled is where you are. --}}
            <nav class="flex gap-1.5 rounded-full bg-surface p-[5px] shadow-chip">
                @foreach ($tabs as $tab)
                    <a
                        href="{{ $tab['href'] }}"
                        wire:navigate
                        @class([
                            'rounded-full px-[18px] py-2 text-[13.5px] font-semibold no-underline transition-colors',
                            'bg-brand text-white' => $tab['active'],
                            'text-muted hover:bg-line-soft hover:text-ink' => ! $tab['active'],
                        ])
                    >{{ $tab['label'] }}</a>
                @endforeach
            </nav>
        </div>
    </x-slot:hero>

    <x-competition.flash />

    @can('manage-competition')
        {{-- The form is folded away until it is wanted, but an Edit click fills
             it server-side, so the panel has to open itself whenever the
             component is holding a category. --}}
        <div x-data="{ open: false }">
            <div x-show="! open && ! @js((bool) $editingId)">
                <flux:button variant="primary" x-on:click="open = true">
                    {{ __('+ New age category') }}
                </flux:button>
            </div>

            <div x-show="open || @js((bool) $editingId)" x-cloak>
                <x-ui.card :title="$editingId ? __('Edit age category') : __('New age category')">
                    <form wire:submit="save">
                        <div class="grid items-start gap-[18px] md:grid-cols-[1fr_2fr_auto]">
                            <div class="flex flex-col gap-[7px]">
                                <label for="cat-name" class="text-[12.5px] font-semibold text-muted">{{ __('Name') }}</label>
                                <flux:input id="cat-name" wire:model="ageCategoryName" placeholder="{{ __('Men Senior') }}" required />
                            </div>

                            <div class="flex flex-col gap-[7px]">
                                <label for="cat-weights" class="text-[12.5px] font-semibold text-muted">{{ __('Weight classes') }}</label>
                                <flux:input id="cat-weights" wire:model="weightLabels" placeholder="-60, -66, -73, -81, -90, +90" required />
                                <p class="text-xs text-muted">
                                    {{ __('Comma separated, in display order. Use + for an open upper class.') }}
                                </p>
                            </div>

                            <div class="flex flex-col gap-[7px]">
                                <span class="text-[12.5px] font-semibold text-muted">{{ __('Gender') }}</span>

                                {{-- A segmented control over the same wire:model
                                     the select used, so the component is
                                     untouched — only the control changed. --}}
                                <div class="flex gap-1 rounded-full bg-ground p-1">
                                    @foreach ([['M', __('Male')], ['F', __('Female')], ['X', __('Mixed')]] as [$value, $label])
                                        <label class="cursor-pointer rounded-full px-3.5 py-[7px] text-[13px] font-semibold text-muted transition-all
                                                      has-[:checked]:bg-surface has-[:checked]:text-ink has-[:checked]:shadow-chip
                                                      has-[:focus-visible]:outline has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-brand">
                                            <input type="radio" wire:model="gender" value="{{ $value }}" class="sr-only">
                                            {{ $label }}
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        @error('weightLabels')
                            <p class="mt-3 text-[13px] text-danger">{{ $message }}</p>
                        @enderror

                        <div class="mt-[22px] flex gap-2.5">
                            <flux:button type="submit" variant="primary">
                                {{ $editingId ? __('Save changes') : __('Add category') }}
                            </flux:button>

                            <flux:button type="button" variant="ghost" wire:click="cancelEdit" x-on:click="open = false">
                                {{ __('Cancel') }}
                            </flux:button>
                        </div>
                    </form>
                </x-ui.card>
            </div>
        </div>
    @endcan

    @forelse ($ageCategories as $ageCategory)
        @php
            // An age category takes its gender from the classes inside it, the
            // same way the edit form reads it back.
            $gender = $ageCategory->weightCategories->first()->gender ?? 'X';

            // Each age category owns a hue, so a long page of them stays
            // scannable: men blue, women green, anything else neutral. The rail
            // is the bright end and the label the readable -deep one.
            [$genderLabel, $rail, $tint, $ink] = match ($gender) {
                // The ink is forced: it lands on a tag that already carries the
                // neutral variant's colour, and two utilities of equal weight
                // are settled by stylesheet order, not by call site.
                'M' => [__('Men'), 'border-info', 'bg-info-soft', '!text-info-deep'],
                'F' => [__('Women'), 'border-brand', 'bg-brand-soft', '!text-brand-deep'],
                default => [__('Mixed'), 'border-line', 'bg-ground', ''],
            };
        @endphp

        <x-ui.card flush class="border-t-[5px] {{ $rail }}" wire:key="age-{{ $ageCategory->id }}">
            <div class="flex flex-wrap items-start justify-between gap-4 px-7 py-[22px] {{ $tint }}">
                <div>
                    <div class="flex items-center gap-2.5">
                        <h2 class="m-0 text-[21px]">{{ $ageCategory->name }}</h2>

                        {{-- Surface, not the hue's soft tint: the header is
                             already that tint, and a pill the colour of its
                             own background is not a pill. --}}
                        <x-ui.tag class="!bg-surface {{ $ink }}">{{ $genderLabel }}</x-ui.tag>
                    </div>

                    <p class="mt-1.5 text-[13.5px] text-muted">
                        {{ trans_choice('{0}No athletes registered|{1}:count athlete registered|[2,*]:count athletes registered', $ageCategory->athletes_count, ['count' => $ageCategory->athletes_count]) }}
                        ·
                        {{ trans_choice('{0}no weight classes|{1}:count weight class|[2,*]:count weight classes', $ageCategory->weightCategories->count(), ['count' => $ageCategory->weightCategories->count()]) }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <flux:button size="sm" :href="route('athletes.index', $ageCategory)" wire:navigate>
                        {{ __('Registration') }}
                    </flux:button>
                    <flux:button size="sm" :href="route('weighin.index', $ageCategory)" wire:navigate>
                        {{ __('Weigh-in') }}
                    </flux:button>

                    @can('manage-competition')
                        <flux:button size="sm" variant="ghost" wire:click="edit({{ $ageCategory->id }})">
                            {{ __('Edit') }}
                        </flux:button>

                        {{-- Destructive actions are ghost buttons in red text,
                             never a solid red block: the weight of a filled
                             button belongs to the primary action. --}}
                        <flux:button
                            size="sm"
                            variant="ghost"
                            class="!text-danger hover:!bg-danger-soft"
                            wire:click="delete({{ $ageCategory->id }})"
                            wire:confirm="{{ __('Delete this age category?') }}"
                        >{{ __('Delete') }}</flux:button>
                    @endcan
                </div>
            </div>

            <div class="grid gap-2.5 px-7 py-6 [grid-template-columns:repeat(auto-fit,minmax(158px,1fr))]">
                @forelse ($ageCategory->weightCategories as $weightCategory)
                    @php
                        $count = $weightCategory->athletes_count;
                        $fill = min(100, (int) round($count / $capacity * 100));

                        // Grey until the class is half drawn, blue while it
                        // fills, green once it can run a full bracket.
                        $bar = match (true) {
                            $fill >= 100 => 'bg-brand',
                            $fill >= 50 => 'bg-info',
                            default => 'bg-muted',
                        };
                    @endphp

                    @php
                        // The tile leads where the reader may actually go: the
                        // draw screen for the people who run it, the published
                        // table for everybody else, and nowhere at all when
                        // there is nothing they are allowed to open.
                        $tileHref = Gate::allows('manage-competition')
                            ? route('bracket.show', $weightCategory)
                            : ($weightCategory->isDrawPublished() ? route('operator.draws.show', $weightCategory) : null);
                    @endphp

                    <a
                        @if ($tileHref) href="{{ $tileHref }}" wire:navigate @endif
                        wire:key="weight-{{ $weightCategory->id }}"
                        @class([
                            'block rounded-md border border-line bg-ground px-4 py-3.5 no-underline transition-all',
                            'hover:-translate-y-px hover:shadow-card' => $tileHref !== null,
                            'cursor-default' => $tileHref === null,
                        ])
                    >
                        <div class="flex items-baseline justify-between gap-2">
                            <span class="text-lg font-bold text-ink">{{ $weightCategory->label }}</span>
                            <span class="text-[12.5px] font-semibold tabular-nums text-muted">{{ $count }}/{{ $capacity }}</span>
                        </div>

                        <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-line">
                            <div class="h-1.5 rounded-full {{ $bar }}" style="width: {{ $fill }}%"></div>
                        </div>
                    </a>
                @empty
                    <p class="text-[13px] text-muted">{{ __('No weight classes defined.') }}</p>
                @endforelse
            </div>
        </x-ui.card>
    @empty
        <x-ui.card class="py-10 text-center">
            <p class="m-0 text-[13.5px] text-muted">
                {{ __('No age categories yet. Add one — for example "Men Senior" with -60, -66, -73, -81, -90, +90.') }}
            </p>
        </x-ui.card>
    @endforelse
</x-page>
