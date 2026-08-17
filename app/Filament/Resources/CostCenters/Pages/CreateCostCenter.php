<?php

namespace App\Filament\Resources\CostCenters\Pages;

use App\Actions\MasterData\CreateCostCenter as CreateCostCenterAction;
use App\Filament\Resources\CostCenters\CostCenterResource;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CreateCostCenter extends CreateRecord
{
    protected static string $resource = CostCenterResource::class;

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

        return app(CreateCostCenterAction::class)->execute($actor, $company, $data, $this->operationId);
    }

    protected function getRedirectUrl(): string
    {
        /** @var CostCenter $record */
        $record = $this->record;

        return CostCenterResource::getUrl('view', ['record' => $record]);
    }
}
