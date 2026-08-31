<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Project;
use App\Models\TenantCompany;
use App\Models\User;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Project');
    }

    public function view(User $user, Project $project): bool
    {
        return $this->canUse($user, $project->company, 'View:Project');
    }

    public function create(User $user, ?Company $company = null): bool
    {
        return $user->can('Create:Project')
            && ($company === null || $this->canAccess($user, $company));
    }

    public function update(User $user, Project $project): bool
    {
        return $this->canUse($user, $project->company, 'Update:Project');
    }

    public function delete(User $user, Project $project): bool
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
