<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractExerciseClassification;
use App\Models\ContractLifecycleFact;
use App\Models\ContractRenewalConfiguration;
use App\Models\TenantCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ContractPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Contract');
    }

    public function view(User $user, Model $record): bool
    {
        return $this->canUse($user, $this->company($record), 'View:Contract');
    }

    public function create(User $user, ?Company $company = null): bool
    {
        return $user->can('Create:Contract')
            && ($company === null || $this->canAccess($user, $company));
    }

    public function update(User $user, Model $record): bool
    {
        return $this->canUse($user, $this->company($record), 'Update:Contract');
    }

    public function delete(User $user, Model $record): bool
    {
        return false;
    }

    private function company(Model $record): Company
    {
        if (! $record instanceof Contract
            && ! $record instanceof ContractCondition
            && ! $record instanceof ContractExerciseClassification
            && ! $record instanceof ContractLifecycleFact
            && ! $record instanceof ContractRenewalConfiguration) {
            throw new \InvalidArgumentException('Unsupported Contract policy record.');
        }

        $company = $record->company;
        if (! $company instanceof Company) {
            throw new \UnexpectedValueException('Contract record has no Company.');
        }

        return $company;
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
