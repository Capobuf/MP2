<?php

namespace App\Filament\Resources\Suppliers\Pages;

use App\Actions\MasterData\CreateSupplier as CreateSupplierAction;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CreateSupplier extends CreateRecord
{
    protected static string $resource = SupplierResource::class;

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

        return app(CreateSupplierAction::class)->execute($actor, $company, $data, $this->operationId);
    }

    protected function afterCreate(): void
    {
        $this->operationId = (string) Str::uuid();
    }

    protected function getRedirectUrl(): string
    {
        /** @var Supplier $record */
        $record = $this->record;

        return SupplierResource::getUrl('view', ['record' => $record]);
    }
}
