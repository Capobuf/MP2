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

class ReplaceProjectTransition
{
    /** @param array<string, mixed> $input */
    public function execute(User $actor, ProjectTransition $transition, array $input, string $operationId): ProjectTransition
    {
        /** @var array{from_state: string, to_state: string, effective_date: string, reason: ?string, replacement_reason: string, operation_id: string} $validated */
        $validated = Validator::make([
            'from_state' => $input['from_state'] ?? null,
            'to_state' => $input['to_state'] ?? null,
            'effective_date' => $input['effective_date'] ?? null,
            'reason' => $this->nullableTrim($input['reason'] ?? null),
            'replacement_reason' => $this->nullableTrim($input['replacement_reason'] ?? null),
            'operation_id' => $operationId,
        ], [
            'from_state' => ['required', Rule::enum(ProjectState::class)],
            'to_state' => ['required', Rule::enum(ProjectState::class)],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string'],
            'replacement_reason' => ['required', 'string'],
            'operation_id' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $transition, $validated): ProjectTransition {
            $company = Company::query()->lockForUpdate()->findOrFail($transition->company_id);
            $exercises = Exercise::query()->where('company_id', $company->id)->open()->orderBy('id')->lockForUpdate()->get();
            $project = Project::query()->lockForUpdate()->findOrFail($transition->project_id);
            $transitions = $project->transitions()->orderBy('effective_date')->orderBy('id')->lockForUpdate()->get();
            $lockedTransition = $transitions->firstWhere('id', $transition->id);
            abort_unless($lockedTransition instanceof ProjectTransition, 404);
            Gate::forUser($actor)->authorize('update', $lockedTransition);

            $existing = AuditEvent::query()->where('operation_id', $validated['operation_id'])->first();
            if ($existing !== null) {
                if ($existing->eventType() !== AuditEventType::ProjectTransitionReplaced
                    || $existing->subject_type !== ProjectTransition::class
                    || $existing->reference_id !== $project->id) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return ProjectTransition::query()->findOrFail($existing->subject_id);
            }

            $today = CarbonImmutable::now($company->timezone)->startOfDay();
            if ($lockedTransition->annulledAt() !== null || ! $lockedTransition->effectiveDate()->startOfDay()->greaterThan($today)) {
                throw ValidationException::withMessages(['transition' => 'Solo una transizione futura attiva può essere sostituita.']);
            }
            if (! CarbonImmutable::parse($validated['effective_date'])->startOfDay()->greaterThan($today)) {
                throw ValidationException::withMessages(['effective_date' => 'La transizione sostitutiva deve avere una data futura.']);
            }

            $from = ProjectState::from($validated['from_state']);
            $to = ProjectState::from($validated['to_state']);
            if ($from->transitionRequiresReason($to) && $validated['reason'] === null) {
                throw ValidationException::withMessages(['reason' => 'Il motivo è obbligatorio per questa transizione.']);
            }

            $beforeRows = $this->rows($transitions);
            $afterRows = array_map(
                fn (array $row): array => $row['id'] === $lockedTransition->id
                    ? [...$row, 'annulled_at' => $today->toISOString()]
                    : $row,
                $beforeRows,
            );
            $immediatelyBefore = CarbonImmutable::parse($validated['effective_date'])->subDay()->toDateString();
            $actualFrom = ProjectStateTimeline::stateAtDate(
                $project->initialState(),
                $project->initialEffectiveDate()->toDateString(),
                $afterRows,
                $immediatelyBefore,
            );
            if ($actualFrom !== $from) {
                throw ValidationException::withMessages(['from_state' => 'Lo stato di origine non coincide con lo stato immediatamente precedente alla nuova data.']);
            }
            $afterRows[] = [
                'id' => null,
                'from_state' => $from,
                'to_state' => $to,
                'effective_date' => $validated['effective_date'],
                'annulled_at' => null,
            ];
            try {
                ProjectStateTimeline::validate(
                    $project->initialState(),
                    $project->initialEffectiveDate()->toDateString(),
                    $afterRows,
                );
            } catch (\DomainException $exception) {
                throw ValidationException::withMessages(['transition' => $exception->getMessage()]);
            }

            $before = ProjectAuditSnapshot::transition($lockedTransition);
            $lockedTransition->update([
                'annulled_at' => now(),
                'annulled_by_id' => $actor->id,
                'annulment_reason' => $validated['replacement_reason'],
            ]);
            $replacement = ProjectTransition::query()->create([
                'company_id' => $company->id,
                'project_id' => $project->id,
                'from_state' => $from,
                'to_state' => $to,
                'effective_date' => $validated['effective_date'],
                'reason' => $validated['reason'],
                'created_by_id' => $actor->id,
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
                'event_type' => AuditEventType::ProjectTransitionReplaced,
                'subject_type' => ProjectTransition::class,
                'subject_id' => $replacement->id,
                'affected_exercise_ids' => $affectedIds,
                'effective_from' => $validated['effective_date'],
                'previous_value' => $before,
                'new_value' => ProjectAuditSnapshot::transition($replacement),
                'allocated_impact_by_exercise' => $this->zeroImpact($affectedIds),
                'actual_impact_by_exercise' => $this->zeroImpact($affectedIds),
                'reason' => $validated['replacement_reason'],
                'reference_type' => Project::class,
                'reference_id' => $project->id,
            ]);

            return $replacement;
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

    private function nullableTrim(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
