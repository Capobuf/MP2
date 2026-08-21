<?php

namespace App\Domain\Contracts;

use Carbon\CarbonImmutable;

final class ContractLifecycleRules
{
    /** @param iterable<array<string, mixed>|object> $facts */
    public static function validate(string $contractualStartDate, iterable $facts): void
    {
        $active = [];
        foreach ($facts as $fact) {
            if (self::value($fact, 'annulled_at') !== null || self::value($fact, 'state_change_date') === null) {
                continue;
            }
            $active[] = $fact;
        }

        usort($active, fn (array|object $left, array|object $right): int => [
            (string) self::value($left, 'state_change_date'),
            (int) (self::value($left, 'id') ?? 0),
        ] <=> [
            (string) self::value($right, 'state_change_date'),
            (int) (self::value($right, 'id') ?? 0),
        ]);

        $dates = [];
        $accepted = [];
        $hasActivation = false;
        foreach ($active as $fact) {
            $date = (string) self::value($fact, 'state_change_date');
            if (isset($dates[$date])) {
                throw new \DomainException('Non possono esistere due eventi contrattuali attivi con la stessa data di efficacia.');
            }
            $dates[$date] = true;

            $type = (string) self::value($fact, 'type');
            $previous = ContractStateTimeline::stateAtDate(
                $contractualStartDate,
                $accepted,
                CarbonImmutable::parse($date)->subDay()->toDateString(),
            );

            $compatible = match ($type) {
                'activation' => $previous === ContractState::Planned,
                'reactivation' => in_array($previous, [ContractState::Cessated, ContractState::Cancelled], true),
                'cessation', 'expiry_cessation' => $previous === ContractState::Active,
                'cancellation' => $previous === ContractState::Planned && ! $hasActivation,
                default => false,
            };
            if (! $compatible) {
                throw new \DomainException('La sequenza degli eventi contrattuali non è compatibile con lo stato precedente.');
            }

            $accepted[] = $fact;
            $hasActivation = $hasActivation || $type === 'activation';
        }
    }

    /** @param array<string, mixed>|object $value */
    private static function value(array|object $value, string $key): mixed
    {
        return is_array($value) ? ($value[$key] ?? null) : ($value->{$key} ?? null);
    }
}
