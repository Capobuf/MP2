<?php

namespace App\Actions\Operations;

use App\Domain\Company\AuditEventType;
use App\Domain\Expenses\Decimal;
use App\Domain\Expenses\ExpenseAuditSnapshot;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SetExpenseReversed
{
    public function execute(User $actor, Expense $expense, bool $reversed, string $reason, string $operationId): Expense
    {
        /** @var array{reason: string, operation_id: string} $validated */
        $validated = Validator::make([
            'reason' => trim($reason),
            'operation_id' => $operationId,
        ], [
            'reason' => ['required', 'string'],
            'operation_id' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $expense, $reversed, $validated): Expense {
            $company = Company::query()->lockForUpdate()->findOrFail($expense->company_id);
            $exercise = Exercise::query()->lockForUpdate()->findOrFail($expense->exercise_id);
            $lockedExpense = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            $lockedExpense->lines()->orderBy('id')->lockForUpdate()->get();
            Gate::forUser($actor)->authorize('update', $lockedExpense);
            $eventType = $reversed ? AuditEventType::ExpenseReversed : AuditEventType::ExpenseRestored;

            $existing = AuditEvent::query()->where('operation_id', $validated['operation_id'])->first();
            if ($existing !== null) {
                if ($existing->eventType() !== $eventType || $existing->subject_type !== Expense::class || $existing->subject_id !== $lockedExpense->id) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return $lockedExpense;
            }

            if ($reversed === $lockedExpense->isReversed()) {
                return $lockedExpense;
            }
            if (! $exercise->isOpen()) {
                throw ValidationException::withMessages(['expense' => 'L’Esercizio deve essere Aperto.']);
            }
            if ($reversed && $lockedExpense->hasActuals()) {
                throw ValidationException::withMessages(['expense' => 'La Spesa contiene Effettivi attivi non nulli e non può essere stornata.']);
            }

            $allocationBefore = $lockedExpense->allocation();
            $actualBefore = $lockedExpense->actual();
            $before = ExpenseAuditSnapshot::expense($lockedExpense, true);
            $lockedExpense->reversed_at = $reversed ? now() : null;
            $lockedExpense->revision++;
            $lockedExpense->save();
            $exercise->increment('revision');

            $allocationAfter = $lockedExpense->allocation();
            $actualAfter = $lockedExpense->actual();
            AuditEvent::query()->create([
                'operation_id' => $validated['operation_id'],
                'company_id' => $company->id,
                'actor_id' => $actor->id,
                'event_type' => $eventType,
                'subject_type' => Expense::class,
                'subject_id' => $lockedExpense->id,
                'affected_exercise_ids' => [$exercise->id],
                'effective_from' => now($company->timezone)->toDateString(),
                'previous_value' => $before,
                'new_value' => ExpenseAuditSnapshot::expense($lockedExpense, true),
                'allocated_impact_by_exercise' => ExpenseAuditSnapshot::impact($exercise->id, Decimal::subtract($allocationAfter, $allocationBefore)),
                'actual_impact_by_exercise' => ExpenseAuditSnapshot::impact($exercise->id, Decimal::subtract($actualAfter, $actualBefore)),
                'reason' => $validated['reason'],
            ]);

            return $lockedExpense;
        });
    }
}
