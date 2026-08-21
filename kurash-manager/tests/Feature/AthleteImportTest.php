<?php

use App\Livewire\Competition\Registration;
use App\Models\AgeCategory;
use App\Models\Athlete;
use App\Models\User;
use App\Models\WeightCategory;
use App\Services\AthleteImporter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Registering a delegation from the workbook it arrived as.
 *
 * The files here are real spreadsheets written to disk and read back, not
 * arrays handed to the parser — the parsing is half of what can go wrong, and a
 * test that skips it would pass on a file the importer cannot open.
 */
beforeEach(function () {
    // Livewire stages an upload on a disk before the component sees it.
    Storage::fake('local');

    $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $this->actingAs($this->admin);

    $this->division = AgeCategory::factory()->create(['name' => 'Men Senior']);

    foreach (['-60' => 60, '-66' => 66, '-73' => 73] as $label => $max) {
        WeightCategory::factory()->create([
            'age_category_id' => $this->division->id,
            'label' => $label,
            'min_kg' => null,
            'max_kg' => $max,
            'gender' => 'M',
        ]);
    }

    WeightCategory::factory()->create([
        'age_category_id' => $this->division->id,
        'label' => '-57',
        'min_kg' => null,
        'max_kg' => 57,
        'gender' => 'F',
    ]);
});

/**
 * Write a real workbook and hand back an upload carrying its bytes.
 *
 * A genuine xlsx rather than an array handed straight to the parser: reading
 * the file is half of what can go wrong, and a test that skipped it would pass
 * on a file the importer cannot open.
 */
function workbook(array $rows, ?array $headings = null): UploadedFile
{
    $headings ??= AthleteImporter::TEMPLATE_HEADINGS;

    $book = new Spreadsheet;
    $book->getActiveSheet()->fromArray([$headings, ...$rows], null, 'A1');

    $path = tempnam(sys_get_temp_dir(), 'import').'.xlsx';
    (new Xlsx($book))->save($path);

    $file = UploadedFile::fake()->createWithContent('entries.xlsx', (string) file_get_contents($path));

    unlink($path);

    return $file;
}

/** One well-formed line, overridable field by field. */
function entry(array $overrides = []): array
{
    return array_values(array_replace([
        'name' => 'Rustam Kamolov',
        'noc' => 'UZB',
        'country' => 'Uzbekistan',
        'gender' => 'M',
        'weight' => '-66',
        'national_id' => '',
        'club' => '',
    ], $overrides));
}

describe('reading a file', function () {
    it('registers every valid row in one go', function () {
        $file = workbook([
            entry(['name' => 'Rustam Kamolov']),
            entry(['name' => 'Aziz Turaev', 'weight' => '-60']),
            entry(['name' => 'Bekzod Yuldashev', 'weight' => '-73', 'noc' => 'KAZ']),
        ]);

        Livewire::test(Registration::class, ['ageCategory' => $this->division])
            ->set('importFile', $file)
            ->call('previewImport')
            ->call('confirmImport');

        expect($this->division->athletes()->count())->toBe(3);

        $rustam = Athlete::where('fullname', 'Rustam Kamolov')->firstOrFail();

        expect($rustam->noc_code)->toBe('UZB')
            ->and($rustam->gender)->toBe('M')
            ->and($rustam->weightCategory->label)->toBe('-66')
            // Through the same door a typed registration uses, so the identifier
            // is minted by one rule rather than two.
            ->and($rustam->ika_id)->toStartWith('IKA');
    });

    it('writes nothing until the import is confirmed', function () {
        Livewire::test(Registration::class, ['ageCategory' => $this->division])
            ->set('importFile', workbook([entry()]))
            ->call('previewImport');

        expect($this->division->athletes()->count())->toBe(0);
    });

    it('reads the headings the system\'s own export writes', function () {
        // The legacy athlete table exports these, so a sheet taken out of the
        // system can be edited and sent back in.
        $file = workbook(
            [['Rustam Kamolov', 'IKA000001', 'UZB', 'M', '-66', 'Semi Final']],
            ["Athlete's Name", "Athlete's ID (IKA)", 'NOC', 'Gender', 'Weight Category', 'Bracket Title'],
        );

        Livewire::test(Registration::class, ['ageCategory' => $this->division])
            ->set('importFile', $file)
            ->call('previewImport')
            ->call('confirmImport');

        expect($this->division->athletes()->count())->toBe(1);
    });

    it('names the columns it could not read', function () {
        $file = workbook(
            [['Rustam Kamolov', 'UZB', 'M', '-66', 'blue']],
            ["Athlete's Name", 'NOC', 'Gender', 'Weight Category', 'Favourite Colour'],
        );

        $preview = Livewire::test(Registration::class, ['ageCategory' => $this->division])
            ->set('importFile', $file)
            ->call('previewImport')
            ->get('preview');

        expect($preview->unmappedHeadings)->toBe(['Favourite Colour'])
            ->and($preview->readyCount())->toBe(1);
    });

    it('accepts a weight class however the sheet spells it', function (string $spelling) {
        Livewire::test(Registration::class, ['ageCategory' => $this->division])
            ->set('importFile', workbook([entry(['weight' => $spelling])]))
            ->call('previewImport')
            ->call('confirmImport');

        expect(Athlete::first()?->weightCategory?->label)->toBe('-66');
    })->with(['-66', '66', '-66 kg', '66kg', ' -66 ']);

    it('accepts a gender however the sheet spells it', function (string $spelling, string $stored) {
        Livewire::test(Registration::class, ['ageCategory' => $this->division])
            ->set('importFile', workbook([entry(['gender' => $spelling, 'weight' => '-57'])]))
            ->call('previewImport')
            ->call('confirmImport');

        expect(Athlete::first()?->gender)->toBe($stored);
    })->with([
        ['F', 'F'], ['female', 'F'], ['Woman', 'F'],
    ]);

    it('skips blank lines in the middle of a file', function () {
        $file = workbook([
            entry(['name' => 'Rustam Kamolov']),
            ['', '', '', '', '', '', ''],
            entry(['name' => 'Aziz Turaev']),
        ]);

        Livewire::test(Registration::class, ['ageCategory' => $this->division])
            ->set('importFile', $file)
            ->call('previewImport')
            ->call('confirmImport');

        expect($this->division->athletes()->count())->toBe(2);
    });
});

describe('rows it refuses', function () {
    /**
     * The point of the review step: a file that is half wrong still registers
     * the half that is right, and says exactly where the rest went wrong.
     */
    it('reports the row number and the reason for each bad line', function () {
        $file = workbook([
            entry(['name' => 'Rustam Kamolov']),                    // row 2, fine
            entry(['name' => '']),                                  // row 3
            entry(['name' => 'No Nation', 'noc' => '']),            // row 4
            entry(['name' => 'Bad Nation', 'noc' => 'ZZZ']),        // row 5
            entry(['name' => 'Bad Gender', 'gender' => 'x']),       // row 6
            entry(['name' => 'Bad Class', 'weight' => '-99']),      // row 7
        ]);

        $preview = Livewire::test(Registration::class, ['ageCategory' => $this->division])
            ->set('importFile', $file)
            ->call('previewImport')
            ->get('preview');

        expect($preview->readyCount())->toBe(1)
            ->and($preview->invalidCount())->toBe(5);

        $byRow = collect($preview->rejected())->keyBy('number');

        expect($byRow[3]->reason())->toContain('No name')
            ->and($byRow[4]->reason())->toContain('No NOC code')
            ->and($byRow[5]->reason())->toContain('ZZZ')
            ->and($byRow[6]->reason())->toContain('M or F')
            ->and($byRow[7]->reason())->toContain('-99');
    });

    it('imports the good rows and leaves the bad ones out', function () {
        $file = workbook([
            entry(['name' => 'Rustam Kamolov']),
            entry(['name' => 'Bad Nation', 'noc' => 'ZZZ']),
            entry(['name' => 'Aziz Turaev', 'weight' => '-60']),
        ]);

        Livewire::test(Registration::class, ['ageCategory' => $this->division])
            ->set('importFile', $file)
            ->call('previewImport')
            ->call('confirmImport');

        expect($this->division->athletes()->pluck('fullname')->sort()->values()->all())
            ->toBe(['Aziz Turaev', 'Rustam Kamolov']);
    });

    /** A men's and a women's class sharing a label are different competitions. */
    it('refuses an athlete entered in a class of the other gender', function () {
        $preview = Livewire::test(Registration::class, ['ageCategory' => $this->division])
            ->set('importFile', workbook([entry(['gender' => 'M', 'weight' => '-57'])]))
            ->call('previewImport')
            ->get('preview');

        expect($preview->readyCount())->toBe(0)
            ->and($preview->rejected()[0]->reason())->toContain('female');
    });

    it('refuses a file it cannot read', function () {
        $file = UploadedFile::fake()->createWithContent('entries.csv', '');

        $preview = Livewire::test(Registration::class, ['ageCategory' => $this->division])
            ->set('importFile', $file)
            ->call('previewImport')
            ->get('preview');

        expect($preview->fatal)->not->toBeNull()
            ->and($preview->hasWork())->toBeFalse();
    });

    it('refuses a sheet with no name column', function () {
        $file = workbook([['UZB', 'M', '-66']], ['NOC', 'Gender', 'Weight Category']);

        $preview = Livewire::test(Registration::class, ['ageCategory' => $this->division])
            ->set('importFile', $file)
            ->call('previewImport')
            ->get('preview');

        expect($preview->fatal)->toContain('No name column');
    });

    it('refuses anything that is not a spreadsheet', function () {
        Livewire::test(Registration::class, ['ageCategory' => $this->division])
            ->set('importFile', UploadedFile::fake()->create('photo.jpg', 40, 'image/jpeg'))
            ->call('previewImport')
            ->assertHasErrors('importFile');

        expect($this->division->athletes()->count())->toBe(0);
    });
});

describe('duplicates', function () {
    it('leaves out somebody already registered in this category', function () {
        Athlete::register([
            'championship_id' => $this->division->championship_id,
            'age_category_id' => $this->division->id,
            'fullname' => 'Rustam Kamolov',
            'gender' => 'M',
            'noc_code' => 'UZB',
        ]);

        $preview = Livewire::test(Registration::class, ['ageCategory' => $this->division])
            ->set('importFile', workbook([entry(['name' => 'Rustam Kamolov'])]))
            ->call('previewImport')
            ->get('preview');

        expect($preview->duplicateCount())->toBe(1)
            ->and($preview->readyCount())->toBe(0)
            ->and($preview->rejected()[0]->reason())->toContain('Already registered');
    });

    it('leaves out somebody named twice in the same file', function () {
        $file = workbook([
            entry(['name' => 'Rustam Kamolov']),
            entry(['name' => 'rustam  kamolov']),   // same person, sloppier typing
        ]);

        Livewire::test(Registration::class, ['ageCategory' => $this->division])
            ->set('importFile', $file)
            ->call('previewImport')
            ->call('confirmImport');

        expect($this->division->athletes()->count())->toBe(1);
    });

    /** A national id is the only thing here that is genuinely unique. */
    it('matches on national id even when the name is spelled differently', function () {
        $file = workbook([
            entry(['name' => 'Rustam Kamolov', 'national_id' => 'AA123456']),
            entry(['name' => 'R. Kamolov', 'national_id' => 'aa123456']),
        ]);

        Livewire::test(Registration::class, ['ageCategory' => $this->division])
            ->set('importFile', $file)
            ->call('previewImport')
            ->call('confirmImport');

        expect($this->division->athletes()->count())->toBe(1);
    });

    /** Two people of the same name from different countries are two people. */
    it('keeps two athletes of the same name from different nations', function () {
        $file = workbook([
            entry(['name' => 'Ali Khan', 'noc' => 'UZB']),
            entry(['name' => 'Ali Khan', 'noc' => 'KAZ']),
        ]);

        Livewire::test(Registration::class, ['ageCategory' => $this->division])
            ->set('importFile', $file)
            ->call('previewImport')
            ->call('confirmImport');

        expect($this->division->athletes()->count())->toBe(2);
    });

    it('is safe to run the same file twice', function () {
        $rows = [entry(['name' => 'Rustam Kamolov']), entry(['name' => 'Aziz Turaev'])];

        foreach ([1, 2] as $attempt) {
            Livewire::test(Registration::class, ['ageCategory' => $this->division])
                ->set('importFile', workbook($rows))
                ->call('previewImport')
                ->call('confirmImport');
        }

        expect($this->division->athletes()->count())->toBe(2);
    });
});

describe('access', function () {
    it('is closed to an account that cannot change competition data', function () {
        $this->actingAs(User::factory()->official()->create());

        Livewire::test(Registration::class, ['ageCategory' => $this->division])
            ->set('importFile', workbook([entry()]))
            ->call('previewImport')
            ->assertForbidden();

        expect($this->division->athletes()->count())->toBe(0);
    });
});
