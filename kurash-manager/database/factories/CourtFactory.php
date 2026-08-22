<?php

namespace Database\Factories;

use App\Models\Championship;
use App\Models\Court;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Court> */
class CourtFactory extends Factory
{
    /**
     * Counted rather than drawn.
     *
     * Two mats are unique on their number within a championship, and a test
     * proving one mat is not offered needs the other to be called something
     * else. Faker's unique() gave neither reliably — it handed out the same
     * number twice often enough to fail a suite at random, which is the worst
     * kind of failing test: the one that is not about anything.
     */
    private static int $sequence = 0;

    public function definition(): array
    {
        $sequence = self::$sequence++;

        return [
            'championship_id' => Championship::factory(),
            // Wrapped inside the range the form accepts. Two mats sharing a
            // number in different championships is allowed; sharing one in the
            // same championship is what the constraint forbids, and a counter
            // will not do that in any test that creates fewer than ninety.
            'number' => ($sequence % 90) + 1,
            'name' => 'Mat '.($sequence + 1),
            'scoreboard_base_url' => 'http://192.168.1.'.fake()->numberBetween(20, 90),
            'scoreboard_api_key' => 'test-key',
            'is_active' => true,
        ];
    }
}
