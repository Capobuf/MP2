<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Widgets\ExpenseOverview;
use App\Livewire\ExpenseDetail;
use App\Models\Company;
use App\Models\Exercise;
use App\Support\ExerciseContext;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Livewire\Attributes\On;

class ListExpenses extends ListRecords
{
    protected static string $resource = ExpenseResource::class;

    public ?int $selectedExpenseId = null;

    public function getSubheading(): ?string
    {
        return 'Elenco e dettaglio delle Spese dell’Esercizio selezionato.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nuova spesa')
                ->disabled(fn (): bool => $this->createDisabledReason() !== null)
                ->tooltip(fn (): ?string => $this->createDisabledReason()),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [ExpenseOverview::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return [
            'md' => 2,
            'xl' => 4,
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getTabsContentComponent(),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
            Grid::make(12)
                ->schema([
                    EmbeddedTable::make()
                        ->extraAttributes(['class' => 'mp2-expense-master']),
                    Livewire::make(ExpenseDetail::class, fn (): array => [
                        'expenseId' => $this->selectedExpenseId,
                        'compact' => true,
                    ])
                        ->key('expense-detail')
                        ->extraAttributes(['class' => 'mp2-expense-detail']),
                ])
                ->extraAttributes(['class' => 'mp2-expense-layout']),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
        ]);
    }

    public function toggleExpense(int $expenseId): void
    {
        $exists = ExpenseResource::getEloquentQuery()->whereKey($expenseId)->exists();
        abort_unless($exists, 404);

        $this->selectedExpenseId = $this->selectedExpenseId === $expenseId ? null : $expenseId;
        $this->dispatch('show-expense-detail', expenseId: $this->selectedExpenseId);
    }

    #[On('close-expense-detail')]
    public function closeExpenseDetail(): void
    {
        $this->selectedExpenseId = null;
    }

    #[On('expense-detail-updated')]
    public function refreshExpenseDetail(int $expenseId): void {}

    public function updated(string $property): void
    {
        if (
            $this->selectedExpenseId === null
            || (! str_starts_with($property, 'tableFilters') && $property !== 'tableSearch')
        ) {
            return;
        }

        $stillVisible = $this->getFilteredTableQuery()
            ->whereKey($this->selectedExpenseId)
            ->exists();

        if (! $stillVisible) {
            $this->selectedExpenseId = null;
            $this->dispatch('show-expense-detail', expenseId: null);
        }
    }

    private function createDisabledReason(): ?string
    {
        $company = Filament::getTenant();
        $exercise = $company instanceof Company
            ? app(ExerciseContext::class)->current($company)
            : null;

        if (! $exercise instanceof Exercise) {
            return 'Seleziona un Esercizio globale prima di creare la Spesa.';
        }

        return $exercise->isOpen() ? null : 'L’Esercizio globale selezionato è Chiuso.';
    }
}
