<?php

namespace App\Filament\Resources\Exercises\Tables;

use App\Models\Exercise;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ExercisesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('year', 'desc')
            ->columns([
                TextColumn::make('year')->label('Anno')->sortable(),
                TextColumn::make('status')->label('Stato')->formatStateUsing(fn ($state): string => $state->label())->badge(),
                TextColumn::make('allocation')->label('Allocato Corrente')->state(fn (Exercise $record): string => $record->allocation())->money('EUR', locale: 'it'),
                TextColumn::make('actual')->label('Effettivo')->state(fn (Exercise $record): string => $record->actual())->money('EUR', locale: 'it'),
                TextColumn::make('variance')->label('Scostamento Operativo')->state(fn (Exercise $record): string => $record->operationalVariance())->money('EUR', locale: 'it'),
                TextColumn::make('expenses_count')->label('Spese')->counts('expenses'),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading('Nessun Esercizio')
            ->emptyStateDescription('Crea un Esercizio Aperto per registrare Stime ed Effettivi.');
    }
}
