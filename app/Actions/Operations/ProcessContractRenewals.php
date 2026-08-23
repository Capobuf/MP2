<?php

namespace App\Actions\Operations;

use App\Domain\Company\AuditEventType;
use App\Domain\Contracts\ContractClosedHistoryGuard;
use App\Domain\Contracts\ContractRenewalSchedule;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractLifecycleFact;
use App\Models\ContractRenewalConfiguration;
use App\Models\Exercise;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProcessContractRenewals
{
    public function __construct(private readonly RecalculateContractEstimates $recalculate) {}

    public function execute(User $actor, Contract $contract, string $operationId): Contract
    {
        validator(['operation_id' => $operationId], ['operation_id' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($actor, $contract, $operationId): Contract {
            $company = Company::query()->lockForUpdate()->findOrFail($contract->company_id);
            $cutoff = CarbonImmutable::now($company->timezone)->startOfDay()->toDateString();
            $exercises = Exercise::query()->whereBelongsTo($company, 'company')->open()->orderBy('id')->lockForUpdate()->get();
            $locked = Contract::query()->lockForUpdate()->findOrFail($contract->id);
            Gate::forUser($actor)->authorize('update', $locked);

            $existing = AuditEvent::query()->where('operation_id', $operationId)->where('event_sequence', 0)->first();
            if ($existing !== null) {
                if (! in_array($existing->eventType(), [AuditEventType::ContractRenewed, AuditEventType::ContractCessated], true)
                    || $existing->reference_type !== Contract::class || $existing->reference_id !== $locked->id) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return $locked;
            }

            $sequence = 0;
            $this->processThroughWithinTransaction(
                actor: $actor,
                contract: $locked,
                cutoffDate: $cutoff,
                operationId: $operationId,
                sequence: $sequence,
                openExercises: $exercises,
                authorize: false,
                recalculate: true,
            );

            return $locked->refresh();
        });
    }

    /**
     * Materialize deterministic renewal/expiry facts through an explicit economic
     * cutoff. The caller owns the surrounding transaction and aggregate authorization.
     *
     * @param  Collection<int, Exercise>  $openExercises
     */
    public function processThroughWithinTransaction(
        User $actor,
        Contract $contract,
        string $cutoffDate,
        string $operationId,
        int &$sequence,
        Collection $openExercises,
        bool $authorize = false,
        bool $recalculate = true,
    ): bool {
        return ContractClosedHistoryGuard::duringAutomaticMaterialization(function () use (
            $actor,
            $contract,
            $cutoffDate,
            $operationId,
            &$sequence,
            $openExercises,
            $authorize,
            $recalculate,
        ): bool {
            $company = Company::query()->lockForUpdate()->findOrFail($contract->company_id);
            $cutoff = CarbonImmutable::parse($cutoffDate, $company->timezone)->startOfDay();
            $locked = Contract::query()->lockForUpdate()->findOrFail($contract->id);
            if ($authorize) {
                Gate::forUser($actor)->authorize('update', $locked);
            }

            $configurations = $locked->renewalConfigurations()->orderBy('effective_from')->orderBy('id')->lockForUpdate()->get();
            $locked->lifecycleFacts()->orderBy('id')->lockForUpdate()->get();
            $locked->conditions()->orderBy('id')->lockForUpdate()->get();

            if ($locked->nextExpiryDate() === null || $locked->nextExpiryDate()->startOfDay()->greaterThan($cutoff)) {
                return false;
            }
            if ($configurations->isEmpty()) {
                throw ValidationException::withMessages(['renewal' => 'Manca la configurazione storica applicabile alla scadenza.']);
            }

            $exerciseIds = $openExercises->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
            while ($locked->nextExpiryDate() !== null && ! $locked->nextExpiryDate()->startOfDay()->greaterThan($cutoff)) {
                $expiry = $locked->nextExpiryDate()->toDateString();
                $configuration = ContractRenewalSchedule::configurationAtDate($configurations, $expiry);
                if (! $configuration instanceof ContractRenewalConfiguration) {
                    throw ValidationException::withMessages(['renewal' => 'Nessuna configurazione storica è efficace alla scadenza.']);
                }

                if (! $configuration->automatic_renewal) {
                    $fact = ContractLifecycleFact::query()->firstOrCreate([
                        'contract_id' => $locked->id,
                        'state_change_date' => CarbonImmutable::parse($expiry)->addDay()->toDateString(),
                    ], [
                        'company_id' => $company->id,
                        'type' => 'expiry_cessation',
                        'declared_contractual_date' => $expiry,
                        'created_by_id' => $actor->id,
                    ]);
                    foreach ($locked->conditions()->active()->whereNull('valid_to')->lockForUpdate()->get() as $condition) {
                        if (! $condition->validFrom()->greaterThan(CarbonImmutable::parse($expiry))) {
                            $condition->update(['valid_to' => $expiry]);
                        }
                    }
                    $locked->update(['next_expiry_date' => null, 'renewal_anchor_date' => null]);
                    $this->audit($actor, $locked, $fact, $operationId, $sequence++, AuditEventType::ContractCessated, $exerciseIds, $expiry, [
                        'cause' => 'expiry_without_renewal',
                        'state_change_date' => CarbonImmutable::parse($expiry)->addDay()->toDateString(),
                        'cutoff_date' => $cutoff->toDateString(),
                    ]);
                    break;
                }

                $duration = $configuration->renewal_duration_months;
                $anchor = $configuration->expiryAnchorDate()?->toDateString();
                if ($duration === null || $duration < 1 || $anchor === null) {
                    throw ValidationException::withMessages(['renewal' => 'La configurazione di rinnovo storica è incompleta.']);
                }
                $nextExpiry = ContractRenewalSchedule::nextAnchoredExpiry($anchor, $duration, $expiry);
                $fact = ContractLifecycleFact::query()->firstOrCreate([
                    'contract_id' => $locked->id,
                    'renewed_expiry_date' => $expiry,
                ], [
                    'company_id' => $company->id,
                    'type' => 'renewal',
                    'declared_contractual_date' => $expiry,
                    'state_change_date' => null,
                    'renewal_configuration_id' => $configuration->id,
                    'created_by_id' => $actor->id,
                ]);
                $locked->update(['next_expiry_date' => $nextExpiry]);
                $this->audit($actor, $locked, $fact, $operationId, $sequence++, AuditEventType::ContractRenewed, $exerciseIds, $expiry, [
                    'renewed_expiry_date' => $expiry,
                    'next_expiry_date' => $nextExpiry,
                    'renewal_configuration_id' => $configuration->id,
                    'renewal_without_condition' => ContractRenewalSchedule::hasRenewalWithoutCondition($locked->conditions, $expiry),
                    'cutoff_date' => $cutoff->toDateString(),
                ]);
            }

            $locked->increment('revision');
            $locked->unsetRelation('conditions')->unsetRelation('lifecycleFacts')->unsetRelation('renewalConfigurations');
            if ($recalculate) {
                $this->recalculate->recalculateWithinTransaction($actor, $locked, $openExercises, $operationId, $sequence);
            }

            return true;
        });
    }

    /**
     * @param  list<int>  $exerciseIds
     * @param  array<string, mixed>  $newValue
     */
    private function audit(User $actor, Contract $contract, ContractLifecycleFact $fact, string $operationId, int $sequence, AuditEventType $type, array $exerciseIds, string $effective, array $newValue): void
    {
        AuditEvent::query()->create([
            'operation_id' => $operationId,
            'event_sequence' => $sequence,
            'company_id' => $contract->company_id,
            'actor_id' => $actor->id,
            'event_type' => $type,
            'subject_type' => ContractLifecycleFact::class,
            'subject_id' => $fact->id,
            'affected_exercise_ids' => $exerciseIds,
            'effective_from' => $effective,
            'previous_value' => null,
            'new_value' => ['contract_id' => $contract->id, ...$newValue],
            'allocated_impact_by_exercise' => array_fill_keys(array_map('strval', $exerciseIds), '0.00'),
            'actual_impact_by_exercise' => array_fill_keys(array_map('strval', $exerciseIds), '0.00'),
            'reference_type' => Contract::class,
            'reference_id' => $contract->id,
        ]);
    }
}
