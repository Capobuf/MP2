<?php

namespace App\Filament\Resources\Expenses;

use App\Actions\Operations\SetExpenseReversed;
use App\Domain\Company\Capability;
use App\Domain\Contracts\ContractActualKind;
use App\Domain\Contracts\ContractState;
use App\Domain\Expenses\Decimal;
use App\Domain\Expenses\ExpenseLineType;
use App\Domain\Projects\ProjectActualKind;
use App\Domain\Projects\ProjectExpenseActivity;
use App\Domain\Projects\ProjectOverspend;
use App\Domain\Projects\ProjectOverspendResult;
use App\Domain\Projects\ProjectState;
use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Expenses\Pages\ViewExpense;
use App\Filament\Resources\Expenses\RelationManagers\ExpenseAttachmentsRelationManager;
use App\Filament\Resources\Expenses\Schemas\ExpenseForm;
use App\Filament\Resources\Expenses\Schemas\ExpenseInfolist;
use App\Filament\Resources\Expenses\Tables\ExpensesTable;
use App\Filament\Resources\Expenses\Widgets\ExpenseOverview;
use App\Filament\Support\ProjectOverspendNotifier;
use App\Models\Company;
use App\Models\Expense;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** @extends resource<Expense> */
class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Spese';

    protected static string|\UnitEnum|null $navigationGroup = 'Operatività';

    protected static ?string $modelLabel = 'spesa autonoma';

    protected static ?string $pluralModelLabel = 'spese';

    protected static ?string $recordTitleAttribute = 'description';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return ExpenseForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ExpenseInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExpensesTable::configure($table);
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $company = Filament::getTenant();

        return $user instanceof User && $company instanceof Company
            && $user->hasCapability($company, Capability::View);
    }

    /** @return Builder<Expense> */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $company = Filament::getTenant();

        return $company instanceof Company
            ? $query->whereBelongsTo($company, 'company')->with([
                'exercise', 'supplier', 'directCostCenter', 'project.classifications.costCenter',
                'contract.classifications.costCenter', 'lines',
            ])
            : $query->whereRaw('1 = 0');
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();
        $company = Filament::getTenant();

        return $user instanceof User && $company instanceof Company
            && $user->hasCapability($company, Capability::ManageOperations);
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof Expense && auth()->user() instanceof User
            && auth()->user()->can('view', $record);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof Expense && $record->origin !== 'system' && auth()->user() instanceof User
            && auth()->user()->can('update', $record);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenses::route('/'),
            'create' => CreateExpense::route('/create'),
            'view' => ViewExpense::route('/{record}'),
            'edit' => EditExpense::route('/{record}/edit'),
        ];
    }

    public static function getRelations(): array
    {
        return [ExpenseAttachmentsRelationManager::class];
    }

    public static function getWidgets(): array
    {
        return [ExpenseOverview::class];
    }

    public static function reverseAction(): Action
    {
        return Action::make('reverse')
            ->label('Storna')
            ->color('warning')
            ->modalHeading('Storna la Spesa')
            ->modalDescription(fn (Expense $record): string => "Saranno esclusi € {$record->allocation()} di Allocato e € {$record->actual()} di Effettivo.")
            ->modalSubmitActionLabel('Storna')
            ->visible(fn (Expense $record): bool => ! $record->isReversed() && static::canEdit($record))
            ->disabled(fn (Expense $record): bool => $record->hasActuals())
            ->tooltip(fn (Expense $record): ?string => $record->hasActuals() ? 'La Spesa contiene Effettivi attivi non nulli.' : null)
            ->form([
                Textarea::make('reason')->label('Motivo')->required(),
                Textarea::make('overspend_note')->label('Nota di sovraspesa')
                    ->visible(fn (Expense $record): bool => self::reversalRequiresOverspendNote($record, true))
                    ->required(fn (Expense $record): bool => self::reversalRequiresOverspendNote($record, true)),
                Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
            ])
            ->action(fn (Expense $record, array $data) => self::setReversed($record, true, $data));
    }

    public static function restoreAction(): Action
    {
        return Action::make('restore')
            ->label('Ripristina')
            ->color('success')
            ->modalHeading('Ripristina la Spesa')
            ->modalDescription(fn (Expense $record): string => 'Le Righe attive torneranno nei totali dell’Esercizio.')
            ->modalSubmitActionLabel('Ripristina')
            ->visible(fn (Expense $record): bool => $record->isReversed() && static::canEdit($record))
            ->form([
                Textarea::make('reason')->label('Motivo del ripristino')
                    ->visible(fn (Expense $record): bool => $record->exercise->hasApprovedBudget())
                    ->required(fn (Expense $record): bool => $record->exercise->hasApprovedBudget()),
                Select::make('actual_kind')->label('Tipo di Effettivo')
                    ->options(fn (Expense $record): array => self::terminalActualOptions($record))
                    ->visible(fn (Expense $record): bool => self::restoreRequiresTerminalDeclaration($record))
                    ->required(fn (Expense $record): bool => self::restoreRequiresTerminalDeclaration($record)),
                Checkbox::make('open_project')->label('Conferma apertura atomica se il Progetto è Pianificato')
                    ->visible(fn (Expense $record): bool => self::restoreRequiresProjectOpening($record))
                    ->required(fn (Expense $record): bool => self::restoreRequiresProjectOpening($record)),
                Textarea::make('activity_note')->label('Nota attività tardiva, rimborso o correzione')
                    ->visible(fn (Expense $record): bool => self::restoreRequiresTerminalDeclaration($record))
                    ->required(fn (Expense $record): bool => self::restoreRequiresTerminalDeclaration($record)),
                Textarea::make('overspend_note')->label('Nota di sovraspesa')
                    ->visible(fn (Expense $record): bool => self::reversalRequiresOverspendNote($record, false))
                    ->required(fn (Expense $record): bool => self::reversalRequiresOverspendNote($record, false)),
                Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
            ])
            ->action(fn (Expense $record, array $data) => self::setReversed($record, false, $data));
    }

    /** @param array<string, mixed> $data */
    private static function setReversed(Expense $expense, bool $reversed, array $data): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        app(SetExpenseReversed::class)->execute(
            $actor,
            $expense,
            $reversed,
            isset($data['reason']) ? (string) $data['reason'] : null,
            (string) $data['operation_id'],
            $data,
        );
        ProjectOverspendNotifier::sendForOperation((string) $data['operation_id']);
        $expense->refresh();
    }

    private static function reversalRequiresOverspendNote(Expense $expense, bool $reversed): bool
    {
        if (! $expense->company->overspend_note_required || $expense->project === null) {
            return false;
        }

        $before = ProjectExpenseActivity::annualVariance($expense->project, $expense->exercise);
        $allocation = Decimal::sum($expense->lines()
            ->active()
            ->where('type', ExpenseLineType::Estimate->value)
            ->pluck('amount')
            ->map(fn (mixed $amount): string => (string) $amount));
        $actual = Decimal::sum($expense->lines()
            ->active()
            ->where('type', ExpenseLineType::Actual->value)
            ->pluck('amount')
            ->map(fn (mixed $amount): string => (string) $amount));
        $contribution = Decimal::subtract($actual, $allocation);
        $after = $reversed
            ? Decimal::subtract($before, $contribution)
            : Decimal::add($before, $contribution);

        return ProjectOverspend::detect($before, $after) !== ProjectOverspendResult::None;
    }

    private static function restoreRequiresTerminalDeclaration(Expense $expense): bool
    {
        if (! $expense->hasActuals()) {
            return false;
        }

        $today = now($expense->company->timezone)->toDateString();

        return ($expense->project !== null && in_array($expense->project->stateAtDate($today), [ProjectState::Closed, ProjectState::Cancelled], true))
            || ($expense->contract !== null && in_array($expense->contract->stateAtDate($today), [ContractState::Cessated, ContractState::Cancelled], true));
    }

    private static function restoreRequiresProjectOpening(Expense $expense): bool
    {
        return $expense->hasActuals()
            && $expense->project !== null
            && $expense->project->stateAtDate(now($expense->company->timezone)->toDateString()) === ProjectState::Planned;
    }

    /** @return array<string, string> */
    private static function terminalActualOptions(Expense $expense): array
    {
        $options = $expense->contract_id !== null ? ContractActualKind::options() : ProjectActualKind::options();
        unset($options[ProjectActualKind::Ordinary->value]);

        return $options;
    }
}
