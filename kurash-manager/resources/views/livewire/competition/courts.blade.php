@php
    $driverNote = $driver === 'http'
        ? __('Driver in use: :driver — live scoreboards will be contacted at the addresses below.', ['driver' => $driver])
        : __('Driver in use: :driver — no real hardware will be contacted.', ['driver' => $driver]);
@endphp

{{-- The add form is folded away until it is wanted. The Alpine state sits on
     the root, because the button that opens the form is beside the title and
     the form itself is in the content column. --}}
<div x-data="{ open: false }">
    <x-page
        :title="__('Mats and scoreboards')"
        :subtitle="$driverNote"
        :breadcrumbs="[
            ['label' => __('Championships'), 'href' => route('championships.index')],
            ['label' => $championship->title, 'href' => route('championships.show', $championship)],
            ['label' => __('Mats')],
        ]"
    >
        @can('manage-competition')
            <x-slot:aside>
                <div x-show="! open && ! @js((bool) $editingId)">
                    <flux:button variant="primary" x-on:click="open = true">{{ __('+ Add mat') }}</flux:button>
                </div>
            </x-slot:aside>
        @endcan

        <x-competition.scope :label="$this->competitionLabel()" route="courts.index" :championship="$championship" />

        <x-competition.flash />

        @can('manage-competition')
            <div x-show="open || @js((bool) $editingId)" x-cloak>
                <x-ui.card :title="$editingId ? __('Edit mat') : __('Add mat')">
                    <form wire:submit="save">
                        <div class="grid gap-[18px] md:grid-cols-[130px_1fr_1.4fr_1fr]">
                            <div class="flex flex-col gap-[7px]">
                                <label for="court-number" class="text-[12.5px] font-semibold text-muted">{{ __('Mat number') }}</label>
                                <flux:input id="court-number" wire:model="number" type="number" min="1" required />
                            </div>

                            <div class="flex flex-col gap-[7px]">
                                <label for="court-name" class="text-[12.5px] font-semibold text-muted">{{ __('Name') }}</label>
                                <flux:input id="court-name" wire:model="name" :placeholder="__('Mat A')" />
                            </div>

                            <div class="flex flex-col gap-[7px]">
                                <label for="court-url" class="text-[12.5px] font-semibold text-muted">{{ __('Scoreboard URL') }}</label>
                                <flux:input id="court-url" wire:model="scoreboard_base_url" placeholder="http://192.168.1.40" />
                            </div>

                            <div class="flex flex-col gap-[7px]">
                                <label for="court-key" class="text-[12.5px] font-semibold text-muted">{{ __('API key') }}</label>
                                <flux:input id="court-key" wire:model="scoreboard_api_key" type="password" />
                                <p class="text-xs text-muted">
                                    {{ $editingId ? __('Leave blank to keep the current key') : __('Optional') }}
                                </p>
                            </div>
                        </div>

                        <div class="mt-[22px] flex gap-2.5">
                            <flux:button type="submit" variant="primary">
                                {{ $editingId ? __('Save changes') : __('Add mat') }}
                            </flux:button>

                            <flux:button type="button" variant="ghost" wire:click="cancelEdit" x-on:click="open = false">
                                {{ __('Cancel') }}
                            </flux:button>
                        </div>
                    </form>
                </x-ui.card>
            </div>
        @endcan

        {{-- A mat is a place in a hall, not a row in a list: three or four of
             them fit on one screen as cards, and each carries its own state. --}}
        <div class="grid gap-3.5 [grid-template-columns:repeat(auto-fit,minmax(320px,1fr))]">
            @forelse ($courts as $court)
                <x-ui.card wire:key="court-{{ $court->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="grid size-11 flex-none place-items-center rounded-md bg-brand-soft text-lg font-bold text-brand-deep">
                                {{ $court->number }}
                            </span>

                            <div class="min-w-0">
                                <div class="text-[17px] font-bold leading-tight">
                                    {{ $court->name ?: __('Mat :n', ['n' => $court->number]) }}
                                </div>
                                <div class="mt-0.5 truncate font-mono text-xs text-muted">
                                    {{ $court->scoreboard_base_url ?: __('no scoreboard address') }}
                                </div>
                            </div>
                        </div>

                        <x-ui.tag :variant="$court->is_active ? 'brand' : 'muted'">
                            {{ $court->is_active ? __('Active') : __('Inactive') }}
                        </x-ui.tag>
                    </div>

                    <div class="mt-[18px] border-t border-line-soft pt-4">
                        <div class="flex items-end justify-between gap-3">
                            <div>
                                <div class="text-xl font-bold leading-none tabular-nums">{{ $court->bouts_count }}</div>
                                <div class="mt-0.5 text-xs text-muted">{{ __('Bouts assigned') }}</div>
                            </div>

                            @if ($court->bouts_count > 0)
                                <x-ui.chip wire:click="toggleBouts({{ $court->id }})">
                                    {{ $showingBoutsFor === $court->id ? __('Hide') : __('Show more') }}
                                </x-ui.chip>
                            @endif
                        </div>

                        {{-- What is standing between this mat and being
                             deleted, and the means of moving it. --}}
                        @if ($showingBoutsFor === $court->id)
                            <div class="mt-4 rounded-md border border-line bg-ground p-3.5">
                                @can('manage-competition')
                                    @if ($moveTargets->isNotEmpty())
                                        <div class="mb-3 flex flex-wrap items-center gap-2">
                                            <span class="text-[12.5px] font-semibold text-muted">{{ __('Move everything to') }}</span>

                                            <flux:select wire:model="moveTargetId" size="sm" class="w-[150px]">
                                                @foreach ($moveTargets as $target)
                                                    <flux:select.option value="{{ $target->id }}">{{ $target->label() }}</flux:select.option>
                                                @endforeach
                                            </flux:select>

                                            <flux:button
                                                size="sm"
                                                wire:click="moveAll({{ $court->id }})"
                                                wire:confirm="{{ __('Move every contest off this mat?') }}"
                                            >{{ __('Move all') }}</flux:button>
                                        </div>
                                    @else
                                        <p class="m-0 mb-3 text-[13px] text-muted">
                                            {{ __('There is no other mat to move these to. Add one first.') }}
                                        </p>
                                    @endif
                                @endcan

                                <div class="flex flex-col gap-1.5">
                                    @foreach ($assignedBouts as $bout)
                                        <div wire:key="assigned-{{ $bout->id }}" class="flex flex-wrap items-center gap-2 rounded-md bg-surface px-3 py-2">
                                            <span class="w-9 flex-none text-[13px] font-bold tabular-nums">
                                                {{ $bout->fight_number ? '#'.$bout->fight_number : '—' }}
                                            </span>

                                            <span class="min-w-0 flex-1 truncate text-[13px]">
                                                <span class="text-muted">{{ $bout->weightCategory?->ageCategory?->name }} {{ $bout->weightCategory?->label }}</span>
                                                <span class="ms-1.5">
                                                    {{ $bout->athleteA?->fullname ?? __('TBD') }}
                                                    <span class="text-muted">{{ __('v') }}</span>
                                                    {{ $bout->athleteB?->fullname ?? __('TBD') }}
                                                </span>
                                            </span>

                                            {{-- A decided contest carries where it
                                                 was fought, so moving one is
                                                 changing a record and says so. --}}
                                            @if ($bout->status === \App\Models\Bout::STATUS_COMPLETED)
                                                <x-ui.tag variant="muted">{{ __('Decided') }}</x-ui.tag>
                                            @elseif ($bout->status === \App\Models\Bout::STATUS_ON_COURT)
                                                <x-ui.tag variant="danger">{{ __('On the mat') }}</x-ui.tag>
                                            @endif

                                            @can('manage-competition')
                                                @foreach ($moveTargets as $target)
                                                    @if ($bout->status === \App\Models\Bout::STATUS_COMPLETED)
                                                        <x-ui.chip
                                                            wire:click="moveBout({{ $bout->id }}, {{ $target->id }})"
                                                            wire:confirm="{{ __('This contest has been decided. Moving it changes where the record says it was fought. Move it anyway?') }}"
                                                        >{{ __('→ :mat', ['mat' => $target->label()]) }}</x-ui.chip>
                                                    @else
                                                        <x-ui.chip wire:click="moveBout({{ $bout->id }}, {{ $target->id }})">
                                                            {{ __('→ :mat', ['mat' => $target->label()]) }}
                                                        </x-ui.chip>
                                                    @endif
                                                @endforeach
                                            @endcan
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>

                    <div class="mt-[18px] flex flex-wrap gap-2">
                        {{-- Available to viewers too: the mat screen is read-only
                             for anyone without the gate, and it is the clearest
                             live view of a contest. --}}
                        <flux:button size="sm" variant="primary" :href="route('mats.live', $court)" wire:navigate>
                            {{ __('Open mat') }}
                        </flux:button>

                        <x-ui.chip :href="route('display.scoreboard', $court)" target="_blank">{{ __('Scoreboard') }}</x-ui.chip>

                        @can('manage-competition')
                            <x-ui.chip wire:click="testConnection({{ $court->id }})">{{ __('Test') }}</x-ui.chip>
                            <x-ui.chip wire:click="toggleActive({{ $court->id }})">
                                {{ $court->is_active ? __('Deactivate') : __('Activate') }}
                            </x-ui.chip>
                            <x-ui.chip variant="ghost" wire:click="edit({{ $court->id }})" x-on:click="open = true">
                                {{ __('Edit') }}
                            </x-ui.chip>
                            <x-ui.chip
                                variant="danger"
                                wire:click="delete({{ $court->id }})"
                                wire:confirm="{{ __('Delete this mat?') }}"
                            >{{ __('Delete') }}</x-ui.chip>
                        @endcan
                    </div>
                </x-ui.card>
            @empty
                <x-ui.card class="py-10 text-center">
                    <h2 class="m-0 text-2xl">{{ __('No mats configured yet') }}</h2>
                    <p class="mt-2 text-[13.5px] text-muted">
                        {{ __('Add a mat to send bouts to it and drive its scoreboard.') }}
                    </p>
                </x-ui.card>
            @endforelse
        </div>
    </x-page>
</div>
