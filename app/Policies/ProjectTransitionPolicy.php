<?php

namespace App\Policies;

use App\Domain\Company\Capability;
use App\Models\Project;
use App\Models\ProjectTransition;
use App\Models\User;

class ProjectTransitionPolicy
{
    public function view(User $user, ProjectTransition $transition): bool
    {
        return $user->hasCapability($transition->project->company, Capability::View);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->hasCapability($project->company, Capability::ManageOperations);
    }

    public function update(User $user, ProjectTransition $transition): bool
    {
        return $user->hasCapability($transition->project->company, Capability::ManageOperations);
    }

    public function delete(User $user, ProjectTransition $transition): bool
    {
        return false;
    }
}
