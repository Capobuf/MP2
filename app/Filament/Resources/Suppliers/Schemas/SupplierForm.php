<?php

namespace App\Filament\Resources\Suppliers\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SupplierForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('legal_name')
                ->label('Ragione Sociale')
                ->required()
                ->maxLength(255),
            TextInput::make('vat_number')
                ->label('Partita IVA')
                ->maxLength(64),
            Textarea::make('notes')
                ->label('Note')
                ->rows(4)
                ->columnSpanFull(),
        ]);
    }
}
