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

class CreateExpenseLine
{
    /** @param array<string, mixed> $input */
    public function execute(User $actor, Expense $expense, array $input, string $operationId): ExpenseLine
    {
        Validator::make(['operation_id' => $operationId], ['operation_id' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($actor, $expense, $input, $operationId): ExpenseLine {
            $company = Company::query()->lockForUpdate()->findOrFail($expense->company_id);
            $exercise = Exercise::query()->lockForUpdate()->findOrFail($expense->exercise_id);
            $lockedExpense = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            Gate::forUser($actor)->authorize('create', [ExpenseLine::class, $lockedExpense]);

            $existing = AuditEvent::query()->where('operation_id', $operationId)->first();
            if ($existing !== null) {
                if ($existing->eventType() !== AuditEventType::ExpenseLineCreated
                    || $existing->subject_type !== ExpenseLine::class
                    || $existing->company_id !== $company->id) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return ExpenseLine::query()->findOrFail($existing->subject_id);
            }

            $this->assertMutable($lockedExpense, $exercise);
            $allocationBefore = $lockedExpense->allocation();
            $actualBefore = $lockedExpense->actual();
            $validated = ManualExpenseLine::validate($input, $company, $exercise);
            $line = $lockedExpense->lines()->create($validated);
            $lockedExpense->increment('revision');
            $exercise->increment('revision');
            $lockedExpense->refresh();

            AuditEvent::query()->create([
                'operation_id' => $operationId,
                'company_id' => $company->id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::ExpenseLineCreated,
                'subject_type' => ExpenseLine::class,
                'subject_id' => $line->id,
                'affected_exercise_ids' => [$exercise->id],
                'effective_from' => now($company->timezone)->toDateString(),
                'previous_value' => null,
                'new_value' => ExpenseAuditSnapshot::line($line),
                'allocated_impact_by_exercise' => ExpenseAuditSnapshot::impact($exercise->id, Decimal::subtract($lockedExpense->allocation(), $allocationBefore)),
                'actual_impact_by_exercise' => ExpenseAuditSnapshot::impact($exercise->id, Decimal::subtract($lockedExpense->actual(), $actualBefore)),
                'reference_type' => Expense::class,
                'reference_id' => $lockedExpense->id,
            ]);

            return $line;
        });
    }

    private function assertMutable(Expense $expense, Exercise $exercise): void
    {
        if (! $exercise->isOpen()) {
            throw ValidationException::withMessages(['expense' => 'L’Esercizio deve essere Aperto.']);
        }
        if ($expense->isReversed()) {
            throw ValidationException::withMessages(['expense' => 'Ripristinare la Spesa prima di modificare le Righe.']);
        }
    }
}
