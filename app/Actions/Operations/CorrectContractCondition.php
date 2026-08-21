<?php

namespace App\Actions\Operations;

use App\Domain\Company\AuditEventType;
use App\Domain\Contracts\ContractAttributionMode;
use App\Domain\Contracts\ContractCycleType;
use App\Domain\Contracts\ContractEconomicChangePlan;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\Exercise;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CorrectContractCondition
{
    public function __construct(private readonly RecalculateContractEstimates $recalculate) {}

    /** @param array<string, mixed> $input */
    public function preview(Contract $contract, ContractCondition $condition, array $input): ContractEconomicChangePlan
    {
        $validated = $this->validate($input);
        $this->assertOwnership($contract, $condition);
        $exercises = Exercise::query()->where('company_id', $contract->company_id)->orderBy('id')->get();

        return ContractEconomicChangePlan::forCorrection($contract, $condition, $this->terms($validated), $exercises);
    }

    /** @param array<string, mixed> $input */
    public function execute(
        User $actor,
        Contract $contract,
        ContractCondition $condition,
        array $input,
        string $fingerprint,
        string $operationId,
    ): ContractCondition {
        $validated = $this->validate($input + ['operation_id' => $operationId]);

        return DB::transaction(function () use ($actor, $contract, $condition, $validated, $fingerprint): ContractCondition {
            $company = Company::query()->lockForUpdate()->findOrFail($contract->company_id);
            $exercises = Exercise::query()->whereBelongsTo($company, 'company')->orderBy('id')->lockForUpdate()->get();
            $lockedContract = Contract::query()->lockForUpdate()->findOrFail($contract->id);
            Gate::forUser($actor)->authorize('update', $lockedContract);
            $conditions = $lockedContract->conditions()->orderBy('id')->lockForUpdate()->get();
            $lockedCondition = $conditions->firstWhere('id', $condition->id);
            if (! $lockedCondition instanceof ContractCondition) {
                throw ValidationException::withMessages(['condition' => 'La condizione non appartiene al Contratto.']);
            }

            $existing = AuditEvent::query()->where('operation_id', $validated['operation_id'])->where('event_sequence', 0)->first();
            if ($existing !== null) {
                if ($existing->eventType() !== AuditEventType::ContractConditionCorrected
                    || $existing->subject_type !== ContractCondition::class
                    || $existing->subject_id !== $lockedCondition->id) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return $lockedCondition;
            }

            $lockedContract->setRelation('conditions', $conditions);
            $lockedContract->setRelation('lifecycleFacts', $lockedContract->lifecycleFacts()->orderBy('id')->lockForUpdate()->get());
            $plan = ContractEconomicChangePlan::forCorrection($lockedContract, $lockedCondition, $this->terms($validated), $exercises);
            if (! hash_equals($plan->fingerprint(), $fingerprint)) {
                throw ValidationException::withMessages(['plan' => 'Il piano di correzione non è più attuale.']);
            }

            $before = $plan->oldTerms;
            $lockedCondition->update([
                'amount' => $validated['amount'],
                'cycle' => $validated['cycle'],
                'attribution_mode' => $validated['attribution_mode'],
                'reason' => $validated['reason'],
            ]);
            $lockedContract->increment('revision');
            AuditEvent::query()->create([
                'operation_id' => $validated['operation_id'],
                'event_sequence' => 0,
                'company_id' => $company->id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::ContractConditionCorrected,
                'subject_type' => ContractCondition::class,
                'subject_id' => $lockedCondition->id,
                'affected_exercise_ids' => array_map('intval', array_keys($plan->exerciseImpacts)),
                'effective_from' => $lockedCondition->validFrom()->toDateString(),
                'effective_to' => $lockedCondition->validTo()?->toDateString(),
                'previous_value' => $before,
                'new_value' => [
                    'condition' => $lockedCondition->only(['id', 'amount', 'cycle', 'attribution_mode', 'valid_from', 'valid_to', 'reason']),
                    'declared_input_error' => true,
                    'declared_no_new_agreement' => true,
                    'impact_fingerprint' => $plan->fingerprint(),
                    'exercise_impacts' => $plan->exerciseImpacts,
                ],
                'allocated_impact_by_exercise' => $plan->allocatedImpact(),
                'actual_impact_by_exercise' => array_fill_keys(array_keys($plan->exerciseImpacts), '0.00'),
                'reason' => $validated['reason'],
            ]);
            $sequence = 1;
            $lockedContract->unsetRelation('conditions');
            $affected = $exercises->whereIn('id', array_map('intval', array_keys($plan->exerciseImpacts)));
            $this->recalculate->recalculateWithinTransaction($actor, $lockedContract, $affected, $validated['operation_id'], $sequence);

            return $lockedCondition;
        });
    }

    /** @param array<string, mixed> $input
     * @return array{amount: string, cycle: string, attribution_mode: string, reason: string, declared_input_error: bool, declared_no_new_agreement: bool, operation_id?: string}
     */
    private function validate(array $input): array
    {
        $payload = [
            'amount' => $input['amount'] ?? null,
            'cycle' => $input['cycle'] ?? null,
            'attribution_mode' => $input['attribution_mode'] ?? null,
            'reason' => is_string($input['reason'] ?? null) ? trim($input['reason']) : null,
            'declared_input_error' => $input['declared_input_error'] ?? false,
            'declared_no_new_agreement' => $input['declared_no_new_agreement'] ?? false,
        ];
        $rules = [
            'amount' => ['required', 'decimal:0,2', 'min:0'],
            'cycle' => ['required', Rule::enum(ContractCycleType::class)],
            'attribution_mode' => ['required', Rule::enum(ContractAttributionMode::class)],
            'reason' => ['required', 'string'],
            'declared_input_error' => ['accepted'],
            'declared_no_new_agreement' => ['accepted'],
        ];
        if (array_key_exists('operation_id', $input)) {
            $payload['operation_id'] = $input['operation_id'];
            $rules['operation_id'] = ['required', 'uuid'];
        }

        /** @var array{amount: string, cycle: string, attribution_mode: string, reason: string, declared_input_error: bool, declared_no_new_agreement: bool, operation_id?: string} $validated */
        $validated = Validator::make($payload, $rules)->validate();

        return $validated;
    }

    /** @param array{amount: string, cycle: string, attribution_mode: string} $validated
     * @return array{amount: string, cycle: string, attribution_mode: string}
     */
    private function terms(array $validated): array
    {
        return array_intersect_key($validated, array_flip(['amount', 'cycle', 'attribution_mode']));
    }

    private function assertOwnership(Contract $contract, ContractCondition $condition): void
    {
        if ($condition->contract_id !== $contract->id || $condition->company_id !== $contract->company_id || $condition->isAnnulled()) {
            throw ValidationException::withMessages(['condition' => 'La condizione attiva deve appartenere al Contratto.']);
        }
    }
}
