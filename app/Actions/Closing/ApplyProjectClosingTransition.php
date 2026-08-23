<?php

namespace App\Actions\Closing;

use App\Domain\Company\AuditEventType;
use App\Domain\Projects\ProjectAuditSnapshot;
use App\Domain\Projects\ProjectState;
use App\Domain\Projects\ProjectStateTimeline;
use App\Domain\Projects\ProjectTransitionImpact;
use App\Models\AuditEvent;
use App\Models\Exercise;
use App\Models\Project;
use App\Models\ProjectTransition;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class ApplyProjectClosingTransition
{
    /** @param Collection<int, Exercise> $openExercises */
    public function executeWithinTransaction(
        User $actor,
        Project $project,
        Exercise $exercise,
        ProjectState $finalState,
        ?string $reason,
        string $operationId,
        int &$sequence,
        Collection $openExercises,
    ): ?ProjectTransition {
        $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);
        $transitions = $lockedProject->transitions()->orderBy('effective_date')->orderBy('id')->lockForUpdate()->get();
        $effectiveDate = $exercise->year.'-12-31';
        $currentState = ProjectStateTimeline::stateAtDate(
            $lockedProject->initialState(),
            $lockedProject->initialEffectiveDate()->toDateString(),
            $transitions,
            $effectiveDate,
        );

        if ($currentState === $finalState) {
            return null;
        }
        if ($currentState === null || ! $currentState->canTransitionTo($finalState)) {
            throw ValidationException::withMessages([
                'project' => 'La decisione finale non è compatibile con lo stato del Progetto al 31 dicembre.',
            ]);
        }
        if ($currentState->transitionRequiresReason($finalState) && $reason === null) {
            throw ValidationException::withMessages([
                'reason' => 'La Nota è obbligatoria per la decisione di stato del Progetto.',
            ]);
        }

        $beforeRows = $this->rows($transitions);
        $afterRows = [...$beforeRows, [
            'from_state' => $currentState,
            'to_state' => $finalState,
            'effective_date' => $effectiveDate,
            'annulled_at' => null,
        ]];
        try {
            ProjectStateTimeline::validate(
                $lockedProject->initialState(),
                $lockedProject->initialEffectiveDate()->toDateString(),
                $afterRows,
            );
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages(['project' => $exception->getMessage()]);
        }

        $transition = ProjectTransition::query()->create([
            'company_id' => $lockedProject->company_id,
            'project_id' => $lockedProject->id,
            'from_state' => $currentState,
            'to_state' => $finalState,
            'effective_date' => $effectiveDate,
            'reason' => $reason,
            'created_by_id' => $actor->id,
        ]);

        $today = CarbonImmutable::now($lockedProject->company->timezone)->startOfDay();
        $affectedIds = ProjectTransitionImpact::affectedExerciseIds(
            $lockedProject,
            $openExercises,
            $beforeRows,
            $afterRows,
            $today,
        );
        $lockedProject->increment('revision');
        if ($affectedIds !== []) {
            Exercise::query()->whereIn('id', $affectedIds)->increment('revision');
        }

        AuditEvent::query()->create([
            'operation_id' => $operationId,
            'event_sequence' => $sequence++,
            'company_id' => $lockedProject->company_id,
            'actor_id' => $actor->id,
            'event_type' => AuditEventType::ProjectTransitionEffective,
            'subject_type' => ProjectTransition::class,
            'subject_id' => $transition->id,
            'affected_exercise_ids' => $affectedIds,
            'effective_from' => $effectiveDate,
            'previous_value' => ['project_id' => $lockedProject->id, 'state' => $currentState->value],
            'new_value' => ProjectAuditSnapshot::transition($transition),
            'allocated_impact_by_exercise' => $this->zeroImpact($affectedIds),
            'actual_impact_by_exercise' => $this->zeroImpact($affectedIds),
            'reason' => $reason,
            'reference_type' => Project::class,
            'reference_id' => $lockedProject->id,
        ]);

        return $transition;
    }

    /** @param iterable<ProjectTransition> $transitions
     * @return list<array<string, mixed>>
     */
    private function rows(iterable $transitions): array
    {
        $rows = [];
        foreach ($transitions as $transition) {
            $rows[] = [
                'from_state' => $transition->from_state,
                'to_state' => $transition->to_state,
                'effective_date' => $transition->effectiveDate()->toDateString(),
                'annulled_at' => $transition->annulledAt()?->toISOString(),
            ];
        }

        return $rows;
    }

    /** @param list<int> $exerciseIds
     * @return array<string, string>
     */
    private function zeroImpact(array $exerciseIds): array
    {
        return array_fill_keys(array_map('strval', $exerciseIds), '0.00');
    }
}
