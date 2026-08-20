<?php

namespace App\Actions\Operations;

use App\Domain\Company\AuditEventType;
use App\Domain\Projects\ProjectAuditSnapshot;
use App\Domain\Projects\ProjectState;
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
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CreateProjectTransition
{
    /** @param array<string, mixed> $input */
    public function execute(User $actor, Project $project, array $input, string $operationId): ProjectTransition
    {
        /** @var array{from_state: string, to_state: string, effective_date: string, reason: ?string, operation_id: string} $validated */
        $validated = Validator::make([
            'from_state' => $input['from_state'] ?? null,
            'to_state' => $input['to_state'] ?? null,
            'effective_date' => $input['effective_date'] ?? null,
            'reason' => $this->nullableTrim($input['reason'] ?? null),
            'operation_id' => $operationId,
        ], [
            'from_state' => ['required', Rule::enum(ProjectState::class)],
            'to_state' => ['required', Rule::enum(ProjectState::class)],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string'],
            'operation_id' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $project, $validated): ProjectTransition {
            $company = Company::query()->lockForUpdate()->findOrFail($project->company_id);
            $exercises = Exercise::query()->where('company_id', $company->id)->open()->orderBy('id')->lockForUpdate()->get();
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);
            $transitions = $lockedProject->transitions()->orderBy('effective_date')->orderBy('id')->lockForUpdate()->get();
            Gate::forUser($actor)->authorize('update', $lockedProject);

            $existing = AuditEvent::query()->where('operation_id', $validated['operation_id'])->first();
            if ($existing !== null) {
                if (! in_array($existing->eventType(), [AuditEventType::ProjectTransitionPlanned, AuditEventType::ProjectTransitionEffective], true)
                    || $existing->subject_type !== ProjectTransition::class
                    || $existing->reference_type !== Project::class
                    || $existing->reference_id !== $lockedProject->id) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return ProjectTransition::query()->findOrFail($existing->subject_id);
            }
            if ($lockedProject->isArchived()) {
                throw ValidationException::withMessages(['project' => 'Ripristinare il Progetto prima di pianificare una transizione.']);
            }

            $from = ProjectState::from($validated['from_state']);
            $to = ProjectState::from($validated['to_state']);
            $beforeRows = $this->rows($transitions);
            $immediatelyBefore = CarbonImmutable::parse($validated['effective_date'])->subDay()->toDateString();
            $actualFrom = ProjectStateTimeline::stateAtDate(
                $lockedProject->initialState(),
                $lockedProject->initialEffectiveDate()->toDateString(),
                $beforeRows,
                $immediatelyBefore,
            );
            if ($actualFrom !== $from) {
                throw ValidationException::withMessages(['from_state' => 'Lo stato di origine non coincide con lo stato immediatamente precedente alla data.']);
            }
            if ($from->transitionRequiresReason($to) && $validated['reason'] === null) {
                throw ValidationException::withMessages(['reason' => 'Il motivo è obbligatorio per questa transizione.']);
            }

            $candidate = [
                'from_state' => $from->value,
                'to_state' => $to->value,
                'effective_date' => $validated['effective_date'],
                'annulled_at' => null,
            ];
            $afterRows = [...$beforeRows, $candidate];
            try {
                ProjectStateTimeline::validate(
                    $lockedProject->initialState(),
                    $lockedProject->initialEffectiveDate()->toDateString(),
                    $afterRows,
                );
            } catch (\DomainException $exception) {
                throw ValidationException::withMessages(['transition' => $exception->getMessage()]);
            }

            $transition = ProjectTransition::query()->create([
                'company_id' => $company->id,
                'project_id' => $lockedProject->id,
                'from_state' => $from,
                'to_state' => $to,
                'effective_date' => $validated['effective_date'],
                'reason' => $validated['reason'],
                'created_by_id' => $actor->id,
            ]);
            $today = CarbonImmutable::now($company->timezone)->startOfDay();
            $affectedIds = ProjectTransitionImpact::affectedExerciseIds(
                $lockedProject,
                $exercises,
                $beforeRows,
                $afterRows,
                $today,
            );
            $lockedProject->increment('revision');
            Exercise::query()->whereIn('id', $affectedIds)->increment('revision');
            $eventType = CarbonImmutable::parse($validated['effective_date'])->startOfDay()->greaterThan($today)
                ? AuditEventType::ProjectTransitionPlanned
                : AuditEventType::ProjectTransitionEffective;

            AuditEvent::query()->create([
                'operation_id' => $validated['operation_id'],
                'company_id' => $company->id,
                'actor_id' => $actor->id,
                'event_type' => $eventType,
                'subject_type' => ProjectTransition::class,
                'subject_id' => $transition->id,
                'affected_exercise_ids' => $affectedIds,
                'effective_from' => $validated['effective_date'],
                'previous_value' => ['project_id' => $lockedProject->id, 'state' => $from->value],
                'new_value' => ProjectAuditSnapshot::transition($transition),
                'allocated_impact_by_exercise' => $this->zeroImpact($affectedIds),
                'actual_impact_by_exercise' => $this->zeroImpact($affectedIds),
                'reason' => $validated['reason'],
                'reference_type' => Project::class,
                'reference_id' => $lockedProject->id,
            ]);

            return $transition;
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

    private function nullableTrim(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
