<?php

namespace App\Domain\Projects;

use App\Models\Exercise;
use App\Models\Project;
use Carbon\CarbonImmutable;

final class ProjectTransitionImpact
{
    /**
     * @param  iterable<Exercise>  $exercises
     * @param  iterable<array<string, mixed>|object>  $beforeTransitions
     * @param  iterable<array<string, mixed>|object>  $afterTransitions
     * @return list<int>
     */
    public static function affectedExerciseIds(
        Project $project,
        iterable $exercises,
        iterable $beforeTransitions,
        iterable $afterTransitions,
        CarbonImmutable $today,
    ): array {
        $affected = [];

        foreach ($exercises as $exercise) {
            $referenceDate = ProjectAnnualReferenceDate::forYear($exercise->year, $today)->toDateString();
            $before = ProjectStateTimeline::stateAtDate(
                $project->initialState(),
                $project->initialEffectiveDate()->toDateString(),
                $beforeTransitions,
                $referenceDate,
            );
            $after = ProjectStateTimeline::stateAtDate(
                $project->initialState(),
                $project->initialEffectiveDate()->toDateString(),
                $afterTransitions,
                $referenceDate,
            );

            if ($before !== $after) {
                $affected[] = $exercise->id;
            }
        }

        sort($affected);

        return $affected;
    }
}
