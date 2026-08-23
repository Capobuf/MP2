<?php

namespace App\Filament\Resources\Proposals\Tables;

use App\Models\Proposal;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProposalsTable
{
    public static function configure(Table $table): Table
    {
        return $table->defaultSort('updated_at', 'desc')->columns([
            TextColumn::make('exercise.year')->label('Esercizio')->sortable(),
            TextColumn::make('purpose')->label('Finalità')->formatStateUsing(fn ($state): string => $state->label()),
            TextColumn::make('referenceBudget.version')->label('Riferimento')->formatStateUsing(fn (mixed $state): string => $state === null ? '—' : 'v'.$state),
            TextColumn::make('status')->label('Stato')->formatStateUsing(fn ($state): string => $state->label())->badge(),
            TextColumn::make('items_count')->label('Elementi')->counts('items'),
            TextColumn::make('planned_allocation')->label('Allocato pianificato')->state(fn (Proposal $record): string => $record->plannedAllocation())->money('EUR', locale: 'it'),
            TextColumn::make('creator.name')->label('Autore'),
            TextColumn::make('updated_at')->label('Aggiornata')->dateTime('d/m/Y H:i')->sortable(),
        ])->recordActions([ViewAction::make()])->emptyStateHeading('Nessuna proposta')->emptyStateDescription('Inizializza una Proposta da un Esercizio Aperto senza Budget.');
    }
}
