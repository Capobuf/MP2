<?php

namespace App\Filament\Resources\Budgets\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BudgetsTable
{
    public static function configure(Table $table): Table
    {
        return $table->defaultSort('approved_at', 'desc')->columns([TextColumn::make('exercise.year')->label('Esercizio'), TextColumn::make('version')->label('Versione')->formatStateUsing(fn ($state): string => 'v'.$state), TextColumn::make('previousBudget.version')->label('Precedente')->formatStateUsing(fn (mixed $state): string => $state === null ? '—' : 'v'.$state), TextColumn::make('purpose')->label('Finalità')->formatStateUsing(fn ($state): string => $state->label()), TextColumn::make('approved_at')->label('Approvato il')->dateTime('d/m/Y H:i'), TextColumn::make('approver.name')->label('Approvato da'), TextColumn::make('total_approved_allocation')->label('Allocato approvato')->money('EUR', locale: 'it')])->recordActions([ViewAction::make()])->emptyStateHeading('Nessun Budget approvato');
    }
}
