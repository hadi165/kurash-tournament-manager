@php
    // Hero figures, derived from the collection the component already loads —
    // no new queries and no change to Categories.php.
    $totalAthletes = $ageCategories->sum('athletes_count');
    $totalWeights = $ageCategories->sum(fn ($c) => $c->weightCategories->count());

    // The capacity bar reads a class's entry count against a full bracket. 16 is
    // the draw the federation's classes are built around; a class beyond it
    // simply reads full rather than overflowing the cell.
    $capacity = 16;
@endphp

<div class="-m-6 flex flex-col lg:-m-8">

    {{-- Utility bar: breadcrumbs one side, the screens an official opens on a
         second monitor the other. --}}
    <div class="flex flex-wrap items-center justify-between gap-4 border-b-2 border-divider px-8 py-2.5">
        <div class="flex items-center gap-2 text-[13px]">
            <a href="{{ route('championships.index') }}" wire:navigate class="font-semibold text-brand-700 no-underline hover:underline dark:text-brand-400">
                {{ __('Championships') }}
            </a>
            <span class="text-ink/55">/</span>
            <span class="font-semibold text-ink/55">{{ $championship->title }}</span>
        </div>

        <div class="flex flex-wrap items-center gap-1.5">
            <span class="kicker me-1 text-ink/55">{{ __('Venue screens') }}</span>

            {{-- Plain links, not wire:navigate: these are opened on a second
                 monitor and left there for the session. --}}
            <a href="{{ route('display.mats', $championship) }}" target="_blank"
               class="px-2.5 py-1 text-xs font-bold text-brand-700 no-underline hover:bg-brand-500/10 dark:text-brand-400">{{ __('Mats') }}</a>
            <a href="{{ route('display.fight-order', $championship) }}" target="_blank"
               class="px-2.5 py-1 text-xs font-bold text-brand-700 no-underline hover:bg-brand-500/10 dark:text-brand-400">{{ __('Fight order') }}</a>
            <a href="{{ route('display.medals', $championship) }}" target="_blank"
               class="px-2.5 py-1 text-xs font-bold text-brand-700 no-underline hover:bg-brand-500/10 dark:text-brand-400">{{ __('Medals') }}</a>

            <span class="mx-1.5 h-5 w-0.5 bg-divider"></span>

            {{-- Flux owns the appearance setting; this is the same switch the
                 settings screen writes, surfaced where the design asks for it. --}}
            <button
                type="button"
                x-data
                x-on:click="$flux.appearance = $flux.appearance === 'dark' ? 'light' : 'dark'"
                class="border border-divider px-3 py-1 text-xs font-bold text-ink hover:bg-ink/7"
            >
                <span class="hidden dark:inline">{{ __('Light mode') }}</span>
                <span class="dark:hidden">{{ __('Dark mode') }}</span>
            </button>
        </div>
    </div>

    {{-- Hero band. The one full-bleed green surface in the system — it marks the
         championship console and is not repeated on the screens below it. --}}
    <header class="flex flex-wrap items-end justify-between gap-8 bg-brand-500 px-8 pb-8 pt-9 text-white">
        <div>
            <div class="flex items-center gap-3">
                @php
                    $heroLogo = config('branding.logo');
                    $hasHeroLogo = $heroLogo && is_file(public_path($heroLogo));
                @endphp

                @if ($hasHeroLogo)
                    <span class="flex-none bg-white p-[5px]">
                        <img src="{{ asset($heroLogo) }}" alt="{{ config('branding.organisation') }}" class="size-[46px] object-contain">
                    </span>
                @endif

                <span class="text-[11px] font-bold uppercase leading-snug tracking-[0.14em] opacity-90">
                    {{ config('branding.organisation') }} · {{ __('Official Championship Console') }}
                </span>
            </div>

            <h1 class="mt-4 max-w-[16ch] text-[clamp(2rem,4vw,52px)] uppercase leading-[1.02] tracking-[-0.02em] text-white">
                {{ $championship->title }}
            </h1>
        </div>

        <div class="flex gap-10">
            @foreach ([
                ['value' => $ageCategories->count(), 'label' => __('Age categories')],
                ['value' => $totalAthletes, 'label' => __('Athletes')],
                ['value' => $totalWeights, 'label' => __('Weight classes')],
            ] as $stat)
                <div>
                    <div class="text-[42px] font-bold leading-none tabular-nums">{{ $stat['value'] }}</div>
                    <div class="kicker mt-1.5 opacity-85">{{ $stat['label'] }}</div>
                </div>
            @endforeach
        </div>
    </header>

    {{-- Section tabs + exports. --}}
    <div class="flex flex-wrap items-center justify-between gap-4 border-b-2 border-divider px-8">
        <div class="flex gap-7">
            @php
                $tabs = [
                    ['label' => __('Categories'), 'href' => route('championships.show', $championship), 'active' => true],
                    ['label' => __('Fight order'), 'href' => route('fight-order.index', $championship), 'active' => false],
                    ['label' => __('Mats & scoreboards'), 'href' => route('courts.index', $championship), 'active' => false],
                    ['label' => __('Medals'), 'href' => route('medals.index', $championship), 'active' => false],
                ];
            @endphp

            @foreach ($tabs as $tab)
                <a
                    href="{{ $tab['href'] }}"
                    wire:navigate
                    @class([
                        '-mb-0.5 border-b-[3px] pb-[11px] pt-3.5 text-sm no-underline',
                        'border-brand-500 font-bold text-ink' => $tab['active'],
                        'border-transparent font-semibold text-ink/60 hover:text-ink' => ! $tab['active'],
                    ])
                >{{ $tab['label'] }}</a>
            @endforeach
        </div>

        <div class="relative py-2" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false">
            <button type="button" x-on:click="open = ! open" :aria-expanded="open"
                    class="px-1 text-[13px] font-bold text-brand-700 hover:bg-brand-500/10 dark:text-brand-400">
                {{ __('Export') }} ▾
            </button>

            <div x-show="open" x-cloak x-transition.opacity.duration.100ms
                 class="absolute end-0 top-full z-50 min-w-[230px] border border-n-300 bg-surface py-2.5 shadow-elev-md">
                @foreach ([
                    ['label' => __('Entries by weight'), 'route' => 'exports.entries-weight'],
                    ['label' => __('Entries by NOC'), 'route' => 'exports.entries-noc'],
                ] as $i => $export)
                    <div @class(['kicker px-4 pb-0.5 pt-1.5 text-ink/55', 'border-t border-n-300 mt-1' => $i > 0])>
                        {{ $export['label'] }}
                    </div>
                    <div class="flex gap-1 px-3 pb-2 pt-0.5">
                        <a href="{{ route($export['route'], ['championship' => $championship, 'format' => 'pdf']) }}"
                           class="px-2.5 py-1 text-xs font-bold text-brand-700 no-underline hover:bg-brand-500/10 dark:text-brand-400">{{ __('PDF') }}</a>
                        <a href="{{ route($export['route'], ['championship' => $championship, 'format' => 'csv']) }}"
                           class="px-2.5 py-1 text-xs font-bold text-brand-700 no-underline hover:bg-brand-500/10 dark:text-brand-400">{{ __('Excel') }}</a>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="flex max-w-[1240px] flex-col gap-5 px-8 pb-12 pt-6">
        <x-competition.flash />

        @can('manage-competition')
            {{-- The form is folded away until it is wanted, but an Edit click
                 fills it server-side, so the panel has to open itself whenever
                 the component is holding a category. --}}
            <div x-data="{ open: false }">
                <div x-show="! open && ! @js((bool) $editingId)">
                    <flux:button variant="primary" x-on:click="open = true">
                        {{ __('+ New age category') }}
                    </flux:button>
                </div>

                <div x-show="open || @js((bool) $editingId)" x-cloak
                     class="border border-n-300 bg-surface px-6 py-[22px] shadow-elev-sm">
                    <form wire:submit="save">
                        <h4 class="m-0 text-xl">{{ $editingId ? __('Edit age category') : __('New age category') }}</h4>

                        <div class="my-[18px] grid items-start gap-4 md:grid-cols-[1fr_2fr_auto]">
                            <div class="flex flex-col gap-1.5">
                                <label for="cat-name" class="kicker">{{ __('Name') }}</label>
                                <flux:input id="cat-name" wire:model="ageCategoryName" placeholder="{{ __('Men Senior') }}" required />
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label for="cat-weights" class="kicker">{{ __('Weight classes') }}</label>
                                <flux:input id="cat-weights" wire:model="weightLabels" placeholder="-60, -66, -73, -81, -90, +90" required />
                                <p class="text-[11px] text-ink/55">
                                    {{ __('Comma separated, in display order. Use + for an open upper class.') }}
                                </p>
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <span class="kicker">{{ __('Gender') }}</span>

                                {{-- A segmented control over the same wire:model
                                     the select used, so the component is
                                     untouched — only the control changed. --}}
                                <div class="flex">
                                    @foreach ([['M', __('Male')], ['F', __('Female')], ['X', __('Mixed')]] as [$value, $label])
                                        <label class="-ms-px cursor-pointer border border-n-400 px-4 py-2 text-[13px] font-bold first:ms-0
                                                      has-[:checked]:bg-ink has-[:checked]:text-ground
                                                      has-[:focus-visible]:outline has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-brand-500">
                                            <input type="radio" wire:model="gender" value="{{ $value }}" class="sr-only">
                                            {{ $label }}
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        @error('weightLabels') <p class="mb-3 text-[13px] text-danger-500">{{ $message }}</p> @enderror

                        <div class="flex gap-2.5">
                            <flux:button type="submit" variant="primary">
                                {{ $editingId ? __('Save changes') : __('Add category') }}
                            </flux:button>

                            <flux:button type="button" variant="ghost" wire:click="cancelEdit" x-on:click="open = false">
                                {{ __('Cancel') }}
                            </flux:button>
                        </div>
                    </form>
                </div>
            </div>
        @endcan

        @forelse ($ageCategories as $ageCategory)
            @php
                // An age category takes its gender from the classes inside it,
                // the same way the edit form reads it back.
                $gender = $ageCategory->weightCategories->first()->gender ?? 'X';

                [$genderLabel, $genderTag] = match ($gender) {
                    'M' => [__('Men'), 'bg-info-200 text-info-800'],
                    'F' => [__('Women'), 'bg-brand-200 text-brand-800'],
                    default => [__('Mixed'), 'bg-n-200 text-ink'],
                };
            @endphp

            <section class="border border-n-300 bg-surface shadow-elev-sm" wire:key="age-{{ $ageCategory->id }}">
                <div class="flex flex-wrap items-start justify-between gap-3.5 px-6 pb-4 pt-5">
                    <div>
                        <div class="flex items-center gap-3">
                            <h3 class="m-0 text-[25px]">{{ $ageCategory->name }}</h3>
                            <span class="kicker px-2.5 py-[3px] {{ $genderTag }}">{{ $genderLabel }}</span>
                        </div>
                        <p class="mt-1 text-[13px] text-ink/55">
                            {{ trans_choice('{0}No athletes registered|{1}:count athlete registered|[2,*]:count athletes registered', $ageCategory->athletes_count, ['count' => $ageCategory->athletes_count]) }}
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
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

                            {{-- Destructive actions are ghost buttons in red
                                 text, never a solid red block: the weight of a
                                 filled button belongs to the primary action. --}}
                            <flux:button
                                size="sm"
                                variant="ghost"
                                class="!text-danger-500 hover:!bg-danger-500/10"
                                wire:click="delete({{ $ageCategory->id }})"
                                wire:confirm="{{ __('Delete this age category?') }}"
                            >{{ __('Delete') }}</flux:button>
                        @endcan
                    </div>
                </div>

                <div class="rule-2"></div>

                {{-- The 1px gaps are the grid: cells sit on a neutral-300 ground
                     so the gutters read as rules rather than empty space. --}}
                <div class="grid gap-px bg-n-300 [grid-template-columns:repeat(auto-fit,minmax(150px,1fr))]">
                    @forelse ($ageCategory->weightCategories as $weightCategory)
                        @php $fill = min(100, (int) round($weightCategory->athletes_count / $capacity * 100)); @endphp

                        <a
                            href="{{ route('bracket.show', $weightCategory) }}"
                            wire:navigate
                            wire:key="weight-{{ $weightCategory->id }}"
                            class="block bg-surface px-4 py-3.5 no-underline hover:bg-n-200"
                        >
                            <div class="flex items-baseline justify-between gap-2">
                                <span class="text-[22px] font-bold text-ink">{{ $weightCategory->label }}</span>
                                <span class="text-xs font-bold text-info-500 tabular-nums">{{ $weightCategory->athletes_count }}</span>
                            </div>

                            <div class="mt-2.5 h-1 bg-ink/15">
                                <div class="h-1 bg-brand-500" style="width: {{ $fill }}%"></div>
                            </div>
                        </a>
                    @empty
                        <p class="bg-surface px-4 py-3.5 text-[13px] text-ink/55">{{ __('No weight classes defined.') }}</p>
                    @endforelse
                </div>
            </section>
        @empty
            <section class="border border-n-300 bg-surface px-6 py-10 text-center shadow-elev-sm">
                <p class="text-[13px] text-ink/55">
                    {{ __('No age categories yet. Add one — for example "Men Senior" with -60, -66, -73, -81, -90, +90.') }}
                </p>
            </section>
        @endforelse
    </div>
</div>
