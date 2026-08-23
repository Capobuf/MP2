<?php

namespace App\Domain\Contracts;

use App\Domain\Expenses\ExerciseStatus;
use App\Models\Contract;
use App\Models\Exercise;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class ContractClosedHistoryGuard
{
    private static int $automaticMaterializationDepth = 0;

    public static function duringAutomaticMaterialization(callable $callback): mixed
    {
        self::$automaticMaterializationDepth++;
        try {
            return $callback();
        } finally {
            self::$automaticMaterializationDepth--;
        }
    }

    public static function automaticMaterializationAllowed(): bool
    {
        return self::$automaticMaterializationDepth > 0;
    }

    public static function assertEventDateIsMutable(int|Contract $companyOrContract, string $date): void
    {
        if (self::automaticMaterializationAllowed()) {
            return;
        }
        $companyId = $companyOrContract instanceof Contract ? $companyOrContract->company_id : $companyOrContract;
        $year = CarbonImmutable::parse($date)->year;
        if (Exercise::query()
            ->where('company_id', $companyId)
            ->where('status', ExerciseStatus::Closed->value)
            ->where('year', '>=', $year)
            ->exists()) {
            throw ValidationException::withMessages([
                'contract' => 'L’operazione ordinaria modificherebbe lo stato storico di un Esercizio Chiuso.',
            ]);
        }
    }

    /** @return list<int> */
    public static function closedYears(int $companyId): array
    {
        return Exercise::query()
            ->where('company_id', $companyId)
            ->where('status', ExerciseStatus::Closed->value)
            ->orderBy('year')
            ->pluck('year')
            ->map(fn (mixed $year): int => (int) $year)
            ->all();
    }

    public static function periodOverlapsYear(string $validFrom, ?string $validTo, int $year): bool
    {
        $start = CarbonImmutable::parse($validFrom)->startOfDay();
        $end = $validTo === null ? null : CarbonImmutable::parse($validTo)->startOfDay();
        $yearStart = CarbonImmutable::create($year, 1, 1)->startOfDay();
        $yearEnd = CarbonImmutable::create($year, 12, 31)->startOfDay();

        return ! $start->greaterThan($yearEnd) && ($end === null || ! $end->lessThan($yearStart));
    }
}
