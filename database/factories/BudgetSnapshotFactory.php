<?php

namespace Database\Factories;

use App\Models\BudgetSnapshot;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<BudgetSnapshot> */
class BudgetSnapshotFactory extends Factory
{
    public function definition(): array
    {
        return ['proposal_id' => Proposal::factory(), 'company_id' => fn (array $attributes): int => Proposal::findOrFail($attributes['proposal_id'])->company_id, 'exercise_id' => fn (array $attributes): int => Proposal::findOrFail($attributes['proposal_id'])->exercise_id, 'version' => 1, 'purpose' => 'initial_budget', 'approved_at' => now(), 'approved_by_id' => User::factory(), 'previous_budget_id' => null, 'total_approved_allocation' => '0.00', 'affected_exercises' => [], 'operation_id' => Str::uuid()];
    }
}
