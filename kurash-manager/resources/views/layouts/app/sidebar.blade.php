<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-ground text-ink">
        {{-- The Flux shell is kept for its mobile collapse behaviour; everything
             inside it is the soft sidebar: 264px, no background and no border,
             so the column floats on the page ground rather than sitting in a
             panel of its own. --}}
        <flux:sidebar sticky collapsible="mobile" class="w-[264px] gap-1 border-none bg-transparent px-3 py-4">
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
                        <span class="block text-sm font-bold leading-tight text-ink">
                            {{ config('branding.short_name') }} {{ __('Manager') }}
                        </span>
                        <span class="block truncate text-[11.5px] text-muted">
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
                $soleCategory = $current ? \App\Support\CurrentChampionship::soleCategory($current) : null;

                $registrationRoute = $soleCategory
                    ? route('athletes.index', $soleCategory)
                    : ($current ? route('championships.show', $current) : null);

                $weighInRoute = $soleCategory
                    ? route('weighin.index', $soleCategory)
                    : ($current ? route('championships.show', $current) : null);
            @endphp

            <nav class="flex flex-col gap-1">
                <div class="kicker px-3 pb-1.5 pt-2.5">{{ __('Platform') }}</div>

                <x-nav-item :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                    {{ __('Dashboard') }}
                </x-nav-item>

                <x-nav-item :href="route('championships.index')" :active="request()->routeIs('championships.index')">
                    {{ __('Championships') }}
                </x-nav-item>

                <x-nav-item :href="route('archive.index')" :active="request()->routeIs('archive.*')">
                    {{ __('Archive') }}
                </x-nav-item>

                @if ($current)
                    <div class="kicker truncate px-3 pb-1.5 pt-4">{{ Str::limit($current->title, 28) }}</div>

                    <x-nav-item :href="route('championships.show', $current)" :active="request()->routeIs('championships.show')">
                        {{ __('Categories') }}
                    </x-nav-item>

                    <x-nav-item :href="$registrationRoute" :active="request()->routeIs('athletes.*')">
                        {{ __('Athlete Registration') }}
                    </x-nav-item>

                    <x-nav-item :href="$weighInRoute" :active="request()->routeIs('weighin.*')">
                        {{ __('Weigh-in Form') }}
                    </x-nav-item>

                    <x-nav-item :href="route('entries.index', $current)" :active="request()->routeIs('entries.*')">
                        {{ __('Entries') }}
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
            <div class="flex flex-wrap gap-x-3 gap-y-1 px-3 pb-2 text-[11.5px]">
                <a href="https://github.com/hadi165/kurash-tournament-manager" target="_blank" rel="noopener"
                   class="text-muted no-underline hover:text-ink">{{ __('Repository') }}</a>
                <a href="https://laravel.com/docs" target="_blank" rel="noopener"
                   class="text-muted no-underline hover:text-ink">{{ __('Documentation') }}</a>
            </div>

            {{-- The user card is the specified design and also the account menu:
                 settings and log out have to stay reachable, so the card itself
                 is the dropdown trigger rather than a decorative block. --}}
            <flux:dropdown position="top" align="start" class="hidden lg:block">
                <button type="button" data-test="sidebar-menu-button"
                        class="flex w-full items-center gap-2.5 rounded-md bg-surface p-3 text-start shadow-chip transition-shadow hover:shadow-card">
                    <span class="grid size-8 flex-none place-items-center rounded-full bg-brand text-[13px] font-bold text-white">
                        {{ Str::upper(Str::substr(auth()->user()->name, 0, 1)) }}
                    </span>
                    <span class="min-w-0">
                        <span class="block truncate text-[13.5px] font-semibold leading-tight text-ink">{{ auth()->user()->name }}</span>
                        <span class="block truncate text-[11.5px] capitalize text-muted">{{ auth()->user()->role }}</span>
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
