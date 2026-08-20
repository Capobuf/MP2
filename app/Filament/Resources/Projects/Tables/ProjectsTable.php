<?php

namespace App\Filament\Resources\Projects\Tables;

use App\Models\Project;
use Carbon\CarbonImmutable;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label('Titolo')->searchable()->sortable(),
                TextColumn::make('current_state')->label('Stato attuale')->state(function (Project $record): string {
                    $today = CarbonImmutable::now($record->company->timezone)->toDateString();

                    return $record->stateAtDate($today)?->label() ?? 'Assente alla data';
                })->badge(),
                TextColumn::make('initial_effective_date')->label('Efficacia iniziale')->date('d/m/Y')->sortable(),
                TextColumn::make('archive_state')->label('Visibilità')->state(fn (Project $record): string => $record->isArchived() ? 'Archiviato' : 'Attivo')->badge(),
                TextColumn::make('updated_at')->label('Ultima modifica')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                TernaryFilter::make('archived_at')
                    ->label('Archivio')
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
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->emptyStateHeading('Nessun progetto');
    }
}
