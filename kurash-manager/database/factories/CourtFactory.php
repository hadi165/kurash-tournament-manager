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
        // Named after its own number rather than after a random letter. Two
        // mats drawing the same letter gave them the same label, and a test
        // asserting one mat is not offered would fail because the other one
        // happened to be called the same thing.
        $number = fake()->unique()->numberBetween(1, 8);

        return [
            'championship_id' => Championship::factory(),
            'number' => $number,
            'name' => 'Mat '.chr(64 + $number),
            'scoreboard_base_url' => 'http://192.168.1.'.fake()->numberBetween(20, 90),
            'scoreboard_api_key' => 'test-key',
            'is_active' => true,
        ];
    }
}
