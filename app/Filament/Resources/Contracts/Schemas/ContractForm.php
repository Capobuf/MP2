<?php

namespace App\Filament\Resources\Contracts\Schemas;

use App\Actions\MasterData\CreateCostCenter;
use App\Actions\MasterData\CreateSupplier;
use App\Domain\Company\Capability;
use App\Domain\Contracts\ContractAttributionMode;
use App\Domain\Contracts\ContractCycleType;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Supplier;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ContractForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Contratto')
                ->description('Identifica il contratto e le sue scadenze contrattuali. Le date di fattura e pagamento appartengono alle Spese.')
                ->schema([
                    TextInput::make('title')->label('Titolo')->required()->maxLength(255),
                    Select::make('supplier_id')->label('Fornitore')
                        ->options(fn (): array => self::supplierOptions())
                        ->searchable()
                        ->createOptionForm([
                            TextInput::make('legal_name')->label('Ragione sociale')->required()->maxLength(255),
                            TextInput::make('vat_number')->label('Partita IVA')->maxLength(64),
                            Textarea::make('notes')->label('Note'),
                        ])
                        ->createOptionUsing(function (array $data): int {
                            $actor = auth()->user();
                            $company = self::company();
                            abort_unless($actor instanceof User && $company instanceof Company, 403);

                            return app(CreateSupplier::class)->execute($actor, $company, $data, (string) Str::uuid())->id;
                        })
                        ->createOptionAction(fn (Action $action): Action => $action
                            ->label('Crea fornitore')
                            ->modalHeading('Nuovo fornitore')
                            ->visible(fn (): bool => self::canManageMasterData()))
                        ->required(),
                    DatePicker::make('contractual_start_date')->label('Data di inizio')->native(false)->displayFormat('d/m/Y')
                        ->placeholder('gg/mm/aaaa')->required(),
                    DatePicker::make('next_expiry_date')->label('Prossima scadenza')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->placeholder('gg/mm/aaaa')
                        ->live(),
                    Textarea::make('notes')->label('Note')->rows(3)->columnSpanFull(),
                ])->columns(2)->columnSpanFull(),
            Section::make('Rinnovo')
                ->description('Definisce la configurazione applicabile dalla data indicata. Per un censimento tardivo usa la decorrenza reale; il preavviso non genera scadenze di pagamento.')
                ->schema([
                    DatePicker::make('renewal_effective_from')->label('Data efficacia configurazione rinnovo')
                        ->native(false)->displayFormat('d/m/Y')->placeholder('gg/mm/aaaa')->required(),
                    Toggle::make('automatic_renewal')->label('Rinnovo automatico')->default(true)->live()
                        ->afterStateUpdated(fn (Set $set, mixed $state): mixed => $state ? null : $set('renewal_duration_months', null)),
                    TextInput::make('renewal_duration_months')->label('Durata rinnovo (mesi)')->numeric()->minValue(1)
                        ->required(fn (Get $get): bool => (bool) $get('automatic_renewal') && filled($get('next_expiry_date')))
                        ->visible(fn (Get $get): bool => (bool) $get('automatic_renewal') && filled($get('next_expiry_date'))),
                    TextInput::make('notice_days')->label('Preavviso (giorni)')->numeric()->minValue(0),
                ])->columns(2)->columnSpanFull(),
            Section::make('Prima condizione economica')
                ->description('Genera le Stime per ciclo negli Esercizi interessati. Nessun prorata: ogni ciclo è attribuito per intero secondo la modalità scelta.')
                ->statePath('condition')->schema([
                    TextInput::make('amount')->label('Importo netto IVA')->numeric()->minValue(0)->prefix('€')->step('0.01')->required()
                        ->columnSpan(['default' => 6, 'xl' => 2]),
                    Select::make('cycle')->label('Ciclo')->options(ContractCycleType::options())->native(false)->required()
                        ->columnSpan(['default' => 6, 'xl' => 2]),
                    Select::make('attribution_mode')->label('Attribuzione Stima')->options(ContractAttributionMode::options())->native(false)->required()
                        ->columnSpan(['default' => 6, 'xl' => 2]),
                    DatePicker::make('valid_from')->label('Valida dal')->native(false)->displayFormat('d/m/Y')
                        ->placeholder('gg/mm/aaaa')->required()
                        ->columnSpan(['default' => 6, 'xl' => 3]),
                    DatePicker::make('valid_to')->label('Valida fino al')->native(false)->displayFormat('d/m/Y')
                        ->placeholder('gg/mm/aaaa')
                        ->columnSpan(['default' => 6, 'xl' => 3]),
                ])->columns(6)->columnSpanFull(),
            Section::make('Classificazioni degli Esercizi Aperti')
                ->description('È proposta una riga per ogni Esercizio Aperto. Senza Centro di Costo, la Stima resta Non classificata.')
                ->schema([
                    Repeater::make('classifications')->hiddenLabel()->schema([
                        Select::make('exercise_id')->label('Esercizio')->options(fn (): array => self::company() instanceof Company
                            ? Exercise::query()->whereBelongsTo(self::company(), 'company')->open()->orderBy('year')->pluck('year', 'id')->all()
                            : [])->required()->disabled()->dehydrated()->selectablePlaceholder(false),
                        Select::make('cost_center_id')->label('Centro di Costo')->options(fn (): array => self::company() instanceof Company
                            ? CostCenter::query()->whereBelongsTo(self::company(), 'company')->active()->orderBy('name')->pluck('name', 'id')->all()
                            : [])
                            ->placeholder('Non classificato')
                            ->searchable()
                            ->createOptionForm([
                                TextInput::make('name')->label('Nome')->required()->maxLength(255),
                            ])
                            ->createOptionUsing(function (array $data): int {
                                $actor = auth()->user();
                                $company = self::company();
                                abort_unless($actor instanceof User && $company instanceof Company, 403);

                                return app(CreateCostCenter::class)->execute($actor, $company, $data, (string) Str::uuid())->id;
                            })
                            ->createOptionAction(fn (Action $action): Action => $action
                                ->label('Crea centro di costo')
                                ->modalHeading('Nuovo centro di costo')
                                ->visible(fn (): bool => self::canManageMasterData())),
                    ])->columns(2)
                        ->default(fn (): array => self::company() instanceof Company
                            ? Exercise::query()
                                ->whereBelongsTo(self::company(), 'company')
                                ->open()
                                ->orderBy('year')
                                ->get()
                                ->map(fn (Exercise $exercise): array => [
                                    'exercise_id' => $exercise->id,
                                    'cost_center_id' => null,
                                ])
                                ->all()
                            : [])
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->grid(['default' => 1, 'xl' => 2])
                        ->columnSpanFull(),
                ])->columnSpanFull(),
        ]);
    }

    private static function canManageMasterData(): bool
    {
        $actor = auth()->user();
        $company = self::company();

        return $actor instanceof User
            && $company instanceof Company
            && $actor->hasCapability($company, Capability::ManageMasterData);
    }

    private static function company(): ?Company
    {
        $company = Filament::getTenant();

        return $company instanceof Company ? $company : null;
    }

    /** @return array<int, string> */
    private static function supplierOptions(): array
    {
        $company = self::company();

        return $company instanceof Company
            ? Supplier::query()->whereBelongsTo($company, 'company')->active()->orderBy('legal_name')->pluck('legal_name', 'id')->all()
            : [];
    }
}
