<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Models\Expense;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ExpenseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identità e classificazione')->schema([
                TextEntry::make('origin_key')->label('OriginKey')->state(fn (Expense $record): string => $record->originKey()),
                TextEntry::make('description')->label('Descrizione'),
                TextEntry::make('notes')->label('Note')->placeholder('—'),
                TextEntry::make('exercise.year')->label('Esercizio'),
                TextEntry::make('supplier_display')->label('Fornitore')->placeholder('—')
                    ->state(fn (Expense $record): ?string => $record->supplier === null
                        ? null
                        : $record->supplier->legal_name.($record->supplier->isArchived() ? ' · Archiviato' : '')),
                TextEntry::make('cost_center_display')->label('Centro di Costo')->placeholder('Non classificata')
                    ->state(fn (Expense $record): ?string => $record->directCostCenter === null
                        ? null
                        : $record->directCostCenter->name.($record->directCostCenter->isArchived() ? ' · Archiviato' : '')),
                TextEntry::make('state')->label('Stato')->state(fn (Expense $record): string => $record->isReversed() ? 'Stornata' : 'Attiva')->badge(),
            ])->columns(3),
            Section::make('Totali correnti')->schema([
                TextEntry::make('allocation')->label('Allocato corrente')->state(fn (Expense $record): string => $record->allocation())->money('EUR', locale: 'it'),
                TextEntry::make('actual')->label('Effettivo')->state(fn (Expense $record): string => $record->actual())->money('EUR', locale: 'it'),
                TextEntry::make('variance')->label('Scostamento operativo')->state(fn (Expense $record): string => $record->operationalVariance())->money('EUR', locale: 'it'),
            ])->columns(3),
        ]);
    }
}
