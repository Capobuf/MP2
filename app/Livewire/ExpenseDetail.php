<?php

namespace App\Livewire;

use App\Actions\Operations\CreateExpenseLine;
use App\Actions\Operations\SetExpenseLineActive;
use App\Actions\Operations\UpdateExpenseLine;
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
use App\Filament\Pages\CompanyAudit;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Schemas\ExpenseForm;
use App\Filament\Support\ProjectOverspendNotifier;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class ExpenseDetail extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    #[Locked]
    public ?int $expenseId = null;

    #[Locked]
    public bool $compact = true;

    public function mount(?int $expenseId, bool $compact = true): void
    {
        $this->expenseId = $expenseId;
        $this->compact = $compact;

        if ($this->expenseId !== null) {
            $this->expense();
        }
    }

    #[On('show-expense-detail')]
    public function showExpense(?int $expenseId): void
    {
        abort_unless($this->compact, 403);

        unset($this->expense);
        $this->expenseId = $expenseId;

        if ($this->expenseId !== null) {
            $this->expense();
        }
    }

    public function close(): void
    {
        abort_unless($this->compact, 403);

        unset($this->expense);
        $this->expenseId = null;
        $this->dispatch('close-expense-detail');
    }

    #[Computed]
    public function expense(): Expense
    {
        $company = Filament::getTenant();
        abort_unless($company instanceof Company && $this->expenseId !== null, 404);

        $expense = Expense::query()
            ->whereBelongsTo($company, 'company')
            ->with([
                'exercise',
                'supplier',
                'directCostCenter',
                'project.classifications.costCenter',
                'contract.classifications.costCenter',
                'lines' => fn ($query) => $query->orderBy('id'),
            ])
            ->findOrFail($this->expenseId);

        abort_unless(ExpenseResource::canView($expense), 403);

        return $expense;
    }

    public function addLineAction(): Action
    {
        return Action::make('addLine')
            ->label('Aggiungi riga')
            ->icon('heroicon-m-plus')
            ->color('primary')
            ->modalHeading('Aggiungi riga')
            ->modalDescription('Aggiungi una Stima o un Effettivo. L’Importo resta il valore autoritativo.')
            ->modalSubmitActionLabel('Aggiungi riga')
            ->modalCancelActionLabel('Annulla')
            ->schema([
                ...ExpenseForm::lineFormSections(requiresBudgetReason: fn (Get $get): bool => $this->lineEstimateReasonRequired($get)),
                $this->lineActivitySection(),
                Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
            ])
            ->visible(fn (): bool => $this->canMutate())
            ->action(function (array $data): void {
                $actor = $this->actor();
                $operationId = (string) $data['operation_id'];
                unset($data['operation_id']);

                app(CreateExpenseLine::class)->execute($actor, $this->expense(), $data, $operationId);
                ProjectOverspendNotifier::sendForOperation($operationId);
                $this->afterMutation('Riga aggiunta.');
            });
    }

    public function editLineAction(): Action
    {
        return Action::make('editLine')
            ->label('Modifica')
            ->icon('heroicon-m-pencil-square')
            ->color('gray')
            ->modalHeading('Modifica riga')
            ->modalDescription('La modifica conserva l’identità della Riga e viene registrata nella Timeline.')
            ->modalSubmitActionLabel('Salva modifica')
            ->modalCancelActionLabel('Annulla')
            ->schema([
                ...ExpenseForm::lineFormSections(requiresBudgetReason: fn (Get $get): bool => $this->lineEstimateReasonRequired($get)),
                $this->lineActivitySection(),
                Hidden::make('line_id')->dehydrated(false),
                Hidden::make('operation_id'),
            ])
            ->fillForm(function (array $arguments): array {
                $line = $this->line((int) $arguments['line']);

                return [
                    'type' => $line->lineType()->value,
                    'amount' => (string) $line->amount,
                    'quantity' => $line->getRawOriginal('quantity'),
                    'unit_amount' => $line->getRawOriginal('unit_amount'),
                    'unit_of_measure' => $line->unit_of_measure,
                    'note' => $line->note,
                    'line_id' => $line->id,
                    'operation_id' => (string) Str::uuid(),
                ];
            })
            ->visible(fn (): bool => $this->canMutate())
            ->action(function (array $data, array $arguments): void {
                $actor = $this->actor();
                $operationId = (string) $data['operation_id'];
                unset($data['operation_id']);

                app(UpdateExpenseLine::class)->execute(
                    $actor,
                    $this->line((int) $arguments['line']),
                    $data,
                    $operationId,
                );
                ProjectOverspendNotifier::sendForOperation($operationId);
                $this->afterMutation('Riga aggiornata.');
            });
    }

    public function annulLineAction(): Action
    {
        return Action::make('annulLine')
            ->label('Annulla')
            ->icon('heroicon-m-no-symbol')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Annulla riga')
            ->modalDescription('La Riga sarà esclusa dai totali correnti, senza essere eliminata.')
            ->modalSubmitActionLabel('Annulla riga')
            ->schema([
                Textarea::make('change_reason')
                    ->label('Motivo della modifica della Stima')
                    ->visible(fn (Get $get): bool => $this->lineEstimateReasonRequired($get))
                    ->required(fn (Get $get): bool => $this->lineEstimateReasonRequired($get))
                    ->dehydrated(fn (Get $get): bool => $this->lineEstimateReasonRequired($get)),
                $this->lineActivitySection(),
                Hidden::make('line_id')->dehydrated(false),
                Hidden::make('type')->dehydrated(false),
                Hidden::make('amount')->dehydrated(false),
                Hidden::make('mutation_mode')->default('annul')->dehydrated(false),
                Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
            ])
            ->visible(fn (): bool => $this->canMutate())
            ->fillForm(fn (array $arguments): array => $this->lineMutationState((int) $arguments['line'], 'annul'))
            ->action(fn (array $data, array $arguments) => $this->setLineActive($data, $arguments, false));
    }

    public function restoreLineAction(): Action
    {
        return Action::make('restoreLine')
            ->label('Ripristina')
            ->icon('heroicon-m-arrow-uturn-left')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Ripristina riga')
            ->modalDescription('La Riga tornerà a contribuire ai totali correnti.')
            ->modalSubmitActionLabel('Ripristina riga')
            ->schema([
                Textarea::make('change_reason')
                    ->label('Motivo della modifica della Stima')
                    ->visible(fn (Get $get): bool => $this->lineEstimateReasonRequired($get))
                    ->required(fn (Get $get): bool => $this->lineEstimateReasonRequired($get))
                    ->dehydrated(fn (Get $get): bool => $this->lineEstimateReasonRequired($get)),
                $this->lineActivitySection(),
                Hidden::make('line_id')->dehydrated(false),
                Hidden::make('type')->dehydrated(false),
                Hidden::make('amount')->dehydrated(false),
                Hidden::make('mutation_mode')->default('restore')->dehydrated(false),
                Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
            ])
            ->visible(fn (): bool => $this->canMutate())
            ->fillForm(fn (array $arguments): array => $this->lineMutationState((int) $arguments['line'], 'restore'))
            ->action(fn (array $data, array $arguments) => $this->setLineActive($data, $arguments, true));
    }

    public function editExpenseAction(): Action
    {
        return Action::make('editExpense')
            ->label('Modifica')
            ->icon('heroicon-m-pencil-square')
            ->color('primary')
            ->url(fn (): string => ExpenseResource::getUrl('edit', ['record' => $this->expense()]));
    }

    public function reverseExpenseAction(): Action
    {
        return ExpenseResource::reverseAction()
            ->record(fn (): Expense => $this->expense())
            ->after(fn () => $this->afterMutation('Spesa stornata.'));
    }

    public function restoreExpenseAction(): Action
    {
        return ExpenseResource::restoreAction()
            ->record(fn (): Expense => $this->expense())
            ->after(fn () => $this->afterMutation('Spesa ripristinata.'));
    }

    /** @return Collection<int, AuditEvent> */
    public function recentEvents(): Collection
    {
        $expense = $this->expense();

        return AuditEvent::query()
            ->where('company_id', $expense->company_id)
            ->where(function (Builder $query) use ($expense): void {
                $query->where(function (Builder $expenseEvent) use ($expense): void {
                    $expenseEvent->where('subject_type', Expense::class)
                        ->where('subject_id', $expense->id);
                })->orWhere(function (Builder $reference) use ($expense): void {
                    $reference->where('reference_type', Expense::class)
                        ->where('reference_id', $expense->id);
                })->orWhere(function (Builder $lineEvent) use ($expense): void {
                    $lineEvent->where('subject_type', ExpenseLine::class)
                        ->whereIn('subject_id', $expense->lines()->select('expense_lines.id'));
                });
            })
            ->with('actor')
            ->latest('created_at')
            ->latest('id')
            ->limit(5)
            ->get();
    }

    public function timelineTone(AuditEvent $event): string
    {
        $type = $event->getRawOriginal('event_type');

        return match (true) {
            str_contains($type, 'annul'), str_contains($type, 'revers') => 'danger',
            str_contains($type, 'restor'), str_contains($type, 'approv') => 'success',
            str_contains($type, 'move'), str_contains($type, 'reclass') => 'warning',
            str_contains($type, 'creat'), str_contains($type, 'updat') => 'info',
            default => 'neutral',
        };
    }

    public function money(string $amount): string
    {
        return Number::currency((float) $amount, in: 'EUR', locale: 'it');
    }

    public function quantity(string $quantity): string
    {
        return (string) Number::format((float) $quantity, maxPrecision: 6, locale: 'it');
    }

    public function fullDetailUrl(): string
    {
        return ExpenseResource::getUrl('view', ['record' => $this->expense()]);
    }

    public function timelineUrl(): string
    {
        $expense = $this->expense();

        return CompanyAudit::getUrl([
            'tenant' => $expense->company,
            'expense' => $expense->id,
        ]);
    }

    public function render(): View
    {
        if ($this->expenseId === null) {
            return view('livewire.expense-detail-empty');
        }

        return view('livewire.expense-detail', [
            'expense' => $this->expense(),
            'events' => $this->recentEvents(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $arguments
     */
    private function setLineActive(array $data, array $arguments, bool $active): void
    {
        $operationId = (string) $data['operation_id'];
        unset($data['operation_id']);

        app(SetExpenseLineActive::class)->execute(
            $this->actor(),
            $this->line((int) $arguments['line']),
            $active,
            $operationId,
            $data,
        );
        ProjectOverspendNotifier::sendForOperation($operationId);
        $this->afterMutation($active ? 'Riga ripristinata.' : 'Riga annullata.');
    }

    private function line(int $lineId): ExpenseLine
    {
        return $this->expense()->lines()->findOrFail($lineId);
    }

    private function lineActivitySection(): Section
    {
        return Section::make('Dichiarazioni richieste')
            ->description('Sono mostrate soltanto le dichiarazioni necessarie per la Riga corrente.')
            ->schema([
                Select::make('actual_kind')
                    ->label('Tipo di Effettivo')
                    ->options(fn (): array => $this->expense()->contract_id !== null
                        ? $this->terminalOptions(ContractActualKind::options())
                        : $this->terminalOptions(ProjectActualKind::options()))
                    ->visible(fn (Get $get): bool => $this->lineRequiresTerminalDeclaration($get))
                    ->required(fn (Get $get): bool => $this->lineRequiresTerminalDeclaration($get))
                    ->dehydrated(fn (Get $get): bool => $this->lineRequiresTerminalDeclaration($get)),
                Checkbox::make('open_project')
                    ->label('Confermo l’apertura del Progetto')
                    ->accepted()
                    ->visible(fn (Get $get): bool => $this->lineRequiresProjectOpening($get))
                    ->required(fn (Get $get): bool => $this->lineRequiresProjectOpening($get))
                    ->dehydrated(fn (Get $get): bool => $this->lineRequiresProjectOpening($get)),
                Textarea::make('activity_note')
                    ->label('Motivo dell’attività tardiva o correttiva')
                    ->visible(fn (Get $get): bool => $this->lineRequiresTerminalDeclaration($get))
                    ->required(fn (Get $get): bool => $this->lineRequiresTerminalDeclaration($get))
                    ->dehydrated(fn (Get $get): bool => $this->lineRequiresTerminalDeclaration($get)),
                Textarea::make('overspend_note')
                    ->label('Nota di sovraspesa')
                    ->visible(fn (Get $get): bool => $this->lineRequiresOverspendNote($get))
                    ->required(fn (Get $get): bool => $this->lineRequiresOverspendNote($get))
                    ->dehydrated(fn (Get $get): bool => $this->lineRequiresOverspendNote($get)),
            ])
            ->columns(2)
            ->visible(fn (Get $get): bool => $this->lineRequiresTerminalDeclaration($get)
                || $this->lineRequiresProjectOpening($get)
                || $this->lineRequiresOverspendNote($get));
    }

    private function lineEstimateReasonRequired(Get $get): bool
    {
        if (! $this->expense()->exercise->hasApprovedBudget()) {
            return false;
        }

        $newType = ExpenseLineType::tryFrom((string) $get('type'));
        $lineId = filter_var($get('line_id'), FILTER_VALIDATE_INT);
        if (! is_int($lineId)) {
            return $newType === ExpenseLineType::Estimate;
        }

        $current = $this->line($lineId);
        $currentType = $current->lineType();
        if (in_array($get('mutation_mode'), ['annul', 'restore'], true)) {
            return $currentType === ExpenseLineType::Estimate;
        }
        if ($newType !== ExpenseLineType::Estimate && $currentType !== ExpenseLineType::Estimate) {
            return false;
        }

        $amount = Decimal::normalizeInput($get('amount'));
        $quantity = Decimal::normalizeInput($get('quantity'));
        $unitAmount = Decimal::normalizeInput($get('unit_amount'));
        $note = is_string($get('note')) && trim($get('note')) !== '' ? trim($get('note')) : null;

        return $newType !== $currentType
            || $this->decimalFieldChanged($amount, (string) $current->amount)
            || $this->decimalFieldChanged($quantity, $current->getRawOriginal('quantity'))
            || $this->decimalFieldChanged($unitAmount, $current->getRawOriginal('unit_amount'))
            || $note !== $current->note;
    }

    private function decimalFieldChanged(mixed $newValue, mixed $currentValue): bool
    {
        if ($newValue === null || $newValue === '') {
            return $currentValue !== null;
        }
        if ((! is_string($newValue) && ! is_int($newValue) && ! is_float($newValue))
            || preg_match('/^-?\d+(?:\.\d+)?$/', (string) $newValue) !== 1) {
            return false;
        }
        if ($currentValue === null) {
            return true;
        }

        return Decimal::compare((string) $newValue, (string) $currentValue, 6) !== 0;
    }

    private function lineRequiresTerminalDeclaration(Get $get): bool
    {
        if ($get('mutation_mode') === 'annul'
            || ExpenseLineType::tryFrom((string) $get('type')) !== ExpenseLineType::Actual) {
            return false;
        }

        $expense = $this->expense();
        $today = now($expense->company->timezone)->toDateString();

        return ($expense->project !== null && in_array($expense->project->stateAtDate($today), [ProjectState::Closed, ProjectState::Cancelled], true))
            || ($expense->contract !== null && in_array($expense->contract->stateAtDate($today), [ContractState::Cessated, ContractState::Cancelled], true));
    }

    private function lineRequiresProjectOpening(Get $get): bool
    {
        $expense = $this->expense();

        return $get('mutation_mode') !== 'annul'
            && ExpenseLineType::tryFrom((string) $get('type')) === ExpenseLineType::Actual
            && $expense->project !== null
            && $expense->project->stateAtDate(now($expense->company->timezone)->toDateString()) === ProjectState::Planned;
    }

    private function lineRequiresOverspendNote(Get $get): bool
    {
        $expense = $this->expense();
        if (! $expense->company->overspend_note_required || $expense->project === null) {
            return false;
        }

        $type = ExpenseLineType::tryFrom((string) $get('type'));
        $amount = Decimal::normalizeInput($get('amount'));
        $amount = is_int($amount) || is_float($amount) ? (string) $amount : $amount;
        if (! $type instanceof ExpenseLineType
            || ! is_string($amount)
            || preg_match('/^-?\d+(?:\.\d+)?$/', $amount) !== 1) {
            return false;
        }

        $before = ProjectExpenseActivity::annualVariance($expense->project, $expense->exercise);
        $after = $before;
        $lineId = filter_var($get('line_id'), FILTER_VALIDATE_INT);
        $mode = (string) $get('mutation_mode');
        if (is_int($lineId)) {
            $current = $this->line($lineId);
            if ($mode === 'annul' && ! $current->isAnnulled()) {
                $after = Decimal::subtract($after, $this->varianceContribution($current->lineType(), (string) $current->amount));
            } elseif ($mode === 'restore' && $current->isAnnulled()) {
                $after = Decimal::add($after, $this->varianceContribution($current->lineType(), (string) $current->amount));
            } elseif (! $current->isAnnulled()) {
                $after = Decimal::subtract($after, $this->varianceContribution($current->lineType(), (string) $current->amount));
                $after = Decimal::add($after, $this->varianceContribution($type, $amount));
            }
        } else {
            $after = Decimal::add($after, $this->varianceContribution($type, $amount));
        }

        return ProjectOverspend::detect($before, $after) !== ProjectOverspendResult::None;
    }

    private function varianceContribution(ExpenseLineType $type, string $amount): string
    {
        return $type === ExpenseLineType::Actual ? Decimal::money($amount) : Decimal::subtract('0.00', $amount);
    }

    /** @return array{line_id: int, type: string, amount: string, mutation_mode: string, operation_id: string} */
    private function lineMutationState(int $lineId, string $mode): array
    {
        $line = $this->line($lineId);

        return [
            'line_id' => $line->id,
            'type' => $line->lineType()->value,
            'amount' => (string) $line->amount,
            'mutation_mode' => $mode,
            'operation_id' => (string) Str::uuid(),
        ];
    }

    /** @param array<string, string> $options
     * @return array<string, string>
     */
    private function terminalOptions(array $options): array
    {
        unset($options[ProjectActualKind::Ordinary->value]);

        return $options;
    }

    private function canMutate(): bool
    {
        $actor = auth()->user();
        $expense = $this->expense();

        return $actor instanceof User
            && $expense->origin !== 'system'
            && ! $expense->isReversed()
            && $actor->hasCapability($expense->company, Capability::ManageOperations);
    }

    private function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function afterMutation(string $message): void
    {
        unset($this->expense);
        Notification::make()->title($message)->success()->send();
        $this->dispatch('expense-detail-updated', expenseId: $this->expenseId);
    }
}
