<?php

namespace App\Services;

use App\Models\AgeCategory;
use App\Models\Athlete;
use App\Models\WeightCategory;
use App\Support\Gender;
use App\Support\Import\AthleteImportPreview;
use App\Support\Import\AthleteImportRow;
use App\Support\Noc;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Registering a delegation from the spreadsheet it arrived as.
 *
 * Federations send entries as a workbook a fortnight before the event and
 * nobody is going to retype two hundred athletes into a form. The awkward part
 * is not the reading — it is that a file assembled by hand over a fortnight is
 * never quite clean, and an import that stops at the first bad row is no more
 * use than typing them in.
 *
 * So it reads in two steps. parse() touches nothing and reports what every row
 * would do; commit() writes the rows that were ready, in one transaction. An
 * official sees what is wrong before anything is saved, and a file that is half
 * wrong still registers the half that is right.
 *
 * Row numbers are the workbook's own, heading row included, because the only
 * useful thing to say about a bad row is where to find it in the file they are
 * looking at.
 */
class AthleteImporter
{
    /**
     * What the column means, and everything a federation might have called it.
     *
     * Matched loosely — case, spaces and punctuation are stripped — because the
     * alternative is refusing a file over a capital letter. The first list
     * carries the headings the system's own athlete export writes, so a sheet
     * exported from here can be edited and sent back.
     *
     * @var array<string, list<string>>
     */
    private const COLUMNS = [
        'fullname' => ['athletesname', 'fullname', 'name', 'athlete', 'athletename', 'surnamename'],
        'noc_code' => ['noc', 'noccode', 'country code', 'countrycode', 'nation', 'nationcode'],
        'noc_name' => ['country', 'nocname', 'countryname', 'nationname'],
        'gender' => ['gender', 'sex'],
        'weight' => ['weightcategory', 'weightclass', 'weight', 'category', 'class', 'kg'],
        'national_id' => ['nationalid', 'idnumber', 'passport', 'passportno', 'passportnumber', 'documentno'],
        'club' => ['club', 'team', 'society'],
        'date_of_birth' => ['dateofbirth', 'dob', 'birthdate', 'birthday', 'born', 'dateofbirthddmmyyyy', 'birth'],
    ];

    /** The headings a downloaded template carries, in order. */
    public const TEMPLATE_HEADINGS = [
        "Athlete's Name", 'NOC', 'Country', 'Gender', 'Weight Category', 'National ID', 'Club', 'Date of Birth',
    ];

    /**
     * Read a workbook and say what it would do, without doing any of it.
     */
    public function parse(string $path, AgeCategory $ageCategory): AthleteImportPreview
    {
        try {
            $sheet = IOFactory::load($path)->getActiveSheet();
            $grid = $sheet->toArray(null, true, false, false);
        } catch (Throwable $e) {
            // The message from a spreadsheet library is not something to put in
            // front of an official; that it could not be read is.
            report($e);

            return AthleteImportPreview::failed(
                __('That file could not be read. Save it as .xlsx or .csv and try again.')
            );
        }

        $grid = array_values(array_filter($grid, fn ($row) => $this->hasContent($row)));

        if ($grid === []) {
            return AthleteImportPreview::failed(__('That file has no rows in it.'));
        }

        // Safe: the empty case returned above, so there is a heading row here.
        [$map, $unmapped] = $this->mapHeadings(array_shift($grid));

        if (! isset($map['fullname'])) {
            return AthleteImportPreview::failed(__(
                'No name column found. The first row must be headings — expected: :headings',
                ['headings' => implode(', ', self::TEMPLATE_HEADINGS)]
            ));
        }

        $classes = $ageCategory->weightCategories()->get();

        // Registered already, and named in this file: both are duplicates, and
        // both have to be caught before anything is written.
        $existing = $this->existingKeys($ageCategory);
        $seen = [];

        $rows = [];
        $number = 2;   // row 1 was the headings

        foreach ($grid as $cells) {
            $rows[] = $this->readRow($cells, $map, $number++, $ageCategory, $classes, $existing, $seen);
        }

        return new AthleteImportPreview(rows: $rows, unmappedHeadings: $unmapped);
    }

    /**
     * Write the rows that were ready.
     *
     * One transaction, so a failure part way leaves no half-registered
     * delegation behind — which is the state that is genuinely expensive,
     * because nobody can tell by looking whether it finished.
     *
     * @param  list<AthleteImportRow>  $rows
     * @return int how many were registered
     */
    public function commit(AgeCategory $ageCategory, array $rows): int
    {
        $ready = array_filter($rows, fn (AthleteImportRow $row) => $row->isReady());

        if ($ready === []) {
            return 0;
        }

        return DB::transaction(function () use ($ageCategory, $ready): int {
            $registered = 0;

            foreach ($ready as $row) {
                // Through register() rather than create(), so an imported
                // athlete gets their IKA identifier by exactly the same rule a
                // typed one does. Two ways of minting an id is how a duplicate
                // eventually appears.
                Athlete::register($row->attributes + [
                    'championship_id' => $ageCategory->championship_id,
                    'age_category_id' => $ageCategory->id,
                ]);

                $registered++;
            }

            return $registered;
        });
    }

    /**
     * @param  array<int, string|null>  $cells
     * @param  array<string, int>  $map
     * @param  Collection<int, WeightCategory>  $classes
     * @param  array<string, true>  $existing
     * @param  array<string, true>  $seen
     */
    private function readRow(
        array $cells,
        array $map,
        int $number,
        AgeCategory $ageCategory,
        Collection $classes,
        array $existing,
        array &$seen,
    ): AthleteImportRow {
        $value = function (string $key) use ($cells, $map): string {
            $index = $map[$key] ?? null;

            return $index === null ? '' : trim((string) ($cells[$index] ?? ''));
        };

        $raw = [];

        foreach (array_keys(self::COLUMNS) as $key) {
            $raw[$key] = $value($key);
        }

        $row = new AthleteImportRow(number: $number, raw: $raw);

        $fullname = $raw['fullname'];
        $gender = $this->readGender($raw['gender']);
        $noc = Noc::normalise($raw['noc_code']);

        if ($fullname === '') {
            $row->fail(__('No name.'));
        }

        if ($gender === null) {
            $row->fail(__('Gender must be M or F, not ":value".', ['value' => $raw['gender']]));
        } elseif ($ageCategory->gender !== Gender::OPEN && $ageCategory->gender !== $gender) {
            // A workbook is where a whole delegation arrives at once, so a
            // file loaded into the wrong division is caught row by row rather
            // than registering a hall full of people in the wrong competition.
            $row->fail(__(':division is a :gender division.', [
                'division' => $ageCategory->name,
                'gender' => strtolower(Gender::label($ageCategory->gender)),
            ]));
        }

        if ($noc === null) {
            $row->fail(__('No NOC code.'));
        } elseif (! in_array($noc, Noc::codes(), true)) {
            // Refused rather than warned about: an unrecognised code puts
            // another country's flag beside an athlete's name on a screen in
            // front of their delegation.
            $row->fail(__('":code" is not a recognised NOC code.', ['code' => $noc]));
        }

        $class = $this->matchClass($raw['weight'], $classes);

        if ($raw['weight'] === '') {
            $row->fail(__('No weight class.'));
        } elseif ($class === null) {
            $row->fail(__('":value" is not a weight class in this category.', ['value' => $raw['weight']]));
        } elseif ($gender !== null && $class->gender !== 'X' && $class->gender !== $gender) {
            // The same rule the registration form applies, because a men's and
            // a women's class sharing a weight label are different competitions.
            $row->fail(__(':class is a :gender class.', [
                'class' => $class->exportName(),
                'gender' => strtolower($class->genderLabel()),
            ]));
        }

        /*
         | The date of birth, and the age group it puts the athlete in.
         |
         | Judged here rather than after the write, because a workbook is how a
         | whole delegation arrives and an age-group mistake in one is
         | systematic — a federation sends its juniors in the cadets' file and
         | every row is wrong the same way. Caught at parse time, the official
         | sees it before anything is registered.
         |
         | An unreadable date fails the row. A missing one does not: the column
         | is new, files prepared before it existed have no such column at all,
         | and refusing those outright would make the importer useless to
         | anybody mid-season. The athlete registers without a date and shows
         | as "Age not verified" until somebody records one, which is the same
         | position every athlete already in the database is in.
         */
        $dateOfBirth = null;

        if ($raw['date_of_birth'] !== '') {
            $dateOfBirth = $this->readDateOfBirth($raw['date_of_birth']);

            if ($dateOfBirth === null) {
                $row->fail(__('":value" is not a date this importer can read. Use YYYY-MM-DD.', [
                    'value' => $raw['date_of_birth'],
                ]));
            }
        }

        if ($dateOfBirth !== null && $gender !== null) {
            $verdict = app(AgeEligibilityPolicy::class)->check(
                dateOfBirth: $dateOfBirth,
                gender: $gender,
                ageGroup: (string) ($ageCategory->age_group ?? ''),
                competitionYear: $ageCategory->championship->competitionYear(),
                version: app(AgeEligibilityPolicy::class)->versionForChampionship($ageCategory->championship),
            );

            // A sanction is a decision taken by a named official about one
            // athlete. It cannot arrive in a spreadsheet, so a row that would
            // need one is refused here and the Chief Referee grants it on the
            // entry list afterwards.
            if (! $verdict->eligible) {
                $row->fail((string) $verdict->reason);
            }
        }

        if (! $row->isReady()) {
            return $row;
        }

        // Only now, with the row known good, is it worth asking whether it has
        // already been done.
        foreach ($this->keysFor($fullname, $noc, $raw['national_id']) as $key) {
            if (isset($existing[$key])) {
                $row->duplicate(__('Already registered in this category.'));

                return $row;
            }

            if (isset($seen[$key])) {
                $row->duplicate(__('Named more than once in this file.'));

                return $row;
            }
        }

        foreach ($this->keysFor($fullname, $noc, $raw['national_id']) as $key) {
            $seen[$key] = true;
        }

        $row->attributes = [
            'fullname' => $fullname,
            'gender' => $gender,
            'noc_code' => $noc,
            'noc_name' => $raw['noc_name'] !== '' ? $raw['noc_name'] : null,
            'national_id' => $raw['national_id'] !== '' ? $raw['national_id'] : null,
            'club' => $raw['club'] !== '' ? $raw['club'] : null,
            'date_of_birth' => $dateOfBirth?->toDateString(),
            'weight_category_id' => $class?->id,
        ];

        return $row;
    }

    /**
     * One cell, turned into a date of birth or into nothing.
     *
     * Spreadsheets are where dates go wrong. Three shapes arrive in practice
     * and all three are accepted:
     *
     *   a real Excel date cell, which reads as a serial number of days
     *   an ISO string, which is what this system's own export writes
     *   a written date, which is what somebody typing into a cell produces
     *
     * Ambiguous numeric forms are deliberately NOT guessed at: 03/04/2009 is
     * March in one country and April in another, and an importer that picks
     * one silently will eventually put an athlete in the wrong age group over
     * a slash. Those fail the row and ask for YYYY-MM-DD.
     */
    private function readDateOfBirth(string $value): ?CarbonImmutable
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // An Excel date cell read as a raw value: days since 1899-12-30.
        if (preg_match('/^\d+(\.\d+)?$/', $value) === 1) {
            $serial = (float) $value;

            // A four-digit number is a year somebody typed, not a serial —
            // 2009 as a serial would be 1905. Years are accepted on their own
            // because birth-year rules are what the age groups are stated in.
            if ($serial >= 1900 && $serial <= 2200) {
                return CarbonImmutable::create((int) $serial, 1, 1) ?: null;
            }

            if ($serial < 1 || $serial > 100000) {
                return null;
            }

            return CarbonImmutable::create(1899, 12, 30)?->addDays((int) $serial);
        }

        // Unambiguous only. Anything with slashes or dots is refused above by
        // falling through to the parse, which is why the accepted list is
        // explicit rather than left to a general date parser.
        foreach (['Y-m-d', 'd M Y', 'j M Y', 'd F Y', 'j F Y', 'Y/m/d'] as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat('!'.$format, $value);
            } catch (Throwable) {
                // Carbon throws on a value that does not fit the format rather
                // than returning false. Caught per format, because "does not
                // match this one" is the normal case for five of the six and
                // must not end the search — or the import.
                continue;
            }

            // The round trip is what does the rejecting: Carbon will happily
            // read 2009-13-45 and roll it forward, and a date that does not
            // print back exactly as it arrived was not the date it claimed.
            if ($parsed !== null && $parsed->format($format) === $value) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * The identities two records are the same person by.
     *
     * A national id when there is one — it is the only thing here that is
     * actually unique — and otherwise the name against the delegation, which
     * is what an official comparing two lists would use.
     *
     * @return list<string>
     */
    private function keysFor(string $fullname, ?string $noc, string $nationalId): array
    {
        $keys = [];

        if (trim($nationalId) !== '') {
            $keys[] = 'id:'.mb_strtolower(trim($nationalId));
        }

        $name = preg_replace('/\s+/u', ' ', mb_strtolower(trim($fullname)));
        $keys[] = 'name:'.$name.'|'.($noc ?? '');

        return $keys;
    }

    /**
     * Everyone already in this category, by the same keys.
     *
     * Scoped to the category rather than the championship: the same athlete may
     * legitimately appear in two divisions of one event, and refusing the
     * second would be refusing a real entry.
     *
     * @return array<string, true>
     */
    private function existingKeys(AgeCategory $ageCategory): array
    {
        $keys = [];

        foreach ($ageCategory->athletes()->get(['fullname', 'noc_code', 'national_id']) as $athlete) {
            foreach ($this->keysFor($athlete->fullname, $athlete->noc_code, (string) $athlete->national_id) as $key) {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    /**
     * Match a weight class however the sheet spells it — "-66", "66", "-66 kg",
     * "66kg" all mean the class labelled -66.
     *
     * @param  Collection<int, WeightCategory>  $classes
     */
    private function matchClass(string $value, Collection $classes): ?WeightCategory
    {
        $wanted = $this->normaliseLabel($value);

        if ($wanted === '') {
            return null;
        }

        return $classes->first(function (WeightCategory $class) use ($wanted): bool {
            $label = $this->normaliseLabel($class->label);

            // "66" matches "-66" but never "+66": the sign is the difference
            // between an upper bound and a lower one, so it is only dropped
            // from the sheet's side when the class itself has none.
            return $label === $wanted || ltrim($label, '-+') === $wanted && ! str_starts_with($label, '+');
        });
    }

    private function normaliseLabel(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace(['kg', 'kilos', 'kilograms', ' '], '', $value);

        return trim($value);
    }

    /** M or F, however the sheet says it. */
    private function readGender(string $value): ?string
    {
        return match (mb_strtolower(trim($value))) {
            'm', 'male', 'man', 'men', 'boy', 'boys' => 'M',
            'f', 'female', 'woman', 'women', 'girl', 'girls', 'w' => 'F',
            default => null,
        };
    }

    /**
     * Work out which column is which.
     *
     * @param  array<int, string|null>  $headings
     * @return array{0: array<string, int>, 1: list<string>}
     */
    private function mapHeadings(array $headings): array
    {
        $map = [];
        $unmapped = [];

        foreach ($headings as $index => $heading) {
            $key = $this->normaliseHeading((string) $heading);

            if ($key === '') {
                continue;
            }

            $matched = null;

            foreach (self::COLUMNS as $column => $aliases) {
                if (in_array($key, array_map(fn (string $a) => $this->normaliseHeading($a), $aliases), true)) {
                    $matched = $column;
                    break;
                }
            }

            // First column wins. A sheet carrying both "Name" and "Athlete
            // Name" is ambiguous, and quietly preferring the later one would
            // import the wrong column without saying so.
            if ($matched !== null && ! isset($map[$matched])) {
                $map[$matched] = (int) $index;
            } elseif ($matched === null) {
                $unmapped[] = trim((string) $heading);
            }
        }

        return [$map, $unmapped];
    }

    private function normaliseHeading(string $heading): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($heading));
    }

    /** @param array<int, string|null> $row */
    private function hasContent(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return true;
            }
        }

        return false;
    }
}
