<?php

namespace App\Actions\Operations;

use App\Domain\Company\AuditEventType;
use App\Domain\Contracts\ContractLifecycleRules;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractLifecycleFact;
use App\Models\Exercise;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AnnulContractLifecycleFact
{
    public function __construct(private readonly RecalculateContractEstimates $recalculate) {}

    public function execute(User $actor, ContractLifecycleFact $fact, string $reason, int $expectedRevision, string $operationId): ContractLifecycleFact
    {
        $data = Validator::make(['reason' => trim($reason), 'revision' => $expectedRevision, 'operation_id' => $operationId], [
            'reason' => ['required', 'string'], 'revision' => ['required', 'integer', 'min:0'], 'operation_id' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $fact, $data): ContractLifecycleFact {
            $company = Company::query()->lockForUpdate()->findOrFail($fact->company_id);
            $today = CarbonImmutable::now($company->timezone)->startOfDay();
            $exercises = Exercise::query()->whereBelongsTo($company, 'company')->open()->orderBy('id')->lockForUpdate()->get();
            $contract = Contract::query()->lockForUpdate()->findOrFail($fact->contract_id);
            $facts = $contract->lifecycleFacts()->lockForUpdate()->get();
            $lockedFact = $facts->firstWhere('id', $fact->id);
            abort_unless($lockedFact instanceof ContractLifecycleFact, 404);
            Gate::forUser($actor)->authorize('update', $lockedFact);
            $existing = AuditEvent::query()->where('operation_id', $data['operation_id'])->where('event_sequence', 0)->first();
            if ($existing !== null) {
                return $lockedFact;
            }
            if ($contract->revision !== (int) $data['revision']) {
                throw ValidationException::withMessages(['revision' => 'Il Contratto è cambiato dopo l’anteprima.']);
            }
            if ($lockedFact->annulledAt() !== null || $lockedFact->stateChangeDate() === null || ! $lockedFact->stateChangeDate()->startOfDay()->greaterThan($today)) {
                throw ValidationException::withMessages(['lifecycle' => 'Solo un evento futuro attivo può essere annullato.']);
            }

            $lockedFact->update(['annulled_at' => now(), 'annulled_by_id' => $actor->id, 'annulment_reason' => $data['reason']]);
            try {
                ContractLifecycleRules::validate($contract->contractualStartDate()->toDateString(), $facts);
            } catch (\DomainException $exception) {
                throw ValidationException::withMessages(['lifecycle' => $exception->getMessage()]);
            }
            $contract->increment('revision');
            $exerciseIds = $exercises->pluck('id')->all();
            $sequence = 0;
            $this->audit($actor, $contract, $lockedFact, $data['operation_id'], $sequence++, AuditEventType::ContractLifecycleFactAnnulled, $exerciseIds, $data['reason']);
            $contract->unsetRelation('conditions')->unsetRelation('lifecycleFacts');
            $this->recalculate->recalculateWithinTransaction($actor, $contract, $exercises, $data['operation_id'], $sequence);

            return $lockedFact->refresh();
        });
    }

    /** @param list<int> $exerciseIds */
    private function audit(User $actor, Contract $contract, ContractLifecycleFact $fact, string $operationId, int $sequence, AuditEventType $type, array $exerciseIds, string $reason): void
    {
        AuditEvent::query()->create([
            'operation_id' => $operationId, 'event_sequence' => $sequence, 'company_id' => $contract->company_id, 'actor_id' => $actor->id,
            'event_type' => $type, 'subject_type' => ContractLifecycleFact::class, 'subject_id' => $fact->id,
            'affected_exercise_ids' => $exerciseIds, 'effective_from' => $fact->declaredContractualDate(),
            'previous_value' => ['annulled' => false], 'new_value' => ['annulled' => true, 'contract_id' => $contract->id],
            'allocated_impact_by_exercise' => array_fill_keys(array_map('strval', $exerciseIds), '0.00'),
            'actual_impact_by_exercise' => array_fill_keys(array_map('strval', $exerciseIds), '0.00'),
            'reason' => $reason, 'reference_type' => Contract::class, 'reference_id' => $contract->id,
        ]);
    }
}
