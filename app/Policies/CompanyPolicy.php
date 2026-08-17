<?php

namespace App\Policies;

use App\Domain\Company\Capability;
use App\Models\Company;
use App\Models\User;

class CompanyPolicy
{
    public function create(User $user): bool
    {
        return $user->is_platform_admin;
    }

    public function view(User $user, Company $company): bool
    {
        return $user->hasCapability($company, Capability::View);
    }

    public function manageSettings(User $user, Company $company): bool
    {
        return $user->hasCapability($company, Capability::ManageSettings);
    }

    public function managePermissions(User $user, Company $company): bool
    {
        return $user->hasCapability($company, Capability::ManagePermissions);
    }
}
