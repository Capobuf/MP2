<?php

namespace App\Filament\Resources\Budgets\Pages;

use App\Filament\Resources\Budgets\BudgetResource;
use App\Filament\Resources\Exercises\ExerciseResource;
use App\Models\Company;
use App\Models\Proposal;
use App\Models\TenantCompany;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;

class ListBudgets extends ListRecords
{
    protected static string $resource = BudgetResource::class;

    public function getHeading(): string
    {
        return 'Budget approvati';
    }

    public function getSubheading(): ?string
    {
        return 'Versioni approvate e immutabili. Per prepararne una nuova, avvia o continua una Proposta da Esercizi.';
    }

    protected function getHeaderActions(): array
    {
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;
        $actor = auth()->user();

        return [
            Action::make('prepareBudget')
                ->label('Prepara Budget')
                ->url(fn (): string => ExerciseResource::getUrl('index'))
                ->visible($actor instanceof User
                    && $company instanceof Company
                    && ExerciseResource::canAccess()
                    && $actor->can('create', [Proposal::class, $company])),
        ];
    }
}
