<?php

namespace App\Filament\Platform\Resources\SuperAdmins\Pages;

use App\Filament\Platform\Resources\SuperAdmins\SuperAdminResource;
use Filament\Resources\Pages\EditRecord;

class EditSuperAdmin extends EditRecord
{
    protected static string $resource = SuperAdminResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return [...$data, 'company_id' => null];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
