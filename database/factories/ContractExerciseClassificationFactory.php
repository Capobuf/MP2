<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractExerciseClassification;
use App\Models\Exercise;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ContractExerciseClassification> */
class ContractExerciseClassificationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'contract_id' => fn (array $attributes): int => Contract::factory()->create(['company_id' => $attributes['company_id']])->id,
            'exercise_id' => fn (array $attributes): int => Exercise::factory()->create(['company_id' => $attributes['company_id']])->id,
            'cost_center_id' => null,
        ];
    }

    public function forContractAndExercise(Contract $contract, Exercise $exercise): static
    {
        return $this->state(fn (): array => [
            'company_id' => $contract->company_id,
            'contract_id' => $contract->id,
            'exercise_id' => $exercise->id,
        ]);
    }
}
