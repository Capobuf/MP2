<?php

namespace App\Filament\Resources\Proposals\Pages;

use App\Filament\Resources\Proposals\ProposalResource;
use Filament\Resources\Pages\ListRecords;

class ListProposals extends ListRecords
{
    protected static string $resource = ProposalResource::class;

    public function getSubheading(): ?string
    {
        return 'Decisioni di piano isolate dalla realtà effettiva fino all’approvazione.';
    }
}
