<?php

namespace App\Domain\Proposals;

enum ProposalActionType: string
{
    case CreateExpense = 'create_expense';
    case CopyExpense = 'copy_expense';
    case SetExpenseEstimates = 'set_expense_estimates';
    case SetExpenseOwner = 'set_expense_owner';
    case SetExpenseSupplier = 'set_expense_supplier';
    case SetExpenseCostCenter = 'set_expense_cost_center';
    case ReverseExpense = 'reverse_expense';
    case RestoreExpense = 'restore_expense';
    case CreateProject = 'create_project';
    case PlanProjectChildExpenses = 'plan_project_child_expenses';
    case SetProjectCostCenter = 'set_project_cost_center';
    case PlanProjectTransition = 'plan_project_transition';
    case PlanProjectDeferral = 'plan_project_deferral';
    case CreateProjectAllocation = 'create_project_allocation';
    case CreateContract = 'create_contract';
    case AddContractCondition = 'add_contract_condition';
    case ChangeContractEconomics = 'change_contract_economics';
    case PlanContractLifecycle = 'plan_contract_lifecycle';
    case SetContractRenewal = 'set_contract_renewal';
    case SetContractCostCenter = 'set_contract_cost_center';
    case LinkProjectContract = 'link_project_contract';

    public function label(): string
    {
        return match ($this) {
            self::CreateExpense => 'Create Expense',
            self::CopyExpense => 'Copy Expense',
            self::SetExpenseEstimates => 'Set Expense Estimates',
            self::SetExpenseOwner => 'Set Expense Owner',
            self::SetExpenseSupplier => 'Set Expense Supplier',
            self::SetExpenseCostCenter => 'Set Expense Cost Center',
            self::ReverseExpense => 'Reverse Expense',
            self::RestoreExpense => 'Restore Expense',
            self::CreateProject => 'Create Project',
            self::PlanProjectChildExpenses => 'Plan Project Child Expenses',
            self::SetProjectCostCenter => 'Set Project Cost Center',
            self::PlanProjectTransition => 'Plan Project Transition',
            self::PlanProjectDeferral => 'Rinvio',
            self::CreateProjectAllocation => 'Nuova Allocazione',
            self::CreateContract => 'Create Contract',
            self::AddContractCondition => 'Add Contract Condition',
            self::ChangeContractEconomics => 'Change Contract Economics',
            self::PlanContractLifecycle => 'Plan Contract Lifecycle',
            self::SetContractRenewal => 'Set Contract Renewal',
            self::SetContractCostCenter => 'Set Contract Cost Center',
            self::LinkProjectContract => 'Link Project Contract',
        };
    }
}
