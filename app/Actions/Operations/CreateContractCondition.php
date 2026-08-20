<?php

namespace App\Actions\Operations;

use App\Domain\Company\AuditEventType;
use App\Domain\Contracts\ContractAttributionMode;
use App\Domain\Contracts\ContractConditionRules;
use App\Domain\Contracts\ContractCycleType;
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

class CreateContractCondition
{
    public function __construct(private readonly RecalculateContractEstimates $recalculate) {}

    /** @param array<string, mixed> $input */
    public function execute(User $actor, Contract $contract, array $input, string $operationId): ContractCondition
    {
        $validated = Validator::make([
            'amount' => $input['amount'] ?? null,
            'cycle' => $input['cycle'] ?? null,
            'attribution_mode' => $input['attribution_mode'] ?? null,
            'valid_from' => $input['valid_from'] ?? null,
            'valid_to' => ($input['valid_to'] ?? null) ?: null,
            'reason' => $this->nullableTrim($input['reason'] ?? null),
            'operation_id' => $operationId,
        ], [
            'amount' => ['required', 'decimal:0,2', 'min:0'],
            'cycle' => ['required', Rule::enum(ContractCycleType::class)],
            'attribution_mode' => ['required', Rule::enum(ContractAttributionMode::class)],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'reason' => ['nullable', 'string'],
            'operation_id' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $contract, $validated): ContractCondition {
            $company = Company::query()->lockForUpdate()->findOrFail($contract->company_id);
            $locked = Contract::query()->lockForUpdate()->findOrFail($contract->id);
            Gate::forUser($actor)->authorize('update', $locked);
            $existing = AuditEvent::query()->where('operation_id', $validated['operation_id'])->where('event_sequence', 0)->first();
            if ($existing !== null) {
                if ($existing->eventType() !== AuditEventType::ContractConditionCreated
                    || $existing->subject_type !== ContractCondition::class
                    || $existing->company_id !== $company->id) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return ContractCondition::query()->findOrFail($existing->subject_id);
            }

            $conditions = $locked->conditions()->orderBy('valid_from')->orderBy('id')->lockForUpdate()->get();
            $locked->setRelation('lifecycleFacts', $locked->lifecycleFacts()->lockForUpdate()->get());
            ContractConditionRules::assertCurrentlyActive($locked, CarbonImmutable::now($company->timezone)->toDateString());
            ContractConditionRules::assertMayPersist($locked, $validated['valid_from'], $validated['valid_to'], $conditions);
            $exercises = Exercise::query()->whereBelongsTo($company, 'company')->open()->orderBy('year')->lockForUpdate()->get();

            $condition = ContractCondition::query()->create([
                'company_id' => $company->id,
                'contract_id' => $locked->id,
                'cycle' => $validated['cycle'],
                'attribution_mode' => $validated['attribution_mode'],
                'amount' => $validated['amount'],
                'valid_from' => $validated['valid_from'],
                'valid_to' => $validated['valid_to'],
                'reason' => $validated['reason'],
                'created_by_id' => $actor->id,
            ]);
            $locked->increment('revision');
            $exerciseIds = $exercises->pluck('id')->all();
            AuditEvent::query()->create([
                'operation_id' => $validated['operation_id'],
                'event_sequence' => 0,
                'company_id' => $company->id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::ContractConditionCreated,
                'subject_type' => ContractCondition::class,
                'subject_id' => $condition->id,
                'affected_exercise_ids' => $exerciseIds,
                'effective_from' => $validated['valid_from'],
                'effective_to' => $validated['valid_to'],
                'new_value' => $condition->only(['id', 'contract_id', 'amount', 'cycle', 'attribution_mode', 'valid_from', 'valid_to', 'reason']),
                'allocated_impact_by_exercise' => array_fill_keys(array_map('strval', $exerciseIds), '0.00'),
                'actual_impact_by_exercise' => array_fill_keys(array_map('strval', $exerciseIds), '0.00'),
            ]);
            $sequence = 1;
            $locked->unsetRelation('conditions');
            $this->recalculate->recalculateWithinTransaction($actor, $locked, $exercises, $validated['operation_id'], $sequence);

            return $condition;
        });
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
