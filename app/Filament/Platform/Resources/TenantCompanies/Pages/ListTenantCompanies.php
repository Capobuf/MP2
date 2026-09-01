<?php

namespace App\Filament\Platform\Resources\TenantCompanies\Pages;

use App\Filament\Platform\Pages\ImportCompanyBackup;
use App\Filament\Platform\Resources\TenantCompanies\TenantCompanyResource;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListTenantCompanies extends ListRecords
{
    protected static string $resource = TenantCompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createCompany')
                ->label('Nuova Azienda')
                ->icon(Heroicon::OutlinedPlus)
                ->url(fn (): ?string => Filament::getPanel('admin')->getTenantRegistrationUrl()),
            Action::make('importCompany')
                ->label('Importa Azienda')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->url(ImportCompanyBackup::getUrl(panel: 'platform')),
        ];
    }
}
