<?php

namespace Database\Factories;

use App\Models\AgeCategory;
use App\Models\WeightCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WeightCategory> */
class WeightCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'age_category_id' => AgeCategory::factory(),
            'label' => '-66',
            'min_kg' => 60,
            'max_kg' => 66,
            'gender' => 'M',
            'sort_order' => 0,
        ];
    }
}
