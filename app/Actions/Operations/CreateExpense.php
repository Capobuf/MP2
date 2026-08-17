<?php

namespace App\Actions\Operations;

use App\Domain\Company\AuditEventType;
use App\Domain\Expenses\ExpenseAuditSnapshot;
use App\Domain\Expenses\ManualExpenseLine;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CreateExpense
{
    /** @param array<string, mixed> $input */
    public function execute(User $actor, Company $company, array $input, string $operationId): Expense
    {
        $normalized = [
            ...$input,
            'supplier_id' => $input['supplier_id'] ?? null,
            'direct_cost_center_id' => $input['direct_cost_center_id'] ?? null,
            'description' => $this->trim($input['description'] ?? null),
            'notes' => $this->nullableTrim($input['notes'] ?? null),
            'operation_id' => $operationId,
        ];
        /** @var array{exercise_id: int, supplier_id: ?int, direct_cost_center_id: ?int, description: string, notes: ?string, lines: array<int, array<string, mixed>>, operation_id: string} $validated */
        $validated = Validator::make($normalized, [
            'exercise_id' => ['required', 'integer'],
            'supplier_id' => ['nullable', 'integer'],
            'direct_cost_center_id' => ['nullable', 'integer'],
            'description' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*' => ['required', 'array'],
            'operation_id' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $company, $validated): Expense {
            $lockedCompany = Company::query()->lockForUpdate()->findOrFail($company->id);
            Gate::forUser($actor)->authorize('create', [Expense::class, $lockedCompany]);

            $existing = AuditEvent::query()->where('operation_id', $validated['operation_id'])->first();
            if ($existing !== null) {
                if ($existing->eventType() !== AuditEventType::ExpenseCreated
                    || $existing->subject_type !== Expense::class
                    || $existing->company_id !== $lockedCompany->id) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return Expense::query()->findOrFail($existing->subject_id);
            }

            $exercise = Exercise::query()->lockForUpdate()->find($validated['exercise_id']);
            if ($exercise === null || $exercise->company_id !== $lockedCompany->id) {
                throw ValidationException::withMessages(['exercise_id' => 'Esercizio non disponibile per questa Azienda.']);
            }
            if (! $exercise->isOpen()) {
                throw ValidationException::withMessages(['exercise_id' => 'L’Esercizio deve essere Aperto.']);
            }

            $supplier = $this->activeReference(Supplier::class, $validated['supplier_id'], $lockedCompany, 'supplier_id');
            $costCenter = $this->activeReference(CostCenter::class, $validated['direct_cost_center_id'], $lockedCompany, 'direct_cost_center_id');
            $lines = array_map(
                fn (array $line): array => ManualExpenseLine::validate($line, $lockedCompany, $exercise),
                $validated['lines'],
            );

            $expense = Expense::query()->create([
                'company_id' => $lockedCompany->id,
                'exercise_id' => $exercise->id,
                'supplier_id' => $supplier?->id,
                'direct_cost_center_id' => $costCenter?->id,
                'description' => $validated['description'],
                'notes' => $validated['notes'],
            ]);
            foreach ($lines as $line) {
                $expense->lines()->create($line);
            }
            $exercise->increment('revision');
            $expense->refresh();

            AuditEvent::query()->create([
                'operation_id' => $validated['operation_id'],
                'company_id' => $lockedCompany->id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::ExpenseCreated,
                'subject_type' => Expense::class,
                'subject_id' => $expense->id,
                'affected_exercise_ids' => [$exercise->id],
                'effective_from' => now($lockedCompany->timezone)->toDateString(),
                'previous_value' => null,
                'new_value' => ExpenseAuditSnapshot::expense($expense, true),
                'allocated_impact_by_exercise' => ExpenseAuditSnapshot::impact($exercise->id, $expense->allocation()),
                'actual_impact_by_exercise' => ExpenseAuditSnapshot::impact($exercise->id, $expense->actual()),
            ]);

            return $expense;
        });
    }

    /** @template TModel of Supplier|CostCenter
     * @param  class-string<TModel>  $model
     * @return TModel|null
     */
    private function activeReference(string $model, ?int $id, Company $company, string $field): Supplier|CostCenter|null
    {
        if ($id === null) {
            return null;
        }

        $record = $model::query()->lockForUpdate()->find($id);
        if ($record === null || $record->company_id !== $company->id || $record->isArchived()) {
            throw ValidationException::withMessages([$field => 'Il riferimento selezionato non è attivo in questa Azienda.']);
        }

        return $record;
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
