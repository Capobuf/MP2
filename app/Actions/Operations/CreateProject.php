<?php

namespace App\Actions\Operations;

use App\Domain\Company\AuditEventType;
use App\Domain\Expenses\ExerciseStatus;
use App\Domain\Expenses\ExpenseAuditSnapshot;
use App\Domain\Projects\ProjectAuditSnapshot;
use App\Domain\Projects\ProjectState;
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
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CreateProject
{
    /** @param array<string, mixed> $input */
    public function execute(User $actor, Company $company, array $input, string $operationId): Project
    {
        $normalized = [
            'title' => $this->trim($input['title'] ?? null),
            'description' => $this->nullableTrim($input['description'] ?? null),
            'notes' => $this->nullableTrim($input['notes'] ?? null),
            'initial_state' => $input['initial_state'] ?? null,
            'initial_effective_date' => $input['initial_effective_date'] ?? null,
            'exercise_id' => $input['exercise_id'] ?? null,
            'cost_center_id' => $input['cost_center_id'] ?? null,
            'operation_id' => $operationId,
        ];
        /** @var array{title: string, description: ?string, notes: ?string, initial_state: string, initial_effective_date: string, exercise_id: int, cost_center_id: ?int, operation_id: string} $validated */
        $validated = Validator::make($normalized, [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'initial_state' => ['required', Rule::enum(ProjectState::class)],
            'initial_effective_date' => ['required', 'date_format:Y-m-d'],
            'exercise_id' => ['required', 'integer'],
            'cost_center_id' => ['nullable', 'integer'],
            'operation_id' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $company, $validated): Project {
            $lockedCompany = Company::query()->lockForUpdate()->findOrFail($company->id);
            Gate::forUser($actor)->authorize('create', [Project::class, $lockedCompany]);

            $existing = AuditEvent::query()->where('operation_id', $validated['operation_id'])->first();
            if ($existing !== null) {
                if ($existing->eventType() !== AuditEventType::ProjectCreated
                    || $existing->subject_type !== Project::class
                    || $existing->company_id !== $lockedCompany->id) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return Project::query()->findOrFail($existing->subject_id);
            }

            $exercise = Exercise::query()->lockForUpdate()->find($validated['exercise_id']);
            if ($exercise === null || $exercise->company_id !== $lockedCompany->id || $exercise->status() !== ExerciseStatus::Open) {
                throw ValidationException::withMessages(['exercise_id' => 'Selezionare un Esercizio Aperto di questa Azienda.']);
            }

            $costCenter = null;
            if ($validated['cost_center_id'] !== null) {
                $costCenter = CostCenter::query()->lockForUpdate()->find($validated['cost_center_id']);
                if ($costCenter === null || $costCenter->company_id !== $lockedCompany->id || $costCenter->isArchived()) {
                    throw ValidationException::withMessages(['cost_center_id' => 'Il Centro di Costo deve essere attivo in questa Azienda.']);
                }
            }

            $project = Project::query()->create([
                'company_id' => $lockedCompany->id,
                'title' => $validated['title'],
                'description' => $validated['description'],
                'notes' => $validated['notes'],
                'initial_state' => ProjectState::from($validated['initial_state']),
                'initial_effective_date' => $validated['initial_effective_date'],
            ]);
            $classification = ProjectExerciseClassification::query()->create([
                'company_id' => $lockedCompany->id,
                'project_id' => $project->id,
                'exercise_id' => $exercise->id,
                'cost_center_id' => $costCenter?->id,
            ]);
            $zeroImpact = ExpenseAuditSnapshot::impact($exercise->id, '0');

            AuditEvent::query()->create([
                'operation_id' => $validated['operation_id'],
                'company_id' => $lockedCompany->id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::ProjectCreated,
                'subject_type' => Project::class,
                'subject_id' => $project->id,
                'affected_exercise_ids' => [$exercise->id],
                'effective_from' => $validated['initial_effective_date'],
                'previous_value' => null,
                'new_value' => [
                    ...ProjectAuditSnapshot::project($project),
                    'initial_classification' => ProjectAuditSnapshot::classification($classification),
                ],
                'allocated_impact_by_exercise' => $zeroImpact,
                'actual_impact_by_exercise' => $zeroImpact,
            ]);

            return $project;
        });
    }

    private function trim(mixed $value): mixed
    {
        return is_string($value) ? trim($value) : $value;
    }

    private function nullableTrim(mixed $value): mixed
    {
        $value = $this->trim($value);

        return $value === '' ? null : $value;
    }
}
