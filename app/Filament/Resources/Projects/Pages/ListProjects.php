<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\TenantCompany;
use App\Support\ExerciseContext;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;

class ListProjects extends ListRecords
{
    protected static string $resource = ProjectResource::class;

    public function getSubheading(): ?string
    {
        return 'Elenco e Situazione Annuale dei Progetti.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nuovo Progetto')
                ->disabled(fn (): bool => $this->createDisabledReason() !== null)
                ->tooltip(fn (): ?string => $this->createDisabledReason()),
        ];
    }

    private function createDisabledReason(): ?string
    {
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;
        $exercise = $company instanceof Company
            ? app(ExerciseContext::class)->current($company)
            : null;

        if (! $exercise instanceof Exercise) {
            return 'Seleziona un Esercizio globale prima di creare il Progetto.';
        }

        return $exercise->isOpen() ? null : 'L’Esercizio globale selezionato è Chiuso.';
    }
}
