<?php

namespace Database\Factories;

use App\Domain\Company\ClosingUnclassifiedPolicy;
use App\Domain\Company\TenantCompanyStatus;
use App\Models\Company;
use App\Models\TenantCompany;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Company> */
class CompanyFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (Company $company): void {
            TenantCompany::query()->create([
                'company_id' => $company->getKey(),
                'status' => TenantCompanyStatus::Active,
            ]);
        });
    }

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'timezone' => 'Europe/Rome',
            'overspend_note_required' => false,
            'unclassified_closing_policy' => ClosingUnclassifiedPolicy::Warning,
        ];
    }
}
