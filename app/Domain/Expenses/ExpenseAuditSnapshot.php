<?php

namespace App\Domain\Expenses;

use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;

final class ExpenseAuditSnapshot
{
    /** @return array{year: int, status: string, revision: int} */
    public static function exercise(Exercise $exercise): array
    {
        return [
            'year' => $exercise->year,
            'status' => $exercise->status()->value,
            'revision' => $exercise->revision,
        ];
    }

    /** @return array<string, mixed> */
    public static function expense(Expense $expense, bool $includeLines = false): array
    {
        $snapshot = [
            'origin_key' => $expense->originKey(),
            'exercise_id' => $expense->exercise_id,
            'supplier_id' => $expense->supplier_id,
            'direct_cost_center_id' => $expense->direct_cost_center_id,
            'description' => $expense->description,
            'notes' => $expense->notes,
            'reversed' => $expense->isReversed(),
            'allocation' => $expense->allocation(),
            'actual' => $expense->actual(),
            'operational_variance' => $expense->operationalVariance(),
            'has_actuals' => $expense->hasActuals(),
            'revision' => $expense->revision,
        ];

        if ($includeLines) {
            $snapshot['lines'] = $expense->lines()->orderBy('id')->get()
                ->map(fn (ExpenseLine $line): array => self::line($line))->all();
        }

        return $snapshot;
    }

    /** @return array<string, mixed> */
    public static function line(ExpenseLine $line): array
    {
        return [
            'expense_id' => $line->expense_id,
            'type' => $line->lineType()->value,
            'amount' => $line->amount,
            'quantity' => $line->quantity,
            'unit_amount' => $line->unit_amount,
            'unit_of_measure' => $line->unit_of_measure,
            'note' => $line->note,
            'annulled' => $line->isAnnulled(),
        ];
    }

    /** @return array<int, string> */
    public static function impact(int $exerciseId, string $amount): array
    {
        return [(string) $exerciseId => Decimal::money($amount)];
    }
}
