<?php

namespace App\Filament\Resources\Exercises\Pages;

use App\Actions\Proposals\InitializeProposal;
use App\Domain\Company\Capability;
use App\Filament\Resources\Budgets\BudgetResource;
use App\Filament\Resources\Closings\ClosingResource;
use App\Filament\Resources\Exercises\ExerciseResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Proposals\ProposalResource;
use App\Models\BudgetSnapshot;
use App\Models\ClosingSnapshot;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Proposal;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Str;

class ViewExercise extends ViewRecord
{
    protected static string $resource = ExerciseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewClosing')
                ->label('Apri Chiusura')
                ->url(fn (): string => ClosingResource::getUrl('view', [
                    'record' => ClosingSnapshot::query()->where('exercise_id', $this->exerciseRecord()->id)->firstOrFail(),
                ]))
                ->visible(fn (): bool => ClosingSnapshot::query()->where('exercise_id', $this->exerciseRecord()->id)->exists()),
            Action::make('closeExercise')
                ->label('Chiudi esercizio')
                ->color('danger')
                ->url(fn (): string => ExerciseResource::getUrl('close', ['record' => $this->exerciseRecord()]))
                ->visible(fn (): bool => $this->canCloseExercise()),
            Action::make('viewProposal')->label('Apri Proposta')->url(fn (): string => ProposalResource::getUrl('view', ['record' => Proposal::query()->where('exercise_id', $this->exerciseRecord()->id)->latest('id')->firstOrFail()]))->visible(fn (): bool => Proposal::query()->where('exercise_id', $this->exerciseRecord()->id)->exists()),
            Action::make('viewBudget')->label('Apri Budget')->url(fn (): string => BudgetResource::getUrl('view', ['record' => BudgetSnapshot::query()->where('exercise_id', $this->exerciseRecord()->id)->latest('version')->firstOrFail()]))->visible(fn (): bool => BudgetSnapshot::query()->where('exercise_id', $this->exerciseRecord()->id)->exists()),
            Action::make('initializeProposal')
                ->label(fn (): string => $this->hasBudget() ? 'Crea revisione' : 'Inizializza proposta')
                ->requiresConfirmation()
                ->modalHeading(fn (): string => $this->hasBudget() ? 'Crea Revisione di Budget' : 'Inizializza Proposta di Budget')
                ->modalDescription(fn (): string => $this->hasBudget()
                    ? 'La base è la realtà corrente. L’ultimo Budget resta immutabile e viene mostrato solo come confronto; gli Effettivi restano in sola lettura.'
                    : 'La Proposta resta isolata: gli Effettivi sono mostrati in sola lettura e non vengono modificati.')
                ->modalSubmitActionLabel(fn (): string => $this->hasBudget() ? 'Crea revisione' : 'Inizializza proposta')
                ->visible(fn (): bool => $this->canManageProposals() && $this->exerciseRecord()->isOpen())
                ->disabled(fn (): bool => $this->proposalDisabledReason() !== null)
                ->tooltip(fn (): ?string => $this->proposalDisabledReason())
                ->form([Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid())])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    $company = Filament::getTenant();
                    abort_unless($actor instanceof User && $company instanceof Company, 403);
                    $proposal = app(InitializeProposal::class)->execute($actor, $company, $this->exerciseRecord(), (string) $data['operation_id']);
                    $this->redirect(ProposalResource::getUrl('view', ['record' => $proposal], tenant: $company));
                }),
            Action::make('createExpense')
                ->label('Nuova spesa')
                ->url(ExpenseResource::getUrl('create'))
                ->visible(fn (): bool => $this->exerciseRecord()->isOpen()),
        ];
    }

    private function exerciseRecord(): Exercise
    {
        if (! $this->record instanceof Exercise) {
            throw new \UnexpectedValueException('Invalid Exercise record.');
        }

        return $this->record;
    }

    private function canManageProposals(): bool
    {
        $actor = auth()->user();
        $company = Filament::getTenant();

        return $actor instanceof User && $company instanceof Company && $actor->hasCapability($company, Capability::ManageProposals);
    }

    private function canCloseExercise(): bool
    {
        $actor = auth()->user();
        $company = Filament::getTenant();

        return $this->exerciseRecord()->isOpen()
            && $actor instanceof User
            && $company instanceof Company
            && $actor->hasCapability($company, Capability::CloseExercise);
    }

    private function proposalDisabledReason(): ?string
    {
        $exercise = $this->exerciseRecord();
        if (! $exercise->isOpen()) {
            return 'L’Esercizio non è Aperto.';
        }
        if (Proposal::query()->where('exercise_id', $exercise->id)->where('status', 'draft')->exists()) {
            return 'Esiste già una Proposta attiva.';
        }

        return null;
    }

    private function hasBudget(): bool
    {
        return BudgetSnapshot::query()->where('exercise_id', $this->exerciseRecord()->id)->exists();
    }
}
