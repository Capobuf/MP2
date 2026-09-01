<?php

namespace App\Filament\Resources\Contracts\Pages;

use App\Actions\Operations\UpdateContract;
use App\Actions\Operations\UploadAttachment;
use App\Filament\Forms\AttachmentUpload;
use App\Filament\Resources\Contracts\ContractResource;
use App\Models\Contract;
use App\Models\Supplier;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class EditContract extends EditRecord
{
    protected static string $resource = ContractResource::class;

    public string $operationId;

    public function mount(int|string $record): void
    {
        $this->operationId = (string) Str::uuid();
        parent::mount($record);
    }

    public function form(Schema $schema): Schema
    {
        $contract = $this->contractRecord();
        $storedAttachments = $contract->attachments()
            ->attached()
            ->orderBy('id')
            ->get()
            ->map(fn ($attachment): AttachmentUpload => AttachmentUpload::forStoredAttachment($attachment))
            ->all();

        return $schema->components([
            TextInput::make('title')->label('Titolo')->required()->maxLength(255),
            Select::make('supplier_id')->label('Fornitore')->options(fn (): array => $this->supplierOptions())->required()->searchable()
                ->helperText('Il Fornitore può cambiare solo prima del primo utilizzo economico del Contratto.'),
            Textarea::make('notes')->label('Note'),
            Section::make('Allegati')
                ->description('I PDF già associati sono consultabili qui. Trascina altri file nel box per aggiungerli insieme al normale salvataggio del Contratto.')
                ->schema([
                    ...$storedAttachments,
                    AttachmentUpload::make('attachments')
                        ->label('Aggiungi Allegati')
                        ->multiple()
                        ->storeFiles(false)
                        ->visible(! $contract->isArchived())
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
        ]);
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $record instanceof Contract, 403);

        $attachments = $data['attachments'] ?? [];
        unset($data['attachments']);
        if (! is_array($attachments)) {
            throw ValidationException::withMessages(['attachments' => 'Gli Allegati caricati non sono validi.']);
        }
        foreach ($attachments as $attachment) {
            if (! $attachment instanceof UploadedFile) {
                throw ValidationException::withMessages(['attachments' => 'Gli Allegati caricati non sono validi.']);
            }
        }

        $updated = app(UpdateContract::class)->execute($actor, $record, $data, $this->operationId);
        foreach (array_values($attachments) as $index => $attachment) {
            app(UploadAttachment::class)->execute(
                $actor,
                $updated,
                $attachment,
                Uuid::uuid5($this->operationId, "attachment:{$index}")->toString(),
            );
        }

        return $updated;
    }

    protected function afterSave(): void
    {
        $this->operationId = (string) Str::uuid();
    }

    protected function getRedirectUrl(): string
    {
        return ContractResource::getUrl('view', ['record' => $this->record]);
    }

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }

    /** @return array<int, string> */
    private function supplierOptions(): array
    {
        /** @var Contract $contract */
        $contract = $this->record;
        $options = Supplier::query()->where('company_id', $contract->company_id)->active()->orderBy('legal_name')->pluck('legal_name', 'id')->all();
        $current = $contract->supplier;
        if ($current->isArchived()) {
            $options[$current->id] = $current->legal_name.' · Archiviato';
        }

        return $options;
    }

    private function contractRecord(): Contract
    {
        $record = $this->getRecord();
        if (! $record instanceof Contract) {
            throw new \UnexpectedValueException('Invalid Contract record.');
        }

        return $record;
    }
}
