<?php

namespace App\Filament\Resources\Contracts\Schemas;

use App\Domain\Contracts\ContractAttributionMode;
use App\Domain\Contracts\ContractCycleType;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Supplier;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ContractForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Contratto')->schema([
                TextInput::make('title')->label('Titolo')->required()->maxLength(255),
                Select::make('supplier_id')->label('Fornitore')
                    ->options(fn (): array => self::company() instanceof Company
                        ? Supplier::query()->whereBelongsTo(self::company(), 'company')->active()->orderBy('legal_name')->pluck('legal_name', 'id')->all()
                        : [])->searchable()->required(),
                Textarea::make('notes')->label('Note')->columnSpanFull(),
                DatePicker::make('contractual_start_date')->label('Data di inizio')->required(),
                DatePicker::make('next_expiry_date')->label('Prossima scadenza'),
            ])->columns(2),
            Section::make('Rinnovo')->schema([
                DatePicker::make('renewal_effective_from')->label('Data efficacia configurazione rinnovo')->required(),
                Toggle::make('automatic_renewal')->label('Rinnovo automatico')->default(true)->live(),
                TextInput::make('renewal_duration_months')->label('Durata rinnovo (mesi)')->numeric()->minValue(1),
                TextInput::make('notice_days')->label('Preavviso (giorni)')->numeric()->minValue(0),
            ])->columns(2),
            Section::make('Prima condizione economica')->statePath('condition')->schema([
                TextInput::make('amount')->label('Importo netto IVA')->numeric()->minValue(0)->required(),
                Select::make('cycle')->label('Ciclo')->options(ContractCycleType::options())->required(),
                Select::make('attribution_mode')->label('Attribuzione Stima')->options(ContractAttributionMode::options())->required(),
                DatePicker::make('valid_from')->label('Valida dal')->required(),
                DatePicker::make('valid_to')->label('Valida fino al'),
            ])->columns(2),
            Section::make('Classificazioni degli Esercizi Aperti')->schema([
                Repeater::make('classifications')->label('Classificazioni')->schema([
                    Select::make('exercise_id')->label('Esercizio')->options(fn (): array => self::company() instanceof Company
                        ? Exercise::query()->whereBelongsTo(self::company(), 'company')->open()->orderBy('year')->pluck('year', 'id')->all()
                        : [])->required()->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                    Select::make('cost_center_id')->label('Centro di Costo')->options(fn (): array => self::company() instanceof Company
                        ? CostCenter::query()->whereBelongsTo(self::company(), 'company')->active()->orderBy('name')->pluck('name', 'id')->all()
                        : [])->placeholder('Non classificato')->searchable(),
                ])->columns(2)->defaultItems(0)->columnSpanFull(),
            ]),
        ]);
    }

    private static function company(): ?Company
    {
        $company = Filament::getTenant();

        return $company instanceof Company ? $company : null;
    }
}
