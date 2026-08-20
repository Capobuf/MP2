<?php

namespace App\Filament\Resources\Contracts\Pages;

use App\Actions\Operations\CreateContract as CreateContractAction;
use App\Filament\Resources\Contracts\ContractResource;
use App\Models\Company;
use App\Models\Contract;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CreateContract extends CreateRecord
{
    protected static string $resource = ContractResource::class;

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

        return app(CreateContractAction::class)->execute($actor, $company, $data, $this->operationId);
    }

    protected function getRedirectUrl(): string
    {
        /** @var Contract $record */
        $record = $this->record;

        return ContractResource::getUrl('view', ['record' => $record]);
    }
}
