<?php

namespace App\Console\Commands;

use App\Models\AgeCategory;
use App\Models\Athlete;
use App\Models\Bout;
use App\Models\Championship;
use App\Models\Court;
use App\Models\User;
use App\Models\WeightCategory;
use App\Services\BoutAdvancer;
use App\Services\BoutScorer;
use App\Services\DrawGenerator;
use App\Services\FightOrderScheduler;
use App\Support\DemoRoster;
use App\Support\TournamentFormat;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Builds a complete championship to look at.
 *
 * Every screen in this application is only as convincing as the data behind
 * it — an empty bracket and a medal table of nobody say nothing about whether
 * the thing works. This fills a championship the way a real one fills: every
 * weight class entered, weighed, drawn and part-fought, with a couple of
 * contests live on mats so the mat screen and the venue displays have
 * something to show.
 *
 * Nothing here is a fixture the tests depend on. It exists to be demonstrated
 * and thrown away.
 */
class SeedDemoChampionship extends Command
{
    protected $signature = 'kurash:demo
                            {--title=Asian Kurash Championship 2026 : Championship name}
                            {--location=Tashkent, Uzbekistan : Where it is held}
                            {--per-class=14 : Athletes entered in each weight class}
                            {--mats=4 : How many mats}
                            {--stage=running : registered, weighed, drawn, running or finished}
                            {--fresh : Delete any existing championship with this title first}
                            {--fresh-all : Delete every championship in the database first}
                            {--small-classes=3 : Weight classes given a field of 2-5, which the IKA rule runs as a round robin}
                            {--reject-rate=8 : One athlete in this many is entered wrongly, on age or on weight}';

    protected $description = 'Fill a championship with demonstration data';

    private const STAGES = ['registered', 'weighed', 'drawn', 'running', 'finished'];

    /** Senior weight classes, as the federation lists them. */
    private const CLASSES = [
        'M' => ['-60', '-66', '-73', '-81', '-90', '+90'],
        'F' => ['-48', '-52', '-57', '-63', '-70', '+70'],
    ];

    public function handle(): int
    {
        $stage = (string) $this->option('stage');

        if (! in_array($stage, self::STAGES, true)) {
            $this->error('Unknown stage. Use one of: '.implode(', ', self::STAGES));

            return self::FAILURE;
        }

        $title = (string) $this->option('title');
        $perClass = max(2, (int) $this->option('per-class'));

        if ($this->option('fresh-all')) {
            $this->deleteAll();
        } elseif ($this->option('fresh')) {
            $this->deleteExisting($title);
        }

        if (Championship::where('title', $title)->exists()) {
            $this->error("A championship called \"{$title}\" already exists. Pass --fresh to replace it.");

            return self::FAILURE;
        }

        $championship = Championship::create([
            'title' => $title,
            'location' => (string) $this->option('location'),
            'starts_on' => now()->subDays(2)->toDateString(),
            'ends_on' => now()->addDay()->toDateString(),
            'genders' => ['M', 'F'],
            'age_groups' => ['Senior'],
        ]);

        $this->info("Building “{$title}”…");

        $categories = $this->buildCategories($championship);
        $this->line(sprintf('  %d weight classes', $categories->count()));

        $entry = $this->register($championship, $categories, $perClass);
        $this->line(sprintf('  %d athletes registered', $entry['entered']));
        $this->line(sprintf('  %d class(es) given a round-robin field of 2-5', $entry['small']));
        $this->line(sprintf('  %d entered in the wrong age group', $entry['mis_aged']));

        $mats = $this->buildMats($championship, max(1, (int) $this->option('mats')));
        $this->line(sprintf('  %d mats', $mats->count()));

        if ($stage !== 'registered') {
            $this->weighIn($championship);
            $this->line('  weigh-in recorded');
        }

        if (in_array($stage, ['drawn', 'running', 'finished'], true)) {
            $draw = $this->drawAndGenerate($categories);
            $this->line(sprintf('  %d classes drawn — %d as a round robin, %d as a bracket', $draw['drawn'], $draw['round_robin'], $draw['drawn'] - $draw['round_robin']));
            $this->line(sprintf('  %d athlete(s) left out of the draw on age or weight', $draw['excluded']));

            $order = app(FightOrderScheduler::class)->schedule($championship);
            $this->line(sprintf('  fight order built: %d contests', $order['scheduled']));
        }

        if ($stage === 'running') {
            $this->runPartially($championship, $categories, $mats);
        }

        if ($stage === 'finished') {
            $this->runToCompletion($categories);
            $this->line('  every contest decided');
        }

        $this->newLine();
        $this->info('Done. Sign in and open the dashboard.');
        $this->line('  Dashboard  '.route('dashboard'));
        $this->line('  Entries    '.route('entries.index', $championship));
        $this->line('  Medals     '.route('medals.index', $championship));

        if ($mats->isNotEmpty()) {
            $this->line('  Mat 1      '.route('mats.live', $mats->first()));
        }

        return self::SUCCESS;
    }

    /**
     * Clear the database of championships entirely.
     *
     * Separate from --fresh, which replaces one by name. This is the "start
     * again" switch for a development machine, and it is deliberately not the
     * default: a demonstration set is usually built alongside real work, not
     * on top of it.
     */
    private function deleteAll(): void
    {
        $all = Championship::query()->get();

        $all->each(fn (Championship $existing) => $this->purge($existing));

        $this->line(sprintf('  %d existing championship(s) removed', $all->count()));
    }

    private function deleteExisting(string $title): void
    {
        Championship::where('title', $title)->get()->each(fn (Championship $existing) => $this->purge($existing));
    }

    private function purge(Championship $existing): void
    {
        // Reopen first: the archive guard refuses to delete anything under
        // a closed championship, which includes a demo one closed by hand.
        if ($existing->isArchived()) {
            $existing->reopen(null, 'Replaced by a fresh demonstration set');
        }

        $existing->bouts()->delete();
        $existing->athletes()->delete();
        $existing->courts()->delete();
        $existing->ageCategories()->each(fn (AgeCategory $c) => $c->weightCategories()->delete());
        $existing->ageCategories()->delete();
        $existing->events()->delete();
        $existing->delete();
    }

    /** @return Collection<int, WeightCategory> */
    private function buildCategories(Championship $championship): Collection
    {
        $categories = collect();

        foreach (['M', 'F'] as $gender) {
            $ageCategory = AgeCategory::create([
                'championship_id' => $championship->id,
                'gender' => $gender,
                'age_group' => 'Senior',
                'sort_order' => $gender === 'M' ? 1 : 2,
            ]);

            // Carried forward rather than looked up by index: one class's upper
            // bound is the next one's lower bound, and walking the list in order
            // says that directly.
            $previousMax = null;

            foreach (self::CLASSES[$gender] as $index => $label) {
                $max = str_starts_with($label, '+') ? null : (float) ltrim($label, '-+');

                $categories->push(WeightCategory::create([
                    'age_category_id' => $ageCategory->id,
                    'label' => $label,
                    'gender' => $gender,
                    'sort_order' => $index + 1,
                    // The bounds the label already states, so weigh-in has a
                    // rule to check against rather than parsing the string.
                    'min_kg' => $previousMax === null ? null : $previousMax + 0.01,
                    'max_kg' => $max,
                ]));

                $previousMax = $max ?? $previousMax;
            }
        }

        return $categories;
    }

    /**
     * Enter the athletes, most of them correctly.
     *
     * Two things here are deliberately uneven, because a demonstration where
     * everything is uniform hides the two screens an official most needs to
     * recognise.
     *
     * A handful of classes are given a field of two to five. The IKA rule runs
     * those as a round robin rather than a bracket, so the draw screen, the
     * ceremony board, the standings table and the round-robin sheet all have
     * something real to render — and the rest of the championship is still a
     * bracket beside them.
     *
     * And roughly one athlete in `--reject-rate` is entered wrongly on their
     * age: born too early or too late for the division they are in. They
     * register — the seeder writes rows directly, as a federation's own
     * spreadsheet import would — and the age check then refuses them at the
     * draw, which is where an official actually meets the problem.
     *
     * @param  Collection<int, WeightCategory>  $categories
     * @return array{entered:int, small:int, mis_aged:int}
     */
    private function register(Championship $championship, Collection $categories, int $perClass): array
    {
        $nocs = DemoRoster::nocs();
        $taken = [];
        $entered = 0;
        $misAged = 0;

        $rejectRate = max(2, (int) $this->option('reject-rate'));
        $year = $championship->competitionYear();

        // Which classes run as a round robin. Taken from the front of a
        // shuffled list so the small fields are scattered across both
        // competitions rather than landing on the heaviest few.
        $smallCount = max(0, (int) $this->option('small-classes'));
        $small = $categories->shuffle()->take($smallCount)->pluck('id')->flip();

        $bar = $this->output->createProgressBar($categories->count());
        $bar->start();

        foreach ($categories as $category) {
            // Rotated rather than picked at random so every class carries a
            // spread of delegations, which is what makes an entries-by-NOC
            // table worth looking at.
            shuffle($nocs);

            $size = $small->has($category->id) ? random_int(2, 5) : $perClass;

            DB::transaction(function () use ($category, $championship, $size, $nocs, $year, $rejectRate, &$taken, &$entered, &$misAged) {
                foreach (range(1, $size) as $i) {
                    $noc = $nocs[$i % count($nocs)];

                    // Senior is 17-35 in competition age, so a birth year
                    // between 18 and 34 years back is comfortably inside it.
                    $wrongAge = random_int(1, $rejectRate) === 1;
                    $age = $wrongAge
                        ? (random_int(0, 1) === 1 ? random_int(14, 16) : random_int(37, 44))
                        : random_int(18, 34);

                    if ($wrongAge) {
                        $misAged++;
                    }

                    Athlete::register([
                        'championship_id' => $championship->id,
                        'age_category_id' => $category->age_category_id,
                        'weight_category_id' => $category->id,
                        'fullname' => DemoRoster::name($noc, $category->gender, $taken),
                        'gender' => $category->gender,
                        'noc_code' => $noc,
                        'noc_name' => DemoRoster::countryName($noc),
                        'club' => DemoRoster::club($noc),
                        'position_title' => 'Athlete',
                        'date_of_birth' => sprintf('%d-%02d-%02d', $year - $age, random_int(1, 12), random_int(1, 28)),
                    ]);

                    $entered++;
                }
            });

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        return ['entered' => $entered, 'small' => $smallCount, 'mis_aged' => $misAged];
    }

    /** @return Collection<int, Court> */
    private function buildMats(Championship $championship, int $count): Collection
    {
        return collect(range(1, $count))->map(fn (int $n) => Court::create([
            'championship_id' => $championship->id,
            'number' => $n,
            'name' => "Mat {$n}",
            'is_active' => true,
        ]));
    }

    /**
     * Put a weight on everyone, with a realistic handful missing the class.
     *
     * A demonstration where every single athlete sails through the scale hides
     * the one screen state an official most needs to recognise.
     */
    private function weighIn(Championship $championship): void
    {
        foreach ($championship->athletes()->with('weightCategory')->get() as $athlete) {
            $category = $athlete->weightCategory;
            $max = $category->max_kg === null ? null : (float) $category->max_kg;

            // One in fourteen comes in over the limit; the rest land inside the
            // half-kilo the class allows.
            $overweight = random_int(1, 14) === 1;

            $kg = $max === null
                ? round(random_int(9100, 11500) / 100, 2)
                : round(($overweight ? $max + random_int(10, 90) / 100 : $max - random_int(0, 48) / 100), 2);

            $athlete->update([
                'weighin_kg' => $kg,
                'weighin_status' => $category->admits($kg) ? 'pass' : 'fail',
                'weighin_at' => now()->subHours(random_int(2, 8)),
            ]);
        }
    }

    /**
     * Draw every class that has a field left to draw.
     *
     * Through DrawGenerator rather than BracketGenerator, which is what makes
     * a class of two to five come out as the round robin the IKA rule asks
     * for. Asking the bracket generator directly would draw a tree for every
     * class and refuse the small ones outright.
     *
     * Only athletes who pass *both* gates are given a number: the scale, and
     * the age rules. That is the same order an accreditation desk works in,
     * and it is what leaves the wrongly-entered athletes sitting in the entry
     * list with a reason against them instead of seeded into a bracket.
     *
     * @param  Collection<int, WeightCategory>  $categories
     * @return array{drawn:int, round_robin:int, excluded:int}
     */
    private function drawAndGenerate(Collection $categories): array
    {
        $drawn = 0;
        $roundRobin = 0;
        $excluded = 0;

        foreach ($categories as $category) {
            $category->refresh();

            $eligible = $category->athletes()
                ->passedWeighIn()
                ->get()
                ->filter(function (Athlete $athlete) use (&$excluded) {
                    if ($athlete->ageVerdict()->eligible) {
                        return true;
                    }

                    $excluded++;

                    return false;
                })
                ->pluck('id')
                ->shuffle();

            if ($eligible->count() < 2) {
                continue;
            }

            DB::transaction(function () use ($category, $eligible) {
                $category->athletes()->update(['draw_number' => null, 'draw_number_source' => null]);

                foreach ($eligible->values() as $index => $id) {
                    Athlete::whereKey($id)->update([
                        'draw_number' => $index + 1,
                        'draw_number_source' => 'random',
                    ]);
                }
            });

            $result = app(DrawGenerator::class)->generate($category->refresh());

            if ($result['format'] === TournamentFormat::RoundRobin) {
                $roundRobin++;
            }

            $drawn++;
        }

        return ['drawn' => $drawn, 'round_robin' => $roundRobin, 'excluded' => $excluded];
    }

    /**
     * Fight about two thirds of the way through, then leave one contest live on
     * each mat with a few calls already scored.
     *
     * @param  Collection<int, WeightCategory>  $categories
     * @param  Collection<int, Court>  $mats
     */
    private function runPartially(Championship $championship, Collection $categories, Collection $mats): void
    {
        $advancer = app(BoutAdvancer::class);
        $decided = 0;

        // Round by round across the whole championship, so progress looks like
        // a session that has been running rather than a few classes finished
        // and the rest untouched.
        foreach ([1, 2] as $round) {
            foreach ($categories as $category) {
                foreach ($category->bouts()->where('round', $round)->readyToFight()->get() as $bout) {
                    $bout->refresh();

                    if (! $bout->isReadyToFight() || random_int(1, 10) > 8) {
                        continue;   // a few left unfought, as a live session has
                    }

                    $this->decide($advancer, $bout);
                    $decided++;
                }
            }
        }

        $this->line(sprintf('  %d contests decided', $decided));

        $this->putBoutsOnMats($championship, $mats);
    }

    /** @param  Collection<int, WeightCategory>  $categories */
    private function runToCompletion(Collection $categories): void
    {
        $advancer = app(BoutAdvancer::class);

        foreach ($categories as $category) {
            $guard = 0;

            while ($guard++ < 200) {
                $ready = $category->bouts()->readyToFight()->orderBy('round')->orderBy('position_in_round')->get();

                if ($ready->isEmpty()) {
                    break;
                }

                foreach ($ready as $bout) {
                    $bout->refresh();

                    if ($bout->isReadyToFight()) {
                        $this->decide($advancer, $bout);
                    }
                }
            }
        }
    }

    /**
     * Decide one contest with a plausible win type and score.
     *
     * Weighted the way kurash results actually fall: most contests end on a
     * throw, a minority go to the clock, and dakki is rare.
     */
    private function decide(BoutAdvancer $advancer, Bout $bout): void
    {
        $winnerIsA = random_int(0, 1) === 1;
        $winnerId = $winnerIsA ? $bout->athlete_a_id : $bout->athlete_b_id;

        // A ladder rather than match arms, so the weighting stays readable and
        // is adjustable without rewriting the structure.
        $roll = random_int(1, 100);

        if ($roll <= 45) {
            [$winType, $winnerScore, $loserScore] = ['khalol', 10.0, 0.0];
        } elseif ($roll <= 75) {
            [$winType, $winnerScore, $loserScore] = ['yonbosh', 2.0, random_int(0, 3) / 10];
        } elseif ($roll <= 92) {
            [$winType, $winnerScore, $loserScore] = ['chala', random_int(2, 4) / 10, random_int(0, 1) / 10];
        } elseif ($roll <= 97) {
            [$winType, $winnerScore, $loserScore] = ['decision', 0.0, 0.0];
        } else {
            [$winType, $winnerScore, $loserScore] = ['dakki', 1.0, 0.0];
        }

        try {
            $advancer->recordResult(
                bout: $bout,
                winnerAthleteId: $winnerId,
                scores: [
                    'score_a' => $winnerIsA ? $winnerScore : $loserScore,
                    'score_b' => $winnerIsA ? $loserScore : $winnerScore,
                ],
                winType: $winType,
                user: User::query()->orderBy('id')->first(),
                source: 'operator',
            );
        } catch (Throwable $e) {
            $this->warn("  skipped bout {$bout->play_code}: {$e->getMessage()}");
        }
    }

    /**
     * Leave one contest live on each mat, part-scored.
     *
     * @param  Collection<int, Court>  $mats
     */
    private function putBoutsOnMats(Championship $championship, Collection $mats): void
    {
        $operator = User::query()->orderBy('id')->first();

        $waiting = Bout::where('championship_id', $championship->id)
            ->readyToFight()
            ->orderByRaw('fight_number is null')
            ->orderBy('fight_number')
            ->limit($mats->count())
            ->get();

        foreach ($mats->values() as $index => $mat) {
            $bout = $waiting[$index] ?? null;

            if ($bout === null) {
                continue;
            }

            $bout->update(['court_id' => $mat->id, 'status' => Bout::STATUS_ON_COURT]);

            // A few calls already made, but never enough to have ended it —
            // one yonbosh at most per side, so the contest is genuinely live
            // when the mat screen opens.
            // Pressed through BoutScorer rather than written as rows, so the
            // demo carries the automatic awards and the parent links a real
            // contest would have — a seeded mat that skipped them would show a
            // board no sequence of calls could produce.
            $calls = [
                ['chala', 'a', 212],
                ['tanbeh', 'b', 188],
                ['yonbosh', 'a', 141],
                ['chala', 'b', 96],
            ];

            foreach (array_slice($calls, 0, random_int(1, 4)) as [$call, $side, $clock]) {
                app(BoutScorer::class)->record(
                    bout: $bout,
                    call: $call,
                    side: $side,
                    clock: $clock,
                    user: $operator,
                );
            }
        }

        $this->line(sprintf('  %d contests live on mats', min($mats->count(), $waiting->count())));
    }
}
