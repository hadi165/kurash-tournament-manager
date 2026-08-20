<x-page
    :title="__('Accounts')"
    :subtitle="__('Operators who work the competition, and scoreboard viewers who only watch one.')"
    :breadcrumbs="[
        ['label' => __('Settings'), 'href' => route('profile.edit')],
        ['label' => __('Accounts')],
    ]"
>
    <x-competition.flash />

    <x-ui.card :title="$editingId ? __('Edit account') : __('New account')">
        <form wire:submit="save">
            <div class="grid gap-[18px] md:grid-cols-2">
                <div class="flex flex-col gap-[7px]">
                    <label for="acc-name" class="text-[12.5px] font-semibold text-muted">{{ __('Name') }}</label>
                    <flux:input id="acc-name" wire:model="name" required />
                    @error('name') <p class="text-[13px] text-danger-deep">{{ $message }}</p> @enderror
                </div>

                <div class="flex flex-col gap-[7px]">
                    <label for="acc-email" class="text-[12.5px] font-semibold text-muted">{{ __('Email') }}</label>
                    <flux:input id="acc-email" wire:model="email" type="email" required />
                    @error('email') <p class="text-[13px] text-danger-deep">{{ $message }}</p> @enderror
                </div>

                <div class="flex flex-col gap-[7px]">
                    <label for="acc-role" class="text-[12.5px] font-semibold text-muted">{{ __('Account type') }}</label>
                    {{-- selected is rendered server-side rather than left to
                         Livewire to apply on boot: this field decides what an
                         account may do, and a form that shows one role while
                         the component holds another is how the wrong account
                         gets made. --}}
                    <flux:select id="acc-role" wire:model.live="role" required>
                        <flux:select.option value="official" :selected="$role === 'official'">
                            {{ __('Operator — works the competition screens') }}
                        </flux:select.option>
                        <flux:select.option value="scoreboard_viewer" :selected="$role === 'scoreboard_viewer'">
                            {{ __('Scoreboard viewer — can only watch a scoreboard') }}
                        </flux:select.option>
                    </flux:select>
                    @error('role') <p class="text-[13px] text-danger-deep">{{ $message }}</p> @enderror
                </div>

                <div class="flex flex-col gap-[7px]">
                    <label for="acc-password" class="text-[12.5px] font-semibold text-muted">{{ __('Password') }}</label>
                    <flux:input id="acc-password" wire:model="password" type="password" :required="! $editingId" />
                    <p class="text-xs text-muted">
                        {{ $editingId ? __('Leave blank to keep the current password') : __('At least 8 characters') }}
                    </p>
                    @error('password') <p class="text-[13px] text-danger-deep">{{ $message }}</p> @enderror
                </div>

                {{-- Scope is only meaningful for an account that watches, and
                     the component clears it for anything else on save. --}}
                @if ($role === 'scoreboard_viewer')
                    <div class="flex flex-col gap-[7px] md:col-span-2">
                        <label for="acc-scope" class="text-[12.5px] font-semibold text-muted">{{ __('Scoreboard scope') }}</label>
                        <flux:select id="acc-scope" wire:model="scopeChampionshipId">
                            <flux:select.option value="">{{ __('Every championship') }}</flux:select.option>
                            @foreach ($championships as $championship)
                                <flux:select.option
                                    value="{{ $championship->id }}"
                                    :selected="(string) $championship->id === (string) $scopeChampionshipId"
                                >{{ $championship->title }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <p class="text-xs text-muted">{{ __('Pin the account to one championship, or leave it open to all.') }}</p>
                    </div>
                @endif
            </div>

            <div class="mt-[22px] flex gap-2.5">
                <flux:button type="submit" variant="primary">
                    {{ $editingId ? __('Save changes') : __('Create account') }}
                </flux:button>

                @if ($editingId)
                    <flux:button type="button" variant="ghost" wire:click="cancelEdit">{{ __('Cancel') }}</flux:button>
                @endif
            </div>
        </form>
    </x-ui.card>

    <x-ui.card flush :title="__('Accounts')">
        <div class="overflow-x-auto">
            <table class="t">
                <thead>
                    <tr>
                        <th>{{ __('Name') }}</th>
                        <th>{{ __('Email') }}</th>
                        <th>{{ __('Account type') }}</th>
                        <th>{{ __('Scope') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($accounts as $account)
                        <tr wire:key="account-{{ $account->id }}">
                            <td class="font-semibold">{{ $account->name }}</td>
                            <td class="font-mono text-xs text-muted">{{ $account->email }}</td>
                            <td>
                                <x-ui.tag :variant="$account->role === 'official' ? 'info' : 'muted'">
                                    {{ $account->role === 'official' ? __('Operator') : __('Scoreboard viewer') }}
                                </x-ui.tag>
                            </td>
                            <td class="text-muted">
                                {{ $account->scoreboardChampionship?->title ?? __('All championships') }}
                            </td>
                            <td>
                                <x-ui.tag :variant="$account->is_active ? 'brand' : 'danger'">
                                    {{ $account->is_active ? __('Active') : __('Deactivated') }}
                                </x-ui.tag>
                            </td>
                            <td>
                                <div class="flex justify-end gap-1.5">
                                    <x-ui.chip variant="ghost" wire:click="edit({{ $account->id }})">{{ __('Edit') }}</x-ui.chip>

                                    <x-ui.chip
                                        :variant="$account->is_active ? 'danger' : 'soft'"
                                        wire:click="toggleActive({{ $account->id }})"
                                        wire:confirm="{{ $account->is_active
                                            ? __('Deactivate :name? They will be signed out on their next request.', ['name' => $account->name])
                                            : __('Reactivate :name?', ['name' => $account->name]) }}"
                                    >{{ $account->is_active ? __('Deactivate') : __('Reactivate') }}</x-ui.chip>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center text-muted">{{ __('No operator or scoreboard accounts yet.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
</x-page>
