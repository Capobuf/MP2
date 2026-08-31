<?php

namespace App\Filament\Platform\Resources\SuperAdmins\Pages;

use App\Filament\Platform\Resources\SuperAdmins\SuperAdminResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSuperAdmin extends CreateRecord
{
    protected static string $resource = SuperAdminResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $record = User::query()->create([...$data, 'company_id' => null]);
        $record->assignRole('super_admin');

        return $record;
    }
}
