<?php

namespace App\Filament\Resources\Expenses\RelationManagers;

use App\Actions\Operations\CreateExpenseLine;
use App\Actions\Operations\DetachAttachment;
use App\Actions\Operations\SetExpenseLineActive;
use App\Actions\Operations\UpdateExpenseLine;
use App\Actions\Operations\UploadAttachment;
use App\Domain\Company\Capability;
use App\Domain\Expenses\ExpenseLineType;
use App\Filament\Resources\Expenses\Schemas\ExpenseForm;
use App\Filament\Support\ProjectOverspendNotifier;
use App\Models\Attachment;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\HtmlString;
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
            ...ExpenseForm::lineFormSections($this->ownerIsContractExpense()),
            ExpenseForm::containerActivitySection($this->ownerIsProjectExpense(), $this->ownerIsContractExpense()),
            Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')->label('Tipo')->formatStateUsing(fn ($state): string => $state instanceof ExpenseLineType ? $state->label() : ExpenseLineType::from($state)->label())
                    ->badge()->color(fn ($state): string => ($state instanceof ExpenseLineType ? $state : ExpenseLineType::from($state)) === ExpenseLineType::Estimate ? 'primary' : 'success'),
                TextColumn::make('note')->label('Nota')->placeholder('—')->wrap(),
                TextColumn::make('quantity')->label('Quantità')->placeholder('—'),
                TextColumn::make('unit_amount')->label('Importo unitario')->placeholder('—'),
                TextColumn::make('unit_of_measure')->label('Unità di misura')->placeholder('—'),
                TextColumn::make('amount')->label('Importo')->money('EUR', locale: 'it')->alignment(Alignment::End),
                TextColumn::make('state')->label('Stato')->state(fn (ExpenseLine $record): string => $record->isAnnulled() ? 'Annullata' : 'Attiva')
                    ->badge()->color(fn (string $state): string => $state === 'Attiva' ? 'success' : 'gray'),
                TextColumn::make('updated_at')->label('Ultima modifica')->dateTime('d/m/Y H:i')
                    ->timezone(fn (ExpenseLine $record): string => $record->expense->company->timezone),
                TextColumn::make('attachments_live')->label('Allegati')->state(fn (ExpenseLine $record): HtmlString => new HtmlString(
                    $record->attachments()->attached()->orderBy('id')->get()->map(
                        fn (Attachment $attachment): string => '<a class="fi-link" href="'.e(route('attachments.download', $attachment)).'">'.e($attachment->original_name).'</a>',
                    )->implode('<br>') ?: '—',
                ))->html(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Aggiungi riga')
                    ->modalHeading('Aggiungi riga')
                    ->modalDescription('Aggiungi una Stima o un Effettivo alla Spesa. L’Importo resta autoritativo.')
                    ->modalSubmitActionLabel('Aggiungi riga')
                    ->modalCancelActionLabel('Annulla')
                    ->slideOver()
                    ->modalWidth(Width::TwoExtraLarge)
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
                    ->modalDescription('La modifica conserva l’identità della Riga e viene registrata nella Timeline.')
                    ->modalSubmitActionLabel('Salva modifica')
                    ->modalCancelActionLabel('Annulla')
                    ->slideOver()
                    ->modalWidth(Width::TwoExtraLarge)
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
                        ...ExpenseForm::containerActivityFields($this->ownerIsProjectExpense(), $this->ownerIsContractExpense()),
                        Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
                    ])
                    ->action(fn (ExpenseLine $record, array $data) => $this->setActive($record, true, $data)),
                Action::make('uploadAttachment')->label('Carica Allegato')->visible(fn (): bool => $this->canManageAttachments())
                    ->form([
                        FileUpload::make('file')->label('File')->storeFiles(false)->required(),
                        Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
                    ])->action(function (ExpenseLine $record, array $data): void {
                        $actor = auth()->user();
                        $file = $data['file'] ?? null;
                        abort_unless($actor instanceof User && $file instanceof UploadedFile, 403);
                        app(UploadAttachment::class)->execute($actor, $record, $file, (string) $data['operation_id']);
                    }),
                Action::make('detachAttachment')->label('Rimuovi Allegato')->color('warning')->requiresConfirmation()
                    ->visible(fn (ExpenseLine $record): bool => $this->canManageAttachments() && $record->attachments()->attached()->exists())
                    ->form([
                        Select::make('attachment_id')->label('Allegato')->required()->options(fn (ExpenseLine $record): array => $record->attachments()->attached()->orderBy('original_name')->pluck('original_name', 'id')->all()),
                    ])->action(function (ExpenseLine $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);
                        $attachment = $record->attachments()->attached()->findOrFail((int) $data['attachment_id']);
                        app(DetachAttachment::class)->execute($actor, $attachment, (string) Str::uuid());
                    }),
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

    private function canManageAttachments(): bool
    {
        $actor = auth()->user();
        $expense = $this->getOwnerRecord();

        return $actor instanceof User && $expense instanceof Expense
            && ($expense->contract === null || ! $expense->contract->isArchived())
            && $actor->can('update', $expense);
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

    private function ownerIsProjectExpense(): bool
    {
        $expense = $this->getOwnerRecord();

        return $expense instanceof Expense && $expense->project_id !== null;
    }

    private function ownerIsContractExpense(): bool
    {
        $expense = $this->getOwnerRecord();

        return $expense instanceof Expense && $expense->contract_id !== null;
    }
}
