<?php

namespace App\Policies;

use App\Domain\Company\Capability;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\User;

class ExercisePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->capabilities()->where('capability', Capability::View->value)->exists();
    }

    public function view(User $user, Exercise $exercise): bool
    {
        return $user->hasCapability($exercise->company, Capability::View);
    }

    public function create(User $user, ?Company $company = null): bool
    {
        return $company === null
            ? $user->capabilities()->where('capability', Capability::ManageOperations->value)->exists()
            : $user->hasCapability($company, Capability::ManageOperations);
    }

    public function update(User $user, Exercise $exercise): bool
    {
        return $user->hasCapability($exercise->company, Capability::ManageOperations);
    }

    public function close(User $user, Exercise $exercise): bool
    {
        return $user->hasCapability($exercise->company, Capability::CloseExercise);
    }

    public function correctClosed(User $user, Exercise $exercise): bool
    {
        return ! $exercise->isOpen()
            && $user->hasCapability($exercise->company, Capability::CorrectClosedExercise);
    }

    public function annotateHistoricalError(User $user, Exercise $exercise): bool
    {
        return ! $exercise->isOpen()
            && $user->hasCapability($exercise->company, Capability::CorrectClosedExercise);
    }

    public function delete(User $user, Exercise $exercise): bool
    {
        return false;
    }
}
