<?php

namespace App\Policies;

use App\Domain\Company\Capability;
use App\Models\Company;
use App\Models\Expense;
use App\Models\User;

class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->capabilities()->where('capability', Capability::View->value)->exists();
    }

    public function view(User $user, Expense $expense): bool
    {
        return $user->hasCapability($expense->company, Capability::View);
    }

    public function create(User $user, ?Company $company = null): bool
    {
        return $company === null
            ? $user->capabilities()->where('capability', Capability::ManageOperations->value)->exists()
            : $user->hasCapability($company, Capability::ManageOperations);
    }

    public function update(User $user, Expense $expense): bool
    {
        return $expense->exercise->isOpen()
            && $user->hasCapability($expense->company, Capability::ManageOperations);
    }

    public function delete(User $user, Expense $expense): bool
    {
        return false;
    }
}
