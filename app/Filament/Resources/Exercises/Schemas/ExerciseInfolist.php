<?php

namespace App\Filament\Resources\Exercises\Schemas;

use App\Models\Exercise;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ExerciseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Riepilogo')->schema([
                TextEntry::make('year')->label('Anno'),
                TextEntry::make('status')->label('Stato')->formatStateUsing(fn ($state): string => $state->label())->badge(),
                TextEntry::make('allocation')->label('Allocato corrente')->state(fn (Exercise $record): string => $record->allocation())->money('EUR', locale: 'it'),
                TextEntry::make('actual')->label('Effettivo')->state(fn (Exercise $record): string => $record->actual())->money('EUR', locale: 'it'),
                TextEntry::make('variance')->label('Scostamento operativo')->state(fn (Exercise $record): string => $record->operationalVariance())->money('EUR', locale: 'it'),
                TextEntry::make('expenses_count')->label('Numero Spese')->state(fn (Exercise $record): int => $record->expenses()->count()),
            ])->columns(3),
        ]);
    }
}
