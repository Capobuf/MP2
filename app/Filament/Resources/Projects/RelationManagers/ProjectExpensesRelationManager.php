<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Domain\Company\Capability;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ProjectExpensesRelationManager extends RelationManager
{
    protected static string $relationship = 'expenses';

    protected static ?string $title = 'Spese di Progetto';

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Project && auth()->user()?->can('view', $ownerRecord) === true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('description')->label('Descrizione')->searchable(),
                TextColumn::make('exercise.year')->label('Esercizio')->sortable(),
                TextColumn::make('supplier.legal_name')->label('Fornitore')->placeholder('—'),
                TextColumn::make('cost_center')->label('Centro di Costo ereditato')->state(fn (Expense $record): string => $record->costCenterLabel()),
                TextColumn::make('state')->label('Stato')->state(fn (Expense $record): string => $record->isReversed() ? 'Stornata' : 'Attiva')->badge(),
                TextColumn::make('allocation')->label('Allocato')->state(fn (Expense $record): string => $record->allocation())->money('EUR', locale: 'it'),
                TextColumn::make('actual')->label('Effettivo')->state(fn (Expense $record): string => $record->actual())->money('EUR', locale: 'it'),
            ])
            ->headerActions([
                Action::make('createProjectExpense')
                    ->label('Nuova spesa di progetto')
                    ->url(fn (): string => ExpenseResource::getUrl('create', [
                        'project' => $this->getOwnerRecord()->getKey(),
                    ]))
                    ->visible(fn (): bool => $this->canMutateOwner()),
            ])
            ->recordActions([
                ViewAction::make()->url(fn (Expense $record): string => ExpenseResource::getUrl('view', ['record' => $record])),
                ExpenseResource::reverseAction(),
                ExpenseResource::restoreAction(),
            ])
            ->emptyStateHeading('Nessuna spesa di Progetto')
            ->emptyStateDescription('Aggiungi una Spesa per alimentare Allocato ed Effettivo del Progetto.');
    }

    private function canMutateOwner(): bool
    {
        $actor = auth()->user();
        $project = $this->getOwnerRecord();

        return $actor instanceof User && $project instanceof Project && ! $project->isArchived()
            && $actor->hasCapability($project->company, Capability::ManageOperations);
    }
}
