<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ContractCondition> */
class ContractConditionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'contract_id' => fn (array $attributes): int => Contract::factory()->create(['company_id' => $attributes['company_id']])->id,
            'cycle' => 'monthly',
            'attribution_mode' => 'cycle_start',
            'amount' => '100.00',
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'reason' => null,
            'created_by_id' => User::factory(),
            'annulled_at' => null,
            'annulled_by_id' => null,
        ];
    }

    public function forContract(Contract $contract): static
    {
        return $this->state(fn (): array => ['company_id' => $contract->company_id, 'contract_id' => $contract->id]);
    }
}
