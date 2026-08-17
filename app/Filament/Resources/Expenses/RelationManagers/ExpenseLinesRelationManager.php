<?php

namespace App\Filament\Resources\Expenses\RelationManagers;

use App\Actions\Operations\CreateExpenseLine;
use App\Actions\Operations\SetExpenseLineActive;
use App\Actions\Operations\UpdateExpenseLine;
use App\Domain\Company\Capability;
use App\Domain\Expenses\ExpenseLineType;
use App\Filament\Resources\Expenses\Schemas\ExpenseForm;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
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

                        return app(CreateExpenseLine::class)->execute($actor, $expense, $data, $operationId);
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

                        return app(UpdateExpenseLine::class)->execute($actor, $record, $data, $operationId);
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
                    ->action(fn (ExpenseLine $record) => $this->setActive($record, false)),
                Action::make('restore')
                    ->label('Ripristina')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Ripristina riga')
                    ->modalDescription('La Riga tornerà a contribuire ai totali correnti.')
                    ->modalSubmitActionLabel('Ripristina riga')
                    ->modalCancelActionLabel('Torna alla riga')
                    ->visible(fn (ExpenseLine $record): bool => $record->isAnnulled() && $this->canMutateOwner())
                    ->action(fn (ExpenseLine $record) => $this->setActive($record, true)),
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

        return $actor instanceof User && $expense instanceof Expense && ! $expense->isReversed()
            && $actor->hasCapability($expense->company, Capability::ManageOperations);
    }

    private function setActive(ExpenseLine $line, bool $active): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        app(SetExpenseLineActive::class)->execute($actor, $line, $active, (string) Str::uuid());
        $line->refresh();
        $this->getOwnerRecord()->refresh();
    }
}
