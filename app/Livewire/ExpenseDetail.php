<?php

namespace App\Livewire;

use App\Actions\Operations\CreateExpenseLine;
use App\Actions\Operations\SetExpenseLineActive;
use App\Actions\Operations\UpdateExpenseLine;
use App\Domain\Company\Capability;
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
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
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
                ...ExpenseForm::lineFormSections(),
                ExpenseForm::projectActivitySection($this->expense()->project_id !== null),
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
                ...ExpenseForm::lineFormSections(),
                ExpenseForm::projectActivitySection($this->expense()->project_id !== null),
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
                ...ExpenseForm::projectActivityFields($this->expense()->project_id !== null),
                Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
            ])
            ->visible(fn (): bool => $this->canMutate())
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
                ...ExpenseForm::projectActivityFields($this->expense()->project_id !== null),
                Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
            ])
            ->visible(fn (): bool => $this->canMutate())
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
