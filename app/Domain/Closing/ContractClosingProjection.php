<?php

namespace App\Domain\Closing;

use App\Domain\Contracts\ContractAnnualAllocation;
use App\Domain\Contracts\ContractRenewalSchedule;
use App\Domain\Contracts\ContractStateTimeline;
use App\Domain\Expenses\Decimal;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractRenewalConfiguration;
use App\Models\Exercise;
use Carbon\CarbonImmutable;
use DomainException;

final class ContractClosingProjection
{
    /**
     * @param  iterable<int, Exercise>  $openExercises
     * @return array<string, mixed>
     */
    public static function build(Contract $contract, string $cutoffDate, iterable $openExercises): array
    {
        $contract->loadMissing(['company', 'conditions', 'lifecycleFacts', 'renewalConfigurations']);
        $cutoff = CarbonImmutable::parse($cutoffDate, $contract->company->timezone)->startOfDay();
        $today = CarbonImmutable::now($contract->company->timezone)->startOfDay();
        $conditions = $contract->conditions->map(fn (ContractCondition $condition): array => [
            'id' => $condition->id,
            'amount' => (string) $condition->amount,
            'cycle' => (string) $condition->cycle,
            'attribution_mode' => (string) $condition->attribution_mode,
            'valid_from' => $condition->validFrom()->toDateString(),
            'valid_to' => $condition->validTo()?->toDateString(),
            'annulled_at' => $condition->annulledAt()?->toISOString(),
        ])->values()->all();
        $facts = $contract->lifecycleFacts->map(fn ($fact): array => [
            'id' => $fact->id,
            'type' => (string) $fact->type,
            'declared_contractual_date' => $fact->declaredContractualDate()->toDateString(),
            'state_change_date' => $fact->stateChangeDate()?->toDateString(),
            'renewed_expiry_date' => $fact->renewedExpiryDate()?->toDateString(),
            'renewal_configuration_id' => $fact->renewal_configuration_id,
            'annulled_at' => $fact->annulledAt()?->toISOString(),
        ])->values()->all();
        $persistedFacts = $facts;
        $configurations = $contract->renewalConfigurations;
        $nextExpiry = $contract->nextExpiryDate()?->toDateString();
        $projectedEvents = [];
        $renewalWithoutCondition = false;

        while ($nextExpiry !== null && $nextExpiry <= $cutoff->toDateString()) {
            $configuration = ContractRenewalSchedule::configurationAtDate($configurations, $nextExpiry);
            if (! $configuration instanceof ContractRenewalConfiguration) {
                throw new DomainException('Nessuna configurazione storica è efficace alla scadenza.');
            }

            if (! $configuration->automatic_renewal) {
                $stateChangeDate = CarbonImmutable::parse($nextExpiry)->addDay()->toDateString();
                $facts[] = [
                    'id' => 0,
                    'type' => 'expiry_cessation',
                    'declared_contractual_date' => $nextExpiry,
                    'state_change_date' => $stateChangeDate,
                    'renewed_expiry_date' => null,
                    'renewal_configuration_id' => $configuration->id,
                    'annulled_at' => null,
                ];
                foreach ($conditions as &$condition) {
                    if ($condition['annulled_at'] === null
                        && $condition['valid_to'] === null
                        && $condition['valid_from'] <= $nextExpiry) {
                        $condition['valid_to'] = $nextExpiry;
                    }
                }
                unset($condition);
                $projectedEvents[] = [
                    'type' => 'expiry_cessation',
                    'declared_contractual_date' => $nextExpiry,
                    'state_change_date' => $stateChangeDate,
                    'renewal_configuration_id' => $configuration->id,
                ];
                $nextExpiry = null;
                break;
            }

            $duration = $configuration->renewal_duration_months;
            $anchor = $configuration->expiryAnchorDate()?->toDateString();
            if ($duration === null || $duration < 1 || $anchor === null) {
                throw new DomainException('La configurazione di rinnovo storica è incompleta.');
            }
            if (ContractRenewalSchedule::hasRenewalWithoutCondition($conditions, $nextExpiry)) {
                $renewalWithoutCondition = true;
            }
            $renewedExpiry = $nextExpiry;
            $nextExpiry = ContractRenewalSchedule::nextAnchoredExpiry($anchor, $duration, $renewedExpiry);
            $facts[] = [
                'id' => 0,
                'type' => 'renewal',
                'declared_contractual_date' => $renewedExpiry,
                'state_change_date' => null,
                'renewed_expiry_date' => $renewedExpiry,
                'renewal_configuration_id' => $configuration->id,
                'annulled_at' => null,
            ];
            $projectedEvents[] = [
                'type' => 'renewal',
                'renewed_expiry_date' => $renewedExpiry,
                'next_expiry_date' => $nextExpiry,
                'renewal_configuration_id' => $configuration->id,
            ];
        }

        $exerciseImpacts = [];
        foreach ($openExercises as $exercise) {
            if ($exercise->company_id !== $contract->company_id) {
                continue;
            }
            $referenceDate = self::annualReferenceDate($exercise->year, $today);
            $stateBefore = ContractStateTimeline::stateAtDate(
                $contract->contractualStartDate()->toDateString(),
                $persistedFacts,
                $referenceDate,
                $configurations,
            );
            $stateAfter = ContractStateTimeline::stateAtDate(
                $contract->contractualStartDate()->toDateString(),
                $facts,
                $referenceDate,
                $configurations,
            );
            $allocation = self::allocationForYear($contract, [
                'conditions' => $conditions,
                'lifecycle_facts' => $facts,
            ], $exercise->year);
            $current = $contract->annualTotals()[$exercise->id]['allocation'] ?? '0.00';
            $exerciseImpacts[$exercise->id] = [
                'exercise_id' => $exercise->id,
                'year' => $exercise->year,
                'allocation_before' => Decimal::money((string) $current),
                'allocation_after' => $allocation['amount'],
                'allocation_delta' => Decimal::subtract($allocation['amount'], (string) $current),
                'composition' => $allocation['composition'],
                'state_before' => $stateBefore->value,
                'state_after' => $stateAfter->value,
                'state_changed' => $stateBefore !== $stateAfter,
            ];
        }
        ksort($exerciseImpacts);

        return [
            'contract_id' => $contract->id,
            'contract_revision' => $contract->revision,
            'cutoff_date' => $cutoff->toDateString(),
            'next_expiry_before' => $contract->nextExpiryDate()?->toDateString(),
            'next_expiry_after' => $nextExpiry,
            'conditions' => $conditions,
            'lifecycle_facts' => $facts,
            'projected_events' => $projectedEvents,
            'renewal_without_condition' => $renewalWithoutCondition,
            'exercise_impacts' => $exerciseImpacts,
            'changed' => $projectedEvents !== [],
        ];
    }

    /**
     * @param  array<string, mixed>  $projection
     * @return array{amount: string, composition: list<array<string, mixed>>}
     */
    public static function allocationForYear(Contract $contract, array $projection, int $year): array
    {
        $contract->loadMissing('renewalConfigurations');
        $allocation = ContractAnnualAllocation::forYear(
            (array) ($projection['conditions'] ?? []),
            $year,
            fn (string $date) => ContractStateTimeline::stateAtDate(
                $contract->contractualStartDate()->toDateString(),
                (array) ($projection['lifecycle_facts'] ?? []),
                $date,
                $contract->renewalConfigurations,
            ),
        );

        return ['amount' => $allocation->amount, 'composition' => $allocation->composition];
    }

    private static function annualReferenceDate(int $year, CarbonImmutable $today): string
    {
        return match (true) {
            $year < $today->year => $year.'-12-31',
            $year > $today->year => $year.'-01-01',
            default => $today->toDateString(),
        };
    }
}
