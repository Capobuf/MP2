<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;
use App\Models\TenantCompany;
use App\Models\User;

class ProjectExerciseClassificationPolicy
{
    public function view(User $user, ProjectExerciseClassification $classification): bool
    {
        return $this->canUse($user, $classification->project->company, 'View:Project');
    }

    public function create(User $user, Project $project): bool
    {
        return $this->canUse($user, $project->company, 'Update:Project');
    }

    public function update(User $user, ProjectExerciseClassification $classification): bool
    {
        return $classification->exercise->isOpen()
            && $this->canUse($user, $classification->project->company, 'Update:Project');
    }

    public function delete(User $user, ProjectExerciseClassification $classification): bool
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
