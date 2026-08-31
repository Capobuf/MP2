<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\ProjectContractLink;
use App\Models\TenantCompany;
use App\Models\User;

class ProjectContractLinkPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Project');
    }

    public function view(User $user, ProjectContractLink $link): bool
    {
        return $this->canUse($user, $link->company, 'View:Project');
    }

    public function create(User $user, ?Company $company = null): bool
    {
        return $user->can('Update:Project')
            && ($company === null || $this->canAccess($user, $company));
    }

    public function update(User $user, ProjectContractLink $link): bool
    {
        return $this->canUse($user, $link->company, 'Update:Project');
    }

    public function delete(User $user, ProjectContractLink $link): bool
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
