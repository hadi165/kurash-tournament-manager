<?php

namespace App\Livewire\Competition;

use App\Models\AgeCategory;
use App\Models\Athlete;
use App\Models\Championship;
use App\Services\AthleteImporter;
use App\Support\Gender;
use App\Support\Import\AthleteImportPreview;
use App\Support\Noc;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Registration extends Component
{
    use WithFileUploads;

    /**
     * Registration is scoped to a competition, not to a division.
     *
     * The age groups a championship runs are settled when it is created, so
     * they are not a place to navigate to — they are a field on the entry.
     * "Men" means every man in the championship, whatever age group they are
     * entered in.
     */
    public Championship $championship;

    /** The competition being registered: one of the championship's genders. */
    public string $competition = Gender::MEN;

    #[Validate('required|string|max:255')]
    public string $fullname = '';

    #[Validate('required|string|max:8')]
    public string $noc_code = '';

    /**
     * The country beside the code. Filled by choosing a suggestion, but left
     * writable: a delegation occasionally enters under a name of its own.
     */
    #[Validate('nullable|string|max:255')]
    public string $noc_name = '';

    #[Validate('required|in:M,F')]
    public string $gender = 'M';

    /** Which division inside this competition — that is, which age group. */
    #[Validate('required|integer')]
    public ?int $age_category_id = null;

    #[Validate('required|integer')]
    public ?int $weight_category_id = null;

    #[Validate('nullable|string|max:255')]
    public string $national_id = '';

    public ?int $editingId = null;

    public string $search = '';

    /**
     * Which delegation the athlete list is exported for. Blank is everybody's,
     * one nation after another.
     *
     * Separate from the search box: search narrows what is on screen while
     * somebody looks for a name, and this decides what leaves the building on
     * a sheet for the hotel.
     */
    public string $exportNoc = '';

    /*
     |--------------------------------------------------------------------------
     | Importing a delegation
     |--------------------------------------------------------------------------
     |
     | Two steps on purpose. The file is read and reported on first, and nothing
     | is written until somebody has looked at what it would do — a workbook a
     | federation assembled over a fortnight is not something to find out about
     | halfway through.
     */

    /** The uploaded workbook, held only until it has been read. */
    public ?TemporaryUploadedFile $importFile = null;

    /** What that file would do. Null until one has been read. */
    public ?AthleteImportPreview $preview = null;

    /** Set once the review table is long enough to be worth collapsing. */
    public bool $showAllRows = false;

    /**
     * A workbook lists a delegation for one division. Where a competition has
     * several age groups the file has to say which, and it says it here rather
     * than in a column the federation's template does not have.
     */
    public ?int $importAgeCategoryId = null;

    public function mount(Championship $championship, string $competition): void
    {
        // The championship is the authority on which competitions exist, so a
        // competition it does not run is not a page at all.
        abort_unless(in_array($competition, $championship->configuredGenders(), true), 404);

        $this->championship = $championship;
        $this->competition = $competition;
        $this->resetToDefaultDivision();
    }

    private function resetToDefaultDivision(): void
    {
        $divisions = $this->divisions();

        $this->age_category_id = $divisions->first()?->id;
        $this->importAgeCategoryId ??= $this->age_category_id;
        $this->gender = $this->competition === Gender::OPEN ? Gender::MEN : $this->competition;
    }

    /**
     * The divisions this competition is run in — one per age group.
     *
     * @return Collection<int, AgeCategory>
     */
    public function divisions(): Collection
    {
        return $this->championship->ageCategories()
            ->where('gender', $this->competition)
            ->orderBy('sort_order')
            ->get();
    }

    /** Is the athlete's own gender a question this competition leaves open? */
    public function genderIsOpen(): bool
    {
        return $this->competition === Gender::OPEN;
    }

    /** The division currently chosen on the form, if it is one of ours. */
    private function chosenDivision(): ?AgeCategory
    {
        return $this->divisions()->firstWhere('id', $this->age_category_id);
    }

    public function edit(int $id): void
    {
        Gate::authorize('manage-competition');

        $athlete = $this->athleteQuery()->findOrFail($id);

        $this->editingId = $athlete->id;
        $this->fullname = $athlete->fullname;
        $this->noc_code = $athlete->noc_code;
        $this->noc_name = $athlete->noc_name ?? '';
        $this->gender = $athlete->gender;
        $this->age_category_id = $athlete->age_category_id;
        $this->weight_category_id = $athlete->weight_category_id;
        $this->national_id = $athlete->national_id ?? '';
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'fullname', 'noc_code', 'noc_name', 'national_id', 'weight_category_id');
        $this->resetToDefaultDivision();
        $this->resetValidation();
    }

    /** Changing age group changes which weight classes exist. */
    public function updatedAgeCategoryId(): void
    {
        $this->weight_category_id = null;
    }

    public function save(): void
    {
        Gate::authorize('manage-competition');
        $this->validate();

        // Checked against the list the suggestions come from, so what is
        // stored is a code the rest of the system can find a flag and a
        // country for. Case-insensitively: "uzb" is not a different nation.
        if (! Noc::exists($this->noc_code)) {
            $this->addError('noc_code', __('":code" is not a recognised NOC code.', [
                'code' => strtoupper(trim($this->noc_code)),
            ]));

            return;
        }

        // A competition is the page you are on, so an athlete entered here is
        // entered into it. Anything else arrived from outside the form.
        if (! $this->genderIsOpen() && $this->gender !== $this->competition) {
            $this->addError('gender', __('This is the :gender competition.', [
                'gender' => strtolower(Gender::label($this->competition)),
            ]));

            return;
        }

        // The age group must be one this championship runs in this
        // competition. Resolved through the championship rather than by id,
        // so a division from elsewhere is simply not found.
        $division = $this->chosenDivision();

        if ($division === null) {
            $this->addError('age_category_id', __('Choose an age group from this competition.'));

            return;
        }

        // The weight class must belong to THAT division. Without the check a
        // crafted form value could move an athlete into another championship.
        $weightCategory = $division->weightCategories()->find($this->weight_category_id);

        if ($weightCategory === null) {
            $this->addError('weight_category_id', __('Choose a weight class from this age group.'));

            return;
        }

        // Men's and women's classes are separate competitions even where they
        // share a weight label, so the entry has to agree with the athlete.
        // Checked against the stored class rather than the posted one: the
        // browser's copy of the form is not the authority on eligibility.
        if ($weightCategory->gender !== 'X' && $weightCategory->gender !== $this->gender) {
            $this->addError('weight_category_id', __(':class is a :gender class.', [
                'class' => $weightCategory->exportName(),
                'gender' => strtolower($weightCategory->genderLabel()),
            ]));

            return;
        }

        $attributes = [
            'age_category_id' => $division->id,
            'fullname' => $this->fullname,
            'noc_code' => strtoupper($this->noc_code),
            'noc_name' => $this->noc_name ?: null,
            'gender' => $this->gender,
            'national_id' => $this->national_id ?: null,
            'weight_category_id' => $weightCategory->id,
        ];

        if ($this->editingId !== null) {
            $athlete = $this->athleteQuery()->findOrFail($this->editingId);

            // Moving weight class after a draw would put them in a bracket they
            // were never drawn into.
            if ($athlete->weight_category_id !== $weightCategory->id && $athlete->draw_number !== null) {
                $attributes['draw_number'] = null;
                $attributes['draw_number_source'] = null;
                session()->flash('error', __('Draw number cleared — :name changed weight class and must be drawn again.', ['name' => $athlete->fullname]));
            }

            $athlete->update($attributes);
            session()->flash('status', __('Athlete updated.'));
        } else {
            $athlete = Athlete::register($attributes + [
                'championship_id' => $this->championship->id,
                'age_category_id' => $division->id,
            ]);

            session()->flash('status', __('Registered :name — IKA ID :id', ['name' => $athlete->fullname, 'id' => $athlete->ika_id]));
        }

        $this->cancelEdit();
    }

    /**
     * Read the uploaded workbook and report what it would do.
     *
     * Writes nothing. Validation of the upload itself happens here rather than
     * in rules() because the rest of that method belongs to the registration
     * form, and a failed import must not mark the form invalid.
     */
    public function previewImport(): void
    {
        Gate::authorize('manage-competition');

        $this->validateOnly('importFile', [
            'importFile' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:5120'],
        ], [
            'importFile.mimes' => __('Upload a spreadsheet — .xlsx, .xls or .csv.'),
            'importFile.max' => __('That file is larger than 5 MB.'),
        ]);

        $division = $this->importDivision();

        if ($division === null) {
            $this->addError('importFile', __('Choose which age group the file is for.'));

            return;
        }

        $this->preview = app(AthleteImporter::class)->parse(
            $this->importFile->getRealPath(),
            $division,
        );

        $this->showAllRows = false;
    }

    /**
     * Register the rows that were ready.
     *
     * The file is read again rather than trusting a preview carried in the
     * browser's payload: what is registered has to come from the workbook, not
     * from a structure a request could have edited on the way back.
     */
    public function confirmImport(): void
    {
        Gate::authorize('manage-competition');

        if ($this->importFile === null) {
            session()->flash('error', __('That file is no longer available. Upload it again.'));
            $this->cancelImport();

            return;
        }

        $division = $this->importDivision();

        if ($division === null) {
            $this->addError('importFile', __('Choose which age group the file is for.'));

            return;
        }

        $preview = app(AthleteImporter::class)->parse(
            $this->importFile->getRealPath(),
            $division,
        );

        if (! $preview->hasWork()) {
            session()->flash('error', $preview->fatal ?? __('There is nothing in that file left to import.'));
            $this->preview = $preview;

            return;
        }

        $registered = app(AthleteImporter::class)->commit($division, $preview->ready());

        $this->cancelImport();

        session()->flash('status', trans_choice(
            '{1}:count athlete registered from the file.|[2,*]:count athletes registered from the file.',
            $registered,
            ['count' => $registered],
        ));
    }

    public function cancelImport(): void
    {
        $this->reset('importFile', 'preview', 'showAllRows');
        $this->resetValidation('importFile');
    }

    /**
     * The division a workbook is being read into. Resolved through this
     * competition's own divisions, so a file cannot be aimed at one somewhere
     * else by editing the request.
     */
    private function importDivision(): ?AgeCategory
    {
        return $this->divisions()->firstWhere('id', $this->importAgeCategoryId)
            ?? $this->divisions()->first();
    }

    /**
     * A blank workbook with the headings this importer reads.
     *
     * Offered rather than documented, because the reliable way to tell somebody
     * what shape a file should be is to hand them the file.
     */
    public function downloadTemplate(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            // A byte-order mark, so a spreadsheet opening this as CSV reads the
            // headings as UTF-8 rather than as mojibake.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, AthleteImporter::TEMPLATE_HEADINGS);

            // One filled line, so the expected spelling of a weight class and a
            // gender is shown rather than described.
            fputcsv($out, [
                'Example Athlete',
                'UZB',
                'Uzbekistan',
                'M',
                $this->importDivision()?->weightCategories()->value('label') ?? '-66',
                '',
                '',
            ]);

            fclose($out);
        }, 'athlete-import-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function delete(int $id): void
    {
        Gate::authorize('manage-competition');

        $athlete = $this->athleteQuery()->findOrFail($id);

        // hasDraw(), not bouts()->exists(): a class of one is drawn by an
        // administrative placement and has no bouts at all, and removing its
        // athlete would silently unmake a decided, published class.
        if ($athlete->weightCategory?->hasDraw()) {
            session()->flash('error', __('Cannot remove: a draw already exists for :class. Delete that draw on its draw screen first, then remove the athlete and draw again.', [
                'class' => $athlete->weightCategory->label,
            ]));

            return;
        }

        $athlete->delete();
        session()->flash('status', __('Athlete removed.'));
    }

    /**
     * The nations entered in this championship, in code order.
     *
     * The whole championship rather than this competition: the hotel is
     * housing a delegation, not a weight class, and the men and the women
     * arrive on the same coach.
     *
     * @return array<string, string>
     */
    public function delegations(): array
    {
        return $this->championship->athletes()
            ->whereNotNull('noc_code')
            ->distinct()
            ->orderBy('noc_code')
            ->pluck('noc_code')
            ->mapWithKeys(function (string $code) {
                $code = Noc::normalise($code) ?? $code;

                return [$code => $code.' — '.(Noc::name($code) ?? $code)];
            })
            ->all();
    }

    /**
     * Every athlete in this competition, across its age groups.
     *
     * @return HasMany<Athlete, Championship>
     */
    private function athleteQuery(): HasMany
    {
        return $this->championship->athletes()
            ->whereIn('age_category_id', $this->divisions()->modelKeys());
    }

    public function render(): View
    {
        $athletes = $this->athleteQuery()
            ->with(['weightCategory', 'ageCategory'])
            ->when($this->search !== '', fn ($q) => $q->where(
                fn ($w) => $w->where('fullname', 'like', "%{$this->search}%")
                    ->orWhere('ika_id', 'like', "%{$this->search}%")
                    ->orWhere('noc_code', 'like', "%{$this->search}%")
            ))
            ->orderBy('fullname')
            ->get();

        return view('livewire.competition.registration', [
            'athletes' => $athletes,
            'divisions' => $this->divisions(),
            // The nations actually entered, so the chooser offers the ones
            // there is a list to make rather than all two hundred.
            'delegations' => $this->delegations(),
            // Two hundred entries, handed over once. A round trip per
            // keystroke would be slower than the answer.
            'nations' => Noc::all(),
            'weightCategories' => $this->chosenDivision()?->weightCategories()->get() ?? collect(),
        ]);
    }
}
