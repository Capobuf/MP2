<?php

namespace App\Domain\Projects;

use App\Domain\Expenses\Decimal;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;
use App\Models\ProjectTransition;

final class ProjectAuditSnapshot
{
    /** @return array<string, mixed> */
    public static function project(Project $project): array
    {
        return [
            'id' => $project->id,
            'origin_key' => $project->originKey(),
            'title' => $project->title,
            'description' => $project->description,
            'notes' => $project->notes,
            'initial_state' => $project->initialState()->value,
            'initial_effective_date' => $project->initialEffectiveDate()->toDateString(),
            'archived_at' => $project->archivedAt()?->toISOString(),
            'revision' => $project->revision,
        ];
    }

    /** @return array<string, mixed> */
    public static function transition(ProjectTransition $transition): array
    {
        return [
            'id' => $transition->id,
            'project_id' => $transition->project_id,
            'from_state' => self::state($transition->from_state)->value,
            'to_state' => self::state($transition->to_state)->value,
            'effective_date' => $transition->effectiveDate()->toDateString(),
            'reason' => $transition->reason,
            'created_by_id' => $transition->created_by_id,
            'annulled_at' => $transition->annulledAt()?->toISOString(),
            'annulled_by_id' => $transition->annulled_by_id,
            'annulment_reason' => $transition->annulment_reason,
        ];
    }

    /** @return array<string, int|null> */
    public static function classification(ProjectExerciseClassification $classification): array
    {
        return [
            'id' => $classification->id,
            'project_id' => $classification->project_id,
            'exercise_id' => $classification->exercise_id,
            'cost_center_id' => $classification->cost_center_id,
        ];
    }

    /** @return array{result: string, variance_before: string, variance_after: string}|null */
    public static function overspend(string $before, string $after): ?array
    {
        $result = ProjectOverspend::detect($before, $after);

        if ($result === ProjectOverspendResult::None) {
            return null;
        }

        return [
            'result' => $result->value,
            'variance_before' => Decimal::money($before),
            'variance_after' => Decimal::money($after),
        ];
    }

    private static function state(mixed $value): ProjectState
    {
        return $value instanceof ProjectState ? $value : ProjectState::from((string) $value);
    }
}
