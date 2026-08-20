<?php

namespace App\Policies;

use App\Domain\Company\Capability;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractExerciseClassification;
use App\Models\ContractLifecycleFact;
use App\Models\ContractRenewalConfiguration;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ContractPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->capabilities()->where('capability', Capability::View->value)->exists();
    }

    public function view(User $user, Model $record): bool
    {
        return $user->hasCapability($this->company($record), Capability::View);
    }

    public function create(User $user, ?Company $company = null): bool
    {
        return $company === null
            ? $user->capabilities()->where('capability', Capability::ManageOperations->value)->exists()
            : $user->hasCapability($company, Capability::ManageOperations);
    }

    public function update(User $user, Model $record): bool
    {
        return $user->hasCapability($this->company($record), Capability::ManageOperations);
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
}
