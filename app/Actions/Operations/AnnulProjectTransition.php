<?php

namespace App\Actions\Operations;

use App\Domain\Company\AuditEventType;
use App\Domain\Projects\ProjectAuditSnapshot;
use App\Domain\Projects\ProjectDeferralTerminalGuard;
use App\Domain\Projects\ProjectStateTimeline;
use App\Domain\Projects\ProjectTransitionImpact;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Project;
use App\Models\ProjectTransition;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AnnulProjectTransition
{
    public function execute(User $actor, ProjectTransition $transition, string $reason, string $operationId): ProjectTransition
    {
        /** @var array{reason: string, operation_id: string} $validated */
        $validated = Validator::make([
            'reason' => trim($reason),
            'operation_id' => $operationId,
        ], [
            'reason' => ['required', 'string'],
            'operation_id' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $transition, $validated): ProjectTransition {
            $company = Company::query()->lockForUpdate()->findOrFail($transition->company_id);
            $exercises = Exercise::query()->where('company_id', $company->id)->open()->orderBy('id')->lockForUpdate()->get();
            $project = Project::query()->lockForUpdate()->findOrFail($transition->project_id);
            $transitions = $project->transitions()->orderBy('effective_date')->orderBy('id')->lockForUpdate()->get();
            $deferrals = $project->deferrals()->with('sourceExercise')->orderBy('id')->lockForUpdate()->get();
            $lockedTransition = $transitions->firstWhere('id', $transition->id);
            abort_unless($lockedTransition instanceof ProjectTransition, 404);
            Gate::forUser($actor)->authorize('update', $lockedTransition);

            $existing = AuditEvent::query()->where('operation_id', $validated['operation_id'])->first();
            if ($existing !== null) {
                if ($existing->eventType() !== AuditEventType::ProjectTransitionAnnulled
                    || $existing->subject_type !== ProjectTransition::class
                    || $existing->subject_id !== $lockedTransition->id) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return $lockedTransition;
            }
            if ($lockedTransition->annulledAt() !== null) {
                return $lockedTransition;
            }

            $today = CarbonImmutable::now($company->timezone)->startOfDay();
            if (! $lockedTransition->effectiveDate()->startOfDay()->greaterThan($today)) {
                throw ValidationException::withMessages(['transition' => 'Una transizione già efficace non può essere annullata.']);
            }

            $beforeRows = $this->rows($transitions);
            $afterRows = array_map(
                fn (array $row): array => $row['id'] === $lockedTransition->id
                    ? [...$row, 'annulled_at' => $today->toISOString()]
                    : $row,
                $beforeRows,
            );
            try {
                ProjectStateTimeline::validate(
                    $project->initialState(),
                    $project->initialEffectiveDate()->toDateString(),
                    $afterRows,
                );
            } catch (\DomainException $exception) {
                throw ValidationException::withMessages(['transition' => $exception->getMessage()]);
            }
            ProjectDeferralTerminalGuard::validate($project, $deferrals, $afterRows);

            $before = ProjectAuditSnapshot::transition($lockedTransition);
            $lockedTransition->update([
                'annulled_at' => now(),
                'annulled_by_id' => $actor->id,
                'annulment_reason' => $validated['reason'],
            ]);
            $affectedIds = ProjectTransitionImpact::affectedExerciseIds(
                $project,
                $exercises,
                $beforeRows,
                $afterRows,
                $today,
            );
            $project->increment('revision');
            Exercise::query()->whereIn('id', $affectedIds)->increment('revision');

            AuditEvent::query()->create([
                'operation_id' => $validated['operation_id'],
                'company_id' => $company->id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::ProjectTransitionAnnulled,
                'subject_type' => ProjectTransition::class,
                'subject_id' => $lockedTransition->id,
                'affected_exercise_ids' => $affectedIds,
                'effective_from' => $today->toDateString(),
                'previous_value' => $before,
                'new_value' => ProjectAuditSnapshot::transition($lockedTransition),
                'allocated_impact_by_exercise' => $this->zeroImpact($affectedIds),
                'actual_impact_by_exercise' => $this->zeroImpact($affectedIds),
                'reason' => $validated['reason'],
                'reference_type' => Project::class,
                'reference_id' => $project->id,
            ]);

            return $lockedTransition;
        });
    }

    /** @param iterable<ProjectTransition> $transitions
     * @return list<array<string, mixed>>
     */
    private function rows(iterable $transitions): array
    {
        $rows = [];
        foreach ($transitions as $transition) {
            $rows[] = [
                'id' => $transition->id,
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
