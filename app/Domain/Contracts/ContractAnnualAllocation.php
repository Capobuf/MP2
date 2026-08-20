<?php

namespace App\Domain\Contracts;

use App\Domain\Expenses\Decimal;

final readonly class ContractAnnualAllocation
{
    /** @param list<array{condition_id: int, cycle_start: string, attribution_date: string, amount: string}> $composition */
    public function __construct(public string $amount, public array $composition) {}

    /**
     * @param  iterable<array<string, mixed>|object>  $conditions
     * @param  callable(string): ContractState  $stateAt
     */
    public static function forYear(iterable $conditions, int $year, callable $stateAt): self
    {
        $composition = [];

        foreach ($conditions as $condition) {
            if (self::value($condition, 'annulled_at') !== null) {
                continue;
            }

            $cycles = ContractCycle::enumerate(
                conditionId: (int) self::value($condition, 'id'),
                cycle: ContractCycleType::from((string) self::value($condition, 'cycle')),
                attributionMode: ContractAttributionMode::from((string) self::value($condition, 'attribution_mode')),
                amount: Decimal::money((string) self::value($condition, 'amount')),
                validFrom: self::date($condition, 'valid_from'),
                validTo: self::nullableDate($condition, 'valid_to'),
                through: $year.'-12-31',
            );

            foreach ($cycles as $cycle) {
                if ($cycle->attributionDate->year !== $year || $stateAt($cycle->start->toDateString()) !== ContractState::Active) {
                    continue;
                }

                $composition[] = [
                    'condition_id' => $cycle->conditionId,
                    'cycle_start' => $cycle->start->toDateString(),
                    'attribution_date' => $cycle->attributionDate->toDateString(),
                    'amount' => Decimal::money($cycle->amount),
                ];
            }
        }

        usort($composition, fn (array $left, array $right): int => [
            $left['attribution_date'], $left['cycle_start'], $left['condition_id'],
        ] <=> [
            $right['attribution_date'], $right['cycle_start'], $right['condition_id'],
        ]);

        return new self(Decimal::sum(array_column($composition, 'amount')), $composition);
    }

    /** @param array<string, mixed>|object $value */
    private static function value(array|object $value, string $key): mixed
    {
        return is_array($value) ? ($value[$key] ?? null) : ($value->{$key} ?? null);
    }

    /** @param array<string, mixed>|object $value */
    private static function date(array|object $value, string $key): string
    {
        $date = self::value($value, $key);

        return $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : (string) $date;
    }

    /** @param array<string, mixed>|object $value */
    private static function nullableDate(array|object $value, string $key): ?string
    {
        $date = self::value($value, $key);

        return $date === null ? null : self::date($value, $key);
    }
}
