<?php

namespace App\Filament\Resources\Suppliers\Tables;

use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\Supplier;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SuppliersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('legal_name')
                    ->label('Ragione Sociale')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('vat_number')
                    ->label('Partita IVA')
                    ->placeholder('—'),
                TextColumn::make('contacts_count')
                    ->label('Referenti')
                    ->counts('contacts'),
                TextColumn::make('status')
                    ->label('Stato')
                    ->state(fn (Supplier $record): string => $record->isArchived() ? 'Archiviato' : 'Attivo')
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
            ->emptyStateHeading('Nessun fornitore')
            ->emptyStateDescription('Non sono presenti fornitori per il filtro selezionato.')
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                SupplierResource::archiveAction(),
                SupplierResource::restoreAction(),
            ]);
    }
}
