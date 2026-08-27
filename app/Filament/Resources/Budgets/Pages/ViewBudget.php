<?php

namespace App\Filament\Resources\Budgets\Pages;

use App\Filament\Pages\CompanyAudit;
use App\Filament\Resources\Budgets\BudgetResource;
use App\Models\BudgetSnapshot;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;

class ViewBudget extends ViewRecord
{
    protected static string $resource = BudgetResource::class;

    public function getHeader(): ?View
    {
        $budget = $this->budget();

        return view('filament.resources.budgets.components.object-header', [
            'budget' => $budget,
            'budgetsUrl' => BudgetResource::getUrl('index', tenant: $budget->company),
        ]);
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    /** @return array<string, string> */
    public function getExtraBodyAttributes(): array
    {
        return ['class' => 'mp2-object-page mp2-budget-object-page'];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('timeline')
                ->label('Timeline del Budget')
                ->icon('heroicon-m-chart-bar')
                ->color('gray')
                ->outlined()
                ->url(fn (): string => CompanyAudit::getUrl([
                    'tenant' => $this->budget()->company,
                    'budget' => $this->budget()->id,
                ])),
        ];
    }

    private function budget(): BudgetSnapshot
    {
        $record = $this->getRecord();
        if (! $record instanceof BudgetSnapshot) {
            throw new \UnexpectedValueException('Invalid Budget record.');
        }

        return $record;
    }
}
