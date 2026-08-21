<?php

namespace App\Actions\Operations;

use App\Domain\Company\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractRenewalConfiguration;
use App\Models\Exercise;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdateContractRenewal
{
    public function __construct(
        private readonly ProcessContractRenewals $processRenewals,
        private readonly RecalculateContractEstimates $recalculate,
    ) {}

    /** @param array<string, mixed> $input */
    public function execute(User $actor, Contract $contract, array $input, string $operationId): ContractRenewalConfiguration
    {
        $normalized = [
            'effective_from' => $input['effective_from'] ?? null,
            'automatic_renewal' => $input['automatic_renewal'] ?? null,
            'expiry_anchor_date' => ($input['expiry_anchor_date'] ?? null) ?: null,
            'renewal_duration_months' => ($input['renewal_duration_months'] ?? null) ?: null,
            'notice_days' => ($input['notice_days'] ?? null) === '' ? null : ($input['notice_days'] ?? null),
            'expected_revision' => $input['expected_revision'] ?? null, 'operation_id' => $operationId,
        ];
        $data = Validator::make($normalized, [
            'effective_from' => ['required', 'date_format:Y-m-d'], 'automatic_renewal' => ['required', 'boolean'],
            'expiry_anchor_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$contract->contractualStartDate()->toDateString()],
            'renewal_duration_months' => ['nullable', 'integer', 'min:1', Rule::requiredIf(($normalized['automatic_renewal'] ?? false) && $normalized['expiry_anchor_date'] !== null)],
            'notice_days' => ['nullable', 'integer', 'min:0'], 'expected_revision' => ['required', 'integer', 'min:0'],
            'operation_id' => ['required', 'uuid'],
        ])->validate();

        Gate::forUser($actor)->authorize('update', $contract);
        $existing = AuditEvent::query()->where('operation_id', $operationId)->where('event_sequence', 0)->first();
        if ($existing !== null) {
            if ($existing->eventType() !== AuditEventType::ContractRenewalChanged) {
                throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
            }

            return ContractRenewalConfiguration::query()->findOrFail($existing->subject_id);
        }
        if ($contract->isArchived()) {
            throw ValidationException::withMessages(['contract' => 'Ripristinare il Contratto prima di modificarne il rinnovo.']);
        }

        $this->processRenewals->execute($actor, $contract, (string) Str::uuid());

        return DB::transaction(function () use ($actor, $contract, $data): ContractRenewalConfiguration {
            $company = Company::query()->lockForUpdate()->findOrFail($contract->company_id);
            $exercises = Exercise::query()->whereBelongsTo($company, 'company')->open()->orderBy('id')->lockForUpdate()->get();
            $locked = Contract::query()->lockForUpdate()->findOrFail($contract->id);
            $locked->renewalConfigurations()->lockForUpdate()->get();
            Gate::forUser($actor)->authorize('update', $locked);
            if ($locked->isArchived()) {
                throw ValidationException::withMessages(['contract' => 'Ripristinare il Contratto prima di modificarne il rinnovo.']);
            }
            if ($locked->revision !== (int) $data['expected_revision']) {
                throw ValidationException::withMessages(['revision' => 'Il Contratto è cambiato dopo l’anteprima o l’elaborazione delle scadenze.']);
            }
            $configuration = ContractRenewalConfiguration::query()->create([
                'company_id' => $company->id, 'contract_id' => $locked->id, 'effective_from' => $data['effective_from'],
                'automatic_renewal' => $data['automatic_renewal'], 'expiry_anchor_date' => $data['expiry_anchor_date'],
                'renewal_duration_months' => $data['renewal_duration_months'], 'notice_days' => $data['notice_days'], 'created_by_id' => $actor->id,
            ]);
            $previous = ['automatic_renewal' => $locked->automatic_renewal, 'next_expiry_date' => $locked->nextExpiryDate()?->toDateString()];
            $locked->update([
                'automatic_renewal' => $data['automatic_renewal'], 'next_expiry_date' => $data['expiry_anchor_date'],
                'renewal_anchor_date' => $data['expiry_anchor_date'], 'renewal_duration_months' => $data['renewal_duration_months'],
                'notice_days' => $data['notice_days'], 'revision' => $locked->revision + 1,
            ]);
            $exerciseIds = $exercises->pluck('id')->all();
            $sequence = 0;
            AuditEvent::query()->create([
                'operation_id' => $data['operation_id'], 'event_sequence' => $sequence++, 'company_id' => $company->id, 'actor_id' => $actor->id,
                'event_type' => AuditEventType::ContractRenewalChanged, 'subject_type' => ContractRenewalConfiguration::class,
                'subject_id' => $configuration->id, 'affected_exercise_ids' => $exerciseIds, 'effective_from' => $data['effective_from'],
                'previous_value' => $previous, 'new_value' => ['contract_id' => $locked->id, 'automatic_renewal' => $configuration->automatic_renewal, 'expiry_anchor_date' => $configuration->expiryAnchorDate()?->toDateString()],
                'allocated_impact_by_exercise' => array_fill_keys(array_map('strval', $exerciseIds), '0.00'),
                'actual_impact_by_exercise' => array_fill_keys(array_map('strval', $exerciseIds), '0.00'),
                'reference_type' => Contract::class, 'reference_id' => $locked->id,
            ]);
            $locked->unsetRelation('conditions')->unsetRelation('lifecycleFacts');
            $this->recalculate->recalculateWithinTransaction($actor, $locked, $exercises, $data['operation_id'], $sequence);

            return $configuration;
        });
    }
}
