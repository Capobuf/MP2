<?php

namespace App\Filament\Resources\Suppliers\Pages;

use App\Filament\Resources\Suppliers\SupplierResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSuppliers extends ListRecords
{
    protected static string $resource = SupplierResource::class;

    public function getSubheading(): ?string
    {
        return 'Anagrafica dei Fornitori Utilizzati nelle Attività Operative.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nuovo Fornitore')];
    }
}
