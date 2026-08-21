<?php

namespace App\Actions\Operations;

use App\Domain\Company\AuditEventType;
use App\Domain\Contracts\ContractClassificationImpactPlan;
use App\Domain\Expenses\ExpenseAuditSnapshot;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractExerciseClassification;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class UpdateContractClassification
{
    public function preview(User $actor, Contract $contract, Exercise $exercise, ?int $costCenterId): ContractClassificationImpactPlan
    {
        Gate::forUser($actor)->authorize('update', $contract);
        $this->validateReferences($contract, $exercise, $costCenterId);

        return ContractClassificationImpactPlan::build($contract, $exercise, $costCenterId);
    }

    public function confirm(User $actor, Contract $contract, ContractClassificationImpactPlan $preview, string $operationId): ContractExerciseClassification
    {
        Validator::make(['operation_id' => $operationId], ['operation_id' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($actor, $contract, $preview, $operationId): ContractExerciseClassification {
            $company = Company::query()->lockForUpdate()->findOrFail($contract->company_id);
            $exercise = Exercise::query()->lockForUpdate()->findOrFail($preview->exerciseId);
            $lockedContract = Contract::query()->lockForUpdate()->findOrFail($contract->id);
            $classification = $lockedContract->classifications()->where('exercise_id', $exercise->id)->lockForUpdate()->first();
            $expenses = $lockedContract->expenses()->where('exercise_id', $exercise->id)->orderBy('id')->lockForUpdate()->get();
            foreach ($expenses as $expense) {
                $expense->lines()->orderBy('id')->lockForUpdate()->get();
            }
            Gate::forUser($actor)->authorize('update', $lockedContract);

            $existing = AuditEvent::query()->where('operation_id', $operationId)->first();
            if ($existing !== null) {
                if ($existing->eventType() !== AuditEventType::ContractClassificationChanged
                    || $existing->reference_type !== Contract::class
                    || $existing->reference_id !== $lockedContract->id) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return ContractExerciseClassification::query()->findOrFail($existing->subject_id);
            }
            if ($preview->contractId !== $lockedContract->id
                || $preview->contractRevision !== $lockedContract->revision
                || $preview->exerciseRevision !== $exercise->revision
                || $preview->classificationId !== $classification?->id) {
                throw ValidationException::withMessages(['preview' => 'Il Contratto o l’Esercizio è cambiato: calcolare una nuova anteprima.']);
            }

            $this->validateReferences($lockedContract, $exercise, $preview->newCostCenterId, true, $classification?->cost_center_id);
            $current = ContractClassificationImpactPlan::build($lockedContract, $exercise, $preview->newCostCenterId);
            if (! hash_equals($preview->fingerprint(), $current->fingerprint())) {
                throw ValidationException::withMessages(['preview' => 'L’impatto è cambiato: calcolare una nuova anteprima.']);
            }
            if ($classification !== null && $classification->cost_center_id === $preview->newCostCenterId) {
                return $classification;
            }

            $before = $classification === null ? null : $this->snapshot($classification);
            $classification ??= new ContractExerciseClassification([
                'company_id' => $company->id,
                'contract_id' => $lockedContract->id,
                'exercise_id' => $exercise->id,
            ]);
            $classification->cost_center_id = $preview->newCostCenterId;
            $classification->save();
            $lockedContract->increment('revision');
            $exercise->increment('revision');

            AuditEvent::query()->create([
                'operation_id' => $operationId,
                'company_id' => $company->id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::ContractClassificationChanged,
                'subject_type' => ContractExerciseClassification::class,
                'subject_id' => $classification->id,
                'affected_exercise_ids' => [$exercise->id],
                'effective_from' => now($company->timezone)->toDateString(),
                'previous_value' => $before,
                'new_value' => [
                    ...$this->snapshot($classification),
                    'affected_expense_ids' => $preview->expenseIds,
                    'allocation_reclassified' => $preview->allocation,
                    'actual_reclassified' => $preview->actual,
                ],
                'allocated_impact_by_exercise' => ExpenseAuditSnapshot::impact($exercise->id, '0.00'),
                'actual_impact_by_exercise' => ExpenseAuditSnapshot::impact($exercise->id, '0.00'),
                'reference_type' => Contract::class,
                'reference_id' => $lockedContract->id,
            ]);

            return $classification;
        });
    }

    private function validateReferences(Contract $contract, Exercise $exercise, ?int $costCenterId, bool $lock = false, ?int $currentId = null): void
    {
        if ($exercise->company_id !== $contract->company_id || ! $exercise->isOpen()) {
            throw ValidationException::withMessages(['exercise_id' => 'La riclassificazione richiede un Esercizio Aperto della stessa Azienda.']);
        }
        if ($contract->isArchived()) {
            throw ValidationException::withMessages(['contract' => 'Ripristinare il Contratto prima di riclassificarlo.']);
        }
        if ($costCenterId === null) {
            return;
        }
        $query = CostCenter::query();
        if ($lock) {
            $query->lockForUpdate();
        }
        $costCenter = $query->find($costCenterId);
        if ($costCenter === null || $costCenter->company_id !== $contract->company_id
            || ($costCenter->isArchived() && $costCenterId !== $currentId)) {
            throw ValidationException::withMessages(['cost_center_id' => 'Il Centro di Costo selezionato non è attivo in questa Azienda.']);
        }
    }

    /** @return array{contract_id: int, exercise_id: int, cost_center_id: int|null} */
    private function snapshot(ContractExerciseClassification $classification): array
    {
        return [
            'contract_id' => $classification->contract_id,
            'exercise_id' => $classification->exercise_id,
            'cost_center_id' => $classification->cost_center_id,
        ];
    }
}
