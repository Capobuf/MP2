<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Actions\Operations\CreateExpense as CreateExpenseAction;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Support\ProjectOverspendNotifier;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\User;
use App\Support\ExerciseContext;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateExpense extends CreateRecord
{
    protected static string $resource = ExpenseResource::class;

    protected static ?string $title = 'Nuova spesa';

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

        return "Esercizio globale: {$exercise->year} · {$exercise->status()->label()}";
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        $company = Filament::getTenant();
        abort_unless($actor instanceof User && $company instanceof Company, 403);

        $exercise = app(ExerciseContext::class)->current($company);
        if (! $exercise instanceof Exercise) {
            throw ValidationException::withMessages([
                'exercise_id' => 'Selezionare un Esercizio globale prima di creare una Spesa.',
            ]);
        }

        $container = $data['container'] ?? null;
        unset($data['container']);

        if ($container === 'autonomous') {
            $data['project_id'] = null;
            $data['contract_id'] = null;
        } elseif ($container === 'project') {
            $data['contract_id'] = null;
            $data['direct_cost_center_id'] = null;
        } elseif ($container === 'contract') {
            $data['project_id'] = null;
            $data['direct_cost_center_id'] = null;
            $data['supplier_id'] = null;
        } else {
            throw ValidationException::withMessages([
                'container' => 'Selezionare un Contenitore valido.',
            ]);
        }

        $data['exercise_id'] = $exercise->id;

        $expense = app(CreateExpenseAction::class)->execute($actor, $company, $data, $this->operationId);
        ProjectOverspendNotifier::sendForOperation($this->operationId);

        return $expense;
    }

    protected function afterCreate(): void
    {
        $this->operationId = (string) Str::uuid();
    }

    protected function getRedirectUrl(): string
    {
        /** @var Expense $record */
        $record = $this->record;

        return ExpenseResource::getUrl('view', ['record' => $record]);
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
            return 'Seleziona un Esercizio globale prima di creare la Spesa.';
        }

        return $exercise->isOpen() ? null : 'L’Esercizio globale selezionato è Chiuso.';
    }

    private function currentExercise(): ?Exercise
    {
        $company = Filament::getTenant();

        return $company instanceof Company
            ? app(ExerciseContext::class)->current($company)
            : null;
    }
}
