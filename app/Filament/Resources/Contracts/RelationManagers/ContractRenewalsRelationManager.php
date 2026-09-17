<?php

namespace App\Filament\Resources\Contracts\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ContractRenewalsRelationManager extends RelationManager
{
    protected static string $relationship = 'renewalConfigurations';

    protected static ?string $title = 'Rinnovi e Scadenze';

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('effective_from')->label('Efficace dal')->date('d/m/Y'),
            IconColumn::make('automatic_renewal')->label('Rinnovo Automatico')->boolean(),
            TextColumn::make('expiry_anchor_date')->label('Scadenza Approvata')->date('d/m/Y')->placeholder('Scadenza non definita'),
            TextColumn::make('renewal_duration_months')->label('Durata Mesi')->placeholder('—'),
            TextColumn::make('notice_days')->label('Preavviso Giorni')->placeholder('—'),
            TextColumn::make('creator.name')->label('Autore')->placeholder('Autore originale non disponibile'),
        ])->defaultSort('effective_from', 'desc')
            ->recordActions([]);
    }
}
