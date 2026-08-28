<?php

namespace App\Installer\Callbacks;

use App\Models\User;

class PromotePlatformAdmin
{
    public function __invoke(User $user): void
    {
        $user->forceFill(['is_platform_admin' => true])->save();
    }
}
