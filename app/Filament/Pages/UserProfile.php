<?php

namespace App\Filament\Pages;

use Filament\Auth\Pages\EditProfile;

class UserProfile extends EditProfile
{
    protected static bool $shouldRegisterNavigation = false;

    public static function isSimple(): bool
    {
        return false;
    }
}
