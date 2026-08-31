<?php

namespace App\Policies;

use App\Models\TenantCompany;
use App\Models\User;

class TenantCompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    public function view(User $user, TenantCompany $tenant): bool
    {
        return $user->hasRole('super_admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    public function archive(User $user, TenantCompany $tenant): bool
    {
        return $user->hasRole('super_admin');
    }

    public function restore(User $user, TenantCompany $tenant): bool
    {
        return $user->hasRole('super_admin');
    }

    public function destroy(User $user, TenantCompany $tenant): bool
    {
        return $user->hasRole('super_admin');
    }
}
