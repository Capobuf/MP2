<?php

namespace App\Filament\Resources\Contracts\Schemas;

use App\Actions\MasterData\CreateCostCenter;
use App\Actions\MasterData\CreateSupplier;
use App\Domain\Contracts\ContractAttributionMode;
use App\Domain\Contracts\ContractCycle;
use App\Domain\Contracts\ContractCycleType;
use App\Filament\Forms\AttachmentUpload;
use App\Filament\Forms\DecimalInput;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Supplier;
use App\Models\TenantCompany;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
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
    public const USE_DEFAULT_COST_CENTER = '__default__';

    public const NO_COST_CENTER = '__unclassified__';

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
                            ->visible(fn (): bool => self::canCreateSupplier()))
                        ->required(),
                    self::costCenterSelect('default_cost_center_id')
                        ->label('Centro di Costo')
                        ->placeholder('Non classificato')
                        ->helperText('Predefinito per tutti gli Esercizi Aperti. Le eventuali eccezioni si impostano in Avanzate.'),
                    Textarea::make('notes')->label('Note')->rows(3)->columnSpanFull(),
                    AttachmentUpload::make('attachments')
                        ->label('Allegati')
                        ->multiple()
                        ->storeFiles(false)
                        ->helperText('Opzionali. Potrai aggiungerne altri dalla scheda del Contratto.')
                        ->columnSpanFull(),
                ])->columns(['default' => 1, 'md' => 2, 'xl' => 3])->columnSpanFull(),
            Section::make('Condizioni economiche')
                ->description('Ogni riga definisce un importo ricorrente; non sono calcolati prorata. “Valida fino al” termina solo quella condizione economica: non determina la scadenza del Contratto.')
                ->schema([
                    Repeater::make('conditions')
                        ->hiddenLabel()
                        ->schema([
                            DecimalInput::make('amount')->label('Importo per ciclo')->minValue(0)->prefix('€')->required(),
                            Select::make('cycle')->label('Frequenza')->options(ContractCycleType::options())->native(false)->required(),
                            Select::make('attribution_mode')->label('Attribuzione')->options(ContractAttributionMode::options())->native(false)->required(),
                            self::dateInput('valid_from', 'Valida dal')->required()
                                ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                                    self::syncSuggestedContractualStart(
                                        $get,
                                        $set,
                                        $get('../../conditions'),
                                        $state,
                                        '../../',
                                    );
                                    self::syncSuggestedContractualTerms($get, $set, $get('../../conditions'), '../../');
                                }),
                            self::dateInput('valid_to', 'Valida fino al')
                                ->placeholder('Fino a variazione')
                                ->afterStateUpdated(fn (Get $get, Set $set) => self::syncSuggestedContractualTerms($get, $set, $get('../../conditions'), '../../')),
                        ])
                        ->table([
                            TableColumn::make('Importo per ciclo')->alignment(Alignment::Center)->markAsRequired(),
                            TableColumn::make('Frequenza')->alignment(Alignment::Center)->markAsRequired(),
                            TableColumn::make('Attribuzione')->alignment(Alignment::Center)->markAsRequired(),
                            TableColumn::make('Valida dal')->alignment(Alignment::Center)->markAsRequired(),
                            TableColumn::make('Valida fino al')->alignment(Alignment::Center),
                        ])
                        ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                            self::syncSuggestedContractualStart($get, $set, $state);
                            self::syncSuggestedContractualTerms($get, $set, $state);
                        })
                        ->defaultItems(1)
                        ->minItems(1)
                        ->addActionLabel('Aggiungi condizione')
                        ->reorderable(false)
                        ->extraAttributes(['class' => 'mp2-economic-conditions'])
                        ->columnSpanFull(),
                ])->columnSpanFull(),
            Section::make('Termini contrattuali')
                ->description('Il sistema propone scadenza e rinnovo dall’ultima condizione economica con termine. Verifica i valori: restano dati contrattuali distinti.')
                ->schema([
                    Hidden::make('suggested_contractual_start_date')->dehydrated(false),
                    Hidden::make('suggested_next_expiry_date')->dehydrated(false),
                    Hidden::make('suggested_renewal_duration_months')->dehydrated(false),
                    Hidden::make('renewal_effective_from'),
                    Grid::make(['default' => 1, 'md' => 2])
                        ->schema([
                            Group::make([
                                self::dateInput('contractual_start_date', 'Data di inizio')->required()
                                    ->helperText('Compilata con il “Valida dal” più antico. Anticipala solo se il Contratto è iniziato prima della prima condizione economica.')
                                    ->afterStateUpdated(fn (Set $set, mixed $state): mixed => filled($state) ? $set('renewal_effective_from', self::dateToIso($state)) : null),
                                self::dateInput('next_expiry_date', 'Prossima scadenza contrattuale')
                                    ->required(fn (Get $get): bool => $get('duration_type') === 'fixed')
                                    ->visible(fn (Get $get): bool => $get('duration_type') === 'fixed')
                                    ->helperText('Data in cui il periodo contrattuale corrente termina o si rinnova; non è la fine di validità di un importo.'),
                                TextInput::make('renewal_duration_months')->label('Rinnovo ogni')->numeric()->minValue(1)
                                    ->suffix('mesi')
                                    ->required(fn (Get $get): bool => $get('duration_type') === 'fixed' && (bool) $get('automatic_renewal'))
                                    ->visible(fn (Get $get): bool => $get('duration_type') === 'fixed' && (bool) $get('automatic_renewal')),
                            ]),
                            Group::make([
                                Placeholder::make('duration_question')
                                    ->label('Durata contrattuale')
                                    ->content('Il Contratto ha una prossima scadenza?'),
                                Radio::make('duration_type')
                                    ->label('Prossima scadenza?')
                                    ->hiddenLabel()
                                    ->options([
                                        'fixed' => 'Sì, la scadenza è definita',
                                        'indefinite' => 'No, è senza scadenza',
                                        'undefined' => 'La scadenza non è ancora definita',
                                    ])
                                    ->descriptions([
                                        'fixed' => 'Indicherai la data e cosa accade quando viene raggiunta.',
                                        'indefinite' => 'Il Contratto prosegue fino a una cessazione esplicita.',
                                        'undefined' => 'Il Contratto prosegue, ma scadenze e rinnovi non possono ancora essere calcolati.',
                                    ])
                                    ->required()
                                    ->live()
                                    ->dehydrated(false)
                                    ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                                        if ($state === 'fixed') {
                                            if ($get('notice_days') === null || $get('notice_days') === '') {
                                                $set('notice_days', 30);
                                            }
                                            self::syncSuggestedContractualTerms($get, $set, $get('conditions'));

                                            return;
                                        }

                                        $set('next_expiry_date', null);
                                        $set('renewal_duration_months', null);
                                        $set('notice_days', null);
                                        $set('automatic_renewal', $state === 'undefined');
                                    }),
                                Toggle::make('automatic_renewal')->label('Alla scadenza si rinnova automaticamente')->default(true)->live()
                                    ->helperText(fn (Get $get): string => (bool) $get('automatic_renewal')
                                        ? 'Il Contratto resta Attivo e la prossima scadenza viene avanzata.'
                                        : 'Il Contratto termina alla scadenza indicata e risulta Cessato dal giorno successivo.')
                                    ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                                        if ($state) {
                                            self::syncSuggestedContractualTerms($get, $set, $get('conditions'));

                                            return;
                                        }

                                        $set('renewal_duration_months', null);
                                    })
                                    ->dehydratedWhenHidden()
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
                    Placeholder::make('undefined_expiry')
                        ->hiddenLabel()
                        ->content('La scadenza non è ancora definita. Le condizioni economiche senza “Valida fino al” continuano a generare Stime, ma non vengono calcolati rinnovi, termini di disdetta o promemoria futuri.')
                        ->visible(fn (Get $get): bool => $get('duration_type') === 'undefined')
                        ->columnSpanFull(),
                ])->columnSpanFull(),
            Section::make('Avanzate')
                ->description('Personalizza il Centro di Costo solo negli Esercizi che fanno eccezione al valore predefinito.')
                ->schema([
                    Repeater::make('classifications')
                        ->label('Centri di Costo per Esercizio')
                        ->helperText('Ogni Esercizio usa il Centro di Costo predefinito, salvo una scelta diversa qui.')
                        ->schema([
                            Select::make('exercise_id')->label('Esercizio')->options(fn (): array => self::company() instanceof Company
                                ? Exercise::query()->whereBelongsTo(self::company(), 'company')->open()->orderBy('year')->pluck('year', 'id')->all()
                                : [])->required()->disabled()->dehydrated()->selectablePlaceholder(false),
                            self::costCenterSelect('cost_center_selection', includeAnnualChoices: true)
                                ->label('Centro di Costo')
                                ->required()
                                ->selectablePlaceholder(false),
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
                                    'cost_center_selection' => self::USE_DEFAULT_COST_CENTER,
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

    private static function costCenterSelect(string $name, bool $includeAnnualChoices = false): Select
    {
        return Select::make($name)
            ->options(fn (): array => ($includeAnnualChoices ? [
                self::USE_DEFAULT_COST_CENTER => 'Usa il predefinito',
                self::NO_COST_CENTER => 'Non classificato',
            ] : []) + self::costCenterOptions())
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
                ->visible(fn (): bool => self::canCreateCostCenter()));
    }

    private static function canCreateSupplier(): bool
    {
        $actor = auth()->user();
        $company = self::company();

        return $actor instanceof User
            && $company instanceof Company
            && $actor->can('create', [Supplier::class, $company]);
    }

    private static function canCreateCostCenter(): bool
    {
        $actor = auth()->user();
        $company = self::company();

        return $actor instanceof User
            && $company instanceof Company
            && $actor->can('create', [CostCenter::class, $company]);
    }

    private static function dateInput(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->mask('99/99/9999')
            ->inputMode('numeric')
            ->placeholder('gg/mm/aaaa')
            ->maxLength(10)
            ->rule('date_format:d/m/Y')
            ->dehydrateStateUsing(fn (mixed $state): mixed => self::dateToIso($state))
            ->live(onBlur: true);
    }

    private static function syncSuggestedContractualStart(
        Get $get,
        Set $set,
        mixed $state,
        mixed $updatedDate = null,
        string $rootPath = '',
    ): void {
        $dates = collect(is_array($state) ? $state : [])
            ->map(fn (mixed $condition): ?string => is_array($condition) && filled($condition['valid_from'] ?? null)
                ? (string) $condition['valid_from']
                : null);

        if (filled($updatedDate)) {
            $dates->push((string) $updatedDate);
        }

        $suggested = $dates
            ->filter(fn (mixed $date): bool => self::dateString($date) !== null)
            ->sortBy(fn (mixed $date): string => self::dateString($date) ?? '')
            ->first();
        $previousSuggestion = $get($rootPath.'suggested_contractual_start_date');
        $currentStart = $get($rootPath.'contractual_start_date');

        if (blank($currentStart) || $currentStart === $previousSuggestion) {
            $set($rootPath.'contractual_start_date', $suggested, shouldCallUpdatedHooks: true);
        }

        $set($rootPath.'suggested_contractual_start_date', $suggested);
    }

    private static function syncSuggestedContractualTerms(
        Get $get,
        Set $set,
        mixed $state,
        string $rootPath = '',
    ): void {
        $latestCondition = collect(is_array($state) ? $state : [])
            ->filter(fn (mixed $condition): bool => is_array($condition) && self::dateString($condition['valid_from'] ?? null) !== null)
            ->sortBy(fn (array $condition): string => self::dateString($condition['valid_from']) ?? '')
            ->last();

        $previousExpirySuggestion = $get($rootPath.'suggested_next_expiry_date');
        $previousDurationSuggestion = $get($rootPath.'suggested_renewal_duration_months');

        $validFrom = is_array($latestCondition) ? self::dateString($latestCondition['valid_from'] ?? null) : null;
        $validTo = is_array($latestCondition) ? self::dateString($latestCondition['valid_to'] ?? null) : null;

        if ($validFrom === null || $validTo === null) {
            if (self::dateString($get($rootPath.'next_expiry_date')) === self::dateString($previousExpirySuggestion)) {
                $set($rootPath.'next_expiry_date', null);
            }
            if ((string) $get($rootPath.'renewal_duration_months') === (string) $previousDurationSuggestion) {
                $set($rootPath.'renewal_duration_months', null);
            }

            $set($rootPath.'suggested_next_expiry_date', null);
            $set($rootPath.'suggested_renewal_duration_months', null);

            return;
        }

        $durationType = $get($rootPath.'duration_type');
        if (filled($durationType) && $durationType !== 'fixed') {
            return;
        }

        $suggestedExpiry = (string) $latestCondition['valid_to'];
        $suggestedDuration = self::suggestedRenewalMonths($validFrom, $validTo);

        $currentExpiry = $get($rootPath.'next_expiry_date');
        if (blank($currentExpiry) || self::dateString($currentExpiry) === self::dateString($previousExpirySuggestion)) {
            $set($rootPath.'next_expiry_date', $suggestedExpiry);
        }

        if ((bool) $get($rootPath.'automatic_renewal')) {
            $currentDuration = $get($rootPath.'renewal_duration_months');
            if (blank($currentDuration) || (string) $currentDuration === (string) $previousDurationSuggestion) {
                $set($rootPath.'renewal_duration_months', $suggestedDuration);
            }
        }

        $set($rootPath.'suggested_next_expiry_date', $suggestedExpiry);
        $set($rootPath.'suggested_renewal_duration_months', $suggestedDuration);

        if (blank($durationType)) {
            $set($rootPath.'duration_type', 'fixed', shouldCallUpdatedHooks: true);
        }
    }

    private static function suggestedRenewalMonths(string $validFrom, string $validTo): int
    {
        $start = CarbonImmutable::parse($validFrom)->startOfDay();
        $exclusiveEnd = CarbonImmutable::parse($validTo)->addDay()->startOfDay();
        $months = max(1, (($exclusiveEnd->year - $start->year) * 12) + $exclusiveEnd->month - $start->month);

        while ($months > 1 && ContractCycle::anchoredDate($start, $months)->greaterThan($exclusiveEnd)) {
            $months--;
        }

        return $months;
    }

    private static function dateString(mixed $value): ?string
    {
        $date = self::dateToIso($value);

        return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
            ? $date
            : null;
    }

    private static function dateToIso(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $date = (string) $value;
        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $date) !== 1) {
            return $date;
        }

        $parsed = CarbonImmutable::createFromFormat('!d/m/Y', $date);

        return $parsed === null || $parsed->format('d/m/Y') !== $date
            ? $date
            : $parsed->toDateString();
    }

    private static function company(): ?Company
    {
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;

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

    /** @return array<int, string> */
    private static function costCenterOptions(): array
    {
        $company = self::company();

        return $company instanceof Company
            ? CostCenter::query()->whereBelongsTo($company, 'company')->active()->orderBy('name')->pluck('name', 'id')->all()
            : [];
    }
}
