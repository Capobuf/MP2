<?php

namespace App\Policies;

use App\Models\TenantCompany;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:User');
    }

    public function view(User $user, User $record): bool
    {
        if ($record->hasRole('super_admin')) {
            return $user->hasRole('super_admin');
        }

        return $user->can('View:User') && $this->sameActiveTenant($user, $record);
    }

    public function create(User $user): bool
    {
        return $user->can('Create:User');
    }

    public function update(User $user, User $record): bool
    {
        if ($record->hasRole('super_admin')) {
            return $user->hasRole('super_admin');
        }

        return $user->can('Update:User') && $this->sameActiveTenant($user, $record);
    }

    public function delete(User $user, User $record): bool
    {
        return false;
    }

    private function sameActiveTenant(User $user, User $record): bool
    {
        return $record->tenantCompany instanceof TenantCompany
            && $user->canAccessTenant($record->tenantCompany);
    }
}
