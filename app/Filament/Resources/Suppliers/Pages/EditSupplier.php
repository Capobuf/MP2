<?php

namespace App\Filament\Resources\Suppliers\Pages;

use App\Actions\MasterData\UpdateSupplier;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\Supplier;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class EditSupplier extends EditRecord
{
    protected static string $resource = SupplierResource::class;

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
        abort_unless($actor instanceof User && $record instanceof Supplier, 403);

        return app(UpdateSupplier::class)->execute($actor, $record, $data, $this->operationId);
    }

    protected function afterSave(): void
    {
        $this->operationId = (string) Str::uuid();
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            SupplierResource::archiveAction(),
            SupplierResource::restoreAction(),
        ];
    }
}
