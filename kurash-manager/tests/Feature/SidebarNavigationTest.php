<?php

use App\Models\AgeCategory;
use App\Models\Championship;
use App\Models\User;
use App\Models\WeightCategory;
use App\Support\PresentableDraws;

it('lists every age category under registration and weigh-in', function () {
    $championship = Championship::factory()->create();
    $a = AgeCategory::factory()->for($championship)->create(['gender' => 'M', 'age_group' => 'Senior']);
    $b = AgeCategory::factory()->for($championship)->create(['gender' => 'F', 'age_group' => 'Senior', 'sort_order' => 1]);

    $this->actingAs(User::factory()->create())
        ->get(route('championships.show', $championship))
        ->assertOk()
        ->assertSee(route('athletes.index', ['championship' => $championship, 'competition' => 'M']), false)
        ->assertSee(route('athletes.index', ['championship' => $championship, 'competition' => 'F']), false)
        ->assertSee(route('weighin.index', ['championship' => $championship, 'competition' => 'M']), false)
        ->assertSee(route('weighin.index', ['championship' => $championship, 'competition' => 'F']), false);
});

it('links straight through when there is one category', function () {
    $championship = Championship::factory()->create();
    $only = AgeCategory::factory()->for($championship)->create();

    $this->actingAs(User::factory()->create())
        ->get(route('championships.show', $championship))
        ->assertOk()
        ->assertSee(route('weighin.index', ['championship' => $championship, 'competition' => 'M']), false);
});

/**
 * The running order splits by competition, so the sidebar carries the split
 * rather than making it a control to find once the page is open.
 */
it('opens the fight order on each competition the championship runs', function () {
    $championship = Championship::factory()->create(['genders' => ['M', 'F']]);
    AgeCategory::factory()->for($championship)->create(['gender' => 'M', 'age_group' => 'Senior']);

    $this->actingAs(User::factory()->create())
        ->get(route('championships.show', $championship))
        ->assertOk()
        ->assertSee(route('fight-order.index', ['championship' => $championship, 'competition' => 'M']), false)
        ->assertSee(route('fight-order.index', ['championship' => $championship, 'competition' => 'F']), false);
});

/** A submenu of one is not a choice, so it stays a plain link. */
it('links straight through when the championship runs one competition', function () {
    $championship = Championship::factory()->create(['genders' => ['M']]);
    AgeCategory::factory()->for($championship)->create(['gender' => 'M', 'age_group' => 'Senior']);

    $this->actingAs(User::factory()->create())
        ->get(route('championships.show', $championship))
        ->assertOk()
        ->assertSee(route('fight-order.index', $championship), false)
        ->assertDontSee(route('fight-order.index', ['championship' => $championship, 'competition' => 'F']), false);
});

/**
 * Every screen under a championship splits the same way, so there is one rule
 * to know rather than seven.
 */
it('splits every championship screen by competition', function () {
    $championship = Championship::factory()->create(['genders' => ['M', 'F']]);
    AgeCategory::factory()->for($championship)->create(['gender' => 'M', 'age_group' => 'Senior']);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('championships.show', $championship))
        ->assertOk();

    foreach (['entries.index', 'brackets.index', 'courts.index', 'medals.index', 'fight-order.index'] as $route) {
        foreach (['M', 'F'] as $competition) {
            $response->assertSee(
                route($route, ['championship' => $championship, 'competition' => $competition]),
                false
            );
        }
    }

    $response->assertSee('Results and Medals')->assertDontSee('Results &amp; Medals');
});

/** A submenu of one is not a choice, so those items stay plain links. */
it('leaves the championship screens as plain links when it runs one competition', function () {
    $championship = Championship::factory()->create(['genders' => ['F']]);
    AgeCategory::factory()->for($championship)->create(['gender' => 'F', 'age_group' => 'Senior']);

    $this->actingAs(User::factory()->create())
        ->get(route('championships.show', $championship))
        ->assertOk()
        ->assertSee(route('medals.index', $championship), false)
        ->assertDontSee(route('medals.index', ['championship' => $championship, 'competition' => 'M']), false);
});

/*
|--------------------------------------------------------------------------
| Draws to present
|--------------------------------------------------------------------------
|
| The badge is a promise about a list. If the two are counted by different
| rules the number sends somebody to an empty screen mid-session, which is the
| one moment nobody has time to work out why.
*/

/** Publication is the act that makes a draw presentable, not generation. */
it('counts only published draws in the sidebar badge', function () {
    $championship = Championship::factory()->create();
    $ageCategory = AgeCategory::factory()->for($championship)->create();

    WeightCategory::factory()->create([
        'age_category_id' => $ageCategory->id,
        'label' => '-60',
    ]);

    expect(PresentableDraws::count())->toBe(0);

    WeightCategory::factory()->create([
        'age_category_id' => $ageCategory->id,
        'label' => '-66',
    ])->forceFill(['draw_published_at' => now()])->save();

    expect(PresentableDraws::count())->toBe(1);
});

it('leaves archived championships out of the badge', function () {
    $championship = Championship::factory()->create();
    $ageCategory = AgeCategory::factory()->for($championship)->create();

    WeightCategory::factory()->create([
        'age_category_id' => $ageCategory->id,
        'label' => '-66',
    ])->forceFill(['draw_published_at' => now()])->save();

    expect(PresentableDraws::count())->toBe(1);

    // Archived last: ArchivedChampionshipGuard refuses every write afterwards.
    $championship->archive();

    expect(PresentableDraws::count())->toBe(0);
});

it('shows the badge beside the draws item once something is published', function () {
    $championship = Championship::factory()->create();
    $ageCategory = AgeCategory::factory()->for($championship)->create();

    $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->get(route('championships.show', $championship));

    $response->assertOk()->assertSee('Draws to present');

    WeightCategory::factory()->create([
        'age_category_id' => $ageCategory->id,
        'label' => '-66',
    ])->forceFill(['draw_published_at' => now()])->save();

    $html = $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->get(route('championships.show', $championship))
        ->assertOk()
        ->getContent();

    // The count sits inside the same pill as the label.
    expect($html)->toMatch('/Draws to present.*?>\s*1\s*</s');
});

/** The links have to stay reachable; they just stop competing with the workflow. */
it('keeps the repository and documentation links behind Help', function () {
    $championship = Championship::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('championships.show', $championship))
        ->assertOk()
        ->assertSee('Help')
        ->assertSee('Repository')
        ->assertSee('Documentation')
        ->assertSee('https://github.com/hadi165/kurash-tournament-manager', false);
});

/** The word named the theme you were not in, which read as a label. */
it('offers the theme switch as a button with an accessible name', function () {
    $championship = Championship::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('championships.show', $championship))
        ->assertOk()
        ->assertSee('Switch theme');
});
