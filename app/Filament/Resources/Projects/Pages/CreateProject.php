<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Actions\Operations\CreateProject as CreateProjectAction;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CreateProject extends CreateRecord
{
    protected static string $resource = ProjectResource::class;

    protected static ?string $title = 'Nuovo progetto';

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

        return app(CreateProjectAction::class)->execute($actor, $company, $data, $this->operationId);
    }

    protected function getRedirectUrl(): string
    {
        /** @var Project $record */
        $record = $this->record;

        return ProjectResource::getUrl('view', ['record' => $record]);
    }
}
