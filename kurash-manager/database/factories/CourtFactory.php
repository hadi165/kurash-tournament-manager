<?php

namespace Database\Factories;

use App\Models\Championship;
use App\Models\Court;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Court> */
class CourtFactory extends Factory
{
    public function definition(): array
    {
        return [
            'championship_id' => Championship::factory(),
            'number' => fake()->unique()->numberBetween(1, 8),
            'name' => 'Mat '.fake()->randomLetter(),
            'scoreboard_base_url' => 'http://192.168.1.'.fake()->numberBetween(20, 90),
            'scoreboard_api_key' => 'test-key',
            'is_active' => true,
        ];
    }
}
