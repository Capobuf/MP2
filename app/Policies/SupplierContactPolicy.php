<?php

namespace App\Policies;

use App\Domain\Company\Capability;
use App\Models\Supplier;
use App\Models\SupplierContact;
use App\Models\User;

class SupplierContactPolicy
{
    public function view(User $user, SupplierContact $contact): bool
    {
        return $user->hasCapability($contact->supplier->company, Capability::View);
    }

    public function create(User $user, Supplier $supplier): bool
    {
        return $user->hasCapability($supplier->company, Capability::ManageMasterData);
    }

    public function update(User $user, SupplierContact $contact): bool
    {
        return $user->hasCapability($contact->supplier->company, Capability::ManageMasterData);
    }

    public function delete(User $user, SupplierContact $contact): bool
    {
        return false;
    }
}
