<?php

namespace Database\Factories;

use App\Domain\Expenses\ExerciseStatus;
use App\Models\Company;
use App\Models\Exercise;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Exercise> */
class ExerciseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'year' => fake()->unique()->numberBetween(2000, 2200),
            'status' => ExerciseStatus::Open,
            'revision' => 0,
        ];
    }
}
