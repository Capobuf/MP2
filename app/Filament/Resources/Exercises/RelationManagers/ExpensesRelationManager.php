<?php

namespace App\Filament\Resources\Exercises\RelationManagers;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Exercise;
use App\Models\Expense;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ExpensesRelationManager extends RelationManager
{
    protected static string $relationship = 'expenses';

    protected static ?string $title = 'Spese Autonome';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Exercise && auth()->user()?->can('view', $ownerRecord) === true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('description')->label('Descrizione'),
                TextColumn::make('state')->label('Stato')->state(fn (Expense $record): string => $record->isReversed() ? 'Stornata' : 'Attiva')->badge(),
                TextColumn::make('allocation')->label('Allocato Corrente')->state(fn (Expense $record): string => $record->allocation())->money('EUR', locale: 'it'),
                TextColumn::make('actual')->label('Effettivo')->state(fn (Expense $record): string => $record->actual())->money('EUR', locale: 'it'),
                TextColumn::make('variance')->label('Scostamento Operativo')->state(fn (Expense $record): string => $record->operationalVariance())->money('EUR', locale: 'it'),
            ])
            ->recordActions([
                Action::make('view')->label('Visualizza')->url(fn (Expense $record): string => ExpenseResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
