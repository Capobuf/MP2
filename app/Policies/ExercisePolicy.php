<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Exercise;
use App\Models\TenantCompany;
use App\Models\User;

class ExercisePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Exercise');
    }

    public function view(User $user, Exercise $exercise): bool
    {
        return $this->canUse($user, $exercise->company, 'View:Exercise');
    }

    public function create(User $user, ?Company $company = null): bool
    {
        return $user->can('Create:Exercise')
            && ($company === null || $this->canAccess($user, $company));
    }

    public function update(User $user, Exercise $exercise): bool
    {
        return $this->canUse($user, $exercise->company, 'Update:Exercise');
    }

    public function close(User $user, Exercise $exercise): bool
    {
        return $this->canUse($user, $exercise->company, 'Close:Exercise');
    }

    public function correctClosed(User $user, Exercise $exercise): bool
    {
        return ! $exercise->isOpen()
            && $this->canUse($user, $exercise->company, 'CorrectClosed:Exercise');
    }

    public function annotateHistoricalError(User $user, Exercise $exercise): bool
    {
        return ! $exercise->isOpen()
            && $this->canUse($user, $exercise->company, 'AnnotateHistoricalError:Exercise');
    }

    public function delete(User $user, Exercise $exercise): bool
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
