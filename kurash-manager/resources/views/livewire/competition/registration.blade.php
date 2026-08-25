<x-page
    :kicker="__(App\Support\Gender::label($competition))"
    kicker-variant="info"
    :title="__('Registration')"
    :subtitle="__('Each athlete gets an IKA ID on registration, independent of any passport number.')"
    :breadcrumbs="[
        ['label' => __('Championships'), 'href' => route('championships.index')],
        ['label' => $championship->title, 'href' => route('championships.show', $championship)],
        ['label' => __('Registration')],
    ]"
>
    {{-- Cards are laid out, not tabulated, so accreditation is PDF only. Four
         to a sheet, cut on the cell borders. --}}
    <x-slot:aside>
        <span class="text-[12.5px] text-muted">{{ __('Accreditation') }}</span>
        @foreach ($divisions as $division)
            <x-ui.chip :href="route('exports.accreditation.category', $division)">{{ $division->name }}</x-ui.chip>
        @endforeach
        <x-ui.chip :href="route('exports.accreditation', $championship)">{{ __('Whole championship') }}</x-ui.chip>
    </x-slot:aside>

    <x-competition.flash />

    {{-- The list the hotel and the organising team work from. Not a
         competition document: everyone entered, by nation, whole or for one
         delegation — which is what somebody asks for when a coach arrives. --}}
    @can('manage-competition')
        @php
            $exportScope = ['championship' => $championship]
                + ($exportNoc === '' ? [] : ['noc' => $exportNoc]);
        @endphp

        <x-ui.card class="mb-[18px]">
            <div class="flex flex-wrap items-center gap-3">
                <div>
                    <div class="text-[13.5px] font-semibold">{{ __('Athlete list') }}</div>
                    <div class="text-[12.5px] text-muted">
                        {{ __('Everyone entered, ordered by country — for the hotel and the organising team.') }}
                    </div>
                </div>

                <div class="ms-auto flex flex-wrap items-center gap-2">
                    <label for="reg-export-noc" class="text-[12.5px] font-semibold text-muted">{{ __('Country') }}</label>

                    <flux:select id="reg-export-noc" wire:model.live="exportNoc" size="sm" class="w-[210px]">
                        <flux:select.option value="">{{ __('All countries') }}</flux:select.option>
                        @foreach ($delegations as $code => $label)
                            <flux:select.option value="{{ $code }}" :selected="$exportNoc === $code">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <x-ui.chip :href="route('exports.athletes', $exportScope + ['format' => 'pdf'])">{{ __('PDF') }}</x-ui.chip>
                    <x-ui.chip :href="route('exports.athletes', $exportScope + ['format' => 'xlsx'])">{{ __('Excel') }}</x-ui.chip>
                </div>
            </div>
        </x-ui.card>
    @endcan

    {{-- The entry form, and the age panel inside it.

         Open to the Chief Referee as well as to a registrar: the sanction
         under Section 25(2) is decided from this panel, and it cannot be
         decided by somebody who cannot see the date of birth and the division.
         What they may actually change is settled inside — the save button and
         the import are a registrar's, the sanction is theirs. --}}
    @if (auth()->user()?->can('manage-competition') || auth()->user()?->can('athlete.sanction_age'))
        <x-ui.card :title="$editingId ? __('Edit athlete') : __('Register athlete')">
            <form wire:submit="save">
                <div class="grid gap-[18px] md:grid-cols-3">
                    <div class="flex flex-col gap-[7px]">
                        <label for="reg-name" class="text-[12.5px] font-semibold text-muted">{{ __('Full name') }}</label>
                        <flux:input id="reg-name" wire:model="fullname" required />
                        @error('fullname')
                            <span class="text-[12.5px] text-danger">{{ $message }}</span>
                        @enderror
                    </div>

                    {{-- Two hundred codes, and getting one wrong puts another
                         country's flag beside an athlete's name on a screen in
                         front of their delegation. So the field suggests as it
                         is typed rather than waiting to complain at the end. --}}
                    <div
                        class="relative flex flex-col gap-[7px]"
                        x-data="nocSuggest({ nations: @js($nations), country: 'reg-country' })"
                        x-on:focusout="leave($event)"
                    >
                        <label for="reg-noc" class="text-[12.5px] font-semibold text-muted">{{ __('NOC code') }}</label>

                        <flux:input
                            id="reg-noc"
                            wire:model="noc_code"
                            placeholder="UZB"
                            required
                            autocomplete="off"
                            maxlength="3"
                            x-ref="code"
                            role="combobox"
                            aria-autocomplete="list"
                            aria-controls="reg-noc-list"
                            x-bind:aria-expanded="open ? 'true' : 'false'"
                            x-bind:aria-activedescendant="active >= 0 ? 'reg-noc-option-' + active : null"
                            x-on:input="search($event.target.value)"
                            x-on:keydown.arrow-down.prevent="open ? move(1) : search($refs.code.value)"
                            x-on:keydown.arrow-up.prevent="move(-1)"
                            x-on:keydown.enter="if (open) { $event.preventDefault(); choose(); }"
                            x-on:keydown.escape.stop="close()"
                        />

                        <ul
                            id="reg-noc-list"
                            role="listbox"
                            x-show="open"
                            x-cloak
                            class="absolute inset-x-0 top-full z-20 mt-1 max-h-64 list-none overflow-y-auto rounded-md
                                   border border-line bg-surface p-1 shadow-chip"
                        >
                            <template x-for="(match, index) in matches" :key="match[0]">
                                <li
                                    role="option"
                                    x-bind:id="'reg-noc-option-' + index"
                                    x-bind:aria-selected="index === active ? 'true' : 'false'"
                                    x-on:mouseenter="active = index"
                                    {{-- mousedown, not click: the field would
                                         lose focus first and take the list
                                         with it before a click ever landed. --}}
                                    x-on:mousedown.prevent="choose(index)"
                                    class="cursor-pointer rounded px-3 py-1.5 text-[13.5px]"
                                    x-bind:class="index === active ? 'bg-brand-soft text-brand-deep' : 'text-ink'"
                                >
                                    <span class="font-mono font-semibold" x-text="match[0]"></span>
                                    <span class="text-muted" x-text="' — ' + match[1]"></span>
                                </li>
                            </template>
                        </ul>

                        @error('noc_code')
                            <span class="text-[12.5px] text-danger">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="flex flex-col gap-[7px]">
                        <label for="reg-country" class="text-[12.5px] font-semibold text-muted">{{ __('Country') }}</label>
                        <flux:input id="reg-country" wire:model="noc_name" :placeholder="__('Uzbekistan')" />
                    </div>

                    {{-- The competition is the page, so there is nothing here
                         to get wrong. Only a competition declared open leaves
                         the question to the form. --}}
                    @if ($this->genderIsOpen())
                        <div class="flex flex-col gap-[7px]">
                            <label for="reg-gender" class="text-[12.5px] font-semibold text-muted">{{ __('Gender') }}</label>
                            <flux:select id="reg-gender" wire:model="gender" required>
                                <flux:select.option value="M">{{ __('Men') }}</flux:select.option>
                                <flux:select.option value="F">{{ __('Women') }}</flux:select.option>
                            </flux:select>
                            @error('gender')
                                <span class="text-[12.5px] text-danger">{{ $message }}</span>
                            @enderror
                        </div>
                    @endif

                    {{-- The age groups were settled when the championship was
                         created, so this offers those and nothing else. One
                         group needs no choice and the field stays out of the
                         way. --}}
                    @if ($divisions->count() > 1)
                        <div class="flex flex-col gap-[7px]">
                            <label for="reg-age-group" class="text-[12.5px] font-semibold text-muted">{{ __('Age group') }}</label>
                            <flux:select id="reg-age-group" wire:model.live="age_category_id" required>
                                @foreach ($divisions as $division)
                                    <flux:select.option value="{{ $division->id }}">{{ $division->age_group }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            @error('age_category_id')
                                <span class="text-[12.5px] text-danger">{{ $message }}</span>
                            @enderror
                        </div>
                    @endif

                    <div class="flex flex-col gap-[7px]">
                        <label for="reg-weight" class="text-[12.5px] font-semibold text-muted">{{ __('Weight class') }}</label>
                        <flux:select id="reg-weight" wire:model="weight_category_id" required>
                            <flux:select.option value="">{{ __('Select…') }}</flux:select.option>
                            @foreach ($weightCategories as $weightCategory)
                                <flux:select.option value="{{ $weightCategory->id }}">{{ $weightCategory->label }} {{ __('kg') }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        @error('weight_category_id')
                            <span class="text-[12.5px] text-danger">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="flex flex-col gap-[7px]">
                        <label for="reg-passport" class="text-[12.5px] font-semibold text-muted">{{ __('Passport / national ID') }}</label>
                        <flux:input id="reg-passport" wire:model="national_id" />
                        <p class="text-xs text-muted">{{ __('Optional') }}</p>
                    </div>

                    {{-- The date of birth, and what it makes of the chosen age
                         group. Bound live because the answer beneath it is the
                         whole reason the field is here: a registrar picking a
                         division wants to be told before they submit, not
                         after. --}}
                    <div class="flex flex-col gap-[7px]">
                        <label for="reg-dob" class="text-[12.5px] font-semibold text-muted">{{ __('Date of birth') }}</label>
                        <flux:input id="reg-dob" type="date" wire:model.live="date_of_birth" />
                        @error('date_of_birth')
                            <span class="text-[12.5px] text-danger">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                {{-- The eligibility panel.

                     Always shown once there is a date, because "this is fine"
                     is information too: it tells the registrar the birth year
                     was read the way they meant it. The age quoted is the
                     competition age — the year minus the birth year — which is
                     the only age the IKA's bands are stated in. --}}
                @if ($ageVerdict->state !== \App\Support\AgeVerdict::NO_DATE)
                    <div @class([
                        'mt-4 rounded-md px-[18px] py-3.5',
                        'bg-brand-soft' => $ageVerdict->eligible && $ageVerdict->judged,
                        'bg-amber-soft' => ! $ageVerdict->eligible && $ageVerdict->sanctionable,
                        'bg-danger-soft' => ! $ageVerdict->eligible && ! $ageVerdict->sanctionable,
                        'bg-ground border border-line' => $ageVerdict->eligible && ! $ageVerdict->judged,
                    ])>
                        <div class="flex flex-wrap items-center gap-3">
                            @if ($ageVerdict->eligible && $ageVerdict->judged)
                                <x-ui.tag variant="brand">{{ __('Age checked') }}</x-ui.tag>
                            @elseif ($ageVerdict->sanctionable)
                                <x-ui.tag variant="amber">{{ __('Chief Referee') }}</x-ui.tag>
                            @elseif (! $ageVerdict->judged)
                                <x-ui.tag variant="muted">{{ __('Not checked') }}</x-ui.tag>
                            @else
                                <x-ui.tag variant="danger">{{ __('Wrong age group') }}</x-ui.tag>
                            @endif

                            @if ($ageVerdict->birthYear !== null)
                                <span class="text-[13.5px]">
                                    {{ __('Born :year — :age in :competitionYear.', [
                                        'year' => $ageVerdict->birthYear,
                                        'age' => $ageVerdict->competitionAge,
                                        'competitionYear' => $competitionYear,
                                    ]) }}
                                    @if ($ageVerdict->belongsIn)
                                        {{ __('That is the :group band (:band).', [
                                            'group' => $ageVerdict->belongsIn->ageGroup,
                                            'band' => $ageVerdict->belongsIn->ageLabel(),
                                        ]) }}
                                    @endif
                                </span>
                            @endif
                        </div>

                        @if ($ageVerdict->reason)
                            <p class="mt-2 text-[13px]">{{ $ageVerdict->reason }}</p>
                        @endif

                        {{-- The sanction, and only where the rules actually
                             offer one. Section 25(2) is an exception for 16-
                             and 17-year-olds entering an adults' competition;
                             every other refusal is a refusal, so no control
                             appears for it and there is nothing to press. --}}
                        @if ($ageVerdict->sanctionable && $editingId)
                            @if ($maySanction)
                                <div class="mt-3 flex flex-wrap items-end gap-3">
                                    <div class="flex min-w-[260px] flex-1 flex-col gap-[7px]">
                                        <label for="reg-sanction" class="text-[12.5px] font-semibold text-amber-deep">
                                            {{ $ageVerdict->needsSanction()
                                                ? __('Reason for the sanction')
                                                : __('Reason for withdrawing it') }}
                                        </label>
                                        <flux:input id="reg-sanction" wire:model="sanctionReason"
                                                    :placeholder="__('Recorded against your name, with the time.')" />
                                    </div>

                                    @if ($ageVerdict->needsSanction())
                                        <flux:button size="sm" variant="primary" wire:click="grantAgeSanction" type="button">
                                            {{ __('Sanction under 25(2)') }}
                                        </flux:button>
                                    @else
                                        <flux:button size="sm" variant="danger" wire:click="revokeAgeSanction" type="button">
                                            {{ __('Withdraw sanction') }}
                                        </flux:button>
                                    @endif
                                </div>

                                @error('sanctionReason')
                                    <p class="mt-2 text-[12.5px] text-danger">{{ $message }}</p>
                                @enderror
                            @else
                                <p class="mt-2 text-[13px] text-amber-deep">
                                    {{ __('Only the Chief Referee may sanction this entry. Enter the athlete in :group instead, or ask them.', [
                                        'group' => $ageVerdict->belongsIn?->ageGroup ?? __('their own age group'),
                                    ]) }}
                                </p>
                            @endif
                        @elseif ($ageVerdict->sanctionable && ! $editingId)
                            <p class="mt-2 text-[13px] text-amber-deep">
                                {{ __('Register the athlete in their own age group first. The Chief Referee can then sanction the move.') }}
                            </p>
                        @endif

                        {{-- What was decided, and by whom. Appended to rather
                             than overwritten, so a withdrawal does not erase
                             the fact that a sanction was once in force. --}}
                        @if ($sanctionHistory->isNotEmpty())
                            <ul class="mt-3 space-y-1 text-[12.5px] text-muted">
                                @foreach ($sanctionHistory as $entry)
                                    <li wire:key="sanction-{{ $entry->id }}">
                                        <b>{{ $entry->grants() ? __('Sanctioned') : __('Withdrawn') }}</b>
                                        {{ __('by :who on :when', [
                                            'who' => $entry->actedBy?->name ?? __('a closed account'),
                                            'when' => $entry->created_at?->format('j M Y H:i'),
                                        ]) }}
                                        — {{ $entry->reason }}
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif

                <div class="mt-[22px] flex flex-wrap items-center gap-2.5">
                    {{-- Saving is the registrar's act. A Chief Referee reached
                         this form to sign for an age and has no business
                         changing a nation or a weight class, so the button is
                         not theirs — and save() refuses them anyway. --}}
                    @can('manage-competition')
                        <flux:button type="submit" variant="primary">
                            {{ $editingId ? __('Save changes') : __('Register athlete') }}
                        </flux:button>
                    @endcan

                    @if ($editingId)
                        <flux:button type="button" variant="ghost" wire:click="cancelEdit">{{ __('Cancel') }}</flux:button>
                    @endif

                    {{-- A delegation arrives as a workbook a fortnight before
                         the event, and nobody is going to retype two hundred
                         athletes into the form above. The label is a label so
                         the file input itself can stay hidden — a browser's
                         native one cannot be styled to sit beside a button. --}}
                    @unless ($editingId)
                        <span class="mx-1 h-6 w-px bg-line"></span>

                        {{-- A workbook lists one age group's delegation, and
                             the federation's template has no column saying
                             which. So it is said here, before the file is
                             read. --}}
                        @if ($divisions->count() > 1)
                            <flux:select wire:model="importAgeCategoryId" size="sm" class="w-[150px]">
                                @foreach ($divisions as $division)
                                    <flux:select.option value="{{ $division->id }}">{{ $division->age_group }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @endif

                        <label class="inline-flex cursor-pointer items-center gap-2 rounded-md border border-line bg-ground px-3.5 py-[9px]
                                      text-[13.5px] font-semibold text-ink transition-shadow hover:shadow-chip
                                      has-[:focus-visible]:outline has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-brand">
                            <input type="file" class="sr-only" accept=".xlsx,.xls,.csv"
                                   wire:model="importFile" wire:key="import-file-{{ $preview ? 'loaded' : 'empty' }}">
                            {{ __('Insert File') }}
                        </label>

                        <span wire:loading wire:target="importFile,previewImport" class="text-[13px] text-muted">
                            {{ __('Reading…') }}
                        </span>

                        <flux:button type="button" size="sm" variant="ghost" wire:click="downloadTemplate">
                            {{ __('Template') }}
                        </flux:button>
                    @endunless
                </div>

                @error('importFile')
                    <p class="mt-3 text-[13px] text-danger-deep">{{ $message }}</p>
                @enderror
            </form>
        </x-ui.card>

        {{-- The review step. Nothing has been written at this point: the file
             has been read and this is what it would do. --}}
        @if ($preview)
            <x-ui.card flush :title="__('Import preview')">
                <div class="rule-2"></div>

                @if ($preview->fatal)
                    <div class="flex flex-wrap items-center gap-3 bg-danger-100/60 px-6 py-4 dark:bg-danger-500/10">
                        <x-ui.tag variant="danger">{{ __('Cannot read') }}</x-ui.tag>
                        <span class="text-sm">{{ $preview->fatal }}</span>
                        <flux:button size="xs" variant="ghost" class="ms-auto" wire:click="cancelImport">{{ __('Close') }}</flux:button>
                    </div>
                @else
                    @php
                        $rows = $showAllRows ? $preview->rows : array_slice($preview->rows, 0, 25);
                        $hidden = count($preview->rows) - count($rows);
                    @endphp

                    <div class="flex flex-wrap items-center gap-3 px-6 py-4">
                        <x-ui.tag variant="brand">{{ trans_choice('{0}Nothing to import|{1}:count ready|[2,*]:count ready', $preview->readyCount(), ['count' => $preview->readyCount()]) }}</x-ui.tag>

                        @if ($preview->invalidCount())
                            <x-ui.tag variant="danger">{{ trans_choice('{1}:count invalid|[2,*]:count invalid', $preview->invalidCount(), ['count' => $preview->invalidCount()]) }}</x-ui.tag>
                        @endif

                        @if ($preview->duplicateCount())
                            <x-ui.tag variant="info">{{ trans_choice('{1}:count already registered|[2,*]:count already registered', $preview->duplicateCount(), ['count' => $preview->duplicateCount()]) }}</x-ui.tag>
                        @endif

                        <div class="ms-auto flex flex-wrap gap-2">
                            @if ($preview->hasWork())
                                <flux:button size="sm" variant="primary" wire:click="confirmImport">
                                    {{ __('Import :count', ['count' => $preview->readyCount()]) }}
                                </flux:button>
                            @endif

                            <flux:button size="sm" variant="ghost" wire:click="cancelImport">{{ __('Cancel') }}</flux:button>
                        </div>
                    </div>

                    @if ($preview->unmappedHeadings)
                        {{-- Named rather than ignored silently: a column nothing
                             was read from is usually a heading spelled in a way
                             the importer did not recognise, and the fix is
                             obvious once it is pointed at. --}}
                        <div class="border-t border-ink/12 px-6 py-3 text-[13px] text-muted">
                            {{ __('Columns not read: :columns', ['columns' => implode(', ', $preview->unmappedHeadings)]) }}
                        </div>
                    @endif

                    <div class="rule-2"></div>

                    <div class="overflow-x-auto">
                        <table class="t">
                            <thead>
                                <tr>
                                    <th class="num">{{ __('Row') }}</th>
                                    <th>{{ __('Name') }}</th>
                                    <th>{{ __('NOC') }}</th>
                                    <th>{{ __('Gender') }}</th>
                                    <th>{{ __('Weight class') }}</th>
                                    <th>{{ __('Result') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $row)
                                    <tr wire:key="import-row-{{ $row->number }}"
                                        @class(['bg-danger-100/40 dark:bg-danger-500/5' => $row->status === 'invalid'])>
                                        {{-- The workbook's own row number, so
                                             a bad line can be found in the file
                                             the official is looking at. --}}
                                        <td class="num font-mono text-xs text-muted">{{ $row->number }}</td>
                                        <td class="font-semibold">{{ $row->cell('fullname') ?: '—' }}</td>
                                        <td>{{ $row->cell('noc_code') ?: '—' }}</td>
                                        <td>{{ $row->cell('gender') ?: '—' }}</td>
                                        <td>{{ $row->cell('weight') ?: '—' }}</td>
                                        <td>
                                            @if ($row->isReady())
                                                <x-ui.tag variant="brand">{{ __('Ready') }}</x-ui.tag>
                                            @elseif ($row->status === 'duplicate')
                                                <span class="text-[13px] text-muted">{{ $row->reason() }}</span>
                                            @else
                                                <span class="text-[13px] text-danger-deep">{{ $row->reason() }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($hidden > 0)
                        <div class="border-t border-ink/12 px-6 py-3">
                            <flux:button size="xs" variant="ghost" wire:click="$set('showAllRows', true)">
                                {{ __('Show the remaining :count rows', ['count' => $hidden]) }}
                            </flux:button>
                        </div>
                    @endif
                @endif
            </x-ui.card>
        @endif
    @endif

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
                            <td>
                                <div class="flex justify-end gap-1.5">
                                    @if (auth()->user()?->can('manage-competition') || auth()->user()?->can('athlete.sanction_age'))
                                        <x-ui.chip variant="ghost" wire:click="edit({{ $athlete->id }})">{{ __('Open') }}</x-ui.chip>
                                    @endif

                                    @can('manage-competition')
                                        <x-ui.chip
                                            variant="danger"
                                            wire:click="delete({{ $athlete->id }})"
                                            wire:confirm="{{ __('Remove this athlete?') }}"
                                        >{{ __('Remove') }}</x-ui.chip>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center text-muted">
                                {{ $search !== '' ? __('No athletes match that search.') : __('No athletes registered yet.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
</x-page>
