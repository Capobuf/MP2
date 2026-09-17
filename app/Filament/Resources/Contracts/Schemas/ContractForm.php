<?php

namespace App\Filament\Resources\Contracts\Schemas;

use App\Actions\MasterData\CreateCostCenter;
use App\Actions\MasterData\CreateSupplier;
use App\Actions\Operations\UpdateContract;
use App\Domain\Contracts\ContractAttributionMode;
use App\Domain\Contracts\ContractCycle;
use App\Domain\Contracts\ContractCycleType;
use App\Domain\CostCenters\CostCenterHierarchy;
use App\Filament\Forms\AttachmentUpload;
use App\Filament\Forms\DateInput;
use App\Filament\Forms\DecimalInput;
use App\Models\Company;
use App\Models\Contract;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Supplier;
use App\Models\TenantCompany;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
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

    public static function configure(Schema $schema, ?Contract $contract = null): Schema
    {
        $editing = $contract !== null;

        return $schema->components([
            Section::make('Dati Principali')
                ->description('Inserisci le informazioni che identificano il Contratto. Le date di fattura e pagamento appartengono alle Spese.')
                ->schema([
                    TextInput::make('title')->label('Titolo')->required()->maxLength(255),
                    Select::make('supplier_id')->label('Fornitore')
                        ->options(fn (): array => self::supplierOptions($contract))
                        ->disabled($contract?->hasEconomicUse() ?? false)->dehydrated()
                        ->helperText($editing ? 'Il Fornitore può cambiare solo prima del primo utilizzo economico; successivamente occorre un nuovo Contratto.' : null)
                        ->searchable()
                        ->createOptionForm([
                            TextInput::make('legal_name')->label('Ragione Sociale')->required()->maxLength(255),
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
                            ->label('Crea Fornitore')
                            ->modalHeading('Nuovo Fornitore')
                            ->visible(fn (): bool => self::canCreateSupplier()))
                        ->required(),
                    self::costCenterSelect('default_cost_center_id')
                        ->label('Centro di Costo')->visible(! $editing)
                        ->placeholder('Non classificato')
                        ->helperText('Predefinito per tutti gli Esercizi Aperti. Le eventuali eccezioni si impostano in Avanzate.'),
                    Textarea::make('notes')->label('Note')->rows(3)->columnSpanFull(),
                ])->columns(['default' => 1, 'md' => 2, 'xl' => $editing ? 2 : 3])->columnSpanFull(),
            Section::make('Condizioni Economiche')
                ->description('Ogni riga definisce un importo ricorrente; non sono calcolati prorata. “Valida fino al” termina solo quella condizione economica: non determina la scadenza del Contratto.')
                ->schema([
                    Repeater::make('conditions')
                        ->hiddenLabel()
                        ->schema([
                            Hidden::make('id')->visible($editing),
                            DecimalInput::make('amount')->label('Importo per Ciclo')->minValue(0)->prefix('€')->required(),
                            Select::make('cycle')->label('Frequenza')->options(ContractCycleType::options())->native(false)->required(),
                            Select::make('attribution_mode')->label('Attribuzione')->options(ContractAttributionMode::options())->native(false)->required(),
                            DateInput::make('valid_from')->label('Valida dal')->required()->readOnly($editing)
                                ->afterStateUpdated(function (Get $get, Set $set, mixed $state) use ($editing): void {
                                    if ($editing) {
                                        return;
                                    }
                                    self::syncSuggestedContractualStart(
                                        $get,
                                        $set,
                                        $get('../../conditions'),
                                        $state,
                                        '../../',
                                    );
                                    self::syncSuggestedContractualTerms($get, $set, $get('../../conditions'), '../../');
                                }),
                            DateInput::make('valid_to')->label('Valida fino al')->readOnly($editing)
                                ->placeholder('Fino a variazione')
                                ->afterStateUpdated(fn (Get $get, Set $set) => $editing ? null : self::syncSuggestedContractualTerms($get, $set, $get('../../conditions'), '../../')),
                        ])
                        ->table([
                            TableColumn::make('Importo per ciclo')->alignment(Alignment::Center)->markAsRequired(),
                            TableColumn::make('Frequenza')->width('20%')->alignment(Alignment::Center)->markAsRequired(),
                            TableColumn::make('Attribuzione')->width('20%')->alignment(Alignment::Center)->markAsRequired(),
                            TableColumn::make('Valida dal')->alignment(Alignment::Center)->markAsRequired(),
                            TableColumn::make('Valida fino al')->alignment(Alignment::Center),
                        ])
                        ->afterStateUpdated(function (Get $get, Set $set, mixed $state) use ($editing): void {
                            if ($editing) {
                                return;
                            }
                            self::syncSuggestedContractualStart($get, $set, $state);
                            self::syncSuggestedContractualTerms($get, $set, $state);
                        })
                        ->defaultItems(1)
                        ->minItems($editing ? 0 : 1)
                        ->addable(! $editing)->deletable(! $editing)
                        ->helperText($editing ? 'Le date delle condizioni esistenti non sono correggibili. Per un nuovo accordo, modifica importo, frequenza o attribuzione: al salvataggio potrai indicare la decorrenza richiesta. Nuove condizioni e annullamenti restano nella scheda del Contratto.' : null)
                        ->addActionLabel('Aggiungi condizione')
                        ->reorderable(false)
                        ->extraAttributes(['class' => 'mp2-economic-conditions'])
                        ->columnSpanFull(),
                ])->columnSpanFull(),
            Section::make('Termini Contrattuali')
                ->description($editing ? 'Le modifiche saranno registrate nello storico del rinnovo, dopo la conferma dell’impatto.' : 'Il sistema propone scadenza e rinnovo dall’ultima condizione economica con termine. Verifica i valori: restano dati contrattuali distinti.')
                ->schema([
                    Hidden::make('suggested_contractual_start_date')->dehydrated(false),
                    Hidden::make('suggested_next_expiry_date')->dehydrated(false),
                    Hidden::make('suggested_renewal_duration_months')->dehydrated(false),
                    Hidden::make('duration_type_manually_selected')->default(false)->dehydrated(false),
                    Hidden::make('renewal_effective_from'),
                    Grid::make(['default' => 1, 'md' => 2])
                        ->schema([
                            Group::make([
                                DateInput::make('contractual_start_date')->label('Data di inizio')->required()->readOnly($editing)
                                    ->helperText($editing ? 'La data di inizio originaria non è correggibile. Gli eventi futuri si gestiscono nel Ciclo di Vita, senza riscrivere l’attivazione già efficace.' : 'Compilata con il “Valida dal” più antico. Anticipala solo se il Contratto è iniziato prima della prima condizione economica.')
                                    ->afterStateUpdated(fn (Set $set, mixed $state): mixed => ! $editing && filled($state) ? $set('renewal_effective_from', DateInput::toIso($state)) : null),
                                DateInput::make('next_expiry_date')->dehydratedWhenHidden()->label('Prossima scadenza contrattuale')
                                    ->required(fn (Get $get): bool => $get('duration_type') === 'fixed')
                                    ->visible(fn (Get $get): bool => $get('duration_type') === 'fixed')
                                    ->helperText('Data in cui il periodo contrattuale corrente termina o si rinnova; non è la fine di validità di un importo.'),
                                TextInput::make('renewal_duration_months')->dehydratedWhenHidden()->label('Rinnovo Ogni')->numeric()->minValue(1)
                                    ->suffix('mesi')
                                    ->required(fn (Get $get): bool => $get('duration_type') === 'fixed' && (bool) $get('automatic_renewal'))
                                    ->visible(fn (Get $get): bool => $get('duration_type') === 'fixed' && (bool) $get('automatic_renewal')),
                            ]),
                            Group::make([
                                Radio::make('duration_type')
                                    ->label('Durata Contrattuale')
                                    ->options([
                                        'fixed' => 'Con Scadenza',
                                        'indefinite' => 'Senza Scadenza',
                                        'undefined' => 'Scadenza da Definire',
                                    ])
                                    ->descriptions([
                                        'fixed' => 'Indicherai la data e cosa accade quando viene raggiunta.',
                                        'indefinite' => 'Il Contratto prosegue fino a una cessazione esplicita.',
                                        'undefined' => 'Il Contratto prosegue, ma scadenze e rinnovi non possono ancora essere calcolati.',
                                    ])
                                    ->default('undefined')
                                    ->required()
                                    ->live()
                                    ->dehydrated(false)
                                    ->afterStateUpdated(function (Get $get, Set $set, mixed $state) use ($editing): void {
                                        $set('duration_type_manually_selected', true);

                                        if ($state === 'fixed') {
                                            if (! $editing && ($get('notice_days') === null || $get('notice_days') === '')) {
                                                $set('notice_days', 30);
                                            }
                                            if (! $editing) {
                                                self::syncSuggestedContractualTerms($get, $set, $get('conditions'));
                                            }

                                            return;
                                        }

                                        $set('next_expiry_date', null);
                                        $set('renewal_duration_months', null);
                                        $set('notice_days', null);
                                        $set('automatic_renewal', $state === 'undefined');
                                    }),
                                Toggle::make('automatic_renewal')->label('Alla Scadenza si Rinnova Automaticamente')->default(true)->live()
                                    ->helperText(fn (Get $get): string => (bool) $get('automatic_renewal')
                                        ? 'Il Contratto resta Attivo e la prossima scadenza viene avanzata.'
                                        : 'Il Contratto termina alla scadenza indicata e risulta Cessato dal giorno successivo.')
                                    ->afterStateUpdated(function (Get $get, Set $set, mixed $state) use ($editing): void {
                                        if ($state) {
                                            if (! $editing) {
                                                self::syncSuggestedContractualTerms($get, $set, $get('conditions'));
                                            }

                                            return;
                                        }

                                        $set('renewal_duration_months', null);
                                    })
                                    ->dehydratedWhenHidden()
                                    ->visible(fn (Get $get): bool => $get('duration_type') === 'fixed'),
                                TextInput::make('notice_days')->dehydratedWhenHidden()->label('Preavviso di Disdetta')->numeric()->minValue(0)
                                    ->suffix('giorni')
                                    ->helperText('Opzionale, in giorni di calendario. Non è una scadenza di pagamento.')
                                    ->visible(fn (Get $get): bool => $get('duration_type') === 'fixed'),
                            ]),
                        ])
                        ->columnSpanFull(),
                ])->columnSpanFull(),
            Section::make('Avanzate')
                ->description($editing ? 'La classificazione vale per tutto l’Esercizio, compresi gli Effettivi. Gli Esercizi Chiusi non sono modificabili.' : 'Personalizza il Centro di Costo solo negli Esercizi che fanno eccezione al valore predefinito.')
                ->schema([
                    Repeater::make('classifications')
                        ->label('Centri di Costo per Esercizio')
                        ->helperText($editing ? 'Sono mostrati i valori esistenti per ogni Esercizio Aperto.' : 'Ogni Esercizio usa il Centro di Costo predefinito, salvo una scelta diversa qui.')
                        ->schema([
                            Select::make('exercise_id')->label('Esercizio')->options(fn (): array => self::company() instanceof Company
                                ? Exercise::query()->whereBelongsTo(self::company(), 'company')->open()->orderBy('year')->pluck('year', 'id')->all()
                                : [])->required()->disabled()->dehydrated()->selectablePlaceholder(false),
                            self::costCenterSelect('cost_center_selection', includeAnnualChoices: true, contract: $contract)
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
                ])->collapsible()->collapsed(! $editing)->columnSpanFull(),
            Section::make('Allegati')->schema([
                ...($contract?->attachments()->attached()->orderBy('id')->get()
                    ->map(fn ($attachment): AttachmentUpload => AttachmentUpload::forStoredAttachment($attachment))->all() ?? []),
                AttachmentUpload::make('attachments')->label($editing ? 'Aggiungi Allegati' : 'Allegati')
                    ->multiple()->storeFiles(false)->visible(! ($contract?->isArchived() ?? false))->columnSpanFull(),
            ])->columnSpanFull(),
            Textarea::make('reason')->label('Motivo della modifica')
                ->helperText('Per titolo e note è obbligatorio dopo un Budget approvato.')
                ->visible($editing && UpdateContract::reasonRequired($contract))
                ->columnSpanFull(),
        ]);
    }

    private static function costCenterSelect(string $name, bool $includeAnnualChoices = false, ?Contract $contract = null): Select
    {
        return Select::make($name)
            ->options(function (Get $get) use ($includeAnnualChoices, $contract): array {
                $options = ($includeAnnualChoices ? [
                    ...($contract === null ? [self::USE_DEFAULT_COST_CENTER => 'Usa il predefinito'] : []),
                    self::NO_COST_CENTER => 'Non classificato',
                ] : []) + self::costCenterOptions();
                $currentId = $contract?->classifications()->where('exercise_id', $get('exercise_id'))->value('cost_center_id');
                if ($currentId !== null && ! array_key_exists($currentId, $options)) {
                    $options += array_intersect_key(CostCenterHierarchy::forCompany($contract->company_id)->options(activeOnly: false), [$currentId => true]);
                }

                return $options;
            })
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
                ->label('Crea Centro di Costo')
                ->modalHeading('Nuovo Centro di Costo')
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
        $durationType = $get($rootPath.'duration_type');
        $durationTypeManuallySelected = (bool) $get($rootPath.'duration_type_manually_selected');

        $validFrom = is_array($latestCondition) ? self::dateString($latestCondition['valid_from'] ?? null) : null;
        $validTo = is_array($latestCondition) ? self::dateString($latestCondition['valid_to'] ?? null) : null;

        if ($validFrom === null || $validTo === null) {
            $expiryWasSuggested = self::dateString($get($rootPath.'next_expiry_date')) === self::dateString($previousExpirySuggestion);
            if ($expiryWasSuggested) {
                $set($rootPath.'next_expiry_date', null);
            }
            if ((string) $get($rootPath.'renewal_duration_months') === (string) $previousDurationSuggestion) {
                $set($rootPath.'renewal_duration_months', null);
            }

            $set($rootPath.'suggested_next_expiry_date', null);
            $set($rootPath.'suggested_renewal_duration_months', null);

            if (! $durationTypeManuallySelected && $durationType === 'fixed' && $expiryWasSuggested) {
                $set($rootPath.'duration_type', 'undefined');
                $set($rootPath.'notice_days', null);
                $set($rootPath.'automatic_renewal', true);
            }

            return;
        }

        if ($durationType !== 'fixed' && $durationTypeManuallySelected) {
            return;
        }

        if ($durationType !== 'fixed') {
            $set($rootPath.'duration_type', 'fixed');
            if ($get($rootPath.'notice_days') === null || $get($rootPath.'notice_days') === '') {
                $set($rootPath.'notice_days', 30);
            }
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
        $date = DateInput::toIso($value);

        return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
            ? $date
            : null;
    }

    private static function company(): ?Company
    {
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;

        return $company instanceof Company ? $company : null;
    }

    /** @return array<int, string> */
    private static function supplierOptions(?Contract $contract = null): array
    {
        $company = self::company();

        return $company instanceof Company
            ? Supplier::query()->whereBelongsTo($company, 'company')->active()->orderBy('legal_name')->pluck('legal_name', 'id')->all() + ($contract === null ? [] : [$contract->supplier_id => $contract->supplier->legal_name])
            : [];
    }

    /** @return array<int, string> */
    private static function costCenterOptions(): array
    {
        $company = self::company();

        return $company instanceof Company
            ? CostCenterHierarchy::forCompany((int) $company->id)->options()
            : [];
    }
}
