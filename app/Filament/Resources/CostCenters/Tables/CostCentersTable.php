<?php

namespace App\Filament\Resources\CostCenters\Tables;

use App\Filament\Resources\CostCenters\CostCenterResource;
use App\Models\CostCenter;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CostCentersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Denominazione')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Stato')
                    ->state(fn (CostCenter $record): string => $record->isArchived() ? 'Archiviato' : 'Attivo')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Attivo' ? 'success' : 'gray'),
                TextColumn::make('updated_at')
                    ->label('Ultima modifica')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('archived_at')
                    ->label('Stato')
                    ->placeholder('Tutti')
                    ->trueLabel('Archiviati')
                    ->falseLabel('Attivi')
                    ->default(false)
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('archived_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('archived_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->emptyStateHeading('Nessun centro di costo')
            ->emptyStateDescription('Non sono presenti centri di costo per il filtro selezionato.')
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                CostCenterResource::archiveAction(),
                CostCenterResource::restoreAction(),
            ]);
    }
}
