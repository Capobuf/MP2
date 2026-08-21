<?php

namespace App\Policies;

use App\Domain\Company\Capability;
use App\Models\BudgetSnapshot;
use App\Models\User;

class BudgetSnapshotPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->capabilities()->where('capability', Capability::View->value)->exists();
    }

    public function view(User $user, BudgetSnapshot $budget): bool
    {
        return $user->hasCapability($budget->company, Capability::View);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, BudgetSnapshot $budget): bool
    {
        return false;
    }

    public function delete(User $user, BudgetSnapshot $budget): bool
    {
        return false;
    }

    public function downloadEvidence(User $user, BudgetSnapshot $budget): bool
    {
        return $this->view($user, $budget);
    }
}
