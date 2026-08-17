<?php

namespace App\Domain\Projects;

use Carbon\CarbonImmutable;
use DomainException;

final class ProjectStateTimeline
{
    /** @param iterable<array<string, mixed>|object> $transitions */
    public static function stateAtDate(
        ProjectState $initialState,
        string $initialEffectiveDate,
        iterable $transitions,
        string $referenceDate,
    ): ?ProjectState {
        $reference = CarbonImmutable::parse($referenceDate)->startOfDay();

        if ($reference->lessThan(CarbonImmutable::parse($initialEffectiveDate)->startOfDay())) {
            return null;
        }

        $state = $initialState;
        foreach (self::activeOrdered($transitions) as $transition) {
            if (CarbonImmutable::parse(self::value($transition, 'effective_date'))->startOfDay()->greaterThan($reference)) {
                break;
            }

            $state = self::stateValue($transition, 'to_state');
        }

        return $state;
    }

    /** @param iterable<array<string, mixed>|object> $transitions */
    public static function validate(ProjectState $initialState, string $initialEffectiveDate, iterable $transitions): void
    {
        $state = $initialState;
        $initialDate = CarbonImmutable::parse($initialEffectiveDate)->toDateString();
        $previousDate = null;

        foreach (self::activeOrdered($transitions) as $transition) {
            $effectiveDate = CarbonImmutable::parse(self::value($transition, 'effective_date'))->toDateString();
            $from = self::stateValue($transition, 'from_state');
            $to = self::stateValue($transition, 'to_state');

            if ($effectiveDate <= $initialDate) {
                throw new DomainException('Una transizione deve essere successiva allo stato iniziale del Progetto.');
            }
            if ($previousDate === $effectiveDate) {
                throw new DomainException('Due transizioni attive non possono avere la stessa data di efficacia.');
            }
            if ($from !== $state) {
                throw new DomainException('Lo stato di origine non coincide con lo stato alla data precedente.');
            }
            if (! $from->canTransitionTo($to)) {
                throw new DomainException('La transizione di Progetto non è ammessa.');
            }

            $state = $to;
            $previousDate = $effectiveDate;
        }
    }

    /**
     * @param  iterable<array<string, mixed>|object>  $transitions
     * @return list<array<string, mixed>|object>
     */
    private static function activeOrdered(iterable $transitions): array
    {
        $active = [];
        foreach ($transitions as $transition) {
            if (self::value($transition, 'annulled_at') === null) {
                $active[] = $transition;
            }
        }

        usort($active, fn (array|object $left, array|object $right): int => strcmp(
            (string) self::value($left, 'effective_date'),
            (string) self::value($right, 'effective_date'),
        ));

        return $active;
    }

    /** @param array<string, mixed>|object $transition */
    private static function value(array|object $transition, string $key): mixed
    {
        if (is_array($transition)) {
            return $transition[$key] ?? null;
        }

        return $transition->{$key} ?? null;
    }

    /** @param array<string, mixed>|object $transition */
    private static function stateValue(array|object $transition, string $key): ProjectState
    {
        $value = self::value($transition, $key);

        return $value instanceof ProjectState ? $value : ProjectState::from((string) $value);
    }
}
