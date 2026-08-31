<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\TenantCompany;
use App\Models\User;

class CompanyPolicy
{
    public function create(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    public function view(User $user, Company $company): bool
    {
        return $this->canUse($user, $company, 'View:BusinessDataBackup');
    }

    public function manageSettings(User $user, Company $company): bool
    {
        return $this->canUse($user, $company, 'View:CompanySettings');
    }

    private function canUse(User $user, Company $company, string $permission): bool
    {
        return $company->tenantCompany instanceof TenantCompany
            && $user->canAccessTenant($company->tenantCompany)
            && $user->can($permission);
    }
}
