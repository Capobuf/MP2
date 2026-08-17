<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Actions\Operations\UpdateProject;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class EditProject extends EditRecord
{
    protected static string $resource = ProjectResource::class;

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
            Textarea::make('description')->label('Descrizione'),
            Textarea::make('notes')->label('Note'),
        ]);
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $record instanceof Project, 403);

        return app(UpdateProject::class)->execute($actor, $record, $data, $this->operationId);
    }

    protected function afterSave(): void
    {
        $this->operationId = (string) Str::uuid();
    }

    protected function getRedirectUrl(): string
    {
        return ProjectResource::getUrl('view', ['record' => $this->record]);
    }

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }
}
