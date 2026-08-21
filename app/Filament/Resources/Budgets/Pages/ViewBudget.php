<?php

namespace App\Filament\Resources\Budgets\Pages;

use App\Filament\Pages\CompanyAudit;
use App\Filament\Resources\Budgets\BudgetResource;
use App\Models\BudgetSnapshot;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewBudget extends ViewRecord
{
    protected static string $resource = BudgetResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('timeline')->label('Timeline del Budget')->url(function (): string {
            $record = $this->record;
            abort_unless($record instanceof BudgetSnapshot, 404);

            return CompanyAudit::getUrl(['tenant' => $record->company, 'budget' => $record->id]);
        })];
    }
}
