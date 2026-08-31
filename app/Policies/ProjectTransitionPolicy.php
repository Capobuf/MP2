<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Project;
use App\Models\ProjectTransition;
use App\Models\TenantCompany;
use App\Models\User;

class ProjectTransitionPolicy
{
    public function view(User $user, ProjectTransition $transition): bool
    {
        return $this->canUse($user, $transition->project->company, 'View:Project');
    }

    public function create(User $user, Project $project): bool
    {
        return $this->canUse($user, $project->company, 'Update:Project');
    }

    public function update(User $user, ProjectTransition $transition): bool
    {
        return $this->canUse($user, $transition->project->company, 'Update:Project');
    }

    public function delete(User $user, ProjectTransition $transition): bool
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
