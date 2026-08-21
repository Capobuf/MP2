<?php

namespace App\Policies;

use App\Domain\Company\Capability;
use App\Domain\Proposals\ProposalStatus;
use App\Models\Company;
use App\Models\Proposal;
use App\Models\User;

class ProposalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->capabilities()->where('capability', Capability::View->value)->exists();
    }

    public function view(User $user, Proposal $proposal): bool
    {
        return $user->hasCapability($proposal->company, Capability::View);
    }

    public function create(User $user, ?Company $company = null): bool
    {
        return $company === null ? $user->capabilities()->where('capability', Capability::ManageProposals->value)->exists() : $user->hasCapability($company, Capability::ManageProposals);
    }

    public function update(User $user, Proposal $proposal): bool
    {
        return $proposal->status === ProposalStatus::Draft && $user->hasCapability($proposal->company, Capability::ManageProposals);
    }

    public function approve(User $user, Proposal $proposal): bool
    {
        return $proposal->status === ProposalStatus::Draft && $user->hasCapability($proposal->company, Capability::ApproveBudget);
    }

    public function delete(User $user, Proposal $proposal): bool
    {
        return false;
    }
}
