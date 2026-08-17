<?php

namespace App\Filament\Resources\Exercises\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ExerciseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('year')
                ->label('Anno')
                ->integer()
                ->minValue(1)
                ->maxValue(9999)
                ->required(),
        ]);
    }
}
