<?php

namespace App\Actions\Operations;

use App\Domain\Company\AuditEventType;
use App\Domain\Expenses\Decimal;
use App\Domain\Expenses\ExpenseAuditSnapshot;
use App\Domain\Expenses\ManualExpenseLine;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UpdateExpenseLine
{
    /** @param array<string, mixed> $input */
    public function execute(User $actor, ExpenseLine $line, array $input, string $operationId): ExpenseLine
    {
        Validator::make(['operation_id' => $operationId], ['operation_id' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($actor, $line, $input, $operationId): ExpenseLine {
            $expenseId = $line->expense_id;
            $unlockedExpense = Expense::query()->findOrFail($expenseId);
            $company = Company::query()->lockForUpdate()->findOrFail($unlockedExpense->company_id);
            $exercise = Exercise::query()->lockForUpdate()->findOrFail($unlockedExpense->exercise_id);
            $expense = Expense::query()->lockForUpdate()->findOrFail($expenseId);
            $lockedLine = ExpenseLine::query()->lockForUpdate()->findOrFail($line->id);
            Gate::forUser($actor)->authorize('update', $lockedLine);

            $existing = AuditEvent::query()->where('operation_id', $operationId)->first();
            if ($existing !== null) {
                if ($existing->eventType() !== AuditEventType::ExpenseLineUpdated
                    || $existing->subject_type !== ExpenseLine::class
                    || $existing->subject_id !== $lockedLine->id) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return $lockedLine;
            }

            if (! $exercise->isOpen() || $expense->isReversed()) {
                throw ValidationException::withMessages(['expense' => 'La Spesa deve essere Attiva in un Esercizio Aperto.']);
            }
            $allocationBefore = $expense->allocation();
            $actualBefore = $expense->actual();
            $before = ExpenseAuditSnapshot::line($lockedLine);
            $validated = ManualExpenseLine::validate($input, $company, $exercise, false);
            $lockedLine->fill($validated);

            if (! $lockedLine->isDirty()) {
                return $lockedLine;
            }

            $lockedLine->save();
            $expense->increment('revision');
            $exercise->increment('revision');
            $expense->refresh();

            AuditEvent::query()->create([
                'operation_id' => $operationId,
                'company_id' => $company->id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::ExpenseLineUpdated,
                'subject_type' => ExpenseLine::class,
                'subject_id' => $lockedLine->id,
                'affected_exercise_ids' => [$exercise->id],
                'effective_from' => now($company->timezone)->toDateString(),
                'previous_value' => $before,
                'new_value' => ExpenseAuditSnapshot::line($lockedLine),
                'allocated_impact_by_exercise' => ExpenseAuditSnapshot::impact($exercise->id, Decimal::subtract($expense->allocation(), $allocationBefore)),
                'actual_impact_by_exercise' => ExpenseAuditSnapshot::impact($exercise->id, Decimal::subtract($expense->actual(), $actualBefore)),
                'reference_type' => Expense::class,
                'reference_id' => $expense->id,
            ]);

            return $lockedLine;
        });
    }
}
