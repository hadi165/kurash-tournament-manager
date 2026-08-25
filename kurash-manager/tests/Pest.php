<?php

use App\Models\AgeCategory;
use App\Models\Athlete;
use App\Models\Bout;
use App\Models\Championship;
use App\Models\Court;
use App\Models\WeightCategory;
use App\Services\BoutAdvancer;
use App\Services\BracketGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * A weight class with athletes who all made the scale.
 *
 * Shared, because more than one suite needs a class that is past the scale and
 * ready to draw.
 */
function weighedClass(int $count, string $gender = 'M', string $label = '-91'): WeightCategory
{
    $ageCategory = AgeCategory::factory()->create();

    $category = WeightCategory::factory()->create([
        'age_category_id' => $ageCategory->id,
        'label' => $label,
        'gender' => $gender,
    ]);

    foreach (range(1, $count) as $draw) {
        Athlete::factory()->drawn($draw)->create([
            'championship_id' => $ageCategory->championship_id,
            'age_category_id' => $ageCategory->id,
            'weight_category_id' => $category->id,
            'fullname' => "Athlete {$draw}",
            'noc_code' => 'UZB',
            'weighin_status' => 'pass',
        ]);
    }

    return $category->refresh();
}

/**
 * Build a weight category seated with `$count` athletes, drawn 1..N.
 *
 * Draw numbers are the seeds: athlete with draw 1 is the top seed. Tests use
 * that to predict the podium, since "lower draw number always wins" then maps
 * directly onto standard seeding.
 *
 * @return array{0: WeightCategory, 1: Collection<int, Athlete>}
 */
function categoryWithAthletes(int $count, string $label = '-66'): array
{
    $ageCategory = AgeCategory::factory()->create();

    $category = WeightCategory::factory()->create([
        'age_category_id' => $ageCategory->id,
        'label' => $label,
    ]);

    // Not range(1, $count): PHP counts *down* when the end is below the start,
    // so range(1, 0) yields [1, 0] and would seat two athletes for a count of 0.
    $athletes = collect($count > 0 ? range(1, $count) : [])->map(
        fn (int $draw) => Athlete::factory()->drawn($draw)->create([
            'championship_id' => $ageCategory->championship_id,
            'age_category_id' => $ageCategory->id,
            'weight_category_id' => $category->id,
            'fullname' => "Athlete {$draw}",
        ])
    )->keyBy('draw_number');

    return [$category->refresh(), $athletes];
}

/**
 * A drawn category with its first contest sitting on a mat.
 *
 * Shared rather than living in one test file: the mat screen and the wall
 * scoreboard are two views of the same situation and both need to set it up.
 *
 * @return array{0: Court, 1: Bout, 2: Collection<int, Athlete>}
 */
function boutOnMat(int $athletes = 4): array
{
    [$category, $drawn] = categoryWithAthletes($athletes);
    app(BracketGenerator::class)->generate($category);

    $court = Court::factory()->create([
        'championship_id' => $category->ageCategory->championship_id,
        'number' => 1,
    ]);

    $bout = $category->bouts()->readyToFight()->orderBy('position_in_round')->firstOrFail();
    $bout->update(['court_id' => $court->id, 'status' => Bout::STATUS_ON_COURT]);

    return [$court, $bout->refresh(), $drawn];
}

/**
 * Run every remaining bout in a category to completion, letting the lower draw
 * number win each time, and return how many contested bouts were fought.
 */
function runTournament(WeightCategory $category): int
{
    $advancer = app(BoutAdvancer::class);
    $fought = 0;
    $guard = 0;

    while (true) {
        $ready = $category->bouts()->readyToFight()->orderBy('round')->orderBy('position_in_round')->get();

        if ($ready->isEmpty() || ++$guard > 200) {
            break;
        }

        foreach ($ready as $bout) {
            $bout->refresh();

            if (! $bout->isReadyToFight()) {
                continue; // already resolved by an advancement this pass
            }

            $drawA = $bout->athleteA->draw_number;
            $drawB = $bout->athleteB->draw_number;
            $winnerId = $drawA < $drawB ? $bout->athlete_a_id : $bout->athlete_b_id;

            $advancer->recordResult($bout, $winnerId, ['score_a' => 10, 'score_b' => 0], 'khalol', null, 'scoreboard');
            $fought++;
        }
    }

    return $fought;
}

/**
 * Build a championship with several weight classes, each drawn and bracketed.
 *
 * Here rather than in one test file: two files were already using it, and the
 * second only passed when the first happened to have been loaded first.
 *
 * @param  array<string, int>  $classes  label => athlete count
 */
function championshipWithBrackets(array $classes): Championship
{
    $ageCategory = AgeCategory::factory()->create();

    foreach ($classes as $label => $count) {
        $category = WeightCategory::factory()->create([
            'age_category_id' => $ageCategory->id,
            'label' => $label,
        ]);

        foreach (range(1, $count) as $draw) {
            Athlete::factory()->drawn($draw)->create([
                'championship_id' => $ageCategory->championship_id,
                'age_category_id' => $ageCategory->id,
                'weight_category_id' => $category->id,
                'fullname' => "{$label} #{$draw}",
            ]);
        }

        app(BracketGenerator::class)->generate($category->refresh());
    }

    return $ageCategory->championship->refresh();
}

/**
 * A date of birth that puts an athlete squarely inside an age group.
 *
 * The IKA states its bands in birth years relative to the competition year, so
 * a fixed date would drift out of the band it was chosen for as the seasons
 * pass. This works back from the year the championship is held in and lands in
 * the middle of the band rather than on its edge — the boundaries themselves
 * are asserted deliberately in AgeEligibilityTest, and a test about something
 * else should not be sitting on one by accident.
 */
function dobFor(string $ageGroup = 'Senior', ?int $competitionYear = null): string
{
    $competitionYear ??= (int) now()->year;

    $age = match ($ageGroup) {
        'Cadet' => 15,
        'Junior' => 16,
        'Veteran' => 40,
        // Clear of the 16-17 window that needs the Chief Referee's signature.
        default => 25,
    };

    return ($competitionYear - $age).'-06-15';
}
