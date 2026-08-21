<?php

namespace App\Filament\Resources\Exercises\Pages;

use App\Actions\Operations\CreateExercise as CreateExerciseAction;
use App\Filament\Resources\Exercises\ExerciseResource;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CreateExercise extends CreateRecord
{
    protected static string $resource = ExerciseResource::class;

    protected static ?string $title = 'Nuovo esercizio';

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

        return app(CreateExerciseAction::class)->execute($actor, $company, $data, $this->operationId);
    }

    protected function afterCreate(): void
    {
        $this->operationId = (string) Str::uuid();
    }

    protected function getRedirectUrl(): string
    {
        /** @var Exercise $record */
        $record = $this->record;

        return ExerciseResource::getUrl('view', ['record' => $record]);
    }
}
