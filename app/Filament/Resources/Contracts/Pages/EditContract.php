<?php

namespace App\Filament\Resources\Contracts\Pages;

use App\Actions\Operations\UpdateContract;
use App\Filament\Resources\Contracts\ContractResource;
use App\Models\Contract;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

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
        return $schema->components([
            TextInput::make('title')->label('Titolo')->required()->maxLength(255),
            Textarea::make('notes')->label('Note'),
        ]);
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $record instanceof Contract, 403);

        return app(UpdateContract::class)->execute($actor, $record, $data, $this->operationId);
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
}
