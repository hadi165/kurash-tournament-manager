<?php

namespace App\Livewire\Competition;

use App\Models\AgeCategory;
use App\Models\Athlete;
use App\Services\AthleteImporter;
use App\Support\Import\AthleteImportPreview;
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

    public AgeCategory $ageCategory;

    #[Validate('required|string|max:255')]
    public string $fullname = '';

    #[Validate('required|string|max:8')]
    public string $noc_code = '';

    #[Validate('nullable|string|max:255')]
    public string $noc_name = '';

    #[Validate('required|in:M,F')]
    public string $gender = 'M';

    #[Validate('required|integer')]
    public ?int $weight_category_id = null;

    #[Validate('nullable|string|max:255')]
    public string $national_id = '';

    public ?int $editingId = null;

    public string $search = '';

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

    public function mount(AgeCategory $ageCategory): void
    {
        $this->ageCategory = $ageCategory->load('championship');
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
        $this->weight_category_id = $athlete->weight_category_id;
        $this->national_id = $athlete->national_id ?? '';
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'fullname', 'noc_code', 'noc_name', 'national_id', 'weight_category_id');
        $this->gender = 'M';
        $this->resetValidation();
    }

    public function save(): void
    {
        Gate::authorize('manage-competition');
        $this->validate();

        // The weight class must belong to THIS age category. Without the check
        // a crafted form value could move an athlete into another championship.
        $weightCategory = $this->ageCategory->weightCategories()->find($this->weight_category_id);

        if ($weightCategory === null) {
            $this->addError('weight_category_id', __('Choose a weight class from this age category.'));

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
                'championship_id' => $this->ageCategory->championship_id,
                'age_category_id' => $this->ageCategory->id,
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

        $this->preview = app(AthleteImporter::class)->parse(
            $this->importFile->getRealPath(),
            $this->ageCategory,
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

        $preview = app(AthleteImporter::class)->parse(
            $this->importFile->getRealPath(),
            $this->ageCategory,
        );

        if (! $preview->hasWork()) {
            session()->flash('error', $preview->fatal ?? __('There is nothing in that file left to import.'));
            $this->preview = $preview;

            return;
        }

        $registered = app(AthleteImporter::class)->commit($this->ageCategory, $preview->ready());

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
                $this->ageCategory->weightCategories()->value('label') ?? '-66',
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

        if ($athlete->weightCategory?->bouts()->exists()) {
            session()->flash('error', __('Cannot remove: a bracket has already been drawn for :class. Delete that bracket on its draw screen first, then remove the athlete and draw again.', [
                'class' => $athlete->weightCategory->label,
            ]));

            return;
        }

        $athlete->delete();
        session()->flash('status', __('Athlete removed.'));
    }

    /** @return HasMany<Athlete, AgeCategory> */
    private function athleteQuery(): HasMany
    {
        return $this->ageCategory->athletes();
    }

    public function render(): View
    {
        $athletes = $this->athleteQuery()
            ->with('weightCategory')
            ->when($this->search !== '', fn ($q) => $q->where(
                fn ($w) => $w->where('fullname', 'like', "%{$this->search}%")
                    ->orWhere('ika_id', 'like', "%{$this->search}%")
                    ->orWhere('noc_code', 'like', "%{$this->search}%")
            ))
            ->orderBy('fullname')
            ->get();

        return view('livewire.competition.registration', [
            'athletes' => $athletes,
            'weightCategories' => $this->ageCategory->weightCategories()->get(),
        ]);
    }
}
