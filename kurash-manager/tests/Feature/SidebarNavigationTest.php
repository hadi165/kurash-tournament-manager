<?php

use App\Models\AgeCategory;
use App\Models\Championship;
use App\Models\User;

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
