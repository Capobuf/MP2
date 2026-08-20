<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Contract;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Contract> */
class ContractFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'supplier_id' => fn (array $attributes): int => Supplier::factory()->create(['company_id' => $attributes['company_id']])->id,
            'title' => fake()->sentence(3),
            'notes' => fake()->optional()->sentence(),
            'contractual_start_date' => '2026-01-01',
            'next_expiry_date' => '2026-12-31',
            'renewal_anchor_date' => '2026-12-31',
            'automatic_renewal' => true,
            'renewal_duration_months' => 12,
            'notice_days' => 90,
            'archived_at' => null,
            'revision' => 0,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['archived_at' => now()]);
    }
}
