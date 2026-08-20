<?php

namespace App\Policies;

use App\Domain\Company\Capability;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\User;

class AttachmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->capabilities()->where('capability', Capability::View->value)->exists();
    }

    public function view(User $user, Attachment $attachment): bool
    {
        return $user->hasCapability($attachment->company, Capability::View);
    }

    public function create(User $user, ?Company $company = null): bool
    {
        return $company === null
            ? $user->capabilities()->where('capability', Capability::ManageOperations->value)->exists()
            : $user->hasCapability($company, Capability::ManageOperations);
    }

    public function update(User $user, Attachment $attachment): bool
    {
        return $user->hasCapability($attachment->company, Capability::ManageOperations);
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        return false;
    }
}
