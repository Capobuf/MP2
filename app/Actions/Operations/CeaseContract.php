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

class CeaseContract
{
    public function __construct(private readonly RecalculateContractEstimates $recalculate) {}

    public function execute(User $actor, Contract $contract, string $lastActiveDate, string $reason, int $expectedRevision, string $operationId): ContractLifecycleFact
    {
        $validated = Validator::make([
            'date' => $lastActiveDate, 'reason' => trim($reason), 'expected_revision' => $expectedRevision, 'operation_id' => $operationId,
        ], [
            'date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string'],
            'expected_revision' => ['required', 'integer', 'min:0'], 'operation_id' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $contract, $validated): ContractLifecycleFact {
            $company = Company::query()->lockForUpdate()->findOrFail($contract->company_id);
            $exercises = Exercise::query()->whereBelongsTo($company, 'company')->open()->orderBy('id')->lockForUpdate()->get();
            $locked = Contract::query()->lockForUpdate()->findOrFail($contract->id);
            Gate::forUser($actor)->authorize('update', $locked);
            $existing = AuditEvent::query()->where('operation_id', $validated['operation_id'])->where('event_sequence', 0)->first();
            if ($existing !== null) {
                if ($existing->eventType() !== AuditEventType::ContractCessated || $existing->subject_type !== ContractLifecycleFact::class) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return ContractLifecycleFact::query()->findOrFail($existing->subject_id);
            }
            $this->guardRevision($locked, (int) $validated['expected_revision']);
            if ($locked->isArchived() || $locked->stateAtDate($validated['date']) !== ContractState::Active) {
                throw ValidationException::withMessages(['contract' => 'La cessazione richiede un Contratto Attivo alla data indicata.']);
            }

            $fact = ContractLifecycleFact::query()->create([
                'company_id' => $company->id, 'contract_id' => $locked->id, 'type' => 'cessation',
                'declared_contractual_date' => $validated['date'],
                'state_change_date' => CarbonImmutable::parse($validated['date'])->addDay()->toDateString(),
                'reason' => $validated['reason'], 'created_by_id' => $actor->id,
            ]);
            $conditions = $locked->conditions()->active()->whereNull('valid_to')->lockForUpdate()->get();
            foreach ($conditions as $condition) {
                if (! $condition->validFrom()->greaterThan(CarbonImmutable::parse($validated['date']))) {
                    $condition->update(['valid_to' => $validated['date'], 'reason' => $validated['reason']]);
                }
            }
            $locked->increment('revision');
            $sequence = 0;
            $exerciseIds = $exercises->pluck('id')->all();
            $this->event($actor, $fact, $locked, $validated['operation_id'], $sequence++, AuditEventType::ContractCessated, $exerciseIds, $validated['date'], $validated['reason']);
            $locked->unsetRelation('conditions')->unsetRelation('lifecycleFacts');
            $this->recalculate->recalculateWithinTransaction($actor, $locked, $exercises, $validated['operation_id'], $sequence);

            return $fact->refresh();
        });
    }

    private function guardRevision(Contract $contract, int $expected): void
    {
        if ($contract->revision !== $expected) {
            throw ValidationException::withMessages(['revision' => 'Il Contratto è cambiato dopo l’anteprima.']);
        }
    }

    /** @param list<int> $exerciseIds */
    private function event(User $actor, ContractLifecycleFact $fact, Contract $contract, string $operationId, int $sequence, AuditEventType $type, array $exerciseIds, string $effective, string $reason): void
    {
        AuditEvent::query()->create([
            'operation_id' => $operationId, 'event_sequence' => $sequence, 'company_id' => $contract->company_id,
            'actor_id' => $actor->id, 'event_type' => $type, 'subject_type' => ContractLifecycleFact::class,
            'subject_id' => $fact->id, 'affected_exercise_ids' => $exerciseIds, 'effective_from' => $effective,
            'previous_value' => ['state' => ContractState::Active->value], 'new_value' => ['contract_id' => $contract->id, 'state' => ContractState::Cessated->value],
            'allocated_impact_by_exercise' => array_fill_keys(array_map('strval', $exerciseIds), '0.00'),
            'actual_impact_by_exercise' => array_fill_keys(array_map('strval', $exerciseIds), '0.00'),
            'reason' => $reason, 'reference_type' => Contract::class, 'reference_id' => $contract->id,
        ]);
    }
}
