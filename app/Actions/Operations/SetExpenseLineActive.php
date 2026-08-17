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

class SetExpenseLineActive
{
    public function execute(User $actor, ExpenseLine $line, bool $active, string $operationId): ExpenseLine
    {
        Validator::make(['operation_id' => $operationId], ['operation_id' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($actor, $line, $active, $operationId): ExpenseLine {
            $unlockedExpense = Expense::query()->findOrFail($line->expense_id);
            $company = Company::query()->lockForUpdate()->findOrFail($unlockedExpense->company_id);
            $exercise = Exercise::query()->lockForUpdate()->findOrFail($unlockedExpense->exercise_id);
            $expense = Expense::query()->lockForUpdate()->findOrFail($unlockedExpense->id);
            $lockedLine = ExpenseLine::query()->lockForUpdate()->findOrFail($line->id);
            Gate::forUser($actor)->authorize('update', $lockedLine);
            $eventType = $active ? AuditEventType::ExpenseLineRestored : AuditEventType::ExpenseLineAnnulled;

            $existing = AuditEvent::query()->where('operation_id', $operationId)->first();
            if ($existing !== null) {
                if ($existing->eventType() !== $eventType
                    || $existing->subject_type !== ExpenseLine::class
                    || $existing->subject_id !== $lockedLine->id) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return $lockedLine;
            }

            if (! $exercise->isOpen() || $expense->isReversed()) {
                throw ValidationException::withMessages(['expense' => 'La Spesa deve essere Attiva in un Esercizio Aperto.']);
            }
            if ($active === ! $lockedLine->isAnnulled()) {
                return $lockedLine;
            }
            if ($active) {
                ManualExpenseLine::validate([
                    'type' => $lockedLine->lineType()->value,
                    'amount' => $lockedLine->amount,
                    'quantity' => $lockedLine->quantity,
                    'unit_amount' => $lockedLine->unit_amount,
                    'unit_of_measure' => $lockedLine->unit_of_measure,
                    'note' => $lockedLine->note,
                    'amount_warning_acknowledged' => true,
                ], $company, $exercise, false);
            }

            $allocationBefore = $expense->allocation();
            $actualBefore = $expense->actual();
            $before = ExpenseAuditSnapshot::line($lockedLine);
            $lockedLine->annulled_at = $active ? null : now();
            $lockedLine->save();
            $expense->increment('revision');
            $exercise->increment('revision');
            $expense->refresh();

            AuditEvent::query()->create([
                'operation_id' => $operationId,
                'company_id' => $company->id,
                'actor_id' => $actor->id,
                'event_type' => $eventType,
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
