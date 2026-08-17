<?php

namespace App\Filament\Pages\Tenancy;

use App\Actions\CreateCompany;
use App\Models\Company;
use App\Models\User;
use DateTimeZone;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class RegisterCompany extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'Crea Azienda';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Denominazione')
                ->required()
                ->maxLength(255),
            Select::make('timezone')
                ->label('Fuso orario')
                ->options(array_combine(
                    DateTimeZone::listIdentifiers(),
                    DateTimeZone::listIdentifiers(),
                ))
                ->searchable()
                ->required(),
        ]);
    }

    /** @param array{name: string, timezone: string} $data */
    protected function handleRegistration(array $data): Model
    {
        /** @var User $actor */
        $actor = auth()->user();

        return app(CreateCompany::class)->execute($actor, $data);
    }

    public function getModel(): string
    {
        return Company::class;
    }
}
