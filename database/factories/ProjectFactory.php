<?php

namespace Database\Factories;

use App\Domain\Projects\ProjectState;
use App\Models\Company;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Project> */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            'notes' => fake()->optional()->sentence(),
            'initial_state' => ProjectState::Planned,
            'initial_effective_date' => '2026-01-01',
            'archived_at' => null,
            'revision' => 0,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['archived_at' => now()]);
    }
}
