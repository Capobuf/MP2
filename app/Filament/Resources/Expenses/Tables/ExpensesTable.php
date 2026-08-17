<?php

namespace App\Filament\Resources\Expenses\Tables;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ExpensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('description')->label('Descrizione')->searchable()->sortable(),
                TextColumn::make('exercise.year')->label('Esercizio')->sortable(),
                TextColumn::make('container')->label('Contenitore')->state(fn (Expense $record): string => $record->containerLabel()),
                TextColumn::make('supplier.legal_name')->label('Fornitore')->placeholder('—'),
                TextColumn::make('cost_center')->label('Centro di Costo')->state(fn (Expense $record): string => $record->costCenterLabel()),
                TextColumn::make('state')->label('Stato')->state(fn (Expense $record): string => $record->isReversed() ? 'Stornata' : 'Attiva')->badge(),
                TextColumn::make('allocation')->label('Allocato corrente')->state(fn (Expense $record): string => $record->allocation())->money('EUR', locale: 'it'),
                TextColumn::make('actual')->label('Effettivo')->state(fn (Expense $record): string => $record->actual())->money('EUR', locale: 'it'),
                TextColumn::make('variance')->label('Scostamento operativo')->state(fn (Expense $record): string => $record->operationalVariance())->money('EUR', locale: 'it'),
            ])
            ->filters([
                SelectFilter::make('exercise')->relationship('exercise', 'year')->label('Esercizio'),
                TernaryFilter::make('reversed_at')->label('Stato')->placeholder('Tutte')->trueLabel('Stornate')->falseLabel('Attive')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('reversed_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('reversed_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                SelectFilter::make('supplier')->relationship('supplier', 'legal_name')->label('Fornitore'),
                SelectFilter::make('directCostCenter')->relationship('directCostCenter', 'name')->label('Centro di Costo'),
            ])
            ->recordActions([
                ViewAction::make(),
                ExpenseResource::reverseAction(),
                ExpenseResource::restoreAction(),
            ])
            ->emptyStateHeading('Nessuna spesa');
    }
}
