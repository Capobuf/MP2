<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractRenewalConfiguration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ContractRenewalConfiguration> */
class ContractRenewalConfigurationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'contract_id' => fn (array $attributes): int => Contract::factory()->create(['company_id' => $attributes['company_id']])->id,
            'effective_from' => '2026-01-01',
            'automatic_renewal' => true,
            'expiry_anchor_date' => '2026-12-31',
            'renewal_duration_months' => 12,
            'notice_days' => 90,
            'created_by_id' => User::factory(),
        ];
    }

    public function forContract(Contract $contract): static
    {
        return $this->state(fn (): array => ['company_id' => $contract->company_id, 'contract_id' => $contract->id]);
    }
}
