<?php

namespace App\Console\Commands;

use App\Models\AgeCategory;
use App\Models\Athlete;
use App\Models\Championship;
use App\Models\Court;
use App\Models\WeightCategory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Reads the original SQLite database and rebuilds it in the new schema.
 *
 * The hard part is weight categories: the old system kept them as two
 * slash-delimited strings on championsubs — corashweights ("1/2/3/4") paired
 * positionally with corashweights_text ("-66/-73/-81/-90"). Athletes referenced
 * the numeric id. This command turns each pair into a real row and remaps every
 * athlete onto it.
 *
 * Bouts are deliberately NOT imported. Match rows in the old table denormalised
 * athlete names and had no reliable forward links, so importing them would
 * carry the broken bracket across. Regenerate brackets from the drawn athletes
 * instead — for a finished event the medal record is in the exports.
 */
class ImportLegacyDatabase extends Command
{
    protected $signature = 'kurash:import-legacy
                            {path : Path to the legacy kurash.db SQLite file}
                            {--fresh : Wipe the target tables before importing}
                            {--dry-run : Report what would be imported, write nothing}';

    protected $description = 'Import championships, categories and athletes from the legacy SQLite database';

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error("No such file: {$path}");

            return self::FAILURE;
        }

        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->error('The pdo_sqlite extension is not loaded, so the legacy file cannot be read.');
            $this->line('  Install it with:  sudo apt install php8.3-sqlite3');
            $this->line('  Or run this command inside the dev container (see tools/Dockerfile.dev).');

            return self::FAILURE;
        }

        $legacy = new PDO('sqlite:'.$path);
        $legacy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $legacy->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry run — nothing will be written.');
        }

        try {
            $summary = DB::transaction(function () use ($legacy, $dryRun) {
                // --fresh applies during a dry run too: the transaction rolls
                // back either way, and it lets you preview a real --fresh
                // import instead of colliding with rows already present.
                if ($this->option('fresh')) {
                    $this->wipe();
                }

                $summary = $this->import($legacy, $dryRun);

                if ($dryRun) {
                    throw new DryRunComplete($summary);
                }

                return $summary;
            });
        } catch (DryRunComplete $e) {
            $summary = $e->summary;
        } catch (Throwable $e) {
            $this->error('Import failed and was rolled back — nothing was written.');
            $this->line('  '.$e->getMessage());

            if (str_contains($e->getMessage(), 'athletes_championship_ika_unique')) {
                $this->newLine();
                $this->warn('That IKA ID is already in the target database. Re-run with --fresh to replace the existing import.');
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(['Imported', 'Count'], collect($summary)->map(
            fn ($count, $label) => [$label, $count]
        )->values()->all());

        if (! $dryRun) {
            $this->info('Done. Regenerate brackets from the Draw screen — legacy bouts were not imported.');
        }

        return self::SUCCESS;
    }

    /**
     * DELETE rather than TRUNCATE: TRUNCATE performs an implicit commit in
     * MySQL, which would end the surrounding transaction and leave a failed
     * import half-applied. Children first, so foreign keys stay satisfied
     * throughout.
     */
    private function wipe(): void
    {
        foreach (['bout_events', 'bouts', 'athletes', 'courts', 'weight_categories', 'age_categories', 'championships'] as $table) {
            DB::table($table)->delete();
        }
    }

    /** @return array<string, int> */
    private function import(PDO $legacy, bool $dryRun): array
    {
        $counts = [
            'Championships' => 0,
            'Age categories' => 0,
            'Weight categories' => 0,
            'Athletes' => 0,
            'Courts' => 0,
            'Athletes skipped (no category match)' => 0,
        ];

        // championship id => new model
        $championships = [];
        // legacy championsub_id => AgeCategory
        $ageCategories = [];
        // "legacyChampionsubId:legacyWeightCode" => WeightCategory
        $weightCategories = [];

        foreach ($this->rows($legacy, 'SELECT * FROM champions ORDER BY id') as $row) {
            $championships[$row['id']] = Championship::create([
                'title' => $row['title'] ?: "Championship {$row['id']}",
            ]);
            $counts['Championships']++;
        }

        foreach ($this->rows($legacy, 'SELECT * FROM championsubs ORDER BY id') as $row) {
            $championship = $championships[$row['champion_id']] ?? null;

            if ($championship === null) {
                $this->warn("Age category {$row['id']} references missing championship {$row['champion_id']} — skipped.");

                continue;
            }

            $ageCategory = AgeCategory::create([
                'championship_id' => $championship->id,
                'name' => $row['subtitle'] ?: "Category {$row['id']}",
            ]);
            $ageCategories[$row['id']] = $ageCategory;
            $counts['Age categories']++;

            foreach ($this->parseWeights($row) as $sortOrder => [$code, $label]) {
                $weightCategories["{$row['id']}:{$code}"] = WeightCategory::create([
                    'age_category_id' => $ageCategory->id,
                    'label' => $label,
                    'min_kg' => $this->minKg($label),
                    'max_kg' => $this->maxKg($label),
                    'gender' => 'X',
                    'sort_order' => $sortOrder,
                ]);
                $counts['Weight categories']++;
            }
        }

        foreach ($this->rows($legacy, 'SELECT * FROM championregisterathletes ORDER BY id') as $row) {
            $ageCategory = $ageCategories[$row['championsub_id']] ?? null;

            if ($ageCategory === null) {
                $counts['Athletes skipped (no category match)']++;

                continue;
            }

            $weightCategory = $weightCategories["{$row['championsub_id']}:{$row['corashweight']}"] ?? null;

            $athlete = Athlete::create([
                'ika_id' => $row['ika_id'] ?: 'LEGACY'.str_pad((string) $row['id'], 4, '0', STR_PAD_LEFT),
                'championship_id' => $ageCategory->championship_id,
                'age_category_id' => $ageCategory->id,
                'weight_category_id' => $weightCategory?->id,
                'fullname' => $row['fullname'] ?: 'Unnamed athlete',
                'gender' => in_array($row['gender'], ['M', 'F'], true) ? $row['gender'] : 'M',
                'noc_code' => $row['noc_code'] ?: 'UNK',
                'noc_name' => $row['noc_name'],
                'national_id' => $row['nationalcode'],
                'club' => $row['lastclub'],
                'weighin_kg' => $row['weighin_value'],
                'weighin_status' => in_array($row['weighin_status'], ['pending', 'pass', 'fail'], true)
                    ? $row['weighin_status']
                    : 'pending',
                'weighin_at' => $row['weighin_datetime'] ?: null,
                'draw_number' => $row['corash_lotterynumber'] > 0 ? $row['corash_lotterynumber'] : null,
                'draw_number_source' => $row['corash_lotterynumber'] > 0 ? 'import' : null,
            ]);

            if (! $row['ika_id']) {
                $athlete->forceFill([
                    'ika_id' => Athlete::nextIkaId((int) $athlete->championship_id),
                ])->save();
            }

            $counts['Athletes']++;
        }

        if ($this->legacyHasTable($legacy, 'kurashcourts')) {
            foreach ($this->rows($legacy, 'SELECT * FROM kurashcourts ORDER BY id') as $row) {
                $championship = $championships[$row['champion_id']] ?? null;

                if ($championship === null) {
                    continue;
                }

                Court::create([
                    'championship_id' => $championship->id,
                    'number' => $row['court_number'] ?: 1,
                    'name' => $row['court_name'],
                    'scoreboard_base_url' => $row['scoreboard_api_base_url'],
                    'scoreboard_api_key' => $row['scoreboard_api_key'],
                    'is_active' => (bool) $row['is_active'],
                ]);
                $counts['Courts']++;
            }
        }

        return $counts;
    }

    /**
     * Turn the two positionally-aligned slash-strings into [code, label] pairs.
     *
     * Where the two lists differ in length — which nothing in the old schema
     * prevented — the shorter one wins and the surplus is reported rather than
     * silently mismatched.
     *
     * @param  array<string, mixed>  $sub
     * @return list<array{int|string, string}>
     */
    private function parseWeights(array $sub): array
    {
        $codes = array_values(array_filter(explode('/', (string) $sub['corashweights']), fn ($v) => $v !== ''));
        $labels = array_values(array_filter(explode('/', (string) $sub['corashweights_text']), fn ($v) => $v !== ''));

        if (count($codes) !== count($labels)) {
            $this->warn(sprintf(
                'Age category %s: %d weight codes but %d labels — importing the first %d.',
                $sub['id'], count($codes), count($labels), min(count($codes), count($labels))
            ));
        }

        $pairs = [];

        foreach ($codes as $i => $code) {
            if (! isset($labels[$i])) {
                break;
            }
            $pairs[] = [$code, trim($labels[$i])];
        }

        return $pairs;
    }

    /** "+90" means 90kg and up. */
    private function minKg(string $label): ?float
    {
        return preg_match('/^\+\s*(\d+(?:\.\d+)?)/', $label, $m) ? (float) $m[1] : null;
    }

    /** "-66" means up to 66kg. */
    private function maxKg(string $label): ?float
    {
        return preg_match('/^-?\s*(\d+(?:\.\d+)?)$/', trim($label), $m) ? (float) $m[1] : null;
    }

    /**
     * Run a read query and return its rows.
     *
     * PDO::query() is documented as returning false on failure. The connection
     * is opened in exception mode above, so that should not happen — but this
     * command writes to a live database, and `foreach (false)` is not a failure
     * mode worth leaving open in an importer.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(PDO $legacy, string $sql): array
    {
        $statement = $legacy->query($sql);

        if ($statement === false) {
            throw new RuntimeException("Legacy query failed: {$sql}");
        }

        // Normalised into string-keyed rows rather than returned raw. The
        // legacy file is a foreign database this command does not control, so
        // its result set is treated as untyped input on the way in.
        $rows = [];

        foreach ($statement as $row) {
            if (! is_array($row)) {
                continue;
            }

            $typed = [];

            foreach ($row as $column => $value) {
                $typed[(string) $column] = $value;
            }

            $rows[] = $typed;
        }

        return $rows;
    }

    private function legacyHasTable(PDO $legacy, string $table): bool
    {
        $stmt = $legacy->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);

        return (bool) $stmt->fetchColumn();
    }
}

/** Signals a successful dry run so the transaction rolls back. */
class DryRunComplete extends RuntimeException
{
    /** @param  array<string, int>  $summary */
    public function __construct(public readonly array $summary)
    {
        parent::__construct('dry run complete');
    }
}
