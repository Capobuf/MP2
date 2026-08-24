<?php

namespace App\Domain\LateCorrections;

use App\Domain\Expenses\ExpenseLineType;
use App\Models\Exercise;
use App\Models\Expense;

final class HistoricalExpenseCompatibility
{
    public function accepts(Expense $expense, Exercise $exercise, string $sourceType, int $sourceOriginId): bool
    {
        if ($expense->company_id !== $exercise->company_id
            || $expense->exercise_id !== $exercise->id
            || $expense->origin !== 'manual'
            || $expense->isReversed()
            || $sourceOriginId < 1) {
            return false;
        }

        if ($expense->contract_id !== null
            && $expense->lines()->where('type', ExpenseLineType::Estimate->value)->exists()) {
            return false;
        }

        return match ($sourceType) {
            'expense' => $expense->project_id === null
                && $expense->contract_id === null
                && $expense->id === $sourceOriginId,
            'project' => $expense->project_id === $sourceOriginId
                && $expense->contract_id === null,
            'contract' => $expense->contract_id === $sourceOriginId
                && $expense->project_id === null,
            default => false,
        };
    }

    public function __invoke(Expense $expense, Exercise $exercise, string $sourceType, int $sourceOriginId): bool
    {
        return $this->accepts($expense, $exercise, $sourceType, $sourceOriginId);
    }
}
