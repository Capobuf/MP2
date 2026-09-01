<?php

namespace App\Filament\Resources\CostCenters\Pages;

use App\Filament\Resources\CostCenters\CostCenterResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCostCenters extends ListRecords
{
    protected static string $resource = CostCenterResource::class;

    public function getSubheading(): ?string
    {
        return 'Anagrafica dei Centri di Costo Usati per la Classificazione Annuale.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nuovo Centro di Costo')];
    }
}
