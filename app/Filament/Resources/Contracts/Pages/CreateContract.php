<?php

namespace App\Filament\Resources\Contracts\Pages;

use App\Actions\Operations\CreateContract as CreateContractAction;
use App\Actions\Operations\UploadAttachment;
use App\Filament\Resources\Contracts\ContractResource;
use App\Models\Company;
use App\Models\Contract;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class CreateContract extends CreateRecord
{
    protected static string $resource = ContractResource::class;

    protected static bool $canCreateAnother = false;

    protected static ?string $title = 'Nuovo contratto';

    public string $operationId;

    public function mount(): void
    {
        $this->operationId = (string) Str::uuid();
        parent::mount();
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        $company = Filament::getTenant();
        abort_unless($actor instanceof User && $company instanceof Company, 403);

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

        $data['renewal_duration_months'] ??= null;
        $contract = app(CreateContractAction::class)->execute($actor, $company, $data, $this->operationId);
        foreach (array_values($attachments) as $index => $attachment) {
            app(UploadAttachment::class)->execute(
                $actor,
                $contract,
                $attachment,
                Uuid::uuid5($this->operationId, "attachment:{$index}")->toString(),
            );
        }

        return $contract;
    }

    protected function getRedirectUrl(): string
    {
        /** @var Contract $record */
        $record = $this->record;

        return ContractResource::getUrl('view', ['record' => $record]);
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Crea contratto');
    }
}
