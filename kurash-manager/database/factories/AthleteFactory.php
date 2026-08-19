<?php

namespace Database\Factories;

use App\Models\AgeCategory;
use App\Models\Athlete;
use App\Models\Championship;
use App\Models\WeightCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Athlete> */
class AthleteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ika_id' => 'IKA'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'championship_id' => Championship::factory(),
            'age_category_id' => AgeCategory::factory(),
            'weight_category_id' => WeightCategory::factory(),
            'fullname' => fake()->name('male'),
            'gender' => 'M',
            'noc_code' => fake()->randomElement(['UZB', 'KAZ', 'IRI', 'TJK', 'TKM', 'IND']),
            'noc_name' => 'Testland',
            'weighin_status' => 'pass',
        ];
    }

    public function drawn(int $number): static
    {
        return $this->state(fn () => [
            'draw_number' => $number,
            'draw_number_source' => 'manual',
        ]);
    }
}
