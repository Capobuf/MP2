<?php

namespace App\Filament\Resources\Expenses\RelationManagers;

use App\Actions\Operations\DetachAttachment;
use App\Filament\Forms\AttachmentUpload;
use App\Models\Attachment;
use App\Models\Expense;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

class ExpenseAttachmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'attachments';

    protected static ?string $title = 'Allegati';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Expense && auth()->user()?->can('view', $ownerRecord) === true;
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNull('detached_at'))
            ->columns([
                TextColumn::make('original_name')->label('File'),
                TextColumn::make('size_bytes')->label('Dimensione')
                    ->formatStateUsing(fn (mixed $state): string => Number::withLocale(
                        'it',
                        fn (): string => Number::fileSize((int) $state, maxPrecision: 2),
                    )),
                TextColumn::make('sha256')->label('SHA-256')->copyable()->limit(16),
                TextColumn::make('uploader.name')->label('Caricato da'),
                TextColumn::make('created_at')->label('Caricato il')->dateTime('d/m/Y H:i'),
            ])->recordActions([
                Action::make('preview')->label('Visualizza')->icon('heroicon-m-eye')
                    ->visible(fn (Attachment $record): bool => $record->media_type === 'application/pdf')
                    ->modalHeading(fn (Attachment $record): string => $record->original_name)
                    ->modalWidth(Width::FiveExtraLarge)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Chiudi')
                    ->form(fn (Attachment $record): array => [AttachmentUpload::forStoredAttachment($record)]),
                Action::make('download')->label('Scarica')->url(fn (Attachment $record): string => route('attachments.download', $record)),
                Action::make('detach')->label('Rimuovi dall’oggetto')->color('warning')->requiresConfirmation()
                    ->visible(fn (): bool => $this->canManage())
                    ->action(function (Attachment $record): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);
                        app(DetachAttachment::class)->execute($actor, $record, (string) Str::uuid());
                    }),
            ]);
    }

    private function expense(): Expense
    {
        $record = $this->getOwnerRecord();
        abort_unless($record instanceof Expense, 404);

        return $record;
    }

    private function canManage(): bool
    {
        $expense = $this->expense();

        return ($expense->contract === null || ! $expense->contract->isArchived())
            && auth()->user()?->can('update', $expense) === true;
    }
}
