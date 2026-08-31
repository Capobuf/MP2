<?php

namespace App\Policies;

use App\Domain\Proposals\ProposalStatus;
use App\Models\Company;
use App\Models\Proposal;
use App\Models\TenantCompany;
use App\Models\User;

class ProposalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Proposal');
    }

    public function view(User $user, Proposal $proposal): bool
    {
        return $this->canUse($user, $proposal->company, 'View:Proposal');
    }

    public function create(User $user, ?Company $company = null): bool
    {
        return $user->can('Create:Proposal')
            && ($company === null || $this->canAccess($user, $company));
    }

    public function update(User $user, Proposal $proposal): bool
    {
        return $proposal->status === ProposalStatus::Draft
            && $this->canUse($user, $proposal->company, 'Update:Proposal');
    }

    public function approve(User $user, Proposal $proposal): bool
    {
        return $proposal->status === ProposalStatus::Draft
            && $this->canUse($user, $proposal->company, 'Approve:Proposal');
    }

    public function delete(User $user, Proposal $proposal): bool
    {
        return false;
    }

    private function canUse(User $user, Company $company, string $permission): bool
    {
        return $this->canAccess($user, $company) && $user->can($permission);
    }

    private function canAccess(User $user, Company $company): bool
    {
        return $company->tenantCompany instanceof TenantCompany
            && $user->canAccessTenant($company->tenantCompany);
    }
}
