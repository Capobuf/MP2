<?php

namespace App\Filament\Resources\Expenses\RelationManagers;

use App\Actions\Operations\CreateExpenseLine;
use App\Actions\Operations\SetExpenseLineActive;
use App\Actions\Operations\UpdateExpenseLine;
use App\Domain\Company\Capability;
use App\Domain\Expenses\ExpenseLineType;
use App\Domain\Projects\ProjectActualKind;
use App\Filament\Resources\Expenses\Schemas\ExpenseForm;
use App\Filament\Support\ProjectOverspendNotifier;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ExpenseLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Righe';

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Expense && auth()->user()?->can('view', $ownerRecord) === true;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            ...ExpenseForm::lineFields(),
            ...$this->projectActivityFields(),
            Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')->label('Tipo')->formatStateUsing(fn ($state): string => $state instanceof ExpenseLineType ? $state->label() : ExpenseLineType::from($state)->label()),
                TextColumn::make('amount')->label('Importo EUR netto IVA')->money('EUR', locale: 'it'),
                TextColumn::make('quantity')->label('Quantità')->placeholder('—'),
                TextColumn::make('unit_amount')->label('Importo unitario')->placeholder('—'),
                TextColumn::make('unit_of_measure')->label('Unità di misura')->placeholder('—'),
                TextColumn::make('note')->label('Nota')->placeholder('—')->wrap(),
                TextColumn::make('state')->label('Stato')->state(fn (ExpenseLine $record): string => $record->isAnnulled() ? 'Annullata' : 'Attiva')->badge(),
                TextColumn::make('updated_at')->label('Ultima modifica')->dateTime('d/m/Y H:i'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Aggiungi riga')
                    ->modalHeading('Aggiungi riga')
                    ->modalSubmitActionLabel('Aggiungi')
                    ->createAnother(false)
                    ->visible(fn (): bool => $this->canMutateOwner())
                    ->using(function (array $data): ExpenseLine {
                        $actor = auth()->user();
                        $expense = $this->getOwnerRecord();
                        abort_unless($actor instanceof User && $expense instanceof Expense, 403);
                        $operationId = (string) $data['operation_id'];
                        unset($data['operation_id']);

                        $line = app(CreateExpenseLine::class)->execute($actor, $expense, $data, $operationId);
                        ProjectOverspendNotifier::sendForOperation($operationId);

                        return $line;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalHeading('Modifica riga')
                    ->modalSubmitActionLabel('Salva')
                    ->visible(fn (): bool => $this->canMutateOwner())
                    ->using(function (array $data, Model $record): ExpenseLine {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User && $record instanceof ExpenseLine, 403);
                        $operationId = (string) $data['operation_id'];
                        unset($data['operation_id']);

                        $line = app(UpdateExpenseLine::class)->execute($actor, $record, $data, $operationId);
                        ProjectOverspendNotifier::sendForOperation($operationId);

                        return $line;
                    }),
                Action::make('annul')
                    ->label('Annulla')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Annulla riga')
                    ->modalDescription('La Riga sarà esclusa dai totali correnti, senza essere eliminata.')
                    ->modalSubmitActionLabel('Annulla riga')
                    ->modalCancelActionLabel('Torna alla riga')
                    ->visible(fn (ExpenseLine $record): bool => ! $record->isAnnulled() && $this->canMutateOwner())
                    ->form([
                        Textarea::make('overspend_note')
                            ->label('Nota di sovraspesa')
                            ->visible(fn (): bool => $this->getOwnerRecord() instanceof Expense && $this->getOwnerRecord()->project_id !== null),
                        Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
                    ])
                    ->action(fn (ExpenseLine $record, array $data) => $this->setActive($record, false, $data)),
                Action::make('restore')
                    ->label('Ripristina')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Ripristina riga')
                    ->modalDescription('La Riga tornerà a contribuire ai totali correnti.')
                    ->modalSubmitActionLabel('Ripristina riga')
                    ->modalCancelActionLabel('Torna alla riga')
                    ->visible(fn (ExpenseLine $record): bool => $record->isAnnulled() && $this->canMutateOwner())
                    ->form([
                        ...$this->projectActivityFields(),
                        Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
                    ])
                    ->action(fn (ExpenseLine $record, array $data) => $this->setActive($record, true, $data)),
            ]);
    }

    protected function getCreateAuthorizationResponse(): Response
    {
        return $this->canMutateOwner() ? Response::allow() : Response::deny('La Spesa deve essere Attiva.');
    }

    private function canMutateOwner(): bool
    {
        $actor = auth()->user();
        $expense = $this->getOwnerRecord();

        return $actor instanceof User && $expense instanceof Expense && $expense->origin !== 'system' && ! $expense->isReversed()
            && $actor->hasCapability($expense->company, Capability::ManageOperations);
    }

    /** @param array<string, mixed> $data */
    private function setActive(ExpenseLine $line, bool $active, array $data = []): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $operationId = isset($data['operation_id']) ? (string) $data['operation_id'] : (string) Str::uuid();
        unset($data['operation_id']);
        app(SetExpenseLineActive::class)->execute($actor, $line, $active, $operationId, $data);
        ProjectOverspendNotifier::sendForOperation($operationId);
        $line->refresh();
        $this->getOwnerRecord()->refresh();
    }

    /** @return array<int, mixed> */
    private function projectActivityFields(): array
    {
        return [
            Select::make('actual_kind')
                ->label('Dichiarazione Effettivo')
                ->options(ProjectActualKind::options())
                ->placeholder('Ordinario')
                ->visible(fn (): bool => $this->getOwnerRecord() instanceof Expense && $this->getOwnerRecord()->project_id !== null),
            Checkbox::make('open_project')
                ->label('Conferma apertura atomica se il Progetto è Pianificato')
                ->visible(fn (): bool => $this->getOwnerRecord() instanceof Expense && $this->getOwnerRecord()->project_id !== null),
            Textarea::make('activity_note')
                ->label('Nota attività tardiva, rimborso o correzione')
                ->visible(fn (): bool => $this->getOwnerRecord() instanceof Expense && $this->getOwnerRecord()->project_id !== null),
            Textarea::make('overspend_note')
                ->label('Nota di sovraspesa')
                ->visible(fn (): bool => $this->getOwnerRecord() instanceof Expense && $this->getOwnerRecord()->project_id !== null),
        ];
    }
}
