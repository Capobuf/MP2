<?php

namespace App\Actions\Operations;

use App\Domain\Company\AuditEventType;
use App\Domain\Expenses\ExerciseStatus;
use App\Domain\Expenses\ExpenseAuditSnapshot;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Exercise;
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

            $exercise = Exercise::query()->create([
                'company_id' => $lockedCompany->id,
                'year' => $validated['year'],
                'status' => ExerciseStatus::Open,
            ]);
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
                'new_value' => ExpenseAuditSnapshot::exercise($exercise),
                'allocated_impact_by_exercise' => $zeroImpact,
                'actual_impact_by_exercise' => $zeroImpact,
            ]);

            return $exercise;
        });
    }
}
