<?php

namespace App\Domain\Contracts;

use App\Domain\Expenses\Decimal;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\Exercise;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final readonly class ContractEconomicChangePlan
{
    /**
     * @param  array<string, mixed>  $oldTerms
     * @param  array<string, mixed>  $newTerms
     * @param  array<int, int>  $exerciseRevisions
     * @param  array<int, array<string, mixed>>  $exerciseImpacts
     * @param  list<int>  $sourceConditionIds
     */
    public function __construct(
        public string $operationKind,
        public int $contractId,
        public int $conditionId,
        public int $contractRevision,
        public string $conditionUpdatedAt,
        public ?string $requestedDate,
        public ?string $minimumDate,
        public string $effectiveDate,
        public string $delayReason,
        public bool $noProrata,
        public bool $futureReplacement,
        public array $oldTerms,
        public array $newTerms,
        public array $exerciseRevisions,
        public array $exerciseImpacts,
        public array $sourceConditionIds,
    ) {}

    /**
     * @param  array{cycle: string, valid_from: string}  $currentTerms
     * @return array{requested_date: string, minimum_date: string, effective_date: string, delay_reason: string, no_prorata: bool, future_replacement: bool}
     */
    public static function boundary(array $currentTerms, string $requestedDate, string $confirmationDate, ?string $lastApplicableDate): array
    {
        $requested = CarbonImmutable::parse($requestedDate)->startOfDay();
        $confirmation = CarbonImmutable::parse($confirmationDate)->startOfDay();
        $minimum = $confirmation->addMonthNoOverflow()->startOfMonth();
        $threshold = $requested->greaterThan($minimum) ? $requested : $minimum;
        $anchor = CarbonImmutable::parse($currentTerms['valid_from'])->startOfDay();
        $futureReplacement = $anchor->greaterThanOrEqualTo($threshold);

        if ($futureReplacement) {
            $effective = $anchor;
        } else {
            $cycle = ContractCycleType::from($currentTerms['cycle']);
            $effective = $anchor;
            for ($index = 0; $effective->lessThan($threshold); $index++) {
                $effective = ContractCycle::anchoredDate($anchor, ($index + 1) * $cycle->months());
            }
        }

        if ($lastApplicableDate !== null && $effective->greaterThan(CarbonImmutable::parse($lastApplicableDate)->startOfDay())) {
            throw new \DomainException('Nessun confine di ciclo applicabile prima della cessazione o della scadenza non rinnovata.');
        }

        $reasons = [];
        if ($requested->lessThan($minimum)) {
            $reasons[] = 'La modifica non può iniziare prima del primo giorno del mese successivo alla conferma.';
        }
        if ($effective->greaterThan($threshold)) {
            $reasons[] = 'La decorrenza è rinviata al primo confine del ciclo applicabile.';
        }
        if ($futureReplacement) {
            $reasons[] = 'La condizione futura non iniziata mantiene la decorrenza originaria.';
        }

        return [
            'requested_date' => $requested->toDateString(),
            'minimum_date' => $minimum->toDateString(),
            'effective_date' => $effective->toDateString(),
            'delay_reason' => $reasons === [] ? 'Nessun rinvio rispetto alla data richiesta.' : implode(' ', $reasons),
            'no_prorata' => true,
            'future_replacement' => $futureReplacement,
        ];
    }

    /**
     * @param  array{amount: string, cycle: string, attribution_mode: string}  $newTerms
     * @param  iterable<int, Exercise>  $exercises
     */
    public static function forChange(
        Contract $contract,
        ContractCondition $condition,
        array $newTerms,
        string $requestedDate,
        string $confirmationDate,
        iterable $exercises,
    ): self {
        $boundary = self::boundary(
            ['cycle' => (string) $condition->cycle, 'valid_from' => $condition->validFrom()->toDateString()],
            $requestedDate,
            $confirmationDate,
            self::lastApplicableDate($contract),
        );

        return self::build(
            operationKind: 'change',
            contract: $contract,
            condition: $condition,
            newTerms: $newTerms,
            exercises: $exercises,
            effectiveDate: $boundary['effective_date'],
            requestedDate: $boundary['requested_date'],
            minimumDate: $boundary['minimum_date'],
            delayReason: $boundary['delay_reason'],
            futureReplacement: $boundary['future_replacement'],
        );
    }

    /**
     * @param  array{amount: string, cycle: string, attribution_mode: string}  $newTerms
     * @param  iterable<int, Exercise>  $exercises
     */
    public static function forCorrection(Contract $contract, ContractCondition $condition, array $newTerms, iterable $exercises): self
    {
        return self::build(
            operationKind: 'correction',
            contract: $contract,
            condition: $condition,
            newTerms: $newTerms,
            exercises: $exercises,
            effectiveDate: $condition->validFrom()->toDateString(),
            requestedDate: null,
            minimumDate: null,
            delayReason: 'Correzione dichiarata dell’input originario; nessun nuovo accordo.',
            futureReplacement: false,
        );
    }

    public function fingerprint(): string
    {
        return ContractImpactFingerprint::make($this->toArray());
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @return array<int, string> */
    public function allocatedImpact(): array
    {
        return array_map(fn (array $impact): string => (string) $impact['allocation_delta'], $this->exerciseImpacts);
    }

    /**
     * @param  array{amount: string, cycle: string, attribution_mode: string}  $newTerms
     * @param  iterable<int, Exercise>  $exercises
     */
    private static function build(
        string $operationKind,
        Contract $contract,
        ContractCondition $condition,
        array $newTerms,
        iterable $exercises,
        string $effectiveDate,
        ?string $requestedDate,
        ?string $minimumDate,
        string $delayReason,
        bool $futureReplacement,
    ): self {
        $contract->loadMissing(['conditions', 'lifecycleFacts']);
        $oldTerms = self::terms($condition);
        $newTerms += ['valid_from' => $effectiveDate, 'valid_to' => $condition->validTo()?->toDateString()];
        $beforeConditions = $contract->conditions->map(fn (ContractCondition $item): array => self::terms($item))->all();
        $afterConditions = [];

        foreach ($beforeConditions as $item) {
            if ((int) $item['id'] !== $condition->id) {
                $afterConditions[] = $item;

                continue;
            }

            if ($operationKind === 'correction') {
                $afterConditions[] = $item + [];
                $last = array_key_last($afterConditions);
                $afterConditions[$last] = array_replace($item, $newTerms);
            } elseif (! $futureReplacement) {
                $afterConditions[] = array_replace($item, [
                    'valid_to' => CarbonImmutable::parse($effectiveDate)->subDay()->toDateString(),
                ]);
            }
        }

        if ($operationKind === 'change') {
            $afterConditions[] = [
                'id' => 0,
                'amount' => $newTerms['amount'],
                'cycle' => $newTerms['cycle'],
                'attribution_mode' => $newTerms['attribution_mode'],
                'valid_from' => $effectiveDate,
                'valid_to' => $condition->validTo()?->toDateString(),
                'annulled_at' => null,
            ];
        }

        $exerciseRevisions = [];
        $exerciseImpacts = [];
        foreach ($exercises as $exercise) {
            if ($exercise->company_id !== $contract->company_id) {
                throw ValidationException::withMessages(['exercises' => 'Gli Esercizi devono appartenere alla stessa Azienda.']);
            }
            $stateAt = fn (string $date): ContractState => ContractStateTimeline::stateAtDate(
                $contract->contractualStartDate()->toDateString(),
                $contract->lifecycleFacts,
                $date,
            );
            $before = ContractAnnualAllocation::forYear($beforeConditions, $exercise->year, $stateAt);
            $after = ContractAnnualAllocation::forYear($afterConditions, $exercise->year, $stateAt);
            if (Decimal::compare($before->amount, $after->amount) === 0 && $before->composition === $after->composition) {
                continue;
            }
            if ($operationKind === 'correction' && ! $exercise->isOpen()) {
                throw ValidationException::withMessages([
                    'exercises' => 'La correzione materiale richiede che ogni Esercizio economicamente interessato sia Aperto.',
                ]);
            }
            if (! $exercise->isOpen()) {
                continue;
            }
            $exerciseRevisions[(string) $exercise->id] = $exercise->revision;
            $exerciseImpacts[(string) $exercise->id] = [
                'year' => $exercise->year,
                'allocation_before' => $before->amount,
                'allocation_after' => $after->amount,
                'allocation_delta' => Decimal::subtract($after->amount, $before->amount),
                'composition_before' => $before->composition,
                'composition_after' => $after->composition,
            ];
        }
        ksort($exerciseRevisions, SORT_NUMERIC);
        ksort($exerciseImpacts, SORT_NUMERIC);

        return new self(
            operationKind: $operationKind,
            contractId: $contract->id,
            conditionId: $condition->id,
            contractRevision: $contract->revision,
            conditionUpdatedAt: $condition->updated_at?->toISOString() ?? '',
            requestedDate: $requestedDate,
            minimumDate: $minimumDate,
            effectiveDate: $effectiveDate,
            delayReason: $delayReason,
            noProrata: true,
            futureReplacement: $futureReplacement,
            oldTerms: $oldTerms,
            newTerms: $newTerms,
            exerciseRevisions: $exerciseRevisions,
            exerciseImpacts: $exerciseImpacts,
            sourceConditionIds: $contract->conditions->pluck('id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all(),
        );
    }

    /** @return array<string, mixed> */
    private static function terms(ContractCondition $condition): array
    {
        return [
            'id' => $condition->id,
            'amount' => Decimal::money((string) $condition->amount),
            'cycle' => (string) $condition->cycle,
            'attribution_mode' => (string) $condition->attribution_mode,
            'valid_from' => $condition->validFrom()->toDateString(),
            'valid_to' => $condition->validTo()?->toDateString(),
            'annulled_at' => $condition->annulledAt()?->toISOString(),
        ];
    }

    private static function lastApplicableDate(Contract $contract): ?string
    {
        $dates = [];
        if (! $contract->automatic_renewal && $contract->nextExpiryDate() !== null) {
            $dates[] = $contract->nextExpiryDate()->toDateString();
        }
        foreach ($contract->lifecycleFacts as $fact) {
            if ($fact->annulled_at === null && (string) $fact->type === 'cessation') {
                $date = $fact->stateChangeDate();
                if ($date !== null) {
                    $dates[] = $date->toDateString();
                }
            }
        }

        sort($dates);

        return $dates[0] ?? null;
    }
}
