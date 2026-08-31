<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Expense;
use App\Models\TenantCompany;
use App\Models\User;

class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Expense');
    }

    public function view(User $user, Expense $expense): bool
    {
        return $this->canUse($user, $expense->company, 'View:Expense');
    }

    public function create(User $user, ?Company $company = null): bool
    {
        return $user->can('Create:Expense')
            && ($company === null || $this->canAccess($user, $company));
    }

    public function update(User $user, Expense $expense): bool
    {
        return $expense->exercise->isOpen()
            && $this->canUse($user, $expense->company, 'Update:Expense');
    }

    public function delete(User $user, Expense $expense): bool
    {
        return false;
    }

    private function canUse(User $user, Company $company, string $permission): bool
    {
        return $this->canAccess($user, $company) && $user->can($permission);
    }

    private function canAccess(User $user, Company $company): bool
    {
        return $company->tenantCompany instanceof TenantCompany
            && $user->canAccessTenant($company->tenantCompany);
    }
}
