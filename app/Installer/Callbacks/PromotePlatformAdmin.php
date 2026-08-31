<?php

namespace App\Installer\Callbacks;

use App\Actions\Authorization\SyncDefaultRoles;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

class PromotePlatformAdmin
{
    public function __invoke(User $user): void
    {
        Artisan::call('shield:generate', [
            '--all' => true,
            '--option' => 'permissions',
            '--panel' => 'admin',
            '--no-interaction' => true,
        ]);

        app(SyncDefaultRoles::class)();

        $user->forceFill(['company_id' => null])->save();

        Artisan::call('shield:super-admin', [
            '--user' => (string) $user->getKey(),
            '--panel' => 'platform',
            '--no-interaction' => true,
        ]);
    }
}
