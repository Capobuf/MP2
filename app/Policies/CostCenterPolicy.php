<?php

namespace App\Policies;

use App\Domain\Company\Capability;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\User;

class CostCenterPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->capabilities()->where('capability', Capability::View->value)->exists();
    }

    public function view(User $user, CostCenter $costCenter): bool
    {
        return $user->hasCapability($costCenter->company, Capability::View);
    }

    public function create(User $user, ?Company $company = null): bool
    {
        if ($company === null) {
            return $user->capabilities()
                ->where('capability', Capability::ManageMasterData->value)
                ->exists();
        }

        return $user->hasCapability($company, Capability::ManageMasterData);
    }

    public function update(User $user, CostCenter $costCenter): bool
    {
        return $user->hasCapability($costCenter->company, Capability::ManageMasterData);
    }

    public function delete(User $user, CostCenter $costCenter): bool
    {
        return false;
    }
}
