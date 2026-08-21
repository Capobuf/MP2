<?php

namespace App\Actions\Operations;

use App\Domain\Company\AuditEventType;
use App\Domain\Contracts\ContractConditionRules;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\Exercise;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SetContractConditionAnnulled
{
    public function __construct(private readonly RecalculateContractEstimates $recalculate) {}

    public function execute(User $actor, ContractCondition $condition, bool $annulled, string $reason, string $operationId): ContractCondition
    {
        $validated = Validator::make(['reason' => trim($reason), 'operation_id' => $operationId], [
            'reason' => ['required', 'string'], 'operation_id' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $condition, $annulled, $validated): ContractCondition {
            $company = Company::query()->lockForUpdate()->findOrFail($condition->company_id);
            $contract = Contract::query()->lockForUpdate()->findOrFail($condition->contract_id);
            $locked = ContractCondition::query()->lockForUpdate()->findOrFail($condition->id);
            Gate::forUser($actor)->authorize('update', $locked);
            $type = $annulled ? AuditEventType::ContractConditionAnnulled : AuditEventType::ContractConditionRestored;
            $existing = AuditEvent::query()->where('operation_id', $validated['operation_id'])->where('event_sequence', 0)->first();
            if ($existing !== null) {
                if ($existing->eventType() !== $type || $existing->subject_type !== ContractCondition::class || $existing->subject_id !== $locked->id) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return $locked;
            }
            if ($annulled === $locked->isAnnulled()) {
                return $locked;
            }

            $conditions = $contract->conditions()->orderBy('valid_from')->orderBy('id')->lockForUpdate()->get();
            if (! $annulled) {
                ContractConditionRules::assertMayPersist(
                    $contract,
                    $locked->validFrom()->toDateString(),
                    $locked->validTo()?->toDateString(),
                    $conditions,
                    $locked->id,
                );
            }
            $exercises = Exercise::query()->whereBelongsTo($company, 'company')->open()->orderBy('year')->lockForUpdate()->get();
            $before = ['annulled_at' => $locked->annulledAt()?->toIso8601String(), 'reason' => $locked->reason];
            $locked->forceFill([
                'annulled_at' => $annulled ? now() : null,
                'annulled_by_id' => $annulled ? $actor->id : null,
                'reason' => $validated['reason'],
            ])->save();
            $contract->increment('revision');
            $exerciseIds = $exercises->pluck('id')->all();
            AuditEvent::query()->create([
                'operation_id' => $validated['operation_id'], 'event_sequence' => 0,
                'company_id' => $company->id, 'actor_id' => $actor->id, 'event_type' => $type,
                'subject_type' => ContractCondition::class, 'subject_id' => $locked->id,
                'affected_exercise_ids' => $exerciseIds,
                'effective_from' => $locked->valid_from,
                'effective_to' => $locked->valid_to,
                'previous_value' => $before,
                'new_value' => ['annulled_at' => $locked->annulledAt()?->toIso8601String(), 'reason' => $locked->reason],
                'allocated_impact_by_exercise' => array_fill_keys(array_map('strval', $exerciseIds), '0.00'),
                'actual_impact_by_exercise' => array_fill_keys(array_map('strval', $exerciseIds), '0.00'),
                'reason' => $validated['reason'],
            ]);
            $sequence = 1;
            $contract->unsetRelation('conditions')->unsetRelation('lifecycleFacts');
            $this->recalculate->recalculateWithinTransaction($actor, $contract, $exercises, $validated['operation_id'], $sequence);

            return $locked;
        });
    }
}
