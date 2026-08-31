<?php

namespace App\Policies;

use App\Models\ClosingSnapshot;
use App\Models\TenantCompany;
use App\Models\User;

class ClosingSnapshotPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:ClosingSnapshot');
    }

    public function view(User $user, ClosingSnapshot $snapshot): bool
    {
        return $snapshot->company->tenantCompany instanceof TenantCompany
            && $user->canAccessTenant($snapshot->company->tenantCompany)
            && $user->can('View:ClosingSnapshot');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ClosingSnapshot $snapshot): bool
    {
        return false;
    }

    public function delete(User $user, ClosingSnapshot $snapshot): bool
    {
        return false;
    }
}
