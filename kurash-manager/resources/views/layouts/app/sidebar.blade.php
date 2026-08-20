<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-ground text-ink">
        {{-- The Flux shell is kept for its mobile collapse behaviour; everything
             inside it is the drawer: 264px of its own surface, rounded on the
             outer corners only, and carrying its own ink and greys because
             they were measured against this fill rather than the page's. --}}
        <flux:sidebar sticky collapsible="mobile"
                      class="sticky top-0 h-screen w-[264px] gap-1 overflow-y-auto rounded-e-[18px] border-0 border-e border-nav-line bg-nav-bg px-3 py-4 text-nav-ink">
            <div class="flex items-center gap-2.5 px-2.5 pb-3.5 pt-2">
                @php
                    $brandLogo = config('branding.logo');
                    $hasBrandLogo = $brandLogo && is_file(public_path($brandLogo));
                @endphp

                <a href="{{ route('dashboard') }}" wire:navigate class="flex min-w-0 items-center gap-2.5 no-underline">
                    {{-- The logo always sits on a white chip and is never
                         recoloured: the artwork is the federation's. --}}
                    <span class="flex-none rounded-md bg-white p-[5px] shadow-chip">
                        @if ($hasBrandLogo)
                            <img src="{{ asset($brandLogo) }}" alt="{{ config('branding.organisation') }}" class="block size-8 object-contain">
                        @else
                            <span class="grid size-8 place-items-center text-[11px] font-bold text-brand-deep">
                                {{ config('branding.short_name') }}
                            </span>
                        @endif
                    </span>

                    <span class="min-w-0">
                        <span class="block text-sm font-bold leading-tight text-nav-ink">
                            {{ config('branding.short_name') }} {{ __('Manager') }}
                        </span>
                        <span class="block truncate text-[11.5px] text-nav-muted">
                            {{ config('branding.organisation') }}
                        </span>
                    </span>
                </a>

                <flux:sidebar.collapse class="ms-auto lg:hidden" />
            </div>

            @php
                // Specification §2.2 asks for one consistent left sidebar carrying
                // the competition workflow in order. Most of those screens only
                // exist inside a championship, so the scoped group appears once one
                // is in view and the top-level items stay put either way.
                $current = \App\Support\CurrentChampionship::resolve();

                // Registration and the weigh-in form both work on one age
                // category. One category — most championships — goes straight
                // there; several open as a group listing them, because the
                // choice is the thing standing between the click and the
                // screen.
                $ageCategories = $current ? $current->ageCategories()->get() : collect();
                $soleCategory = $ageCategories->count() === 1 ? $ageCategories->first() : null;

                // Which one is open is read from the bound route model rather
                // than from the id in the URL, so it cannot go stale if the
                // route ever changes shape.
                $boundCategory = request()->route('ageCategory');
                $boundCategory = $boundCategory instanceof \App\Models\AgeCategory ? $boundCategory : null;

                $categoryItems = fn (string $route) => $ageCategories
                    ->map(fn ($category) => [
                        'label' => $category->name,
                        'href' => route($route, $category),
                        'active' => $boundCategory?->is($category) ?? false,
                    ])
                    ->all();
            @endphp

            <nav class="flex flex-col gap-1">
                <div class="kicker px-4 pb-1.5 pt-2.5 !text-nav-muted">{{ __('Platform') }}</div>

                <x-nav-item :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                    {{ __('Dashboard') }}
                </x-nav-item>

                <x-nav-item :href="route('championships.index')" :active="request()->routeIs('championships.index')">
                    {{ __('Championships') }}
                </x-nav-item>

                <x-nav-item :href="route('archive.index')" :active="request()->routeIs('archive.*')">
                    {{ __('Archive') }}
                </x-nav-item>

                <x-nav-item :href="route('operator.draws.index')" :active="request()->routeIs('operator.draws.*')">
                    {{ __('Draws to present') }}
                </x-nav-item>

                @if ($current)
                    <div class="kicker truncate px-4 pb-1.5 pt-4 !text-nav-muted">{{ Str::limit($current->title, 28) }}</div>

                    <x-nav-item :href="route('championships.show', $current)" :active="request()->routeIs('championships.show')">
                        {{ __('Categories') }}
                    </x-nav-item>

                    @if ($soleCategory)
                        <x-nav-item :href="route('athletes.index', $soleCategory)" :active="request()->routeIs('athletes.*')">
                            {{ __('Athlete Registration') }}
                        </x-nav-item>

                        <x-nav-item :href="route('weighin.index', $soleCategory)" :active="request()->routeIs('weighin.*')">
                            {{ __('Weigh-in Form') }}
                        </x-nav-item>
                    @elseif ($ageCategories->isNotEmpty())
                        <x-nav-group
                            :label="__('Athlete Registration')"
                            :items="$categoryItems('athletes.index')"
                            :active="request()->routeIs('athletes.*')"
                        />

                        <x-nav-group
                            :label="__('Weigh-in Form')"
                            :items="$categoryItems('weighin.index')"
                            :active="request()->routeIs('weighin.*')"
                        />
                    @endif

                    <x-nav-item :href="route('entries.index', $current)" :active="request()->routeIs('entries.*')">
                        {{ __('Entries and Draw') }}
                    </x-nav-item>

                    <x-nav-item :href="route('fight-order.index', $current)" :active="request()->routeIs('fight-order.*')">
                        {{ __('Fight Order') }}
                    </x-nav-item>

                    <x-nav-item :href="route('courts.index', $current)" :active="request()->routeIs('courts.*') || request()->routeIs('mats.*')">
                        {{ __('Mats') }}
                    </x-nav-item>

                    {{-- The specification lists Result and Medal Standing
                         separately; both are sections of this one screen. --}}
                    <x-nav-item :href="route('medals.index', $current)" :active="request()->routeIs('medals.*')">
                        {{ __('Results & Medals') }}
                    </x-nav-item>
                @endif
            </nav>

            <flux:spacer />

            {{-- Not in the design, but the links have to stay reachable, so they
                 sit as quiet meta text rather than as nav items competing with
                 the workflow above. --}}
            <div class="flex flex-wrap gap-x-3 gap-y-1 px-4 pb-2 text-[11.5px]">
                <a href="https://github.com/hadi165/kurash-tournament-manager" target="_blank" rel="noopener"
                   class="text-nav-muted no-underline hover:text-nav-ink">{{ __('Repository') }}</a>
                <a href="https://laravel.com/docs" target="_blank" rel="noopener"
                   class="text-nav-muted no-underline hover:text-nav-ink">{{ __('Documentation') }}</a>
            </div>

            {{-- The user card is the specified design and also the account menu:
                 settings and log out have to stay reachable, so the card itself
                 is the dropdown trigger rather than a decorative block. --}}
            <flux:dropdown position="top" align="start" class="hidden lg:block">
                <button type="button" data-test="sidebar-menu-button"
                        class="flex w-full items-center gap-2.5 rounded-md bg-nav-card p-3 text-start shadow-chip transition-shadow hover:shadow-card">
                    <span class="grid size-8 flex-none place-items-center rounded-full bg-brand text-[13px] font-bold text-white">
                        {{ Str::upper(Str::substr(auth()->user()->name, 0, 1)) }}
                    </span>
                    <span class="min-w-0">
                        <span class="block truncate text-[13.5px] font-semibold leading-tight text-nav-ink">{{ auth()->user()->name }}</span>
                        <span class="block truncate text-[11.5px] capitalize text-nav-muted">{{ str_replace('_', ' ', auth()->user()->role) }}</span>
                    </span>
                </button>

                <flux:menu>
                    <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                        {{ __('Settings') }}
                    </flux:menu.item>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full cursor-pointer">
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
