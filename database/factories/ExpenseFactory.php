<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Expense> */
class ExpenseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'exercise_id' => fn (array $attributes): int => Exercise::factory()->create([
                'company_id' => $attributes['company_id'],
            ])->id,
            'project_id' => null,
            'contract_id' => null,
            'origin' => 'manual',
            'supplier_id' => null,
            'direct_cost_center_id' => null,
            'description' => fake()->sentence(3),
            'notes' => fake()->optional()->sentence(),
            'reversed_at' => null,
            'revision' => 0,
        ];
    }

    public function reversed(): static
    {
        return $this->state(fn (): array => ['reversed_at' => now()]);
    }

    public function forExercise(Exercise $exercise): static
    {
        return $this->state(fn (): array => [
            'company_id' => $exercise->company_id,
            'exercise_id' => $exercise->id,
        ]);
    }
}
