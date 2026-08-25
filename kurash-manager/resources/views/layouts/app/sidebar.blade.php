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

                @php
                    // A referee has no dashboard to be sent to, so the mark
                    // points at the screen they actually start from. Sending
                    // them to a 403 from the brand in the corner reads as a
                    // broken system rather than as a role.
                    $homeRoute = auth()->user()?->isReferee()
                        ? route('referee.mats')
                        : route('dashboard');
                @endphp

                <a href="{{ $homeRoute }}" wire:navigate class="flex min-w-0 items-center gap-2.5 no-underline">
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

                // Every screen under a championship splits by competition. The
                // age groups were settled when the championship was created, so
                // they are not a place to navigate to; the competition is. Each
                // item carries the split rather than making it a control to
                // find once the page is open, and a championship running one
                // competition needs no choice at all.
                $competitions = $current ? $current->configuredGenders() : [];

                // Named two ways, because two kinds of screen read it.
                // Registration and the weigh-in belong to a competition, so it
                // is a segment of their path. The rest are the championship's
                // and a competition is a way of reading them, so it is a
                // filter in the query string.
                $boundCompetition = (string) (request()->route('competition') ?? '');
                $queriedCompetition = (string) (request()->query('competition') ?? '');

                // One rule to know rather than seven.
                $itemsFor = fn (string $route, string $pattern, string $open) => collect($competitions)
                    ->map(fn (string $gender) => [
                        'label' => __(\App\Support\Gender::label($gender)),
                        'href' => route($route, ['championship' => $current, 'competition' => $gender]),
                        'active' => request()->routeIs($pattern) && $open === $gender,
                    ])
                    ->all();

                $registrationItems = $itemsFor('athletes.index', 'athletes.*', $boundCompetition);
                $weighInItems = $itemsFor('weighin.index', 'weighin.*', $boundCompetition);

                $entriesItems = $itemsFor('entries.index', 'entries.*', $queriedCompetition);
                $bracketItems = $itemsFor('brackets.index', 'brackets.*', $queriedCompetition);
                $matItems = $itemsFor('courts.index', 'courts.*', $queriedCompetition);
                $medalItems = $itemsFor('medals.index', 'medals.*', $queriedCompetition);
                $fightOrderItems = $itemsFor('fight-order.index', 'fight-order.*', $queriedCompetition);
            @endphp

            @if (auth()->user()?->isReferee())
                {{-- The referee's whole application: the mats they work and the
                     board that shows them. Rendered as its own nav rather than
                     as the full one with items hidden, because a menu whose
                     items would all refuse is not a menu. --}}
                @php
                    // The same scoped query the landing page and the scoreboard
                    // selector read. A menu built from a different rule than
                    // the one enforcing access is a menu that eventually offers
                    // a door that refuses.
                    $refereeCourts = \App\Support\AssignedCourts::for(auth()->user());
                @endphp

                <nav class="flex flex-col gap-1">
                    <div class="kicker px-4 pb-1.5 pt-2.5 !text-nav-muted">{{ __('Judging') }}</div>

                    <x-nav-item :href="route('referee.mats')" :active="request()->routeIs('referee.*')">
                        {{ __('Mats') }}
                    </x-nav-item>

                    <x-nav-item :href="route('scoreboard.index')" :active="request()->routeIs('scoreboard.*')">
                        {{ __('Score Board') }}
                    </x-nav-item>

                    @if ($refereeCourts->isEmpty())
                        {{-- An account with no mat assigned reaches nothing,
                             which is the secure default and also confusing if
                             it is left unexplained. --}}
                        <div class="px-4 pt-4 text-[11.5px] leading-relaxed text-nav-muted">
                            {{ __('No mat has been assigned to this account yet. An administrator assigns one.') }}
                        </div>
                    @else
                        <div class="kicker px-4 pb-1.5 pt-4 !text-nav-muted">{{ __('Score a mat') }}</div>

                        @foreach ($refereeCourts as $refereeCourt)
                            <x-nav-item
                                :href="route('mats.live', $refereeCourt)"
                                :active="request()->routeIs('mats.live') && request()->route('court')?->is($refereeCourt)"
                            >{{ $refereeCourt->label() }}</x-nav-item>
                        @endforeach
                    @endif
                </nav>
            @else
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

                @php
                    // Counted only for accounts that may open the screen: a
                    // badge is a promise about a list, and the query is waste
                    // for anybody the list would refuse.
                    $presentableDraws = \Illuminate\Support\Facades\Gate::allows('draw.view_published')
                        ? \App\Support\PresentableDraws::count()
                        : 0;
                @endphp

                <x-nav-item
                    :href="route('operator.draws.index')"
                    :active="request()->routeIs('operator.draws.*')"
                    :badge="$presentableDraws > 0 ? $presentableDraws : null"
                >
                    {{ __('Draws to present') }}
                </x-nav-item>

                @if ($current)
                    <div class="kicker truncate px-4 pb-1.5 pt-4 !text-nav-muted">{{ Str::limit($current->title, 28) }}</div>

                    <x-nav-item :href="route('championships.show', $current)" :active="request()->routeIs('championships.show')">
                        {{ __('Categories') }}
                    </x-nav-item>

                    @if (count($competitions) > 1)
                        <x-nav-group
                            :label="__('Athlete Registration')"
                            :items="$registrationItems"
                            :active="request()->routeIs('athletes.*')"
                        />
                    @else
                        <x-nav-item
                            :href="route('athletes.index', ['championship' => $current, 'competition' => $competitions[0] ?? 'M'])"
                            :active="request()->routeIs('athletes.*')"
                        >
                            {{ __('Athlete Registration') }}
                        </x-nav-item>
                    @endif

                    @if (count($competitions) > 1)
                        <x-nav-group
                            :label="__('Weigh-in Form')"
                            :items="$weighInItems"
                            :active="request()->routeIs('weighin.*')"
                        />
                    @else
                        <x-nav-item
                            :href="route('weighin.index', ['championship' => $current, 'competition' => $competitions[0] ?? 'M'])"
                            :active="request()->routeIs('weighin.*')"
                        >
                            {{ __('Weigh-in Form') }}
                        </x-nav-item>
                    @endif

                    @if (count($competitions) > 1)
                        <x-nav-group
                            :label="__('Entries and Draw')"
                            :items="$entriesItems"
                            :active="request()->routeIs('entries.*')"
                        />
                    @else
                        <x-nav-item :href="route('entries.index', $current)" :active="request()->routeIs('entries.*')">
                            {{ __('Entries and Draw') }}
                        </x-nav-item>
                    @endif

                    @if (count($competitions) > 1)
                        <x-nav-group
                            :label="__('Bracket')"
                            :items="$bracketItems"
                            :active="request()->routeIs('brackets.*')"
                        />
                    @else
                        <x-nav-item :href="route('brackets.index', $current)" :active="request()->routeIs('brackets.*')">
                            {{ __('Bracket') }}
                        </x-nav-item>
                    @endif

                    @if (count($competitions) > 1)
                        <x-nav-group
                            :label="__('Fight Order')"
                            :items="$fightOrderItems"
                            :active="request()->routeIs('fight-order.*')"
                        />
                    @else
                        <x-nav-item :href="route('fight-order.index', $current)" :active="request()->routeIs('fight-order.*')">
                            {{ __('Fight Order') }}
                        </x-nav-item>
                    @endif

                    @if (count($competitions) > 1)
                        <x-nav-group
                            :label="__('Mats')"
                            :items="$matItems"
                            :active="request()->routeIs('courts.*') || request()->routeIs('mats.*')"
                        />
                    @else
                        <x-nav-item :href="route('courts.index', $current)" :active="request()->routeIs('courts.*') || request()->routeIs('mats.*')">
                            {{ __('Mats') }}
                        </x-nav-item>
                    @endif

                    {{-- The specification lists Result and Medal Standing
                         separately; both are sections of this one screen. --}}
                    @if (count($competitions) > 1)
                        <x-nav-group
                            :label="__('Results and Medals')"
                            :items="$medalItems"
                            :active="request()->routeIs('medals.*')"
                        />
                    @else
                        <x-nav-item :href="route('medals.index', $current)" :active="request()->routeIs('medals.*')">
                            {{ __('Results and Medals') }}
                        </x-nav-item>
                    @endif
                @endif
            </nav>
            @endif

            <flux:spacer />

            {{-- Reachable, but out of the way. As two loose links they sat at the
                 same level as the competition workflow and were read as part of
                 it; behind one Help item they are what they are — somewhere to
                 go once, not a step in running an event. --}}
            <flux:dropdown position="top" align="start">
                <button type="button"
                        class="flex w-full items-center gap-2 rounded-full px-4 py-2 text-start text-[12.5px] font-medium text-nav-muted transition-colors hover:bg-nav-hover hover:text-nav-ink">
                    <flux:icon.question-mark-circle class="size-4" />
                    {{ __('Help') }}
                </button>

                <flux:menu>
                    <flux:menu.item href="https://github.com/hadi165/kurash-tournament-manager"
                                    target="_blank" rel="noopener" icon="code-bracket">
                        {{ __('Repository') }}
                    </flux:menu.item>

                    <flux:menu.item href="https://laravel.com/docs"
                                    target="_blank" rel="noopener" icon="book-open">
                        {{ __('Documentation') }}
                    </flux:menu.item>
                </flux:menu>
            </flux:dropdown>

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
