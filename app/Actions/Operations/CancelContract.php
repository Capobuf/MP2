<?php

namespace App\Actions\Operations;

use App\Domain\Company\AuditEventType;
use App\Domain\Contracts\ContractState;
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

class CancelContract
{
    public function __construct(private readonly RecalculateContractEstimates $recalculate) {}

    public function execute(User $actor, Contract $contract, string $reason, int $expectedRevision, string $operationId): ContractLifecycleFact
    {
        $data = Validator::make(['reason' => trim($reason), 'revision' => $expectedRevision, 'operation_id' => $operationId], [
            'reason' => ['required', 'string'], 'revision' => ['required', 'integer', 'min:0'], 'operation_id' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $contract, $data): ContractLifecycleFact {
            $company = Company::query()->lockForUpdate()->findOrFail($contract->company_id);
            $today = CarbonImmutable::now($company->timezone)->startOfDay();
            $exercises = Exercise::query()->whereBelongsTo($company, 'company')->open()->orderBy('id')->lockForUpdate()->get();
            $locked = Contract::query()->lockForUpdate()->findOrFail($contract->id);
            $facts = $locked->lifecycleFacts()->lockForUpdate()->get();
            Gate::forUser($actor)->authorize('update', $locked);
            $existing = AuditEvent::query()->where('operation_id', $data['operation_id'])->where('event_sequence', 0)->first();
            if ($existing !== null) {
                return ContractLifecycleFact::query()->findOrFail($existing->subject_id);
            }
            if ($locked->revision !== (int) $data['revision']) {
                throw ValidationException::withMessages(['revision' => 'Il Contratto è cambiato dopo l’anteprima.']);
            }
            $everActive = $locked->contractualStartDate()->startOfDay()->lessThanOrEqualTo($today)
                || $facts->contains(fn (ContractLifecycleFact $fact): bool => in_array($fact->type, ['activation', 'reactivation'], true)
                    && $fact->annulledAt() === null && $fact->stateChangeDate() !== null
                    && $fact->stateChangeDate()->startOfDay()->lessThanOrEqualTo($today));
            if ($locked->isArchived() || $everActive || $locked->stateAtDate($today->toDateString()) !== ContractState::Planned) {
                throw ValidationException::withMessages(['contract' => 'Può essere annullato soltanto un Contratto Pianificato mai attivato.']);
            }
            foreach ($facts as $fact) {
                if ($fact->annulledAt() === null && $fact->stateChangeDate() !== null && $fact->stateChangeDate()->startOfDay()->greaterThan($today)) {
                    $fact->update(['annulled_at' => now(), 'annulled_by_id' => $actor->id, 'annulment_reason' => $data['reason']]);
                }
            }
            foreach ($locked->conditions()->active()->lockForUpdate()->get() as $condition) {
                $condition->update(['annulled_at' => now(), 'annulled_by_id' => $actor->id, 'reason' => $data['reason']]);
            }
            $fact = ContractLifecycleFact::query()->create([
                'company_id' => $company->id, 'contract_id' => $locked->id, 'type' => 'cancellation',
                'declared_contractual_date' => $today->toDateString(), 'state_change_date' => $today->toDateString(),
                'reason' => $data['reason'], 'created_by_id' => $actor->id,
            ]);
            $locked->increment('revision');
            $exerciseIds = $exercises->pluck('id')->all();
            $sequence = 0;
            AuditEvent::query()->create([
                'operation_id' => $data['operation_id'], 'event_sequence' => $sequence++, 'company_id' => $company->id,
                'actor_id' => $actor->id, 'event_type' => AuditEventType::ContractCancelled,
                'subject_type' => ContractLifecycleFact::class, 'subject_id' => $fact->id, 'affected_exercise_ids' => $exerciseIds,
                'effective_from' => $today, 'previous_value' => ['state' => 'planned'], 'new_value' => ['state' => 'cancelled', 'contract_id' => $locked->id],
                'allocated_impact_by_exercise' => array_fill_keys(array_map('strval', $exerciseIds), '0.00'),
                'actual_impact_by_exercise' => array_fill_keys(array_map('strval', $exerciseIds), '0.00'),
                'reason' => $data['reason'], 'reference_type' => Contract::class, 'reference_id' => $locked->id,
            ]);
            $locked->unsetRelation('conditions')->unsetRelation('lifecycleFacts');
            $this->recalculate->recalculateWithinTransaction($actor, $locked, $exercises, $data['operation_id'], $sequence);

            return $fact->refresh();
        });
    }
}
