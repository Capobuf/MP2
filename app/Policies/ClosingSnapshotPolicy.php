<?php

namespace App\Policies;

use App\Domain\Company\Capability;
use App\Models\ClosingSnapshot;
use App\Models\User;

class ClosingSnapshotPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->capabilities()->where('capability', Capability::View->value)->exists();
    }

    public function view(User $user, ClosingSnapshot $snapshot): bool
    {
        return $user->hasCapability($snapshot->company, Capability::View);
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
