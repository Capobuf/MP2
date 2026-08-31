<?php

namespace App\Filament\Platform\Resources\SuperAdmins\Pages;

use App\Filament\Platform\Resources\SuperAdmins\SuperAdminResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSuperAdmins extends ListRecords
{
    protected static string $resource = SuperAdminResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
