<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Contract;
use App\Models\Project;
use App\Models\ProjectContractLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProjectContractLink> */
class ProjectContractLinkFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'project_id' => fn (array $attributes): int => Project::factory()->create(['company_id' => $attributes['company_id']])->id,
            'contract_id' => fn (array $attributes): int => Contract::factory()->create(['company_id' => $attributes['company_id']])->id,
            'note' => fake()->optional()->sentence(),
            'archived_at' => null,
            'revision' => 0,
        ];
    }

    public function forProjectAndContract(Project $project, Contract $contract): static
    {
        return $this->state(fn (): array => [
            'company_id' => $project->company_id,
            'project_id' => $project->id,
            'contract_id' => $contract->id,
        ]);
    }
}
