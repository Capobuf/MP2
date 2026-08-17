<?php

namespace App\Actions\Operations;

use App\Domain\Company\AuditEventType;
use App\Domain\Expenses\ExerciseStatus;
use App\Domain\Expenses\ExpenseAuditSnapshot;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CreateExercise
{
    /** @param array{year?: mixed} $input */
    public function execute(User $actor, Company $company, array $input, string $operationId): Exercise
    {
        /** @var array{year: int, operation_id: string} $validated */
        $validated = Validator::make([...$input, 'operation_id' => $operationId], [
            'year' => ['required', 'integer', 'between:1,9999'],
            'operation_id' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $company, $validated): Exercise {
            $lockedCompany = Company::query()->lockForUpdate()->findOrFail($company->id);
            Gate::forUser($actor)->authorize('create', [Exercise::class, $lockedCompany]);

            $existing = AuditEvent::query()->where('operation_id', $validated['operation_id'])->first();

            if ($existing !== null) {
                if (
                    $existing->eventType() !== AuditEventType::ExerciseCreated
                    || $existing->subject_type !== Exercise::class
                    || $existing->company_id !== $lockedCompany->id
                ) {
                    throw ValidationException::withMessages([
                        'operation_id' => 'Identificativo operazione già utilizzato.',
                    ]);
                }

                return Exercise::query()->findOrFail($existing->subject_id);
            }

            if (Exercise::query()->where('company_id', $lockedCompany->id)->where('year', $validated['year'])->exists()) {
                throw ValidationException::withMessages([
                    'year' => 'Esiste già un Esercizio per questo anno nell’Azienda.',
                ]);
            }

            $projects = Project::query()->where('company_id', $lockedCompany->id)->orderBy('id')->lockForUpdate()->get();

            $exercise = Exercise::query()->create([
                'company_id' => $lockedCompany->id,
                'year' => $validated['year'],
                'status' => ExerciseStatus::Open,
            ]);
            $classificationIds = [];
            foreach ($projects as $project) {
                $latest = ProjectExerciseClassification::query()
                    ->select('project_exercise_classifications.*')
                    ->join('exercises', 'exercises.id', '=', 'project_exercise_classifications.exercise_id')
                    ->where('project_exercise_classifications.project_id', $project->id)
                    ->orderByDesc('exercises.year')
                    ->orderByDesc('project_exercise_classifications.id')
                    ->lockForUpdate()
                    ->first();
                $classification = ProjectExerciseClassification::query()->create([
                    'company_id' => $lockedCompany->id,
                    'project_id' => $project->id,
                    'exercise_id' => $exercise->id,
                    'cost_center_id' => $latest?->cost_center_id,
                ]);
                $classificationIds[] = $classification->id;
                $project->increment('revision');
            }
            if ($projects->isNotEmpty()) {
                $exercise->increment('revision');
                $exercise->refresh();
            }
            $zeroImpact = ExpenseAuditSnapshot::impact($exercise->id, '0');

            AuditEvent::query()->create([
                'operation_id' => $validated['operation_id'],
                'company_id' => $lockedCompany->id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::ExerciseCreated,
                'subject_type' => Exercise::class,
                'subject_id' => $exercise->id,
                'affected_exercise_ids' => [$exercise->id],
                'effective_from' => now($lockedCompany->timezone)->toDateString(),
                'previous_value' => null,
                'new_value' => [
                    ...ExpenseAuditSnapshot::exercise($exercise),
                    'project_classification_ids' => $classificationIds,
                ],
                'allocated_impact_by_exercise' => $zeroImpact,
                'actual_impact_by_exercise' => $zeroImpact,
            ]);

            return $exercise;
        });
    }
}
