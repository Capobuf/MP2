<?php

namespace App\Domain\Contracts;

use App\Models\Contract;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class ContractConditionRules
{
    /** @param iterable<array<string, mixed>|object> $conditions */
    public static function assertMayPersist(
        Contract $contract,
        string $validFrom,
        ?string $validTo,
        iterable $conditions,
        ?int $ignoredConditionId = null,
    ): void {
        $from = CarbonImmutable::parse($validFrom)->startOfDay();
        $to = $validTo === null ? null : CarbonImmutable::parse($validTo)->startOfDay();

        if ($from->lessThan($contract->contractual_start_date->startOfDay())) {
            throw ValidationException::withMessages(['valid_from' => 'La condizione non può precedere l’inizio contrattuale.']);
        }
        if ($to !== null && $to->lessThan($from)) {
            throw ValidationException::withMessages(['valid_to' => 'La fine validità non può precedere l’inizio.']);
        }

        foreach ($conditions as $condition) {
            if ((int) self::value($condition, 'id') === $ignoredConditionId || self::value($condition, 'annulled_at') !== null) {
                continue;
            }
            $otherFrom = CarbonImmutable::parse(self::date($condition, 'valid_from'))->startOfDay();
            $otherToValue = self::nullableDate($condition, 'valid_to');
            $otherTo = $otherToValue === null ? null : CarbonImmutable::parse($otherToValue)->startOfDay();

            $endsBefore = $to !== null && $to->lessThan($otherFrom);
            $startsAfter = $otherTo !== null && $from->greaterThan($otherTo);
            if (! $endsBefore && ! $startsAfter) {
                throw ValidationException::withMessages(['valid_from' => 'Le condizioni valide dello stesso Contratto non possono sovrapporsi.']);
            }
        }
    }

    public static function assertCurrentlyActive(Contract $contract, string $today): void
    {
        if ($contract->stateAtDate($today) !== ContractState::Active) {
            throw ValidationException::withMessages(['contract' => 'Una nuova condizione richiede un Contratto Attivo.']);
        }
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
        return self::value($value, $key) === null ? null : self::date($value, $key);
    }
}
