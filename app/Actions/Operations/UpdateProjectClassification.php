<?php

namespace App\Actions\Operations;

use App\Domain\Company\AuditEventType;
use App\Domain\Expenses\ExpenseAuditSnapshot;
use App\Domain\Expenses\ExpenseLineType;
use App\Domain\Projects\ProjectAuditSnapshot;
use App\Domain\Projects\ProjectClassificationImpactPlan;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class UpdateProjectClassification
{
    public function preview(User $actor, Project $project, Exercise $exercise, ?int $costCenterId): ProjectClassificationImpactPlan
    {
        Gate::forUser($actor)->authorize('update', $project);
        $this->validateReferences($project, $exercise, $costCenterId);

        return ProjectClassificationImpactPlan::build($project, $exercise, $costCenterId);
    }

    public function confirm(User $actor, Project $project, ProjectClassificationImpactPlan $preview, string $operationId, ?string $reason = null): ProjectExerciseClassification
    {
        $reason = $this->nullableTrim($reason);
        Validator::make([
            'reason' => $reason,
            'operation_id' => $operationId,
        ], [
            'reason' => ['nullable', 'string'],
            'operation_id' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $project, $preview, $operationId, $reason): ProjectExerciseClassification {
            $company = Company::query()->lockForUpdate()->findOrFail($project->company_id);
            $exercise = Exercise::query()->lockForUpdate()->findOrFail($preview->exerciseId);
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);
            $classification = $lockedProject->classifications()->where('exercise_id', $exercise->id)->lockForUpdate()->first();
            $expenses = $lockedProject->expenses()->where('exercise_id', $exercise->id)->orderBy('id')->lockForUpdate()->get();
            foreach ($expenses as $expense) {
                $expense->lines()->orderBy('id')->lockForUpdate()->get();
            }
            Gate::forUser($actor)->authorize('update', $lockedProject);

            $existing = AuditEvent::query()->where('operation_id', $operationId)->first();
            if ($existing !== null) {
                if ($existing->eventType() !== AuditEventType::ProjectClassificationChanged
                    || $existing->reference_type !== Project::class
                    || $existing->reference_id !== $lockedProject->id) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return ProjectExerciseClassification::query()->findOrFail($existing->subject_id);
            }
            if ($preview->projectId !== $lockedProject->id
                || $preview->projectRevision !== $lockedProject->revision
                || $preview->exerciseRevision !== $exercise->revision
                || $preview->classificationId !== $classification?->id) {
                throw ValidationException::withMessages(['preview' => 'Il Progetto o l’Esercizio è cambiato: calcolare una nuova anteprima.']);
            }

            $this->validateReferences($lockedProject, $exercise, $preview->newCostCenterId, true, $classification?->cost_center_id);
            $current = ProjectClassificationImpactPlan::build($lockedProject, $exercise, $preview->newCostCenterId);
            if (! hash_equals($preview->fingerprint(), $current->fingerprint())) {
                throw ValidationException::withMessages(['preview' => 'L’impatto è cambiato: calcolare una nuova anteprima.']);
            }
            if ($classification !== null && $classification->cost_center_id === $preview->newCostCenterId) {
                return $classification;
            }
            if (($exercise->hasApprovedBudget() || $this->hasActuals($lockedProject, $exercise)) && $reason === null) {
                throw ValidationException::withMessages([
                    'reason' => 'La Nota è obbligatoria perché la riclassificazione interessa Effettivi o un Budget approvato.',
                ]);
            }

            $before = $classification === null ? null : ProjectAuditSnapshot::classification($classification);
            $classification ??= new ProjectExerciseClassification([
                'company_id' => $company->id,
                'project_id' => $lockedProject->id,
                'exercise_id' => $exercise->id,
            ]);
            $classification->cost_center_id = $preview->newCostCenterId;
            $classification->save();
            $lockedProject->increment('revision');
            $exercise->increment('revision');

            AuditEvent::query()->create([
                'operation_id' => $operationId,
                'company_id' => $company->id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::ProjectClassificationChanged,
                'subject_type' => ProjectExerciseClassification::class,
                'subject_id' => $classification->id,
                'affected_exercise_ids' => [$exercise->id],
                'effective_from' => now($company->timezone)->toDateString(),
                'previous_value' => $before,
                'new_value' => [
                    ...ProjectAuditSnapshot::classification($classification),
                    'affected_expense_ids' => $preview->expenseIds,
                    'allocation_reclassified' => $preview->allocation,
                    'actual_reclassified' => $preview->actual,
                ],
                'allocated_impact_by_exercise' => ExpenseAuditSnapshot::impact($exercise->id, '0.00'),
                'actual_impact_by_exercise' => ExpenseAuditSnapshot::impact($exercise->id, '0.00'),
                'reason' => $reason,
                'reference_type' => Project::class,
                'reference_id' => $lockedProject->id,
            ]);

            return $classification;
        });
    }

    private function hasActuals(Project $project, Exercise $exercise): bool
    {
        return $project->expenses()
            ->whereNull('reversed_at')
            ->where('exercise_id', $exercise->id)
            ->whereHas('lines', fn ($query) => $query
                ->whereNull('annulled_at')
                ->where('type', ExpenseLineType::Actual->value)
                ->where('amount', '!=', '0.00'))
            ->exists();
    }

    private function nullableTrim(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function validateReferences(Project $project, Exercise $exercise, ?int $costCenterId, bool $lock = false, ?int $currentId = null): void
    {
        if ($exercise->company_id !== $project->company_id || ! $exercise->isOpen()) {
            throw ValidationException::withMessages(['exercise_id' => 'La riclassificazione richiede un Esercizio Aperto della stessa Azienda.']);
        }
        if ($project->isArchived()) {
            throw ValidationException::withMessages(['project' => 'Ripristinare il Progetto prima di riclassificarlo.']);
        }
        if ($costCenterId === null) {
            return;
        }
        $query = CostCenter::query();
        if ($lock) {
            $query->lockForUpdate();
        }
        $costCenter = $query->find($costCenterId);
        if ($costCenter === null || $costCenter->company_id !== $project->company_id
            || ($costCenter->isArchived() && $costCenterId !== $currentId)) {
            throw ValidationException::withMessages(['cost_center_id' => 'Il Centro di Costo selezionato non è attivo in questa Azienda.']);
        }
    }
}
