<?php

use App\Models\AgeCategory;
use App\Models\Championship;
use App\Models\User;

it('lists every age category under registration and weigh-in', function () {
    $championship = Championship::factory()->create();
    $a = AgeCategory::factory()->for($championship)->create(['name' => 'Men Senior']);
    $b = AgeCategory::factory()->for($championship)->create(['name' => 'Women Senior']);

    $this->actingAs(User::factory()->create())
        ->get(route('championships.show', $championship))
        ->assertOk()
        ->assertSee(route('athletes.index', $a), false)
        ->assertSee(route('athletes.index', $b), false)
        ->assertSee(route('weighin.index', $a), false)
        ->assertSee(route('weighin.index', $b), false);
});

it('links straight through when there is one category', function () {
    $championship = Championship::factory()->create();
    $only = AgeCategory::factory()->for($championship)->create();

    $this->actingAs(User::factory()->create())
        ->get(route('championships.show', $championship))
        ->assertOk()
        ->assertSee(route('athletes.index', $only), false)
        ->assertSee(route('weighin.index', $only), false);
});
