<?php

namespace App\Policies;

use App\Domain\Company\Capability;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\User;

class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->capabilities()->where('capability', Capability::View->value)->exists();
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $user->hasCapability($supplier->company, Capability::View);
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

    public function update(User $user, Supplier $supplier): bool
    {
        return $user->hasCapability($supplier->company, Capability::ManageMasterData);
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return false;
    }
}
