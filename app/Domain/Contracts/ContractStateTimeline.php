<?php

namespace App\Domain\Contracts;

use Carbon\CarbonImmutable;

final class ContractStateTimeline
{
    /** @param iterable<array<string, mixed>|object> $facts */
    public static function stateAtDate(string $contractualStartDate, iterable $facts, string $referenceDate): ContractState
    {
        $reference = CarbonImmutable::parse($referenceDate)->startOfDay();
        $start = CarbonImmutable::parse($contractualStartDate)->startOfDay();
        $state = $reference->lessThan($start) ? ContractState::Planned : ContractState::Active;
        $active = [];

        foreach ($facts as $fact) {
            if (self::value($fact, 'annulled_at') === null && self::value($fact, 'state_change_date') !== null) {
                $active[] = $fact;
            }
        }

        usort($active, fn (array|object $left, array|object $right): int => [
            (string) self::value($left, 'state_change_date'),
            (int) (self::value($left, 'id') ?? 0),
        ] <=> [
            (string) self::value($right, 'state_change_date'),
            (int) (self::value($right, 'id') ?? 0),
        ]);

        foreach ($active as $fact) {
            $effective = CarbonImmutable::parse((string) self::value($fact, 'state_change_date'))->startOfDay();
            if ($effective->greaterThan($reference)) {
                break;
            }

            $state = match ((string) self::value($fact, 'type')) {
                'activation', 'reactivation' => ContractState::Active,
                'cessation', 'expiry_cessation' => ContractState::Cessated,
                'cancellation' => ContractState::Cancelled,
                default => $state,
            };
        }

        return $state;
    }

    public static function referenceDateForExercise(int $year, CarbonImmutable $today): CarbonImmutable
    {
        if ($year < $today->year) {
            return CarbonImmutable::create($year, 12, 31, 0, 0, 0, $today->timezone);
        }

        if ($year > $today->year) {
            return CarbonImmutable::create($year, 1, 1, 0, 0, 0, $today->timezone);
        }

        return $today->startOfDay();
    }

    /** @param array<string, mixed>|object $value */
    private static function value(array|object $value, string $key): mixed
    {
        return is_array($value) ? ($value[$key] ?? null) : ($value->{$key} ?? null);
    }
}
