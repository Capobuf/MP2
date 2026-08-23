<?php

namespace Database\Factories;

use App\Domain\Projects\ProjectDeferralMode;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Project;
use App\Models\ProjectDeferral;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProjectDeferral> */
class ProjectDeferralFactory extends Factory
{
    public function definition(): array
    {
        $company = Company::factory()->create();
        $year = fake()->unique()->numberBetween(2000, 2200);

        return [
            'company_id' => $company->id,
            'project_id' => Project::factory()->for($company),
            'source_exercise_id' => Exercise::factory()->for($company)->state(['year' => $year]),
            'destination_exercise_id' => Exercise::factory()->for($company)->state(['year' => $year + 1]),
            'mode' => ProjectDeferralMode::None,
            'carryover_amount' => '0.00',
            'carryover_state' => null,
            'reprogrammed_amount' => '0.00',
            'reprogramming_operation_id' => null,
            'reprogramming_effects' => null,
        ];
    }

    public function carryover(string $amount = '100.00'): static
    {
        return $this->state(fn (): array => [
            'mode' => ProjectDeferralMode::Carryover,
            'carryover_amount' => $amount,
            'carryover_state' => 'provisional',
        ]);
    }
}
