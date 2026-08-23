<?php

namespace App\Filament\Resources\Contracts\Schemas;

use App\Actions\MasterData\CreateCostCenter;
use App\Actions\MasterData\CreateSupplier;
use App\Domain\Company\Capability;
use App\Domain\Contracts\ContractAttributionMode;
use App\Domain\Contracts\ContractCycleType;
use App\Filament\Forms\DecimalInput;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Supplier;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Illuminate\Support\Str;

class ContractForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dati principali')
                ->description('Inserisci le informazioni che identificano il Contratto. Le date di fattura e pagamento appartengono alle Spese.')
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
                    Textarea::make('notes')->label('Note')->rows(3)->columnSpanFull(),
                ])->columns(2)->columnSpanFull(),
            Section::make('Condizioni economiche')
                ->description('Ogni riga definisce un periodo economico. Le condizioni valide non possono sovrapporsi e non producono prorata.')
                ->schema([
                    Repeater::make('conditions')
                        ->hiddenLabel()
                        ->schema([
                            DecimalInput::make('amount')->label('Importo per ciclo')->minValue(0)->prefix('€')->required(),
                            Select::make('cycle')->label('Frequenza')->options(ContractCycleType::options())->native(false)->required(),
                            Select::make('attribution_mode')->label('Attribuzione')->options(ContractAttributionMode::options())->native(false)->required(),
                            DatePicker::make('valid_from')->label('Valida dal')->native(false)->displayFormat('d/m/Y')
                                ->placeholder('gg/mm/aaaa')->required()->live()
                                ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                                    self::syncSuggestedContractualStart(
                                        $get,
                                        $set,
                                        $get('conditions', isAbsolute: true),
                                        $state,
                                    );
                                }),
                            DatePicker::make('valid_to')->label('Valida fino al')->native(false)->displayFormat('d/m/Y')
                                ->placeholder('Senza fine')
                                ->minDate(fn (Get $get): ?string => $get('valid_from')),
                        ])
                        ->table([
                            TableColumn::make('Importo per ciclo')->alignment(Alignment::Center)->markAsRequired(),
                            TableColumn::make('Frequenza')->alignment(Alignment::Center)->markAsRequired(),
                            TableColumn::make('Attribuzione')->alignment(Alignment::Center)->markAsRequired(),
                            TableColumn::make('Valida dal')->alignment(Alignment::Center)->markAsRequired(),
                            TableColumn::make('Valida fino al')->alignment(Alignment::Center),
                        ])
                        ->afterStateUpdated(fn (Get $get, Set $set, mixed $state) => self::syncSuggestedContractualStart($get, $set, $state))
                        ->defaultItems(1)
                        ->minItems(1)
                        ->addActionLabel('Aggiungi condizione')
                        ->reorderable(false)
                        ->extraAttributes(['class' => 'mp2-economic-conditions'])
                        ->columnSpanFull(),
                ])->columnSpanFull(),
            Section::make('Termini contrattuali')
                ->description('La durata contrattuale è distinta dalla frequenza economica delle condizioni inserite sopra.')
                ->schema([
                    Hidden::make('suggested_contractual_start_date')->dehydrated(false),
                    Hidden::make('renewal_effective_from'),
                    Grid::make(['default' => 1, 'md' => 2])
                        ->schema([
                            Group::make([
                                DatePicker::make('contractual_start_date')->label('Data di inizio')->native(false)->displayFormat('d/m/Y')
                                    ->placeholder('gg/mm/aaaa')->required()->live()
                                    ->helperText('Primo giorno Attivo. È proposta dalla condizione con decorrenza più antica, ma puoi anticiparla.')
                                    ->afterStateUpdated(fn (Set $set, mixed $state): mixed => filled($state) ? $set('renewal_effective_from', $state) : null),
                                DatePicker::make('next_expiry_date')->label('Prossima scadenza contrattuale')
                                    ->native(false)
                                    ->displayFormat('d/m/Y')
                                    ->placeholder('gg/mm/aaaa')
                                    ->minDate(fn (Get $get): ?string => $get('contractual_start_date'))
                                    ->required(fn (Get $get): bool => $get('duration_type') === 'fixed')
                                    ->visible(fn (Get $get): bool => $get('duration_type') === 'fixed'),
                                TextInput::make('renewal_duration_months')->label('Rinnovo ogni')->numeric()->minValue(1)
                                    ->suffix('mesi')
                                    ->required(fn (Get $get): bool => (bool) $get('automatic_renewal'))
                                    ->visible(fn (Get $get): bool => $get('duration_type') === 'fixed' && (bool) $get('automatic_renewal')),
                            ]),
                            Group::make([
                                Radio::make('duration_type')
                                    ->label('Durata contrattuale')
                                    ->options([
                                        'fixed' => 'Con scadenza contrattuale',
                                        'indefinite' => 'Senza scadenza · durata indefinita',
                                    ])
                                    ->descriptions([
                                        'fixed' => 'Il periodo corrente termina o si rinnova alla data indicata.',
                                        'indefinite' => 'Il Contratto prosegue fino a una cessazione esplicita.',
                                    ])
                                    ->required()
                                    ->live()
                                    ->dehydrated(false)
                                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                                        if ($state !== 'indefinite') {
                                            return;
                                        }

                                        $set('next_expiry_date', null);
                                        $set('renewal_duration_months', null);
                                        $set('notice_days', null);
                                    }),
                                Toggle::make('automatic_renewal')->label('Rinnovo automatico')->default(true)->live()
                                    ->helperText('Alla scadenza il Contratto resta Attivo e la prossima scadenza viene avanzata.')
                                    ->afterStateUpdated(fn (Set $set, mixed $state): mixed => $state ? null : $set('renewal_duration_months', null))
                                    ->visible(fn (Get $get): bool => $get('duration_type') === 'fixed'),
                                TextInput::make('notice_days')->label('Preavviso di disdetta')->numeric()->minValue(0)
                                    ->suffix('giorni')
                                    ->helperText('Opzionale, in giorni di calendario. Non è una scadenza di pagamento.')
                                    ->visible(fn (Get $get): bool => $get('duration_type') === 'fixed'),
                            ]),
                        ])
                        ->columnSpanFull(),
                    Placeholder::make('indefinite_duration')
                        ->hiddenLabel()
                        ->content('Nessuna scadenza o data limite di disdetta verrà calcolata. Il Contratto resterà Attivo fino a una cessazione esplicita.')
                        ->visible(fn (Get $get): bool => $get('duration_type') === 'indefinite')
                        ->columnSpanFull(),
                ])->columnSpanFull(),
            Section::make('Centri di Costo per Esercizio')
                ->description('Opzionale. Assegna un Centro di Costo agli Esercizi Aperti; gli altri restano Non classificati.')
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
                        ->table([
                            TableColumn::make('Esercizio')->markAsRequired(),
                            TableColumn::make('Centro di Costo'),
                        ])
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
                        ->columnSpanFull(),
                ])->collapsible()->collapsed()->columnSpanFull(),
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

    private static function syncSuggestedContractualStart(Get $get, Set $set, mixed $state, mixed $updatedDate = null): void
    {
        $dates = collect(is_array($state) ? $state : [])
            ->map(fn (mixed $condition): ?string => is_array($condition) && filled($condition['valid_from'] ?? null)
                ? (string) $condition['valid_from']
                : null)
            ->filter();

        if (filled($updatedDate)) {
            $dates->push((string) $updatedDate);
        }

        $suggested = $dates->sort()->first();
        $previousSuggestion = $get('suggested_contractual_start_date', isAbsolute: true);
        $currentStart = $get('contractual_start_date', isAbsolute: true);

        if (blank($currentStart) || $currentStart === $previousSuggestion) {
            $set('contractual_start_date', $suggested, isAbsolute: true, shouldCallUpdatedHooks: true);
        }

        $set('suggested_contractual_start_date', $suggested, isAbsolute: true);
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
