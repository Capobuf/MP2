<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Domain\Projects\ProjectState;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Exercise;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Progetto')->schema([
                TextInput::make('title')->label('Titolo')->required()->maxLength(255),
                Textarea::make('description')->label('Descrizione')->columnSpanFull(),
                Textarea::make('notes')->label('Note')->columnSpanFull(),
            ])->columns(2),
            Section::make('Stato iniziale e classificazione')->schema([
                Select::make('initial_state')
                    ->label('Stato iniziale')
                    ->options(ProjectState::options())
                    ->required(),
                DatePicker::make('initial_effective_date')
                    ->label('Data di efficacia iniziale')
                    ->required(),
                Select::make('exercise_id')
                    ->label('Esercizio della classificazione iniziale')
                    ->options(fn (): array => self::company() instanceof Company
                        ? Exercise::query()->whereBelongsTo(self::company(), 'company')->open()->orderByDesc('year')->pluck('year', 'id')->all()
                        : [])
                    ->required(),
                Select::make('cost_center_id')
                    ->label('Centro di Costo')
                    ->options(fn (): array => self::company() instanceof Company
                        ? CostCenter::query()->whereBelongsTo(self::company(), 'company')->active()->orderBy('name')->pluck('name', 'id')->all()
                        : [])
                    ->searchable()
                    ->placeholder('Non classificato'),
            ])->columns(2),
        ]);
    }

    private static function company(): ?Company
    {
        $company = Filament::getTenant();

        return $company instanceof Company ? $company : null;
    }
}
