<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Exercise;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Proposal> */
class ProposalFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'exercise_id' => fn (array $attributes): int => Exercise::factory()->create(['company_id' => $attributes['company_id']])->id,
            'purpose' => 'initial_budget', 'status' => 'draft', 'created_by_id' => User::factory(),
            'approved_by_id' => null, 'approved_at' => null, 'approval_operation_id' => null, 'revision' => 0,
        ];
    }
}
