<?php

namespace App\Actions\Operations;

use App\Domain\Company\AuditEventType;
use App\Domain\Contracts\ContractAttributionMode;
use App\Domain\Contracts\ContractCycleType;
use App\Domain\Contracts\ContractRenewalSchedule;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractExerciseClassification;
use App\Models\ContractLifecycleFact;
use App\Models\ContractRenewalConfiguration;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CreateContract
{
    public function __construct(private readonly RecalculateContractEstimates $recalculate) {}

    /** @param array<string, mixed> $input */
    public function execute(User $actor, Company $company, array $input, string $operationId): Contract
    {
        $normalized = $this->normalize($input, $operationId);
        $validated = Validator::make($normalized, [
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'supplier_id' => ['required', 'integer'],
            'contractual_start_date' => ['required', 'date_format:Y-m-d'],
            'next_expiry_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:contractual_start_date'],
            'renewal_effective_from' => ['required', 'date_format:Y-m-d'],
            'automatic_renewal' => ['required', 'boolean'],
            'renewal_duration_months' => ['nullable', 'integer', 'min:1', Rule::requiredIf(
                ($normalized['automatic_renewal'] ?? false) && ($normalized['next_expiry_date'] ?? null) !== null,
            )],
            'notice_days' => ['nullable', 'integer', 'min:0'],
            'condition' => ['required', 'array'],
            'condition.amount' => ['required', 'decimal:0,2', 'min:0'],
            'condition.cycle' => ['required', Rule::enum(ContractCycleType::class)],
            'condition.attribution_mode' => ['required', Rule::enum(ContractAttributionMode::class)],
            'condition.valid_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:contractual_start_date'],
            'condition.valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:condition.valid_from'],
            'classifications' => ['array'],
            'operation_id' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $company, $validated): Contract {
            $lockedCompany = Company::query()->lockForUpdate()->findOrFail($company->id);
            Gate::forUser($actor)->authorize('create', [Contract::class, $lockedCompany]);

            $existing = AuditEvent::query()->where('operation_id', $validated['operation_id'])->where('event_sequence', 0)->first();
            if ($existing !== null) {
                if ($existing->eventType() !== AuditEventType::ContractCreated
                    || $existing->subject_type !== Contract::class
                    || $existing->company_id !== $lockedCompany->id) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return Contract::query()->findOrFail($existing->subject_id);
            }

            $supplier = Supplier::query()->lockForUpdate()->find($validated['supplier_id']);
            if ($supplier === null || $supplier->company_id !== $lockedCompany->id || $supplier->isArchived()) {
                throw ValidationException::withMessages(['supplier_id' => 'Il Fornitore deve essere attivo in questa Azienda.']);
            }

            $exercises = Exercise::query()->whereBelongsTo($lockedCompany, 'company')->open()->orderBy('year')->lockForUpdate()->get();
            $classificationMap = $this->validateClassifications($validated['classifications'] ?? [], $exercises, $lockedCompany);
            $today = CarbonImmutable::now($lockedCompany->timezone)->startOfDay();
            $lateCensus = CarbonImmutable::parse($validated['contractual_start_date'])->lessThan($today);
            $anchor = $validated['next_expiry_date'];
            $nextExpiry = $anchor;

            $contract = Contract::query()->create([
                'company_id' => $lockedCompany->id,
                'supplier_id' => $supplier->id,
                'title' => $validated['title'],
                'notes' => $validated['notes'],
                'contractual_start_date' => $validated['contractual_start_date'],
                'next_expiry_date' => $nextExpiry,
                'renewal_anchor_date' => $anchor,
                'automatic_renewal' => $validated['automatic_renewal'],
                'renewal_duration_months' => $validated['renewal_duration_months'],
                'notice_days' => $validated['notice_days'],
            ]);
            $configuration = ContractRenewalConfiguration::query()->create([
                'company_id' => $lockedCompany->id,
                'contract_id' => $contract->id,
                'effective_from' => $validated['renewal_effective_from'],
                'automatic_renewal' => $validated['automatic_renewal'],
                'expiry_anchor_date' => $anchor,
                'renewal_duration_months' => $validated['renewal_duration_months'],
                'notice_days' => $validated['notice_days'],
                'created_by_id' => $actor->id,
            ]);
            $activation = ContractLifecycleFact::query()->create([
                'company_id' => $lockedCompany->id,
                'contract_id' => $contract->id,
                'type' => 'activation',
                'declared_contractual_date' => $validated['contractual_start_date'],
                'state_change_date' => $validated['contractual_start_date'],
                'created_by_id' => $actor->id,
            ]);
            $condition = ContractCondition::query()->create([
                'company_id' => $lockedCompany->id,
                'contract_id' => $contract->id,
                'cycle' => $validated['condition']['cycle'],
                'attribution_mode' => $validated['condition']['attribution_mode'],
                'amount' => $validated['condition']['amount'],
                'valid_from' => $validated['condition']['valid_from'],
                'valid_to' => $validated['condition']['valid_to'],
                'created_by_id' => $actor->id,
            ]);

            foreach ($exercises as $exercise) {
                ContractExerciseClassification::query()->create([
                    'company_id' => $lockedCompany->id,
                    'contract_id' => $contract->id,
                    'exercise_id' => $exercise->id,
                    'cost_center_id' => $classificationMap[$exercise->id] ?? null,
                ]);
            }

            $sequence = 0;
            $affectedIds = $exercises->pluck('id')->all();
            $this->event($actor, $contract, $validated['operation_id'], $sequence++, AuditEventType::ContractCreated, $affectedIds, [
                'title' => $contract->title,
                'supplier_id' => $contract->supplier_id,
                'contractual_start_date' => $contract->contractual_start_date->toDateString(),
                'renewal_configuration_effective_from' => $configuration->effective_from->toDateString(),
            ], $validated['contractual_start_date']);
            if ($lateCensus) {
                $this->event($actor, $contract, $validated['operation_id'], $sequence++, AuditEventType::ContractCensused, $affectedIds, [
                    'censused_at' => now()->toIso8601String(),
                    'real_contractual_start_date' => $contract->contractual_start_date->toDateString(),
                ], $validated['contractual_start_date']);
            }
            $this->event($actor, $contract, $validated['operation_id'], $sequence++, AuditEventType::ContractActivation, $affectedIds, [
                'lifecycle_fact_id' => $activation->id,
            ], $validated['contractual_start_date']);
            $this->event($actor, $contract, $validated['operation_id'], $sequence++, AuditEventType::ContractConditionCreated, $affectedIds, [
                'condition_id' => $condition->id,
                'amount' => (string) $condition->amount,
                'cycle' => $condition->cycle,
                'attribution_mode' => $condition->attribution_mode,
            ], $validated['condition']['valid_from']);

            if ($validated['automatic_renewal'] && $anchor !== null) {
                $schedule = ContractRenewalSchedule::fromAnchor($anchor, (int) $validated['renewal_duration_months'], $today->toDateString());
                foreach ($schedule->elapsed as $expiry) {
                    $renewal = ContractLifecycleFact::query()->create([
                        'company_id' => $lockedCompany->id,
                        'contract_id' => $contract->id,
                        'type' => 'renewal',
                        'declared_contractual_date' => $expiry,
                        'renewed_expiry_date' => $expiry,
                        'renewal_configuration_id' => $configuration->id,
                        'created_by_id' => $actor->id,
                    ]);
                    $this->event($actor, $contract, $validated['operation_id'], $sequence++, AuditEventType::ContractRenewed, $affectedIds, [
                        'lifecycle_fact_id' => $renewal->id,
                        'renewed_expiry_date' => $expiry,
                        'renewal_configuration_id' => $configuration->id,
                    ], $expiry);
                }
                $contract->update(['next_expiry_date' => $schedule->nextExpiry]);
            }

            $contract->unsetRelation('conditions')->unsetRelation('lifecycleFacts');
            $this->recalculate->recalculateWithinTransaction($actor, $contract, $exercises, $validated['operation_id'], $sequence);

            return $contract->refresh();
        });
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalize(array $input, string $operationId): array
    {
        $condition = is_array($input['condition'] ?? null) ? $input['condition'] : [];

        return [
            'title' => is_string($input['title'] ?? null) ? trim($input['title']) : ($input['title'] ?? null),
            'notes' => $this->nullableTrim($input['notes'] ?? null),
            'supplier_id' => $input['supplier_id'] ?? null,
            'contractual_start_date' => $input['contractual_start_date'] ?? null,
            'next_expiry_date' => $input['next_expiry_date'] ?: null,
            'renewal_effective_from' => $input['renewal_effective_from'] ?? null,
            'automatic_renewal' => $input['automatic_renewal'] ?? true,
            'renewal_duration_months' => $input['renewal_duration_months'] ?: null,
            'notice_days' => ($input['notice_days'] ?? null) === '' ? null : ($input['notice_days'] ?? null),
            'condition' => [
                'amount' => $condition['amount'] ?? null,
                'cycle' => $condition['cycle'] ?? null,
                'attribution_mode' => $condition['attribution_mode'] ?? null,
                'valid_from' => $condition['valid_from'] ?? null,
                'valid_to' => ($condition['valid_to'] ?? null) ?: null,
            ],
            'classifications' => $input['classifications'] ?? [],
            'operation_id' => $operationId,
        ];
    }

    /**
     * @param  Collection<int, Exercise>  $exercises
     * @return array<int, int|null>
     */
    private function validateClassifications(mixed $input, $exercises, Company $company): array
    {
        if (! is_array($input)) {
            throw ValidationException::withMessages(['classifications' => 'Le classificazioni non sono valide.']);
        }

        $map = [];
        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $exerciseId = (int) ($value['exercise_id'] ?? 0);
                $costCenterId = ($value['cost_center_id'] ?? null) ?: null;
            } else {
                $exerciseId = (int) $key;
                $costCenterId = $value ?: null;
            }

            $exercise = $exercises->firstWhere('id', $exerciseId);
            if ($exercise === null) {
                throw ValidationException::withMessages(['classifications' => 'Ogni classificazione deve riferirsi a un Esercizio Aperto interessato.']);
            }
            if ($costCenterId !== null) {
                $costCenter = CostCenter::query()->lockForUpdate()->find($costCenterId);
                if ($costCenter === null || $costCenter->company_id !== $company->id || $costCenter->isArchived()) {
                    throw ValidationException::withMessages(['classifications' => 'Il Centro di Costo deve essere attivo in questa Azienda.']);
                }
            }
            $map[$exerciseId] = $costCenterId === null ? null : (int) $costCenterId;
        }

        return $map;
    }

    /** @param list<int> $exerciseIds
     * @param  array<string, mixed>  $newValue
     */
    private function event(User $actor, Contract $contract, string $operationId, int $sequence, AuditEventType $type, array $exerciseIds, array $newValue, ?string $effectiveFrom): void
    {
        AuditEvent::query()->create([
            'operation_id' => $operationId,
            'event_sequence' => $sequence,
            'company_id' => $contract->company_id,
            'actor_id' => $actor->id,
            'event_type' => $type,
            'subject_type' => Contract::class,
            'subject_id' => $contract->id,
            'affected_exercise_ids' => $exerciseIds,
            'effective_from' => $effectiveFrom,
            'previous_value' => null,
            'new_value' => $newValue,
            'allocated_impact_by_exercise' => array_fill_keys(array_map('strval', $exerciseIds), '0.00'),
            'actual_impact_by_exercise' => array_fill_keys(array_map('strval', $exerciseIds), '0.00'),
        ]);
    }

    private function nullableTrim(mixed $value): mixed
    {
        $value = is_string($value) ? trim($value) : $value;

        return $value === '' ? null : $value;
    }
}
