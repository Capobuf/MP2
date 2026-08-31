<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Supplier;
use App\Models\TenantCompany;
use App\Models\User;

class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Supplier');
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $this->canUse($user, $supplier->company, 'View:Supplier');
    }

    public function create(User $user, ?Company $company = null): bool
    {
        return $user->can('Create:Supplier')
            && ($company === null || $this->canAccess($user, $company));
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $this->canUse($user, $supplier->company, 'Update:Supplier');
    }

    public function delete(User $user, Supplier $supplier): bool
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
