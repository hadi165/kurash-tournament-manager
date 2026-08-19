<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

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

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Platform')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item
                        icon="trophy"
                        :href="route('championships.index')"
                        :current="request()->routeIs('championships.index')"
                        wire:navigate
                    >
                        {{ __('Championship Management') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="archive-box" :href="route('archive.index')" :current="request()->routeIs('archive.*')" wire:navigate>
                        {{ __('Archive') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>

                @if ($current)
                    <flux:sidebar.group :heading="Str::limit($current->title, 28)" class="grid">
                        <flux:sidebar.item icon="rectangle-group" :href="route('championships.show', $current)" :current="request()->routeIs('championships.show')" wire:navigate>
                            {{ __('Categories') }}
                        </flux:sidebar.item>

                        <flux:sidebar.item icon="user-plus" :href="$registrationRoute" :current="request()->routeIs('athletes.*')" wire:navigate>
                            {{ __('Athlete Registration') }}
                        </flux:sidebar.item>

                        <flux:sidebar.item icon="scale" :href="$weighInRoute" :current="request()->routeIs('weighin.*')" wire:navigate>
                            {{ __('Weigh-in Form') }}
                        </flux:sidebar.item>

                        <flux:sidebar.item icon="chart-bar" :href="route('entries.index', $current)" :current="request()->routeIs('entries.*')" wire:navigate>
                            {{ __('Entries') }}
                        </flux:sidebar.item>

                        <flux:sidebar.item icon="list-bullet" :href="route('fight-order.index', $current)" :current="request()->routeIs('fight-order.*')" wire:navigate>
                            {{ __('Fight Order') }}
                        </flux:sidebar.item>

                        <flux:sidebar.item icon="tv" :href="route('courts.index', $current)" :current="request()->routeIs('courts.*') || request()->routeIs('mats.*')" wire:navigate>
                            {{ __('Mats') }}
                        </flux:sidebar.item>

                        {{-- The specification lists Result and Medal Standing
                             separately; both are sections of this one screen. --}}
                        <flux:sidebar.item icon="trophy" :href="route('medals.index', $current)" :current="request()->routeIs('medals.*')" wire:navigate>
                            {{ __('Results & Medal Standing') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @endif
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:sidebar.nav>
                <flux:sidebar.item icon="folder-git-2" href="https://github.com/laravel/livewire-starter-kit" target="_blank">
                    {{ __('Repository') }}
                </flux:sidebar.item>

                <flux:sidebar.item icon="book-open-text" href="https://laravel.com/docs/starter-kits#livewire" target="_blank">
                    {{ __('Documentation') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
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
