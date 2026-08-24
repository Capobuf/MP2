<?php

namespace App\Policies;

use App\Domain\Company\Capability;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\LateCorrection;
use App\Models\User;

class LateCorrectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->capabilities()->where('capability', Capability::View->value)->exists();
    }

    public function view(User $user, LateCorrection $correction): bool
    {
        return $user->hasCapability($correction->company, Capability::View);
    }

    public function create(User $user, Company|Exercise|null $context = null): bool
    {
        $company = $context instanceof Exercise ? $context->company : $context;

        return $company === null
            ? $user->capabilities()->where('capability', Capability::CorrectClosedExercise->value)->exists()
            : $user->hasCapability($company, Capability::CorrectClosedExercise);
    }

    public function update(User $user, LateCorrection $correction): bool
    {
        return false;
    }

    public function delete(User $user, LateCorrection $correction): bool
    {
        return false;
    }
}
