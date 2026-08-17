<?php

namespace App\Filament\Resources\CostCenters\Pages;

use App\Actions\MasterData\RenameCostCenter;
use App\Filament\Resources\CostCenters\CostCenterResource;
use App\Models\CostCenter;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class EditCostCenter extends EditRecord
{
    protected static string $resource = CostCenterResource::class;

    public string $operationId;

    public function mount(int|string $record): void
    {
        $this->operationId = (string) Str::uuid();
        parent::mount($record);
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $record instanceof CostCenter, 403);

        return app(RenameCostCenter::class)->execute($actor, $record, $data, $this->operationId);
    }

    protected function afterSave(): void
    {
        $this->operationId = (string) Str::uuid();
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            CostCenterResource::archiveAction(),
            CostCenterResource::restoreAction(),
        ];
    }
}
