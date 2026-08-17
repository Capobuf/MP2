<?php

namespace App\Policies;

use App\Domain\Company\Capability;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;
use App\Models\User;

class ProjectExerciseClassificationPolicy
{
    public function view(User $user, ProjectExerciseClassification $classification): bool
    {
        return $user->hasCapability($classification->project->company, Capability::View);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->hasCapability($project->company, Capability::ManageOperations);
    }

    public function update(User $user, ProjectExerciseClassification $classification): bool
    {
        return $user->hasCapability($classification->project->company, Capability::ManageOperations);
    }

    public function delete(User $user, ProjectExerciseClassification $classification): bool
    {
        return false;
    }
}
