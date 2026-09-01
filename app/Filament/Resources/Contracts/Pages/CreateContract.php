<?php

namespace App\Filament\Resources\Contracts\Pages;

use App\Actions\Operations\CreateContract as CreateContractAction;
use App\Actions\Operations\UploadAttachment;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Contracts\Schemas\ContractForm;
use App\Models\Company;
use App\Models\Contract;
use App\Models\TenantCompany;
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

    protected static ?string $title = 'Nuovo Contratto';

    public string $operationId;

    public function mount(): void
    {
        $this->operationId = (string) Str::uuid();
        parent::mount();
    }

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $defaultCostCenterId = $this->costCenterId($data['default_cost_center_id'] ?? null, allowNull: true);
        $classifications = $data['classifications'] ?? [];
        if (! is_array($classifications)) {
            throw ValidationException::withMessages(['classifications' => 'Le classificazioni non sono valide.']);
        }

        $data['classifications'] = array_map(function (mixed $classification) use ($defaultCostCenterId): array {
            if (! is_array($classification)) {
                throw ValidationException::withMessages(['classifications' => 'Le classificazioni non sono valide.']);
            }

            $selection = $classification['cost_center_selection'] ?? null;
            $costCenterId = match ($selection) {
                ContractForm::USE_DEFAULT_COST_CENTER => $defaultCostCenterId,
                ContractForm::NO_COST_CENTER => null,
                default => $this->costCenterId($selection),
            };

            return [
                'exercise_id' => $classification['exercise_id'] ?? null,
                'cost_center_id' => $costCenterId,
            ];
        }, $classifications);
        unset($data['default_cost_center_id']);

        return $data;
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;
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
        return parent::getCreateFormAction()->label('Crea Contratto');
    }

    private function costCenterId(mixed $value, bool $allowNull = false): ?int
    {
        if ($allowNull && ($value === null || $value === '')) {
            return null;
        }

        if ((! is_int($value) && ! (is_string($value) && ctype_digit($value))) || (int) $value < 1) {
            throw ValidationException::withMessages(['classifications' => 'Il Centro di Costo selezionato non è valido.']);
        }

        return (int) $value;
    }
}
