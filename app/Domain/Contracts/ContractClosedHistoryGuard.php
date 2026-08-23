<?php

namespace App\Domain\Contracts;

use App\Domain\Expenses\ExerciseStatus;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractLifecycleFact;
use App\Models\Exercise;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class ContractClosedHistoryGuard
{
    public static function assertEventDateIsMutable(Contract $contract, string $date): void
    {
        $year = CarbonImmutable::parse($date)->year;
        if (Exercise::query()
            ->where('company_id', $contract->company_id)
            ->where('status', ExerciseStatus::Closed->value)
            ->where('year', '>=', $year)
            ->exists()) {
            throw ValidationException::withMessages([
                'contract' => 'L’operazione ordinaria modificherebbe lo stato storico di un Esercizio Chiuso.',
            ]);
        }
    }

    public static function assertConditionIsMutable(ContractCondition $condition): void
    {
        self::assertPeriodIsMutable(
            $condition->contract,
            $condition->validFrom()->toDateString(),
            $condition->validTo()?->toDateString(),
        );
    }

    public static function assertConditionPeriodIsMutable(Contract $contract, string $validFrom, ?string $validTo): void
    {
        self::assertPeriodIsMutable($contract, $validFrom, $validTo);
    }

    public static function assertLifecycleFactIsMutable(ContractLifecycleFact $fact): void
    {
        $date = $fact->stateChangeDate()?->toDateString()
            ?? $fact->renewedExpiryDate()?->toDateString()
            ?? $fact->declaredContractualDate()->toDateString();
        self::assertEventDateIsMutable($fact->contract, $date);
    }

    private static function assertPeriodIsMutable(Contract $contract, string $validFrom, ?string $validTo): void
    {
        $fromYear = CarbonImmutable::parse($validFrom)->year;
        $query = Exercise::query()
            ->where('company_id', $contract->company_id)
            ->where('status', ExerciseStatus::Closed->value)
            ->where('year', '>=', $fromYear);
        if ($validTo !== null) {
            $query->where('year', '<=', CarbonImmutable::parse($validTo)->year);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'contract' => 'L’operazione ordinaria modificherebbe condizioni economiche di un Esercizio Chiuso.',
            ]);
        }
    }
}
