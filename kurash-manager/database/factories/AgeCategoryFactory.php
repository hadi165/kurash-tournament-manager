<?php

namespace Database\Factories;

use App\Models\AgeCategory;
use App\Models\Championship;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AgeCategory> */
class AgeCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'championship_id' => Championship::factory(),
            'name' => 'Men Senior',
            'sort_order' => 0,
        ];
    }
}
