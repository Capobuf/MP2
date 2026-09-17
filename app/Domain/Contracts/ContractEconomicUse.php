<?php

namespace App\Domain\Contracts;

use App\Domain\Company\AuditEventType;
use App\Domain\Expenses\ExpenseLineType;
use App\Models\AuditEvent;
use App\Models\BudgetSourceRow;
use App\Models\ClosingSourceRow;
use App\Models\Contract;
use App\Models\ExpenseLine;

final class ContractEconomicUse
{
    public static function exists(Contract $contract): bool
    {
        if (BudgetSourceRow::query()->where('company_id', $contract->company_id)->where('source_type', 'contract')->where('origin_id', $contract->id)->exists()
            || ClosingSourceRow::query()->where('company_id', $contract->company_id)->where('source_type', 'contract')->where('origin_id', $contract->id)->exists()) {
            return true;
        }

        // A later recalculation to zero cannot undo the first economic use.
        if (AuditEvent::query()->where('company_id', $contract->company_id)
            ->where('subject_type', Contract::class)->where('subject_id', $contract->id)
            ->where('event_type', AuditEventType::ContractEstimateRecalculated)
            ->where('new_value->allocation', '!=', '0.00')->exists()) {
            return true;
        }

        return ExpenseLine::query()
            ->join('expenses', 'expenses.id', '=', 'expense_lines.expense_id')
            ->where('expenses.contract_id', $contract->id)
            ->where(function ($query): void {
                $query->where(function ($query): void {
                    $query->where('expenses.origin', 'system')
                        ->whereNull('expense_lines.annulled_at')
                        ->where('expense_lines.type', ExpenseLineType::Estimate->value)
                        ->where('expense_lines.amount', '!=', '0.00');
                })->orWhere('expense_lines.type', ExpenseLineType::Actual->value);
            })
            ->exists();
    }
}
