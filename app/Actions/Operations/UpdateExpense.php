<?php

namespace App\Actions\Operations;

use App\Domain\Company\AuditEventType;
use App\Domain\Expenses\ExpenseAuditSnapshot;
use App\Domain\Expenses\ExpenseImpactPlan;
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

class UpdateExpense
{
    /** @param array<string, mixed> $input */
    public function preview(User $actor, Expense $expense, array $input): ExpenseImpactPlan
    {
        Gate::forUser($actor)->authorize('update', $expense);
        $normalized = $this->normalizeClassification($expense, $input);
        $source = $expense->exercise;
        $target = Exercise::query()->find($normalized['exercise_id']);
        $this->validateExercisesAndReferences($expense, $source, $target, $normalized);

        return ExpenseImpactPlan::build(
            $expense,
            $source,
            $target,
            $normalized['supplier_id'],
            $normalized['direct_cost_center_id'],
            $normalized['reason'],
        );
    }

    public function confirm(User $actor, Expense $expense, ExpenseImpactPlan $preview, string $operationId): Expense
    {
        Validator::make(['operation_id' => $operationId], ['operation_id' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($actor, $expense, $preview, $operationId): Expense {
            $company = Company::query()->lockForUpdate()->findOrFail($expense->company_id);
            $exerciseIds = array_values(array_unique([$preview->sourceExerciseId, $preview->targetExerciseId]));
            sort($exerciseIds);
            /** @var array<int, Exercise> $exercises */
            $exercises = Exercise::query()->whereIn('id', $exerciseIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id')->all();
            $lockedExpense = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            $lockedExpense->lines()->orderBy('id')->lockForUpdate()->get();
            Gate::forUser($actor)->authorize('update', $lockedExpense);

            $existing = AuditEvent::query()->where('operation_id', $operationId)->first();
            if ($existing !== null) {
                if ($existing->eventType() !== AuditEventType::ExpenseMovedOrReclassified
                    || $existing->subject_type !== Expense::class
                    || $existing->subject_id !== $lockedExpense->id) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return $lockedExpense;
            }

            if ($preview->expenseId !== $lockedExpense->id || $preview->expenseRevision !== $lockedExpense->revision) {
                throw ValidationException::withMessages(['preview' => 'La Spesa è cambiata: calcolare una nuova anteprima.']);
            }
            foreach ($preview->exerciseRevisions as $id => $revision) {
                if (! isset($exercises[(int) $id]) || $exercises[(int) $id]->revision !== $revision) {
                    throw ValidationException::withMessages(['preview' => 'Un Esercizio è cambiato: calcolare una nuova anteprima.']);
                }
            }

            $source = $exercises[$preview->sourceExerciseId] ?? null;
            $target = $exercises[$preview->targetExerciseId] ?? null;
            $normalized = [
                'exercise_id' => $preview->targetExerciseId,
                'supplier_id' => $preview->supplierId,
                'direct_cost_center_id' => $preview->directCostCenterId,
                'reason' => $preview->reason,
            ];
            $this->validateExercisesAndReferences($lockedExpense, $source, $target, $normalized, lockReferences: true);
            $current = ExpenseImpactPlan::build($lockedExpense, $source, $target, $preview->supplierId, $preview->directCostCenterId, $preview->reason);
            if (! hash_equals($preview->fingerprint(), $current->fingerprint())) {
                throw ValidationException::withMessages(['preview' => 'L’impatto è cambiato: calcolare una nuova anteprima.']);
            }

            $before = ExpenseAuditSnapshot::expense($lockedExpense, true);
            $lockedExpense->fill([
                'exercise_id' => $preview->targetExerciseId,
                'supplier_id' => $preview->supplierId,
                'direct_cost_center_id' => $preview->directCostCenterId,
            ]);
            if (! $lockedExpense->isDirty()) {
                return $lockedExpense;
            }
            $lockedExpense->revision++;
            $lockedExpense->save();
            foreach ($exerciseIds as $exerciseId) {
                $exercises[$exerciseId]->increment('revision');
            }
            $lockedExpense->refresh();

            AuditEvent::query()->create([
                'operation_id' => $operationId,
                'company_id' => $company->id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::ExpenseMovedOrReclassified,
                'subject_type' => Expense::class,
                'subject_id' => $lockedExpense->id,
                'affected_exercise_ids' => $exerciseIds,
                'effective_from' => now($company->timezone)->toDateString(),
                'previous_value' => $before,
                'new_value' => ExpenseAuditSnapshot::expense($lockedExpense, true),
                'allocated_impact_by_exercise' => $preview->allocatedImpact(),
                'actual_impact_by_exercise' => $preview->actualImpact(),
                'reason' => $preview->reason,
            ]);

            return $lockedExpense;
        });
    }

    /** @param array<string, mixed> $input */
    public function updateDetails(User $actor, Expense $expense, array $input, string $operationId): Expense
    {
        $normalized = [
            'description' => is_string($input['description'] ?? null) ? trim($input['description']) : ($input['description'] ?? null),
            'notes' => $this->nullableTrim($input['notes'] ?? null),
            'operation_id' => $operationId,
        ];
        /** @var array{description: string, notes: ?string, operation_id: string} $validated */
        $validated = Validator::make($normalized, [
            'description' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'operation_id' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $expense, $validated): Expense {
            $company = Company::query()->lockForUpdate()->findOrFail($expense->company_id);
            $exercise = Exercise::query()->lockForUpdate()->findOrFail($expense->exercise_id);
            $lockedExpense = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            Gate::forUser($actor)->authorize('update', $lockedExpense);
            $existing = AuditEvent::query()->where('operation_id', $validated['operation_id'])->first();
            if ($existing !== null) {
                if ($existing->eventType() !== AuditEventType::ExpenseUpdated || $existing->subject_id !== $lockedExpense->id) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return $lockedExpense;
            }
            if (! $exercise->isOpen()) {
                throw ValidationException::withMessages(['expense' => 'L’Esercizio deve essere Aperto.']);
            }
            $before = ExpenseAuditSnapshot::expense($lockedExpense);
            $lockedExpense->fill(['description' => $validated['description'], 'notes' => $validated['notes']]);
            if (! $lockedExpense->isDirty()) {
                return $lockedExpense;
            }
            $lockedExpense->revision++;
            $lockedExpense->save();

            AuditEvent::query()->create([
                'operation_id' => $validated['operation_id'],
                'company_id' => $company->id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::ExpenseUpdated,
                'subject_type' => Expense::class,
                'subject_id' => $lockedExpense->id,
                'affected_exercise_ids' => [$exercise->id],
                'effective_from' => now($company->timezone)->toDateString(),
                'previous_value' => $before,
                'new_value' => ExpenseAuditSnapshot::expense($lockedExpense),
                'allocated_impact_by_exercise' => ExpenseAuditSnapshot::impact($exercise->id, '0'),
                'actual_impact_by_exercise' => ExpenseAuditSnapshot::impact($exercise->id, '0'),
            ]);

            return $lockedExpense;
        });
    }

    /** @param array<string, mixed> $input
     * @return array{exercise_id: int, supplier_id: ?int, direct_cost_center_id: ?int, reason: ?string}
     */
    private function normalizeClassification(Expense $expense, array $input): array
    {
        /** @var array{exercise_id: int, supplier_id: ?int, direct_cost_center_id: ?int, reason: ?string} $validated */
        $validated = Validator::make([
            'exercise_id' => $input['exercise_id'] ?? $expense->exercise_id,
            'supplier_id' => array_key_exists('supplier_id', $input) ? $input['supplier_id'] : $expense->supplier_id,
            'direct_cost_center_id' => array_key_exists('direct_cost_center_id', $input) ? $input['direct_cost_center_id'] : $expense->direct_cost_center_id,
            'reason' => $this->nullableTrim($input['reason'] ?? null),
        ], [
            'exercise_id' => ['required', 'integer'],
            'supplier_id' => ['nullable', 'integer'],
            'direct_cost_center_id' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string'],
        ])->validate();

        return $validated;
    }

    /** @param array{exercise_id: int, supplier_id: ?int, direct_cost_center_id: ?int, reason: ?string} $input */
    private function validateExercisesAndReferences(Expense $expense, ?Exercise $source, ?Exercise $target, array $input, bool $lockReferences = false): void
    {
        if ($source === null || $target === null || $source->company_id !== $expense->company_id || $target->company_id !== $expense->company_id) {
            throw ValidationException::withMessages(['exercise_id' => 'Esercizio non disponibile per questa Azienda.']);
        }
        if (! $source->isOpen() || ! $target->isOpen()) {
            throw ValidationException::withMessages(['exercise_id' => 'Sorgente e destinazione devono essere Esercizi Aperti.']);
        }
        if (! $source->is($target) && $expense->hasActuals()) {
            if ($input['reason'] === null) {
                throw ValidationException::withMessages(['reason' => 'Il motivo è obbligatorio per spostare una Spesa con Effettivi.']);
            }
            if ($target->year > now($expense->company->timezone)->year) {
                throw ValidationException::withMessages(['exercise_id' => 'Una Spesa con Effettivi non può essere spostata in un anno futuro.']);
            }
        }

        $this->validateReference(Supplier::class, $input['supplier_id'], $expense->supplier_id, $expense->company_id, 'supplier_id', $lockReferences);
        $this->validateReference(CostCenter::class, $input['direct_cost_center_id'], $expense->direct_cost_center_id, $expense->company_id, 'direct_cost_center_id', $lockReferences);
    }

    /** @param class-string<Supplier|CostCenter> $model */
    private function validateReference(string $model, ?int $id, ?int $currentId, int $companyId, string $field, bool $lock): void
    {
        if ($id === null) {
            return;
        }
        $query = $model::query();
        if ($lock) {
            $query->lockForUpdate();
        }
        $record = $query->find($id);
        if ($record === null || $record->company_id !== $companyId || ($id !== $currentId && $record->isArchived())) {
            throw ValidationException::withMessages([$field => 'Il riferimento selezionato non è attivo in questa Azienda.']);
        }
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
