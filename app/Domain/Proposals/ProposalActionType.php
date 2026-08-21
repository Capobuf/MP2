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
    case CreateContract = 'create_contract';
    case AddContractCondition = 'add_contract_condition';
    case ChangeContractEconomics = 'change_contract_economics';
    case PlanContractLifecycle = 'plan_contract_lifecycle';
    case SetContractRenewal = 'set_contract_renewal';
    case SetContractCostCenter = 'set_contract_cost_center';
    case LinkProjectContract = 'link_project_contract';

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->title()->toString();
    }
}
