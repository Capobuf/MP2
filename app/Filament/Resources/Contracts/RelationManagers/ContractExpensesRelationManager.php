<?php

namespace App\Filament\Resources\Contracts\RelationManagers;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Contract;
use App\Models\Expense;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ContractExpensesRelationManager extends RelationManager
{
    protected static string $relationship = 'expenses';

    protected static ?string $title = 'Spese';

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Contract && auth()->user()?->can('view', $ownerRecord) === true;
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('description')->label('Descrizione')->searchable(),
            TextColumn::make('origin')->label('Origine')->formatStateUsing(fn (string $state): string => $state === 'system' ? 'Stima di sistema' : 'Manuale')->badge(),
            TextColumn::make('exercise.year')->label('Esercizio')->sortable(),
            TextColumn::make('supplier.legal_name')->label('Fornitore'),
            TextColumn::make('cost_center')->label('Centro di Costo Ereditato')->state(fn (Expense $record): string => $record->costCenterLabel()),
            TextColumn::make('allocation')->label('Allocato')->state(fn (Expense $record): string => $record->allocation())->money('EUR', locale: 'it'),
            TextColumn::make('actual')->label('Effettivo')->state(fn (Expense $record): string => $record->actual())->money('EUR', locale: 'it'),
        ])->headerActions([
            Action::make('createContractActual')->label('Nuova Spesa')
                ->url(fn (): string => ExpenseResource::getUrl('create', ['contract' => $this->getOwnerRecord()->getKey()]))
                ->visible(fn (): bool => $this->canMutateOwner()),
        ])->recordActions([
            ViewAction::make()->url(fn (Expense $record): string => ExpenseResource::getUrl('view', ['record' => $record])),
            ExpenseResource::reverseAction(),
            ExpenseResource::restoreAction(),
        ])->defaultSort('id', 'desc')
            ->emptyStateHeading('Nessuna Spesa del Contratto')
            ->emptyStateDescription('Le Stime sono generate dal motore; gli Effettivi sono registrati manualmente senza matching ai cicli.');
    }

    private function canMutateOwner(): bool
    {
        $actor = auth()->user();
        $contract = $this->getOwnerRecord();

        return $actor instanceof User && $contract instanceof Contract && ! $contract->isArchived()
            && $actor->can('update', $contract);
    }
}
