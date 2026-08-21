<?php

namespace App\Domain\Contracts;

use App\Domain\Expenses\ExpenseLineType;
use App\Models\Contract;
use App\Models\ExpenseLine;

final class ContractEconomicUse
{
    public static function exists(Contract $contract): bool
    {
        return ExpenseLine::query()
            ->join('expenses', 'expenses.id', '=', 'expense_lines.expense_id')
            ->where('expenses.contract_id', $contract->id)
            ->whereNull('expense_lines.annulled_at')
            ->where(function ($query): void {
                $query->where(function ($query): void {
                    $query->where('expenses.origin', 'system')
                        ->where('expense_lines.type', ExpenseLineType::Estimate->value)
                        ->where('expense_lines.amount', '!=', '0.00');
                })->orWhere('expense_lines.type', ExpenseLineType::Actual->value);
            })
            ->exists();
    }
}
