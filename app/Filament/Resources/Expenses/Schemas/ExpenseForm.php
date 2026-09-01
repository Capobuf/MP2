<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Actions\MasterData\CreateCostCenter;
use App\Actions\MasterData\CreateSupplier;
use App\Domain\Contracts\ContractActualKind;
use App\Domain\Contracts\ContractState;
use App\Domain\Expenses\Decimal;
use App\Domain\Expenses\ExpenseLineType;
use App\Domain\Expenses\ManualExpenseLine;
use App\Domain\Projects\ProjectActualKind;
use App\Domain\Projects\ProjectExpenseActivity;
use App\Domain\Projects\ProjectOverspend;
use App\Domain\Projects\ProjectOverspendResult;
use App\Domain\Projects\ProjectState;
use App\Filament\Forms\AttachmentUpload;
use App\Filament\Forms\DecimalInput;
use App\Models\Company;
use App\Models\Contract;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\TenantCompany;
use App\Models\User;
use App\Support\ExerciseContext;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ExpenseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Spesa')->schema([
                TextInput::make('description')->label('Descrizione')->required()->maxLength(255),
                Select::make('container')
                    ->label('Contenitore')
                    ->options([
                        'autonomous' => 'Autonoma',
                        'project' => 'Progetto',
                        'contract' => 'Contratto',
                    ])
                    ->default(fn (): string => self::initialContainer())
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                        if ($state === 'autonomous') {
                            $set('project_id', null);
                            $set('contract_id', null);
                        } elseif ($state === 'project') {
                            $set('contract_id', null);
                            $set('direct_cost_center_id', null);
                        } elseif ($state === 'contract') {
                            $set('project_id', null);
                            $set('direct_cost_center_id', null);

                            $lines = array_map(function (mixed $line): mixed {
                                if (is_array($line)) {
                                    $line['type'] = ExpenseLineType::Actual->value;
                                }

                                return $line;
                            }, (array) $get('lines'));

                            $set('lines', $lines);
                        }
                    })
                    ->required(),
                Select::make('project_id')
                    ->label('Progetto')
                    ->options(fn (): array => self::company() instanceof Company
                        ? Project::query()->whereBelongsTo(self::company(), 'company')->active()->orderBy('title')->pluck('title', 'id')->all()
                        : [])
                    ->default(fn (): ?int => request()->integer('project') ?: null)
                    ->searchable()
                    ->live()
                    ->required(fn (Get $get): bool => $get('container') === 'project')
                    ->visible(fn (Get $get): bool => $get('container') === 'project')
                    ->dehydrated(fn (Get $get): bool => $get('container') === 'project'),
                Select::make('contract_id')
                    ->label('Contratto')
                    ->options(fn (): array => self::company() instanceof Company
                        ? Contract::query()->whereBelongsTo(self::company(), 'company')->active()->orderBy('title')->pluck('title', 'id')->all()
                        : [])
                    ->default(fn (): ?int => request()->integer('contract') ?: null)
                    ->searchable()
                    ->live()
                    ->required(fn (Get $get): bool => $get('container') === 'contract')
                    ->visible(fn (Get $get): bool => $get('container') === 'contract')
                    ->dehydrated(fn (Get $get): bool => $get('container') === 'contract'),
                Select::make('supplier_id')
                    ->label('Fornitore')
                    ->options(fn (): array => self::company() instanceof Company
                        ? Supplier::query()->whereBelongsTo(self::company(), 'company')->active()->orderBy('legal_name')->pluck('legal_name', 'id')->all()
                        : [])
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
                    ->visible(fn (Get $get): bool => in_array($get('container'), ['autonomous', 'project'], true))
                    ->dehydrated(fn (Get $get): bool => in_array($get('container'), ['autonomous', 'project'], true))
                    ->placeholder('Nessuno'),
                Select::make('direct_cost_center_id')
                    ->label('Centro di Costo')
                    ->options(fn (): array => self::company() instanceof Company
                        ? CostCenter::query()->whereBelongsTo(self::company(), 'company')->active()->orderBy('name')->pluck('name', 'id')->all()
                        : [])
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
                        ->visible(fn (): bool => self::canCreateCostCenter()))
                    ->placeholder('Non classificata')
                    ->visible(fn (Get $get): bool => $get('container') === 'autonomous')
                    ->dehydrated(fn (Get $get): bool => $get('container') === 'autonomous'),
                Textarea::make('notes')->label('Note')->columnSpanFull(),
                AttachmentUpload::make('attachments')
                    ->label('Allegati')
                    ->multiple()
                    ->storeFiles(false)
                    ->helperText('Opzionali. Potrai aggiungerne altri dalla scheda della Spesa.')
                    ->columnSpanFull(),
                Textarea::make('change_reason')
                    ->label('Motivo della Variazione rispetto al Budget')
                    ->helperText('Richiesto perché l’Esercizio ha già un Budget approvato e la nuova Spesa ha un valore economico diverso da zero.')
                    ->visible(fn (Get $get): bool => self::creationRequiresBudgetReason($get))
                    ->required(fn (Get $get): bool => self::creationRequiresBudgetReason($get))
                    ->dehydrated(fn (Get $get): bool => self::creationRequiresBudgetReason($get))
                    ->columnSpanFull(),
            ])->columns(2)->columnSpanFull(),
            Section::make('Righe')
                ->description('Il Tipo appartiene alla Riga. Il Totale è l’Importo autoritativo; importo unitario e quantità propongono automaticamente il valore, che resta modificabile.')
                ->schema([
                    Repeater::make('lines')
                        ->hiddenLabel()
                        ->schema(self::repeaterLineFields())
                        ->columns(12)
                        ->cloneable()
                        ->reorderable(false)
                        ->minItems(1)
                        ->defaultItems(1)
                        ->addActionLabel('Aggiungi riga')
                        ->columnSpanFull(),
                ])->columnSpanFull(),
            Section::make('Informazioni Aggiuntive')
                ->schema(self::creationActivityFields())
                ->columns(2)
                ->visible(fn (Get $get): bool => self::hasInitialActual($get) && self::hasSelectedContainer($get))
                ->columnSpanFull(),
        ]);
    }

    /** @return array<int, mixed> */
    public static function repeaterLineFields(bool $contractActualOnly = false, bool $preserveIdentity = false): array
    {
        $fields = [
            Select::make('type')->label('Tipo')
                ->options(fn (Get $get): array => $contractActualOnly || $get('../../container') === 'contract'
                    ? [ExpenseLineType::Actual->value => ExpenseLineType::Actual->label()]
                    : ExpenseLineType::options())
                ->default(fn (): ?string => $contractActualOnly || self::initialContainer() === 'contract'
                    ? ExpenseLineType::Actual->value
                    : null)
                ->placeholder('Seleziona')
                ->required()->native(false)->live()
                ->columnSpan(['default' => 12, 'md' => 3, 'xl' => 2]),
            TextInput::make('note')->label('Nota')
                ->columnSpan(['default' => 12, 'md' => 9, 'xl' => 4]),
            Hidden::make('suggested_amount')->dehydrated(false),
            DecimalInput::make('unit_amount', 14, 6)->label('Importo Unitario')->suffix('EUR')->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set): void {
                    self::syncSuggestedAmount($get, $set);
                })
                ->columnSpan(['default' => 6, 'md' => 4, 'xl' => 2]),
            DecimalInput::make('quantity', 14, 6)->label('Quantità')->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set): void {
                    self::syncSuggestedAmount($get, $set);
                })
                ->columnSpan(['default' => 6, 'md' => 3, 'xl' => 1]),
            DecimalInput::make('amount')->label('Totale')->helperText('Importo autoritativo in EUR, netto IVA.')
                ->suffix('EUR')->required()->live(onBlur: true)
                ->columnSpan(['default' => 12, 'md' => 5, 'xl' => 3]),
            Checkbox::make('amount_warning_acknowledged')
                ->label(fn (Get $get): string => self::amountMismatchMessage($get).' Confermo il Totale indicato.')
                ->visible(fn (Get $get): bool => self::hasAmountMismatch($get))
                ->columnSpanFull(),
        ];

        if (! $preserveIdentity) {
            return $fields;
        }

        return [
            Hidden::make('line_id'),
            Hidden::make('unit_of_measure'),
            ...$fields,
        ];
    }

    /** @return array<int, mixed> */
    private static function creationActivityFields(): array
    {
        return [
            Placeholder::make('ordinary_project_activity')
                ->hiddenLabel()
                ->content('Il Progetto è Aperto: l’Effettivo è registrato come ordinario.')
                ->visible(fn (Get $get): bool => self::projectState($get) === ProjectState::Open),
            Placeholder::make('planned_project_activity')
                ->hiddenLabel()
                ->content('Il Progetto è Pianificato. La conferma apre il Progetto insieme alla registrazione dell’Effettivo ordinario.')
                ->visible(fn (Get $get): bool => self::projectState($get) === ProjectState::Planned),
            Placeholder::make('ordinary_contract_activity')
                ->hiddenLabel()
                ->content('Il Contratto è Attivo: l’Effettivo è registrato come ordinario.')
                ->visible(fn (Get $get): bool => self::contractState($get) === ContractState::Active),
            Placeholder::make('planned_contract_activity')
                ->hiddenLabel()
                ->content('Il Contratto è Pianificato: non è possibile registrare un Effettivo ordinario finché non risulta Attivo.')
                ->visible(fn (Get $get): bool => self::contractState($get) === ContractState::Planned),
            Placeholder::make('unavailable_project_activity')
                ->hiddenLabel()
                ->content('Il Progetto non ha ancora uno stato efficace alla data aziendale.')
                ->visible(fn (Get $get): bool => $get('container') === 'project' && filled($get('project_id')) && self::projectState($get) === null),
            Select::make('actual_kind')
                ->label('Tipo di Effettivo')
                ->options(fn (Get $get): array => self::terminalActualOptions($get))
                ->required(fn (Get $get): bool => self::requiresTerminalDeclaration($get))
                ->visible(fn (Get $get): bool => self::requiresTerminalDeclaration($get))
                ->dehydrated(fn (Get $get): bool => self::requiresTerminalDeclaration($get)),
            Checkbox::make('open_project')
                ->label('Confermo l’apertura del Progetto')
                ->accepted()
                ->visible(fn (Get $get): bool => self::projectState($get) === ProjectState::Planned)
                ->dehydrated(fn (Get $get): bool => self::projectState($get) === ProjectState::Planned),
            Textarea::make('activity_note')
                ->label('Motivo')
                ->required(fn (Get $get): bool => self::requiresTerminalDeclaration($get))
                ->visible(fn (Get $get): bool => self::requiresTerminalDeclaration($get))
                ->dehydrated(fn (Get $get): bool => self::requiresTerminalDeclaration($get))
                ->columnSpanFull(),
            Textarea::make('overspend_note')
                ->label('Nota di Sovraspesa')
                ->helperText('Richiesta dal dominio solo se questo Effettivo porta il Progetto oltre lo stanziamento annuale.')
                ->visible(fn (Get $get): bool => self::creationRequiresOverspendNote($get))
                ->required(fn (Get $get): bool => self::creationRequiresOverspendNote($get))
                ->dehydrated(fn (Get $get): bool => self::creationRequiresOverspendNote($get))
                ->columnSpanFull(),
        ];
    }

    /** @return array<int, mixed> */
    public static function lineFields(): array
    {
        return [
            ...self::economicLineFields(),
            ...self::lineNoteFields(),
        ];
    }

    /** @return array<int, mixed> */
    public static function lineFormSections(bool $contractActualOnly = false, bool|\Closure $requiresBudgetReason = false): array
    {
        return [
            Section::make('Valore Economico')->description('Il Totale è l’Importo autoritativo; importo unitario e quantità propongono automaticamente il valore, che resta modificabile.')
                ->schema(self::economicLineFields($contractActualOnly))->columns(4),
            Section::make('Nota e Verifica')->schema(self::lineNoteFields($requiresBudgetReason)),
        ];
    }

    public static function projectActivitySection(bool $visible): Section
    {
        return self::containerActivitySection($visible, false);
    }

    public static function containerActivitySection(bool $project, bool $contract): Section
    {
        return Section::make('Dichiarazione Attività del Contenitore')
            ->description('Richiesta quando la Riga Effettivo dipende dallo stato del Progetto o del Contratto.')
            ->schema(self::containerActivityFields($project, $contract))
            ->columns(2)
            ->visible($project || $contract);
    }

    /** @return array<int, mixed> */
    public static function projectActivityFields(bool $visible): array
    {
        return self::containerActivityFields($visible, false);
    }

    /** @return array<int, mixed> */
    public static function containerActivityFields(bool $project, bool $contract): array
    {
        return [
            Select::make('actual_kind')
                ->label('Dichiarazione Effettivo')
                ->options($contract ? ContractActualKind::options() : ProjectActualKind::options())
                ->placeholder('Ordinario (predefinito)')
                ->visible($project || $contract),
            Checkbox::make('open_project')
                ->label('Conferma Apertura Atomica se il Progetto è Pianificato')
                ->visible($project),
            Textarea::make('activity_note')
                ->label('Nota Attività Tardiva, Rimborso o Correzione')
                ->visible($project || $contract),
            Textarea::make('overspend_note')
                ->label('Nota di Sovraspesa')
                ->visible($project),
        ];
    }

    /** @return array<int, mixed> */
    private static function economicLineFields(bool $contractActualOnly = false): array
    {
        return [
            Select::make('type')->label('Tipo Riga')
                ->options(fn (Get $get): array => $contractActualOnly || filled($get('../../contract_id'))
                    ? [ExpenseLineType::Actual->value => ExpenseLineType::Actual->label()]
                    : ExpenseLineType::options())
                ->default($contractActualOnly ? ExpenseLineType::Actual->value : null)
                ->required()->native(false)->live(),
            Hidden::make('suggested_amount')->dehydrated(false),
            DecimalInput::make('unit_amount', 14, 6)->label('Importo Unitario')->suffix('EUR')->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set): void {
                    self::syncSuggestedAmount($get, $set);
                }),
            DecimalInput::make('quantity', 14, 6)->label('Quantità')->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set): void {
                    self::syncSuggestedAmount($get, $set);
                }),
            DecimalInput::make('amount')->label('Totale')->helperText('Importo autoritativo in EUR, netto IVA.')
                ->suffix('EUR')->required()->live(onBlur: true),
        ];
    }

    /** @return array<int, mixed> */
    private static function lineNoteFields(bool|\Closure $requiresBudgetReason = false): array
    {
        return [
            Textarea::make('note')->label('Nota')->live(onBlur: true)
                ->helperText('Obbligatoria per un Effettivo negativo e normalmente richiesta per una nuova Riga a zero.'),
            Textarea::make('change_reason')
                ->label('Motivo della Modifica della Stima')
                ->helperText('Richiesto perché l’Esercizio ha già un Budget approvato.')
                ->visible($requiresBudgetReason)
                ->required($requiresBudgetReason)
                ->dehydrated($requiresBudgetReason),
            Checkbox::make('amount_warning_acknowledged')
                ->label('Salva Comunque il Totale Indicato')
                ->helperText(fn (Get $get): string => self::amountMismatchMessage($get))
                ->visible(fn (Get $get): bool => self::hasAmountMismatch($get)),
        ];
    }

    private static function syncSuggestedAmount(Get $get, Set $set): void
    {
        $quantity = self::decimalString($get('quantity'));
        $unitAmount = self::decimalString($get('unit_amount'));
        $previousSuggestion = self::decimalString($get('suggested_amount'));
        $currentAmount = $get('amount');
        $suggested = $quantity === null || $unitAmount === null
            ? null
            : ManualExpenseLine::suggestedAmount($quantity, $unitAmount);

        if ($suggested === null) {
            if (self::amountMatchesSuggestion($currentAmount, $previousSuggestion)) {
                $set('amount', null);
            }

            $set('suggested_amount', null);

            return;
        }

        if (blank($currentAmount) || self::amountMatchesSuggestion($currentAmount, $previousSuggestion)) {
            $set('amount', $suggested);
        }

        $set('suggested_amount', $suggested);
    }

    private static function amountMatchesSuggestion(mixed $amount, ?string $suggestion): bool
    {
        $amount = self::decimalString($amount);

        return $amount !== null
            && $suggestion !== null
            && Decimal::compare($amount, $suggestion, 2) === 0;
    }

    private static function decimalString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $normalized = Decimal::normalizeInput((string) $value);

        return is_string($normalized) && preg_match('/^-?\d+(?:\.\d+)?$/', $normalized) === 1
            ? $normalized
            : null;
    }

    private static function hasAmountMismatch(Get $get): bool
    {
        $quantity = self::decimalString($get('quantity'));
        $unitAmount = self::decimalString($get('unit_amount'));
        $amount = self::decimalString($get('amount'));

        return $quantity !== null
            && $unitAmount !== null
            && $amount !== null
            && ManualExpenseLine::hasAmountMismatch($quantity, $unitAmount, $amount);
    }

    private static function amountMismatchMessage(Get $get): string
    {
        $quantity = self::decimalString($get('quantity'));
        $unitAmount = self::decimalString($get('unit_amount'));
        $amount = self::decimalString($get('amount'));
        if ($quantity === null || $unitAmount === null || $amount === null) {
            return '';
        }

        $suggested = ManualExpenseLine::suggestedAmount($quantity, $unitAmount);

        return "Quantità × importo unitario suggerisce € {$suggested}; l’Importo autoritativo indicato è € {$amount}.";
    }

    private static function initialContainer(): string
    {
        if (request()->integer('project') > 0) {
            return 'project';
        }

        if (request()->integer('contract') > 0) {
            return 'contract';
        }

        return 'autonomous';
    }

    private static function hasInitialActual(Get $get): bool
    {
        foreach ((array) $get('lines') as $line) {
            if (is_array($line) && ($line['type'] ?? null) === ExpenseLineType::Actual->value) {
                return true;
            }
        }

        return false;
    }

    private static function creationRequiresBudgetReason(Get $get): bool
    {
        $exercise = self::currentExercise();
        if (! $exercise?->hasApprovedBudget()) {
            return false;
        }

        $totals = self::creationLineTotals($get);

        return Decimal::compare($totals['estimate'], '0.00') !== 0
            || Decimal::compare($totals['actual'], '0.00') !== 0;
    }

    private static function creationRequiresOverspendNote(Get $get): bool
    {
        $company = self::company();
        $exercise = self::currentExercise();
        if (! $company instanceof Company
            || ! $company->overspend_note_required
            || ! $exercise instanceof Exercise
            || $get('container') !== 'project'
            || blank($get('project_id'))) {
            return false;
        }

        $project = Project::query()
            ->whereBelongsTo($company, 'company')
            ->active()
            ->find((int) $get('project_id'));
        if (! $project instanceof Project) {
            return false;
        }

        $totals = self::creationLineTotals($get);
        $before = ProjectExpenseActivity::annualVariance($project, $exercise);
        $after = Decimal::add($before, Decimal::subtract($totals['actual'], $totals['estimate']));

        return ProjectOverspend::detect($before, $after) !== ProjectOverspendResult::None;
    }

    /** @return array{estimate: string, actual: string} */
    private static function creationLineTotals(Get $get): array
    {
        $estimates = [];
        $actuals = [];
        foreach ((array) $get('lines') as $line) {
            if (! is_array($line)) {
                continue;
            }
            $amount = self::decimalString($line['amount'] ?? null);
            if ($amount === null) {
                continue;
            }
            if (($line['type'] ?? null) === ExpenseLineType::Estimate->value) {
                $estimates[] = $amount;
            } elseif (($line['type'] ?? null) === ExpenseLineType::Actual->value) {
                $actuals[] = $amount;
            }
        }

        return ['estimate' => Decimal::sum($estimates), 'actual' => Decimal::sum($actuals)];
    }

    private static function currentExercise(): ?Exercise
    {
        $company = self::company();

        return $company instanceof Company
            ? app(ExerciseContext::class)->current($company)
            : null;
    }

    private static function hasSelectedContainer(Get $get): bool
    {
        return ($get('container') === 'project' && filled($get('project_id')))
            || ($get('container') === 'contract' && filled($get('contract_id')));
    }

    private static function projectState(Get $get): ?ProjectState
    {
        $company = self::company();
        if ($get('container') !== 'project' || ! $company instanceof Company || blank($get('project_id'))) {
            return null;
        }

        $project = Project::query()
            ->whereBelongsTo($company, 'company')
            ->active()
            ->find((int) $get('project_id'));

        return $project?->stateAtDate(now($company->timezone)->toDateString());
    }

    private static function contractState(Get $get): ?ContractState
    {
        $company = self::company();
        if ($get('container') !== 'contract' || ! $company instanceof Company || blank($get('contract_id'))) {
            return null;
        }

        $contract = Contract::query()
            ->whereBelongsTo($company, 'company')
            ->active()
            ->find((int) $get('contract_id'));

        return $contract?->stateAtDate(now($company->timezone)->toDateString());
    }

    private static function requiresTerminalDeclaration(Get $get): bool
    {
        return in_array(self::projectState($get), [ProjectState::Closed, ProjectState::Cancelled], true)
            || in_array(self::contractState($get), [ContractState::Cessated, ContractState::Cancelled], true);
    }

    /** @return array<string, string> */
    private static function terminalActualOptions(Get $get): array
    {
        $options = $get('container') === 'contract'
            ? ContractActualKind::options()
            : ProjectActualKind::options();

        unset($options[ContractActualKind::Ordinary->value]);

        return $options;
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

    private static function company(): ?Company
    {
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;

        return $company instanceof Company ? $company : null;
    }
}
