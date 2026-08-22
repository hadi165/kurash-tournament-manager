<?php

namespace Database\Factories;

use App\Models\AgeCategory;
use App\Models\Championship;
use App\Support\Gender;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AgeCategory> */
class AgeCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'championship_id' => Championship::factory(),
            'gender' => Gender::MEN,
            'age_group' => 'Senior',
            'sort_order' => 0,
        ];
    }
}
