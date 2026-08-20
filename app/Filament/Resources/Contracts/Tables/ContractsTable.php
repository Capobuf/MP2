<?php

namespace App\Filament\Resources\Contracts\Tables;

use App\Models\Contract;
use Carbon\CarbonImmutable;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ContractsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->label('Titolo')->searchable()->sortable(),
            TextColumn::make('supplier.legal_name')->label('Fornitore')->searchable(),
            TextColumn::make('current_state')->label('Stato attuale')->state(fn (Contract $record): string => $record
                ->stateAtDate(CarbonImmutable::now($record->company->timezone)->toDateString())->label())->badge(),
            TextColumn::make('contractual_start_date')->label('Data inizio')->date('d/m/Y')->sortable(),
            TextColumn::make('next_expiry_date')->label('Prossima scadenza')->date('d/m/Y')->placeholder('Scadenza non definita')->sortable(),
            TextColumn::make('automatic_renewal')->label('Rinnovo automatico')->formatStateUsing(fn (bool $state): string => $state ? 'Sì' : 'No'),
            TextColumn::make('archive_state')->label('Visibilità')->state(fn (Contract $record): string => $record->isArchived() ? 'Archiviato' : 'Attivo')->badge(),
            TextColumn::make('updated_at')->label('Ultima modifica')->dateTime('d/m/Y H:i')->sortable(),
        ])->filters([
            TernaryFilter::make('archived_at')->label('Archivio')->placeholder('Tutti')->trueLabel('Archiviati')->falseLabel('Attivi')->default(false)
                ->queries(
                    true: fn (Builder $query): Builder => $query->whereNotNull('archived_at'),
                    false: fn (Builder $query): Builder => $query->whereNull('archived_at'),
                    blank: fn (Builder $query): Builder => $query,
                ),
        ])->recordActions([ViewAction::make(), EditAction::make()])->emptyStateHeading('Nessun contratto');
    }
}
