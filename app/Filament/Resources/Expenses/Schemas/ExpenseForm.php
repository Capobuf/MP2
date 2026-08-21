<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Actions\MasterData\CreateCostCenter;
use App\Actions\MasterData\CreateSupplier;
use App\Domain\Company\Capability;
use App\Domain\Contracts\ContractActualKind;
use App\Domain\Contracts\ContractState;
use App\Domain\Expenses\ExpenseLineType;
use App\Domain\Expenses\ManualExpenseLine;
use App\Domain\Projects\ProjectActualKind;
use App\Domain\Projects\ProjectState;
use App\Models\Company;
use App\Models\Contract;
use App\Models\CostCenter;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
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
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                        if ($state === 'autonomous') {
                            $set('project_id', null);
                            $set('contract_id', null);
                        } elseif ($state === 'project') {
                            $set('contract_id', null);
                            $set('direct_cost_center_id', null);
                        } elseif ($state === 'contract') {
                            $set('project_id', null);
                            $set('direct_cost_center_id', null);
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
                        ->label('Crea centro di costo')
                        ->modalHeading('Nuovo centro di costo')
                        ->visible(fn (): bool => self::canManageMasterData()))
                    ->placeholder('Non classificata')
                    ->visible(fn (Get $get): bool => $get('container') === 'autonomous')
                    ->dehydrated(fn (Get $get): bool => $get('container') === 'autonomous'),
                Textarea::make('notes')->label('Note')->columnSpanFull(),
            ])->columns(2)->columnSpanFull(),
            Section::make('Righe')
                ->description('Il Tipo appartiene alla Riga. L’Importo è il valore autoritativo; quantità e prezzo unitario sono informazioni di supporto.')
                ->schema([
                    Repeater::make('lines')
                        ->hiddenLabel()
                        ->schema(self::creationLineFields())
                        ->columns(12)
                        ->reorderable(false)
                        ->minItems(1)
                        ->defaultItems(1)
                        ->addActionLabel('Aggiungi riga')
                        ->columnSpanFull(),
                ])->columnSpanFull(),
            Section::make('Informazioni aggiuntive')
                ->schema(self::creationActivityFields())
                ->columns(2)
                ->visible(fn (Get $get): bool => self::hasInitialActual($get) && self::hasSelectedContainer($get))
                ->columnSpanFull(),
        ]);
    }

    /** @return array<int, mixed> */
    private static function creationLineFields(): array
    {
        return [
            Select::make('type')->label('Tipo')
                ->options(ExpenseLineType::options())
                ->placeholder('Seleziona')
                ->required()->native(false)->live()
                ->columnSpan(['default' => 12, 'md' => 3, 'xl' => 2]),
            TextInput::make('note')->label('Nota')
                ->columnSpan(['default' => 12, 'md' => 9, 'xl' => 4]),
            TextInput::make('quantity')->label('Q.tà')->inputMode('decimal')->regex('/^-?\d{1,14}(\.\d{1,6})?$/')->live()
                ->columnSpan(['default' => 6, 'md' => 3, 'xl' => 1]),
            TextInput::make('unit_amount')->label('Prezzo unit.')->suffix('EUR')->inputMode('decimal')
                ->regex('/^-?\d{1,14}(\.\d{1,6})?$/')->live()
                ->columnSpan(['default' => 6, 'md' => 3, 'xl' => 2]),
            TextInput::make('unit_of_measure')->label('U.M.')->maxLength(64)
                ->columnSpan(['default' => 6, 'md' => 3, 'xl' => 1]),
            TextInput::make('amount')->label('Importo')->suffix('EUR')->inputMode('decimal')
                ->required()->regex('/^-?\d{1,17}(\.\d{1,2})?$/')->live()
                ->columnSpan(['default' => 6, 'md' => 3, 'xl' => 2]),
            Checkbox::make('amount_warning_acknowledged')
                ->label(fn (Get $get): string => self::amountMismatchMessage($get).' Confermo l’Importo indicato.')
                ->visible(fn (Get $get): bool => self::hasAmountMismatch($get))
                ->columnSpanFull(),
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
                ->label('Nota di sovraspesa')
                ->helperText('Richiesta dal dominio solo se questo Effettivo porta il Progetto oltre lo stanziamento annuale.')
                ->visible(fn (Get $get): bool => $get('container') === 'project')
                ->dehydrated(fn (Get $get): bool => $get('container') === 'project')
                ->columnSpanFull(),
        ];
    }

    /** @return array<int, mixed> */
    public static function lineFields(): array
    {
        return [
            ...self::economicLineFields(),
            ...self::descriptiveLineFields(),
            ...self::lineNoteFields(),
        ];
    }

    /** @return array<int, mixed> */
    public static function lineFormSections(bool $contractActualOnly = false): array
    {
        return [
            Section::make('Valore economico')->description('Il Tipo appartiene alla Riga. L’Importo resta il valore autoritativo.')
                ->schema(self::economicLineFields($contractActualOnly))->columns(2),
            Section::make('Dettagli descrittivi')->description('Quantità e unitario aiutano la lettura ma non sostituiscono l’Importo.')
                ->schema(self::descriptiveLineFields())->columns(3),
            Section::make('Nota e verifica')->schema(self::lineNoteFields()),
        ];
    }

    public static function projectActivitySection(bool $visible): Section
    {
        return self::containerActivitySection($visible, false);
    }

    public static function containerActivitySection(bool $project, bool $contract): Section
    {
        return Section::make('Dichiarazione attività del contenitore')
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
                ->label('Conferma apertura atomica se il Progetto è Pianificato')
                ->visible($project),
            Textarea::make('activity_note')
                ->label('Nota attività tardiva, rimborso o correzione')
                ->visible($project || $contract),
            Textarea::make('overspend_note')
                ->label('Nota di sovraspesa')
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
            TextInput::make('amount')->label('Importo')->helperText('Valore autoritativo in EUR, netto IVA.')
                ->suffix('EUR')->inputMode('decimal')->required()->regex('/^-?\d{1,17}(\.\d{1,2})?$/')->live(),
        ];
    }

    /** @return array<int, mixed> */
    private static function descriptiveLineFields(): array
    {
        return [
            TextInput::make('quantity')->label('Quantità')->inputMode('decimal')->regex('/^-?\d{1,14}(\.\d{1,6})?$/')->live(),
            TextInput::make('unit_amount')->label('Importo unitario')->suffix('EUR')->inputMode('decimal')
                ->regex('/^-?\d{1,14}(\.\d{1,6})?$/')->live(),
            TextInput::make('unit_of_measure')->label('Unità di misura')->maxLength(64),
        ];
    }

    /** @return array<int, mixed> */
    private static function lineNoteFields(): array
    {
        return [
            Textarea::make('note')->label('Nota')
                ->helperText('Obbligatoria per un Effettivo negativo e normalmente richiesta per una nuova Riga a zero.'),
            Checkbox::make('amount_warning_acknowledged')
                ->label('Salva comunque l’Importo indicato')
                ->helperText(fn (Get $get): string => self::amountMismatchMessage($get))
                ->visible(fn (Get $get): bool => self::hasAmountMismatch($get)),
        ];
    }

    private static function hasAmountMismatch(Get $get): bool
    {
        $quantity = is_string($get('quantity')) ? $get('quantity') : null;
        $unitAmount = is_string($get('unit_amount')) ? $get('unit_amount') : null;
        $amount = is_string($get('amount')) ? $get('amount') : null;

        return $amount !== null && ManualExpenseLine::hasAmountMismatch($quantity, $unitAmount, $amount);
    }

    private static function amountMismatchMessage(Get $get): string
    {
        $quantity = is_string($get('quantity')) ? $get('quantity') : null;
        $unitAmount = is_string($get('unit_amount')) ? $get('unit_amount') : null;
        $amount = is_string($get('amount')) ? $get('amount') : null;
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
}
