<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Exercise;
use App\Models\LateCorrection;
use App\Models\TenantCompany;
use App\Models\User;

class LateCorrectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('View:Exercise');
    }

    public function view(User $user, LateCorrection $correction): bool
    {
        return $this->canUse($user, $correction->company, 'View:Exercise');
    }

    public function create(User $user, Company|Exercise|null $context = null): bool
    {
        $company = $context instanceof Exercise ? $context->company : $context;

        return $user->can('CorrectClosed:Exercise')
            && ($company === null || $this->canAccess($user, $company));
    }

    public function update(User $user, LateCorrection $correction): bool
    {
        return false;
    }

    public function delete(User $user, LateCorrection $correction): bool
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
