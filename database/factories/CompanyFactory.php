<?php

namespace Database\Factories;

use App\Domain\Company\ClosingUnclassifiedPolicy;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Company> */
class CompanyFactory extends Factory
{
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
