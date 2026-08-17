<?php

namespace App\Policies;

use App\Domain\Company\Capability;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\User;

class ExpenseLinePolicy
{
    public function view(User $user, ExpenseLine $line): bool
    {
        return $user->hasCapability($line->expense->company, Capability::View);
    }

    public function create(User $user, Expense $expense): bool
    {
        return $user->hasCapability($expense->company, Capability::ManageOperations);
    }

    public function update(User $user, ExpenseLine $line): bool
    {
        return $user->hasCapability($line->expense->company, Capability::ManageOperations);
    }

    public function delete(User $user, ExpenseLine $line): bool
    {
        return false;
    }
}
