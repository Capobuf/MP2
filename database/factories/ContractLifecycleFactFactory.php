<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractLifecycleFact;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ContractLifecycleFact> */
class ContractLifecycleFactFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'contract_id' => fn (array $attributes): int => Contract::factory()->create(['company_id' => $attributes['company_id']])->id,
            'type' => 'activation',
            'declared_contractual_date' => '2026-01-01',
            'state_change_date' => '2026-01-01',
            'renewed_expiry_date' => null,
            'renewal_configuration_id' => null,
            'reason' => null,
            'created_by_id' => User::factory(),
            'annulled_at' => null,
            'annulled_by_id' => null,
            'annulment_reason' => null,
        ];
    }

    public function forContract(Contract $contract): static
    {
        return $this->state(fn (): array => ['company_id' => $contract->company_id, 'contract_id' => $contract->id]);
    }
}
