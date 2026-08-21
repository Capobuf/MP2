<?php

namespace App\Filament\Resources\Contracts\RelationManagers;

use App\Actions\Operations\DetachAttachment;
use App\Actions\Operations\UploadAttachment;
use App\Models\Attachment;
use App\Models\Contract;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class ContractAttachmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'attachments';

    protected static ?string $title = 'Allegati';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Contract && auth()->user()?->can('view', $ownerRecord) === true;
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNull('detached_at'))
            ->columns([
                TextColumn::make('original_name')->label('File'),
                TextColumn::make('size_bytes')->label('Dimensione')->numeric(),
                TextColumn::make('sha256')->label('SHA-256')->copyable()->limit(16),
                TextColumn::make('uploader.name')->label('Caricato da'),
                TextColumn::make('created_at')->label('Caricato il')->dateTime('d/m/Y H:i'),
            ])->headerActions([
                Action::make('upload')->label('Carica Allegato')->visible(fn (): bool => $this->canManage())
                    ->form([
                        FileUpload::make('file')->label('File')->storeFiles(false)->required(),
                        Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
                    ])->action(function (array $data): void {
                        $actor = auth()->user();
                        $file = $data['file'] ?? null;
                        abort_unless($actor instanceof User && $file instanceof UploadedFile, 403);
                        app(UploadAttachment::class)->execute($actor, $this->contract(), $file, (string) $data['operation_id']);
                    }),
            ])->recordActions([
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

    private function contract(): Contract
    {
        $record = $this->getOwnerRecord();
        abort_unless($record instanceof Contract, 404);

        return $record;
    }

    private function canManage(): bool
    {
        return ! $this->contract()->isArchived() && auth()->user()?->can('update', $this->contract()) === true;
    }
}
