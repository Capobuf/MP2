<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Supplier;
use App\Models\SupplierContact;
use App\Models\TenantCompany;
use App\Models\User;

class SupplierContactPolicy
{
    public function view(User $user, SupplierContact $contact): bool
    {
        return $this->canUse($user, $contact->supplier->company, 'View:Supplier');
    }

    public function create(User $user, Supplier $supplier): bool
    {
        return $this->canUse($user, $supplier->company, 'Update:Supplier');
    }

    public function update(User $user, SupplierContact $contact): bool
    {
        return $this->canUse($user, $contact->supplier->company, 'Update:Supplier');
    }

    public function delete(User $user, SupplierContact $contact): bool
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
