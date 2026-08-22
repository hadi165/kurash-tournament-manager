<?php

namespace Database\Factories;

use App\Models\Championship;
use App\Support\Gender;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Championship> */
class ChampionshipFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => 'Asian Kurash Championship '.fake()->year(),
            'location' => fake()->city(),
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addDays(2)->toDateString(),
            'genders' => Gender::DEFAULT,
            'age_groups' => ['Senior'],
        ];
    }
}
