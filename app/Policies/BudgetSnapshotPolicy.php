<?php

namespace App\Policies;

use App\Models\BudgetSnapshot;
use App\Models\TenantCompany;
use App\Models\User;

class BudgetSnapshotPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:BudgetSnapshot');
    }

    public function view(User $user, BudgetSnapshot $budget): bool
    {
        return $budget->company->tenantCompany instanceof TenantCompany
            && $user->canAccessTenant($budget->company->tenantCompany)
            && $user->can('View:BudgetSnapshot');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, BudgetSnapshot $budget): bool
    {
        return false;
    }

    public function delete(User $user, BudgetSnapshot $budget): bool
    {
        return false;
    }

    public function downloadEvidence(User $user, BudgetSnapshot $budget): bool
    {
        return $this->view($user, $budget);
    }
}
