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
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ChangeContractCondition
{
    public function __construct(private readonly RecalculateContractEstimates $recalculate) {}

    /** @param array<string, mixed> $input */
    public function preview(Contract $contract, ContractCondition $condition, array $input): ContractEconomicChangePlan
    {
        $validated = $this->validate($input);
        $this->assertOwnership($contract, $condition);
        $exercises = Exercise::query()->where('company_id', $contract->company_id)->orderBy('id')->get();

        try {
            return ContractEconomicChangePlan::forChange(
                $contract,
                $condition,
                $this->terms($validated),
                $validated['requested_date'],
                CarbonImmutable::now($contract->company->timezone)->toDateString(),
                $exercises,
            );
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages(['effective_date' => $exception->getMessage()]);
        }
    }

    /** @param array<string, mixed> $input */
    public function execute(
        User $actor,
        Contract $contract,
        ContractCondition $condition,
        array $input,
        string $fingerprint,
        string $confirmedEffectiveDate,
        string $operationId,
    ): ContractCondition {
        $validated = $this->validate($input + ['operation_id' => $operationId]);

        return DB::transaction(function () use ($actor, $contract, $condition, $validated, $fingerprint, $confirmedEffectiveDate): ContractCondition {
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
                if ($existing->eventType() !== AuditEventType::ContractConditionChanged
                    || $existing->subject_type !== ContractCondition::class
                    || $existing->company_id !== $company->id) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return ContractCondition::query()->findOrFail($existing->subject_id);
            }

            $lockedContract->setRelation('conditions', $conditions);
            $lockedContract->setRelation('lifecycleFacts', $lockedContract->lifecycleFacts()->orderBy('id')->lockForUpdate()->get());
            try {
                $plan = ContractEconomicChangePlan::forChange(
                    $lockedContract,
                    $lockedCondition,
                    $this->terms($validated),
                    $validated['requested_date'],
                    CarbonImmutable::now($company->timezone)->toDateString(),
                    $exercises,
                );
            } catch (\DomainException $exception) {
                throw ValidationException::withMessages(['effective_date' => $exception->getMessage()]);
            }
            if (! hash_equals($plan->fingerprint(), $fingerprint) || $confirmedEffectiveDate !== $plan->effectiveDate) {
                throw ValidationException::withMessages(['plan' => 'Il piano economico è cambiato o la decorrenza effettiva non è stata confermata.']);
            }

            $before = $plan->oldTerms;
            $originalValidTo = $lockedCondition->validTo()?->toDateString();
            if ($plan->futureReplacement) {
                $lockedCondition->forceFill([
                    'annulled_at' => now(),
                    'annulled_by_id' => $actor->id,
                    'reason' => $validated['reason'] ?? $lockedCondition->reason,
                ])->save();
            } else {
                $lockedCondition->update([
                    'valid_to' => CarbonImmutable::parse($plan->effectiveDate)->subDay()->toDateString(),
                ]);
            }

            $newCondition = ContractCondition::query()->create([
                'company_id' => $company->id,
                'contract_id' => $lockedContract->id,
                'amount' => $validated['amount'],
                'cycle' => $validated['cycle'],
                'attribution_mode' => $validated['attribution_mode'],
                'valid_from' => $plan->effectiveDate,
                'valid_to' => $originalValidTo,
                'reason' => $validated['reason'],
                'created_by_id' => $actor->id,
            ]);
            $lockedContract->increment('revision');
            AuditEvent::query()->create([
                'operation_id' => $validated['operation_id'],
                'event_sequence' => 0,
                'company_id' => $company->id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::ContractConditionChanged,
                'subject_type' => ContractCondition::class,
                'subject_id' => $newCondition->id,
                'affected_exercise_ids' => array_map('intval', array_keys($plan->exerciseImpacts)),
                'effective_from' => $plan->effectiveDate,
                'effective_to' => $originalValidTo,
                'previous_value' => $before,
                'new_value' => [
                    'condition' => $newCondition->only(['id', 'amount', 'cycle', 'attribution_mode', 'valid_from', 'valid_to', 'reason']),
                    'requested_date' => $plan->requestedDate,
                    'minimum_date' => $plan->minimumDate,
                    'effective_date' => $plan->effectiveDate,
                    'delay_reason' => $plan->delayReason,
                    'prorata_applied' => false,
                    'future_replacement' => $plan->futureReplacement,
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

            return $newCondition;
        });
    }

    /** @param array<string, mixed> $input
     * @return array{requested_date: string, amount: string, cycle: string, attribution_mode: string, reason: ?string, operation_id?: string}
     */
    private function validate(array $input): array
    {
        $payload = [
            'requested_date' => $input['requested_date'] ?? null,
            'amount' => $input['amount'] ?? null,
            'cycle' => $input['cycle'] ?? null,
            'attribution_mode' => $input['attribution_mode'] ?? null,
            'reason' => $this->nullableTrim($input['reason'] ?? null),
        ];
        $rules = [
            'requested_date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'decimal:0,2', 'min:0'],
            'cycle' => ['required', Rule::enum(ContractCycleType::class)],
            'attribution_mode' => ['required', Rule::enum(ContractAttributionMode::class)],
            'reason' => ['nullable', 'string'],
        ];
        if (array_key_exists('operation_id', $input)) {
            $payload['operation_id'] = $input['operation_id'];
            $rules['operation_id'] = ['required', 'uuid'];
        }

        /** @var array{requested_date: string, amount: string, cycle: string, attribution_mode: string, reason: ?string, operation_id?: string} $validated */
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

    private function nullableTrim(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
