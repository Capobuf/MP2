<?php

namespace Database\Factories;

use App\Domain\Expenses\ExpenseLineType;
use App\Models\Expense;
use App\Models\ExpenseLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExpenseLine> */
class ExpenseLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'expense_id' => Expense::factory(),
            'type' => ExpenseLineType::Estimate,
            'amount' => fake()->randomFloat(2, 1, 10000),
            'quantity' => null,
            'unit_amount' => null,
            'unit_of_measure' => null,
            'note' => null,
            'annulled_at' => null,
            'revision' => 0,
        ];
    }

    public function actual(): static
    {
        return $this->state(fn (): array => ['type' => ExpenseLineType::Actual]);
    }

    public function annulled(): static
    {
        return $this->state(fn (): array => ['annulled_at' => now()]);
    }
}
