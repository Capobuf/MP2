<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Actions\Operations\CreateProject as CreateProjectAction;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Project;
use App\Models\TenantCompany;
use App\Models\User;
use App\Support\ExerciseContext;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

    public function getSubheading(): ?string
    {
        $exercise = $this->currentExercise();

        if (! $exercise instanceof Exercise) {
            return 'Nessun Esercizio globale selezionato.';
        }

        return "Classificazione iniziale nell’Esercizio globale {$exercise->year} · {$exercise->status()->label()}";
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;
        abort_unless($actor instanceof User && $company instanceof Company, 403);

        $exercise = app(ExerciseContext::class)->current($company);
        if (! $exercise instanceof Exercise) {
            throw ValidationException::withMessages([
                'exercise_id' => 'Selezionare un Esercizio globale prima di creare un Progetto.',
            ]);
        }

        $data['exercise_id'] = $exercise->id;

        return app(CreateProjectAction::class)->execute($actor, $company, $data, $this->operationId);
    }

    protected function afterCreate(): void
    {
        $this->operationId = (string) Str::uuid();
    }

    protected function getRedirectUrl(): string
    {
        /** @var Project $record */
        $record = $this->record;

        return ProjectResource::getUrl('view', ['record' => $record]);
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->disabled(fn (): bool => $this->createDisabledReason() !== null)
            ->tooltip(fn (): ?string => $this->createDisabledReason());
    }

    private function createDisabledReason(): ?string
    {
        $exercise = $this->currentExercise();

        if (! $exercise instanceof Exercise) {
            return 'Seleziona un Esercizio globale prima di creare il Progetto.';
        }

        return $exercise->isOpen() ? null : 'L’Esercizio globale selezionato è Chiuso.';
    }

    private function currentExercise(): ?Exercise
    {
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;

        return $company instanceof Company
            ? app(ExerciseContext::class)->current($company)
            : null;
    }
}
