<?php

namespace App\Filament\Platform\Resources\TenantCompanies\Pages;

use App\Filament\Platform\Resources\TenantCompanies\TenantCompanyResource;
use Filament\Resources\Pages\ListRecords;

class ListTenantCompanies extends ListRecords
{
    protected static string $resource = TenantCompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
