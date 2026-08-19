<?php

namespace App\Console\Commands;

use App\Models\AgeCategory;
use App\Models\Athlete;
use App\Models\Bout;
use App\Models\BoutEvent;
use App\Models\Championship;
use App\Models\Court;
use App\Models\User;
use App\Models\WeightCategory;
use App\Services\BoutAdvancer;
use App\Services\BracketGenerator;
use App\Services\FightOrderScheduler;
use App\Services\KurashScore;
use App\Support\DemoRoster;
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
                            {--fresh : Delete any existing championship with this title first}';

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

        if ($this->option('fresh')) {
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
        ]);

        $this->info("Building “{$title}”…");

        $categories = $this->buildCategories($championship);
        $this->line(sprintf('  %d weight classes', $categories->count()));

        $athletes = $this->register($championship, $categories, $perClass);
        $this->line(sprintf('  %d athletes registered', $athletes));

        $mats = $this->buildMats($championship, max(1, (int) $this->option('mats')));
        $this->line(sprintf('  %d mats', $mats->count()));

        if ($stage !== 'registered') {
            $this->weighIn($championship);
            $this->line('  weigh-in recorded');
        }

        if (in_array($stage, ['drawn', 'running', 'finished'], true)) {
            $drawn = $this->drawAndGenerate($categories);
            $this->line(sprintf('  %d brackets drawn', $drawn));

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

    private function deleteExisting(string $title): void
    {
        Championship::where('title', $title)->get()->each(function (Championship $existing) {
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
        });
    }

    /** @return Collection<int, WeightCategory> */
    private function buildCategories(Championship $championship): Collection
    {
        $categories = collect();

        foreach (['M' => 'Men Senior', 'F' => 'Women Senior'] as $gender => $name) {
            $ageCategory = AgeCategory::create([
                'championship_id' => $championship->id,
                'name' => $name,
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

    /** @param  Collection<int, WeightCategory>  $categories */
    private function register(Championship $championship, Collection $categories, int $perClass): int
    {
        $nocs = DemoRoster::nocs();
        $taken = [];
        $count = 0;

        $bar = $this->output->createProgressBar($categories->count());
        $bar->start();

        foreach ($categories as $category) {
            // Rotated rather than picked at random so every class carries a
            // spread of delegations, which is what makes an entries-by-NOC
            // table worth looking at.
            shuffle($nocs);

            DB::transaction(function () use ($category, $championship, $perClass, $nocs, &$taken, &$count) {
                foreach (range(1, $perClass) as $i) {
                    $noc = $nocs[$i % count($nocs)];

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
                    ]);

                    $count++;
                }
            });

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        return $count;
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

    /** @param  Collection<int, WeightCategory>  $categories */
    private function drawAndGenerate(Collection $categories): int
    {
        $drawn = 0;

        foreach ($categories as $category) {
            $eligible = $category->athletes()->where('weighin_status', 'pass')->pluck('id')->shuffle();

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

            app(BracketGenerator::class)->generate($category->refresh());
            $drawn++;
        }

        return $drawn;
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
            [$winType, $winnerScore, $loserScore] = ['halal', 10.0, 0.0];
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
            $calls = [
                ['call' => 'chala', 'athlete_id' => $bout->athlete_a_id, 'clock' => 212],
                ['call' => 'tanbeh', 'athlete_id' => $bout->athlete_b_id, 'clock' => 188],
                ['call' => 'yonbosh', 'athlete_id' => $bout->athlete_a_id, 'clock' => 141],
                ['call' => 'chala', 'athlete_id' => $bout->athlete_b_id, 'clock' => 96],
            ];

            foreach (array_slice($calls, 0, random_int(1, 4)) as $call) {
                BoutEvent::create([
                    'bout_id' => $bout->id,
                    'user_id' => $operator?->id,
                    'action' => KurashScore::ACTION_SCORED,
                    'source' => 'operator',
                    'after' => $call,
                ]);
            }
        }

        $this->line(sprintf('  %d contests live on mats', min($mats->count(), $waiting->count())));
    }
}
