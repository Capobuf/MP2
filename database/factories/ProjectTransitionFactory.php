<?php

namespace Database\Factories;

use App\Domain\Projects\ProjectState;
use App\Models\Company;
use App\Models\Project;
use App\Models\ProjectTransition;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProjectTransition> */
class ProjectTransitionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'project_id' => fn (array $attributes): int => Project::factory()->create([
                'company_id' => $attributes['company_id'],
            ])->id,
            'from_state' => ProjectState::Planned,
            'to_state' => ProjectState::Open,
            'effective_date' => '2026-02-01',
            'reason' => null,
            'created_by_id' => User::factory(),
            'annulled_at' => null,
            'annulled_by_id' => null,
            'annulment_reason' => null,
        ];
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn (): array => [
            'company_id' => $project->company_id,
            'project_id' => $project->id,
        ]);
    }
}
