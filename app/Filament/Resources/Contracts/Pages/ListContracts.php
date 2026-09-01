<?php

namespace App\Filament\Resources\Contracts\Pages;

use App\Filament\Resources\Contracts\ContractResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListContracts extends ListRecords
{
    protected static string $resource = ContractResource::class;

    public function getSubheading(): ?string
    {
        return 'Elenco, Valori Annuali e Scadenze dei Contratti.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nuovo Contratto')];
    }
}
