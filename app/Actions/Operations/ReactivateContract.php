<?php

namespace App\Actions\Operations;

use App\Domain\Company\AuditEventType;
use App\Domain\Contracts\ContractAttributionMode;
use App\Domain\Contracts\ContractCycleType;
use App\Domain\Contracts\ContractState;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractLifecycleFact;
use App\Models\Exercise;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReactivateContract
{
    public function __construct(private readonly RecalculateContractEstimates $recalculate) {}

    /** @param array<string, mixed> $input */
    public function execute(User $actor, Contract $contract, array $input, string $operationId): ContractLifecycleFact
    {
        $condition = is_array($input['condition'] ?? null) ? $input['condition'] : [];
        $data = Validator::make([
            'start_date' => $input['start_date'] ?? null, 'next_expiry_date' => ($input['next_expiry_date'] ?? null) ?: null,
            'reason' => is_string($input['reason'] ?? null) ? trim($input['reason']) : null,
            'expected_revision' => $input['expected_revision'] ?? null, 'operation_id' => $operationId,
            'condition' => $condition,
        ], [
            'start_date' => ['required', 'date_format:Y-m-d'], 'next_expiry_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'reason' => ['required', 'string'], 'expected_revision' => ['required', 'integer', 'min:0'], 'operation_id' => ['required', 'uuid'],
            'condition' => ['required', 'array'], 'condition.amount' => ['required', 'decimal:0,2', 'min:0'],
            'condition.cycle' => ['required', Rule::enum(ContractCycleType::class)],
            'condition.attribution_mode' => ['required', Rule::enum(ContractAttributionMode::class)],
            'condition.valid_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'condition.valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:condition.valid_from'],
        ])->validate();

        return DB::transaction(function () use ($actor, $contract, $data): ContractLifecycleFact {
            $company = Company::query()->lockForUpdate()->findOrFail($contract->company_id);
            $exercises = Exercise::query()->whereBelongsTo($company, 'company')->open()->orderBy('id')->lockForUpdate()->get();
            $locked = Contract::query()->lockForUpdate()->findOrFail($contract->id);
            Gate::forUser($actor)->authorize('update', $locked);
            $existing = AuditEvent::query()->where('operation_id', $data['operation_id'])->where('event_sequence', 0)->first();
            if ($existing !== null) {
                if ($existing->eventType() !== AuditEventType::ContractReactivated || $existing->subject_type !== ContractLifecycleFact::class) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return ContractLifecycleFact::query()->findOrFail($existing->subject_id);
            }
            if ($locked->revision !== (int) $data['expected_revision']) {
                throw ValidationException::withMessages(['revision' => 'Il Contratto è cambiato dopo l’anteprima.']);
            }
            $previous = $locked->stateAtDate((string) $data['start_date']);
            if ($locked->isArchived() || ! in_array($previous, [ContractState::Cessated, ContractState::Cancelled], true)) {
                throw ValidationException::withMessages(['contract' => 'La riattivazione richiede un Contratto Cessato o Annullato.']);
            }

            $fact = ContractLifecycleFact::query()->create([
                'company_id' => $company->id, 'contract_id' => $locked->id, 'type' => 'reactivation',
                'declared_contractual_date' => $data['start_date'], 'state_change_date' => $data['start_date'],
                'reason' => $data['reason'], 'created_by_id' => $actor->id,
            ]);
            $condition = ContractCondition::query()->create([
                'company_id' => $company->id, 'contract_id' => $locked->id,
                'amount' => $data['condition']['amount'], 'cycle' => $data['condition']['cycle'],
                'attribution_mode' => $data['condition']['attribution_mode'], 'valid_from' => $data['condition']['valid_from'],
                'valid_to' => $data['condition']['valid_to'] ?? null, 'reason' => $data['reason'], 'created_by_id' => $actor->id,
            ]);
            $locked->update(['next_expiry_date' => $data['next_expiry_date'], 'renewal_anchor_date' => $data['next_expiry_date'], 'revision' => $locked->revision + 1]);
            $exerciseIds = $exercises->pluck('id')->all();
            $sequence = 0;
            $this->event($actor, $fact, $locked, $data['operation_id'], $sequence++, AuditEventType::ContractReactivated, $exerciseIds, $data['start_date'], $data['reason']);
            $this->event($actor, $fact, $locked, $data['operation_id'], $sequence++, AuditEventType::ContractConditionCreated, $exerciseIds, $data['condition']['valid_from'], $data['reason'], ['condition_id' => $condition->id]);
            $locked->unsetRelation('conditions')->unsetRelation('lifecycleFacts');
            $this->recalculate->recalculateWithinTransaction($actor, $locked, $exercises, $data['operation_id'], $sequence);

            return $fact->refresh();
        });
    }

    /**
     * @param  list<int>  $exerciseIds
     * @param  array<string, mixed>  $extra
     */
    private function event(User $actor, ContractLifecycleFact $fact, Contract $contract, string $operationId, int $sequence, AuditEventType $type, array $exerciseIds, string $effective, string $reason, array $extra = []): void
    {
        AuditEvent::query()->create([
            'operation_id' => $operationId, 'event_sequence' => $sequence, 'company_id' => $contract->company_id, 'actor_id' => $actor->id,
            'event_type' => $type, 'subject_type' => ContractLifecycleFact::class, 'subject_id' => $fact->id,
            'affected_exercise_ids' => $exerciseIds, 'effective_from' => $effective,
            'previous_value' => null, 'new_value' => ['contract_id' => $contract->id, ...$extra],
            'allocated_impact_by_exercise' => array_fill_keys(array_map('strval', $exerciseIds), '0.00'),
            'actual_impact_by_exercise' => array_fill_keys(array_map('strval', $exerciseIds), '0.00'),
            'reason' => $reason, 'reference_type' => Contract::class, 'reference_id' => $contract->id,
        ]);
    }
}
