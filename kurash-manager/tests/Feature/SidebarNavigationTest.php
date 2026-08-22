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
        ->assertSee(route('weighin.index', $a), false)
        ->assertSee(route('weighin.index', $b), false);
});

it('links straight through when there is one category', function () {
    $championship = Championship::factory()->create();
    $only = AgeCategory::factory()->for($championship)->create();

    $this->actingAs(User::factory()->create())
        ->get(route('championships.show', $championship))
        ->assertOk()
        ->assertSee(route('weighin.index', $only), false);
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
        ->assertSee(route('fight-order.index', ['championship' => $championship, 'division' => 'M']), false)
        ->assertSee(route('fight-order.index', ['championship' => $championship, 'division' => 'F']), false);
});

/** A submenu of one is not a choice, so it stays a plain link. */
it('links straight through when the championship runs one competition', function () {
    $championship = Championship::factory()->create(['genders' => ['M']]);
    AgeCategory::factory()->for($championship)->create(['gender' => 'M', 'age_group' => 'Senior']);

    $this->actingAs(User::factory()->create())
        ->get(route('championships.show', $championship))
        ->assertOk()
        ->assertSee(route('fight-order.index', $championship), false)
        ->assertDontSee(route('fight-order.index', ['championship' => $championship, 'division' => 'F']), false);
});
