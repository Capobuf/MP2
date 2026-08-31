<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\CostCenter;
use App\Models\TenantCompany;
use App\Models\User;

class CostCenterPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:CostCenter');
    }

    public function view(User $user, CostCenter $costCenter): bool
    {
        return $this->canUse($user, $costCenter->company, 'View:CostCenter');
    }

    public function create(User $user, ?Company $company = null): bool
    {
        return $user->can('Create:CostCenter')
            && ($company === null || $this->canAccess($user, $company));
    }

    public function update(User $user, CostCenter $costCenter): bool
    {
        return $this->canUse($user, $costCenter->company, 'Update:CostCenter');
    }

    public function delete(User $user, CostCenter $costCenter): bool
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
