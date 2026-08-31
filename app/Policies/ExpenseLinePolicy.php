<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\TenantCompany;
use App\Models\User;

class ExpenseLinePolicy
{
    public function view(User $user, ExpenseLine $line): bool
    {
        return $this->canUse($user, $line->expense->company, 'View:Expense');
    }

    public function create(User $user, Expense $expense): bool
    {
        return $expense->exercise->isOpen()
            && $this->canUse($user, $expense->company, 'Update:Expense');
    }

    public function update(User $user, ExpenseLine $line): bool
    {
        return $line->expense->exercise->isOpen()
            && $this->canUse($user, $line->expense->company, 'Update:Expense');
    }

    public function delete(User $user, ExpenseLine $line): bool
    {
        return false;
    }

    private function canUse(User $user, Company $company, string $permission): bool
    {
        return $company->tenantCompany instanceof TenantCompany
            && $user->canAccessTenant($company->tenantCompany)
            && $user->can($permission);
    }
}
