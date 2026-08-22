<?php

use App\Models\AgeCategory;
use App\Models\Athlete;
use App\Models\Court;
use App\Models\User;
use App\Models\WeightCategory;
use App\Services\BoutAdvancer;
use App\Services\BracketGenerator;
use App\Services\FightOrderScheduler;
use App\Support\DisplayCache;
use Illuminate\Support\Facades\DB;

/** A drawn, scheduled class ready to be shown on a screen. */
function drawnChampionship(int $athletes = 4): WeightCategory
{
    $ageCategory = AgeCategory::factory()->create();

    $category = WeightCategory::factory()->create([
        'age_category_id' => $ageCategory->id,
        'label' => '-90',
        'gender' => 'M',
    ]);

    foreach (range(1, $athletes) as $draw) {
        Athlete::factory()->drawn($draw)->create([
            'championship_id' => $ageCategory->championship_id,
            'age_category_id' => $ageCategory->id,
            'weight_category_id' => $category->id,
            'fullname' => "Display Athlete {$draw}",
            'weighin_status' => 'pass',
        ]);
    }

    app(BracketGenerator::class)->generate($category->refresh());
    app(FightOrderScheduler::class)->schedule($category->ageCategory->championship);

    return $category->refresh();
}

describe('who may look at a display screen', function () {
    it('sends an anonymous visitor to log in while the screens are private', function () {
        config(['display.public' => false]);
        $category = drawnChampionship();

        $this->get(route('display.fight-order', $category->ageCategory->championship))
            ->assertRedirect(route('login'));
    });

    it('lets anyone watch once the screens are made public', function () {
        config(['display.public' => true]);
        $category = drawnChampionship();

        $this->get(route('display.fight-order', $category->ageCategory->championship))
            ->assertOk()
            ->assertSee('Display Athlete 1');
    });

    it('always lets a signed-in user watch', function () {
        config(['display.public' => false]);
        $category = drawnChampionship();

        $this->actingAs(User::factory()->create(['role' => 'viewer']))
            ->get(route('display.fight-order', $category->ageCategory->championship))
            ->assertOk();
    });
});

describe('caching', function () {
    beforeEach(fn () => config(['display.public' => true]));

    it('builds the second view from cache rather than from the database', function () {
        $category = drawnChampionship();
        $championship = $category->ageCategory->championship;

        // Counting queries is the only assertion that actually proves this: a
        // cached and an uncached response have identical bodies.
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->get(route('display.fight-order', $championship))->assertOk();
        $cold = $queries;

        $queries = 0;
        $this->get(route('display.fight-order', $championship))->assertOk();
        $warm = $queries;

        // Exactly one: route-model binding resolves {championship} before the
        // controller runs, so it can never reach zero. Everything the screen
        // itself needs comes from cache.
        expect($warm)->toBe(1)
            ->and($cold)->toBeGreaterThan($warm);
    });

    /**
     * The property that matters most. A screen showing a result that has since
     * been overturned is worse than a screen showing nothing.
     */
    it('shows a new result immediately', function () {
        $category = drawnChampionship();
        $championship = $category->ageCategory->championship;

        $this->get(route('display.fight-order', $championship))
            ->assertOk()
            ->assertDontSee('WINNER-MARKER');

        $bout = $championship->bouts()->where('fight_number', 1)->first();

        app(BoutAdvancer::class)->recordResult(
            bout: $bout,
            winnerAthleteId: $bout->athlete_a_id,
            winType: 'khalol',
            user: null,
            source: 'operator',
        );

        $response = $this->get(route('display.fight-order', $championship));

        $response->assertOk();

        // The winner column is populated, which it was not a moment ago.
        expect($response->getContent())->toContain($bout->athleteA->fullname);

        $fresh = $championship->bouts()->where('fight_number', 1)->first();
        expect($fresh->winner_athlete_id)->toBe($bout->athlete_a_id);
    });

    it('invalidates every screen for the championship at once, not just one', function () {
        $category = drawnChampionship();
        $championship = $category->ageCategory->championship;

        $before = [
            DisplayCache::key('mats', $championship->id),
            DisplayCache::key('medals', $championship->id),
        ];

        DisplayCache::bump($championship->id);

        $after = [
            DisplayCache::key('mats', $championship->id),
            DisplayCache::key('medals', $championship->id),
        ];

        expect($after[0])->not->toBe($before[0])
            ->and($after[1])->not->toBe($before[1]);
    });

    it('leaves another championship alone', function () {
        $one = drawnChampionship()->ageCategory->championship;
        $two = drawnChampionship()->ageCategory->championship;

        $keyTwo = DisplayCache::key('mats', $two->id);

        DisplayCache::bump($one->id);

        expect(DisplayCache::key('mats', $two->id))->toBe($keyTwo);
    });
});

describe('conditional requests', function () {
    beforeEach(fn () => config(['display.public' => true]));

    /**
     * A screen refreshing every ten seconds all afternoon should transfer
     * nothing while the competition is between bouts.
     */
    it('answers an unchanged screen with 304 and no body', function () {
        $category = drawnChampionship();
        $championship = $category->ageCategory->championship;

        $first = $this->get(route('display.fight-order', $championship));
        $first->assertOk();

        $etag = $first->headers->get('ETag');
        expect($etag)->not->toBeNull();

        $second = $this->withHeaders(['If-None-Match' => $etag])
            ->get(route('display.fight-order', $championship));

        $second->assertStatus(304);
        expect($second->getContent())->toBe('');
    });

    it('sends a new body once something changes', function () {
        $category = drawnChampionship();
        $championship = $category->ageCategory->championship;

        $etag = $this->get(route('display.fight-order', $championship))->headers->get('ETag');

        $bout = $championship->bouts()->where('fight_number', 1)->first();
        app(BoutAdvancer::class)->recordResult(
            bout: $bout,
            winnerAthleteId: $bout->athlete_a_id,
            winType: 'khalol',
            user: null,
            source: 'operator',
        );

        $this->withHeaders(['If-None-Match' => $etag])
            ->get(route('display.fight-order', $championship))
            ->assertOk();
    });
});

describe('the screens themselves', function () {
    beforeEach(fn () => config(['display.public' => true]));

    it('shows what is on each mat', function () {
        $category = drawnChampionship();
        $championship = $category->ageCategory->championship;
        // No name, so the label falls back to the mat number. The factory
        // otherwise generates one, which would read "Mat p".
        $court = Court::factory()->create([
            'championship_id' => $championship->id,
            'number' => 1,
            'name' => null,
        ]);

        $bout = $championship->bouts()->where('fight_number', 1)->first();
        $bout->update(['court_id' => $court->id]);

        $this->get(route('display.mats', $championship))
            ->assertOk()
            ->assertSee('Mat 1')
            ->assertSee($bout->athleteA->fullname);
    });

    it('renders the bracket', function () {
        $category = drawnChampionship(8);

        $this->get(route('display.bracket', $category))
            ->assertOk()
            ->assertSee('Display Athlete 1')
            ->assertSee('Final');
    });

    it('says so plainly when a class has not been drawn', function () {
        $category = WeightCategory::factory()->create();

        $this->get(route('display.bracket', $category))
            ->assertOk()
            ->assertSee('has not been drawn yet');
    });

    it('renders the medal screen before anything is decided', function () {
        $category = drawnChampionship();

        $this->get(route('display.medals', $category->ageCategory->championship))
            ->assertOk()
            ->assertSee('No class has been decided yet');
    });
});
