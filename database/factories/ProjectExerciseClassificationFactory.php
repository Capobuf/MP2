<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Exercise;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProjectExerciseClassification> */
class ProjectExerciseClassificationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'project_id' => fn (array $attributes): int => Project::factory()->create([
                'company_id' => $attributes['company_id'],
            ])->id,
            'exercise_id' => fn (array $attributes): int => Exercise::factory()->create([
                'company_id' => $attributes['company_id'],
            ])->id,
            'cost_center_id' => null,
        ];
    }

    public function forProjectAndExercise(Project $project, Exercise $exercise): static
    {
        return $this->state(fn (): array => [
            'company_id' => $project->company_id,
            'project_id' => $project->id,
            'exercise_id' => $exercise->id,
        ]);
    }
}
