<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-ground text-ink">
        {{-- The Flux shell is kept for its mobile collapse behaviour; everything
             inside it is the Modernist sidebar: 252px, a 2px right rule, and no
             padding tricks — items sit flush to the left edge. --}}
        <flux:sidebar sticky collapsible="mobile" class="w-[252px] border-e-2 border-divider bg-ground p-0">
            <div class="flex items-center gap-2.5 px-5 py-4">
                @php
                    $brandLogo = config('branding.logo');
                    $hasBrandLogo = $brandLogo && is_file(public_path($brandLogo));
                @endphp

                <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-2.5 no-underline">
                    {{-- The logo always sits on a white chip and is never
                         recoloured: the artwork is the federation's. --}}
                    <span class="flex-none bg-white p-1">
                        @if ($hasBrandLogo)
                            <img src="{{ asset($brandLogo) }}" alt="{{ config('branding.organisation') }}" class="size-[38px] object-contain">
                        @else
                            <span class="grid size-[38px] place-items-center text-[11px] font-extrabold text-brand-700">
                                {{ config('branding.short_name') }}
                            </span>
                        @endif
                    </span>

                    <span class="text-[12.5px] font-extrabold uppercase leading-[1.25] text-ink">
                        {{ config('branding.organisation') }}
                    </span>
                </a>

                <flux:sidebar.collapse class="ms-auto lg:hidden" />
            </div>

            <div class="rule-2"></div>

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

            <nav class="flex flex-col py-3">
                <div class="kicker px-5 pb-1 pt-2.5 text-ink/55">{{ __('Platform') }}</div>

                <x-nav-item :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                    {{ __('Dashboard') }}
                </x-nav-item>

                <x-nav-item :href="route('championships.index')" :active="request()->routeIs('championships.index')">
                    {{ __('Championship Management') }}
                </x-nav-item>

                <x-nav-item :href="route('archive.index')" :active="request()->routeIs('archive.*')">
                    {{ __('Archive') }}
                </x-nav-item>

                @if ($current)
                    <div class="kicker px-5 pb-1 pt-[18px] text-ink/55">{{ Str::limit($current->title, 28) }}</div>

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
                        {{ __('Results & Medal Standing') }}
                    </x-nav-item>
                @endif
            </nav>

            <flux:spacer />

            <div class="py-2">
                <a href="https://github.com/hadi165/kurash-tournament-manager" target="_blank" rel="noopener"
                   class="block px-5 py-1.5 text-[13px] font-semibold text-ink no-underline hover:bg-n-200">
                    {{ __('Repository') }}
                </a>
                <a href="https://laravel.com/docs" target="_blank" rel="noopener"
                   class="block px-5 py-1.5 text-[13px] font-semibold text-ink no-underline hover:bg-n-200">
                    {{ __('Documentation') }}
                </a>
            </div>

            <div class="rule-2"></div>

            {{-- The user row is the specified design and also the account menu:
                 settings and log out have to stay reachable, so the row itself
                 is the dropdown trigger rather than a decorative block. --}}
            <flux:dropdown position="top" align="start" class="hidden lg:block">
                <button type="button" data-test="sidebar-menu-button"
                        class="flex w-full items-center gap-2.5 px-5 py-3.5 text-start hover:bg-n-200">
                    <span class="grid size-[30px] flex-none place-items-center bg-brand-500 text-[13px] font-extrabold text-white">
                        {{ Str::upper(Str::substr(auth()->user()->name, 0, 1)) }}
                    </span>
                    <span class="min-w-0">
                        <span class="block truncate text-[13px] font-extrabold leading-tight text-ink">{{ auth()->user()->name }}</span>
                        <span class="block truncate text-[11px] capitalize text-ink/55">{{ auth()->user()->role }}</span>
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
