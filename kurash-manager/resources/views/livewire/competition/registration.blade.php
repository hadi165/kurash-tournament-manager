<x-page
    :kicker="$ageCategory->name"
    kicker-variant="info"
    :title="__('Registration')"
    :subtitle="__('Each athlete gets an IKA ID on registration, independent of any passport number.')"
    :breadcrumbs="[
        ['label' => __('Championships'), 'href' => route('championships.index')],
        ['label' => $ageCategory->championship->title, 'href' => route('championships.show', $ageCategory->championship)],
        ['label' => __('Registration')],
    ]"
>
    {{-- Cards are laid out, not tabulated, so accreditation is PDF only. Four
         to a sheet, cut on the cell borders. --}}
    <x-slot:aside>
        <span class="text-[12.5px] text-muted">{{ __('Accreditation') }}</span>
        <x-ui.chip :href="route('exports.accreditation.category', $ageCategory)">{{ __('This category') }}</x-ui.chip>
        <x-ui.chip :href="route('exports.accreditation', $ageCategory->championship)">{{ __('Whole championship') }}</x-ui.chip>
    </x-slot:aside>

    <x-competition.flash />

    @can('manage-competition')
        <x-ui.card :title="$editingId ? __('Edit athlete') : __('Register athlete')">
            <form wire:submit="save">
                <div class="grid gap-[18px] md:grid-cols-3">
                    @foreach ([
                        ['id' => 'reg-name', 'model' => 'fullname', 'label' => __('Full name'), 'placeholder' => null, 'required' => true],
                        ['id' => 'reg-noc', 'model' => 'noc_code', 'label' => __('NOC code'), 'placeholder' => 'UZB', 'required' => true],
                        ['id' => 'reg-country', 'model' => 'noc_name', 'label' => __('Country'), 'placeholder' => __('Uzbekistan'), 'required' => false],
                    ] as $field)
                        <div class="flex flex-col gap-[7px]">
                            <label for="{{ $field['id'] }}" class="text-[12.5px] font-semibold text-muted">{{ $field['label'] }}</label>
                            <flux:input
                                id="{{ $field['id'] }}"
                                wire:model="{{ $field['model'] }}"
                                :placeholder="$field['placeholder']"
                                :required="$field['required']"
                            />
                        </div>
                    @endforeach

                    <div class="flex flex-col gap-[7px]">
                        <label for="reg-gender" class="text-[12.5px] font-semibold text-muted">{{ __('Gender') }}</label>
                        <flux:select id="reg-gender" wire:model="gender" required>
                            <flux:select.option value="M">{{ __('Male') }}</flux:select.option>
                            <flux:select.option value="F">{{ __('Female') }}</flux:select.option>
                        </flux:select>
                    </div>

                    <div class="flex flex-col gap-[7px]">
                        <label for="reg-weight" class="text-[12.5px] font-semibold text-muted">{{ __('Weight class') }}</label>
                        <flux:select id="reg-weight" wire:model="weight_category_id" required>
                            <flux:select.option value="">{{ __('Select…') }}</flux:select.option>
                            @foreach ($weightCategories as $weightCategory)
                                <flux:select.option value="{{ $weightCategory->id }}">{{ $weightCategory->label }} {{ __('kg') }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    <div class="flex flex-col gap-[7px]">
                        <label for="reg-passport" class="text-[12.5px] font-semibold text-muted">{{ __('Passport / national ID') }}</label>
                        <flux:input id="reg-passport" wire:model="national_id" />
                        <p class="text-xs text-muted">{{ __('Optional') }}</p>
                    </div>
                </div>

                <div class="mt-[22px] flex gap-2.5">
                    <flux:button type="submit" variant="primary">
                        {{ $editingId ? __('Save changes') : __('Register athlete') }}
                    </flux:button>

                    @if ($editingId)
                        <flux:button type="button" variant="ghost" wire:click="cancelEdit">{{ __('Cancel') }}</flux:button>
                    @endif
                </div>
            </form>
        </x-ui.card>
    @endcan

    <x-ui.card
        flush
        :title="trans_choice('{0}No athletes|{1}:count athlete|[2,*]:count athletes', $athletes->count(), ['count' => $athletes->count()])"
    >
        <x-slot:head>
            {{-- The search field is a pill: it is the one control on the card
                 that is asked a question rather than filled in. --}}
            <flux:input
                wire:model.live.debounce.300ms="search"
                icon="magnifying-glass"
                :placeholder="__('Search name, IKA ID or NOC')"
                class="w-[280px] max-w-full"
                class:input="!rounded-full"
            />
        </x-slot:head>

        <div class="overflow-x-auto">
            <table class="t">
                <thead>
                    <tr>
                        <th>{{ __('IKA ID') }}</th>
                        <th>{{ __('Name') }}</th>
                        <th>{{ __('NOC') }}</th>
                        <th>{{ __('Weight') }}</th>
                        <th>{{ __('Weigh-in') }}</th>
                        <th class="num">{{ __('Draw') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($athletes as $athlete)
                        <tr wire:key="athlete-{{ $athlete->id }}">
                            <td class="font-mono text-xs text-muted">{{ $athlete->ika_id }}</td>
                            <td class="font-semibold">{{ $athlete->fullname }}</td>
                            <td>
                                {{-- The NOC reads as a bordered mono chip beside
                                     the flag: a code, not a word. --}}
                                <span class="inline-flex items-center gap-2">
                                    <x-flag :noc="$athlete->noc_code" :name="$athlete->noc_name" />
                                    <span class="rounded-sm border border-line bg-ground px-2 py-0.5 font-mono text-[11.5px]">
                                        {{ \App\Support\Noc::normalise($athlete->noc_code) }}
                                    </span>
                                    <span class="text-[12.5px] text-muted">{{ $athlete->noc_name }}</span>
                                </span>
                            </td>
                            <td>{{ $athlete->weightCategory?->label ?? '—' }}</td>
                            <td>
                                @if ($athlete->weighin_kg === null)
                                    <x-ui.tag>{{ __('Not weighed') }}</x-ui.tag>
                                @elseif ($athlete->weighin_status === 'pass')
                                    <x-ui.tag variant="brand">{{ $athlete->weighin_kg }} {{ __('kg') }}</x-ui.tag>
                                @else
                                    <x-ui.tag variant="danger">{{ $athlete->weighin_kg }} {{ __('kg') }}</x-ui.tag>
                                @endif
                            </td>
                            <td class="num">{{ $athlete->draw_number ?? '—' }}</td>
                            <td>
                                @can('manage-competition')
                                    <div class="flex justify-end gap-1.5">
                                        <x-ui.chip variant="ghost" wire:click="edit({{ $athlete->id }})">{{ __('Edit') }}</x-ui.chip>
                                        <x-ui.chip
                                            variant="danger"
                                            wire:click="delete({{ $athlete->id }})"
                                            wire:confirm="{{ __('Remove this athlete?') }}"
                                        >{{ __('Remove') }}</x-ui.chip>
                                    </div>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-8 text-center text-muted">
                                {{ $search !== '' ? __('No athletes match that search.') : __('No athletes registered yet.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
</x-page>
