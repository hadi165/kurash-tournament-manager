<x-page
    :kicker="$ageCategory->championship->title"
    :title="__('Athlete Registration')"
    :subtitle="__('Each athlete gets an IKA ID on registration, independent of any passport number.')"
    :breadcrumbs="[
        ['label' => __('Championships'), 'href' => route('championships.index')],
        ['label' => $ageCategory->championship->title, 'href' => route('championships.show', $ageCategory->championship)],
        ['label' => $ageCategory->name],
    ]"
>
    <x-slot:actions>
        <span class="kicker me-1 text-ink/55">{{ __('Accreditation') }}</span>

        {{-- Cards are laid out, not tabulated, so they are PDF only. Four to a
             sheet, cut on the cell borders. --}}
        <a href="{{ route('exports.accreditation.category', $ageCategory) }}"
           class="px-2.5 py-1 text-xs font-bold text-brand-700 no-underline hover:bg-brand-500/10 dark:text-brand-400">{{ __('This category') }}</a>
        <a href="{{ route('exports.accreditation', $ageCategory->championship) }}"
           class="px-2.5 py-1 text-xs font-bold text-brand-700 no-underline hover:bg-brand-500/10 dark:text-brand-400">{{ __('Whole championship') }}</a>

        <span class="mx-1.5 h-5 w-0.5 bg-line"></span>
    </x-slot:actions>

    <x-competition.flash />

    @can('manage-competition')
        <x-ui.card>
            <form wire:submit="save">
                <h4 class="m-0 text-xl">{{ $editingId ? __('Edit athlete') : __('Register athlete') }}</h4>

                <div class="my-[18px] grid gap-4 md:grid-cols-3">
                    @foreach ([
                        ['id' => 'reg-name', 'model' => 'fullname', 'label' => __('Full name'), 'placeholder' => null, 'required' => true],
                        ['id' => 'reg-noc', 'model' => 'noc_code', 'label' => __('NOC code'), 'placeholder' => 'UZB', 'required' => true],
                        ['id' => 'reg-country', 'model' => 'noc_name', 'label' => __('Country'), 'placeholder' => __('Uzbekistan'), 'required' => false],
                    ] as $field)
                        <div class="flex flex-col gap-1.5">
                            <label for="{{ $field['id'] }}" class="kicker">{{ $field['label'] }}</label>
                            <flux:input
                                id="{{ $field['id'] }}"
                                wire:model="{{ $field['model'] }}"
                                :placeholder="$field['placeholder']"
                                :required="$field['required']"
                            />
                        </div>
                    @endforeach

                    <div class="flex flex-col gap-1.5">
                        <label for="reg-gender" class="kicker">{{ __('Gender') }}</label>
                        <flux:select id="reg-gender" wire:model="gender" required>
                            <flux:select.option value="M">{{ __('Male') }}</flux:select.option>
                            <flux:select.option value="F">{{ __('Female') }}</flux:select.option>
                        </flux:select>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label for="reg-weight" class="kicker">{{ __('Weight class') }}</label>
                        <flux:select id="reg-weight" wire:model="weight_category_id" required>
                            <flux:select.option value="">{{ __('Select…') }}</flux:select.option>
                            @foreach ($weightCategories as $weightCategory)
                                <flux:select.option value="{{ $weightCategory->id }}">{{ $weightCategory->label }} kg</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label for="reg-passport" class="kicker">{{ __('Passport / national ID') }}</label>
                        <flux:input id="reg-passport" wire:model="national_id" />
                        <p class="text-[11px] text-ink/55">{{ __('Optional') }}</p>
                    </div>
                </div>

                <div class="flex gap-2.5">
                    <flux:button type="submit" variant="primary">
                        {{ $editingId ? __('Save changes') : __('Register') }}
                    </flux:button>

                    @if ($editingId)
                        <flux:button type="button" variant="ghost" wire:click="cancelEdit">{{ __('Cancel') }}</flux:button>
                    @endif
                </div>
            </form>
        </x-ui.card>
    @endcan

    <x-ui.card flush>
        <div class="flex flex-wrap items-center justify-between gap-3 px-6 pb-4 pt-5">
            <h4 class="m-0 text-xl">
                {{ trans_choice('{0}No athletes|{1}:count athlete|[2,*]:count athletes', $athletes->count(), ['count' => $athletes->count()]) }}
            </h4>

            <flux:input
                wire:model.live.debounce.300ms="search"
                icon="magnifying-glass"
                :placeholder="__('Search name, IKA ID or NOC')"
                class="max-w-xs"
            />
        </div>

        <div class="rule-2"></div>

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
                            <td class="font-mono text-xs">{{ $athlete->ika_id }}</td>
                            <td class="font-bold">{{ $athlete->fullname }}</td>
                            <td>
                                {{-- The NOC reads as a bordered mono chip beside
                                     the flag: a code, not a word. --}}
                                <span class="inline-flex items-center gap-2">
                                    <x-flag :noc="$athlete->noc_code" :name="$athlete->noc_name" />
                                    <span class="border border-line px-1.5 py-px font-mono text-[11px]">
                                        {{ \App\Support\Noc::normalise($athlete->noc_code) }}
                                    </span>
                                </span>
                            </td>
                            <td>{{ $athlete->weightCategory?->label ?? '—' }}</td>
                            <td>
                                @if ($athlete->weighin_kg === null)
                                    <x-ui.tag variant="outline">{{ __('Not weighed') }}</x-ui.tag>
                                @elseif ($athlete->weighin_status === 'pass')
                                    <x-ui.tag variant="brand">{{ $athlete->weighin_kg }} {{ __('kg') }}</x-ui.tag>
                                @else
                                    <x-ui.tag variant="danger">{{ $athlete->weighin_kg }} {{ __('kg') }}</x-ui.tag>
                                @endif
                            </td>
                            <td class="num">{{ $athlete->draw_number ?? '—' }}</td>
                            <td>
                                @can('manage-competition')
                                    <div class="flex justify-end gap-2">
                                        <flux:button size="xs" variant="ghost" wire:click="edit({{ $athlete->id }})">{{ __('Edit') }}</flux:button>
                                        <flux:button
                                            size="xs"
                                            variant="ghost"
                                            class="!text-danger-500 hover:!bg-danger-500/10"
                                            wire:click="delete({{ $athlete->id }})"
                                            wire:confirm="{{ __('Remove this athlete?') }}"
                                        >{{ __('Remove') }}</flux:button>
                                    </div>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-8 text-center text-ink/55">
                                {{ $search !== '' ? __('No athletes match that search.') : __('No athletes registered yet.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
</x-page>
